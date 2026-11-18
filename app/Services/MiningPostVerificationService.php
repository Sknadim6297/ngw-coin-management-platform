<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Database;
use RuntimeException;
use Throwable;

/**
 * Phase 13A — isolated end-to-end verification of `mining:post`.
 *
 * Works ONLY on coin_platform_phase13a (recreated each run). The live `coin_platform`
 * database is read (SELECT COUNT/SUM) before and after to prove it was never touched.
 * Real posting is executed through the actual Spark command in child processes.
 */
class MiningPostVerificationService
{
    public const GROUP    = 'phase13a';
    public const DATABASE = 'coin_platform_phase13a';

    /** Synthetic cohort: key => [label, purchases[], eligible_for_yesterday, eligible_for_today, deactivate] */
    private const COHORT = [
        'A' => ['label' => 'LOCKED_ONLY',   'buys' => [['100',   true]],               'd1' => true,  'd2' => true,  'deactivate' => false],
        'B' => ['label' => 'LOCKED_ONLY',   'buys' => [['250.5', true]],               'd1' => true,  'd2' => true,  'deactivate' => false],
        'C' => ['label' => 'AVAILABLE_ONLY','buys' => [['40',    false]],              'd1' => true,  'd2' => true,  'deactivate' => false],
        'D' => ['label' => 'MIXED',         'buys' => [['30', false], ['20', true]],   'd1' => true,  'd2' => true,  'deactivate' => false],
        'E' => ['label' => 'NO_PURCHASE',   'buys' => [],                              'd1' => false, 'd2' => false, 'deactivate' => false],
        'F' => ['label' => 'INACTIVE',      'buys' => [['60',    true]],               'd1' => false, 'd2' => false, 'deactivate' => true],
        'G' => ['label' => 'STARTS_TODAY',  'buys' => [['80',    true]],               'd1' => false, 'd2' => true,  'deactivate' => false],
    ];

    private const RATE_PERCENT = '3.00000000';

    /** @var list<array{name: string, ok: bool, detail: string}> */
    private array $checks = [];

    /**
     * @return array<string, mixed>
     */
    public function run(): array
    {
        $this->checks = [];
        $report = [
            'phase'       => '13A',
            'started_at'  => date('c'),
            'database'    => self::DATABASE,
            'success'     => false,
            'errors'      => [],
        ];

        $previous = config('Database')->defaultGroup;

        try {
            $this->assertTargetIsIsolated();
            $report['live_baseline_before'] = $this->liveBaseline();

            $this->recreateDatabase();
            $this->switchToIsolated();

            $report['switches_before'] = service('financialSandboxService')->productionSwitchSnapshot();
            $this->check(
                'platform_kill_switches_off_by_default',
                empty($report['switches_before']['miningPostingEnabled']) && empty(config('Platform')->miningAutoPostEnabled),
                'miningPostingEnabled=false miningAutoPostEnabled=false'
            );

            $seed = $this->seed();
            $report['seed'] = $seed;

            $d1 = service('miningPostingRunnerService')->resolveDate('yesterday');
            $d2 = service('miningPostingRunnerService')->resolveDate('today');
            $report['business_dates'] = ['yesterday' => $d1, 'today' => $d2];
            $report['rate_percent']   = self::RATE_PERCENT;

            $ownershipBefore = $this->ownershipSnapshot();
            $expected = $this->expectedPerMember($seed['members'], $d1, $d2);
            $report['expected'] = $expected;

            $report['precision_note'] = $this->precisionNote();

            // ----------------------------------------------------- negative / safety scenarios
            $report['scenarios'] = [];
            $report['scenarios'] = array_merge($report['scenarios'], $this->safetyScenarios($seed, $d1, $d2));

            // ----------------------------------------------------- real posting through the Spark command
            $runs = [];
            $runs['d1_first']   = $this->spark(['--date=' . $d1, '--scheduled', '--confirm=APPLY_STAGING']);
            $afterFirst         = $this->ledgerTotals($d1);
            $balancesAfterFirst = $this->walletSnapshot();

            $runs['d1_second']  = $this->spark(['--date=' . $d1, '--scheduled', '--confirm=APPLY_STAGING']);
            $runs['d1_manual']  = $this->spark(['--date=' . $d1, '--confirm=APPLY_STAGING']);
            $afterRepeat        = $this->ledgerTotals($d1);
            $balancesAfterRepeat = $this->walletSnapshot();

            $runs['d2_first']   = $this->spark(['--date=' . $d2, '--scheduled', '--confirm=APPLY_STAGING', '--allow-incomplete-day']);
            $runs['d2_second']  = $this->spark(['--date=' . $d2, '--scheduled', '--confirm=APPLY_STAGING', '--allow-incomplete-day']);
            $report['runs']     = array_map(static fn (array $r): array => [
                'exit' => $r['exit'],
                'status' => $r['json']['status'] ?? null,
                'credited' => $r['json']['credited'] ?? null,
                'duplicates' => $r['json']['duplicates'] ?? null,
                'attempts' => $r['json']['attempts'] ?? null,
                'expected_total' => $r['json']['expected_total'] ?? null,
                'posted_total' => $r['json']['posted_total'] ?? null,
                'mismatches' => isset($r['json']['mismatches']) ? count($r['json']['mismatches']) : null,
                'run_ref' => $r['json']['run_ref'] ?? null,
            ], $runs);

            $eligibleD1 = count(array_filter($expected, static fn (array $e): bool => $e['d1_eligible']));
            $eligibleD2 = count(array_filter($expected, static fn (array $e): bool => $e['d2_eligible']));

            $this->check('d1_first_run_completed', ($runs['d1_first']['json']['status'] ?? '') === 'completed' && $runs['d1_first']['exit'] === 0, 'status=' . ($runs['d1_first']['json']['status'] ?? 'n/a'));
            $this->check('d1_first_credited_count', (int) ($runs['d1_first']['json']['credited'] ?? -1) === $eligibleD1, 'credited=' . ($runs['d1_first']['json']['credited'] ?? 'n/a') . ' expected=' . $eligibleD1);
            $this->check('d1_second_run_zero_new_credits', (int) ($runs['d1_second']['json']['credited'] ?? -1) === 0 && (int) ($runs['d1_second']['json']['duplicates'] ?? -1) === $eligibleD1, 'credited=' . ($runs['d1_second']['json']['credited'] ?? 'n/a') . ' duplicates=' . ($runs['d1_second']['json']['duplicates'] ?? 'n/a'));
            $this->check('d1_manual_third_run_zero_new_credits', (int) ($runs['d1_manual']['json']['credited'] ?? -1) === 0, 'credited=' . ($runs['d1_manual']['json']['credited'] ?? 'n/a'));
            $this->check('d1_ledger_rows_unchanged_after_repeat', $afterFirst['rows'] === $afterRepeat['rows'] && $afterFirst['sum'] === $afterRepeat['sum'], 'rows ' . $afterFirst['rows'] . '→' . $afterRepeat['rows'] . ' sum ' . $afterFirst['sum'] . '→' . $afterRepeat['sum']);
            $this->check('wallet_balances_unchanged_after_repeat', $balancesAfterFirst === $balancesAfterRepeat, 'wallet snapshot identical after 2 repeat runs');
            $this->check('d2_first_credited_count', (int) ($runs['d2_first']['json']['credited'] ?? -1) === $eligibleD2, 'credited=' . ($runs['d2_first']['json']['credited'] ?? 'n/a') . ' expected=' . $eligibleD2);
            $this->check('d2_second_run_zero_new_credits', (int) ($runs['d2_second']['json']['credited'] ?? -1) === 0, 'credited=' . ($runs['d2_second']['json']['credited'] ?? 'n/a'));

            // ----------------------------------------------------- expected vs actual
            $table = $this->compare($seed['members'], $expected, $d1, $d2);
            $report['comparison'] = $table['rows'];
            $report['totals']     = $table['totals'];
            foreach ($table['failures'] as $f) {
                $this->check($f['name'], false, $f['detail']);
            }
            $this->check('expected_equals_actual_all_members', $table['failures'] === [], count($table['rows']) . ' members compared');

            $dupes = db_connect()->query(
                "SELECT member_user_id, business_date, COUNT(*) AS c FROM mining_wallet_ledger
                 WHERE entry_type = 'daily_mining_credit' GROUP BY member_user_id, business_date HAVING c > 1"
            )->getResultArray();
            $report['duplicate_credit_groups'] = $dupes;
            $this->check('zero_duplicate_credits_per_member_per_date', $dupes === [], 'duplicate groups=' . count($dupes));

            $ledgerVsWallet = $this->ledgerVsWallet();
            $report['ledger_vs_wallet'] = $ledgerVsWallet;
            $this->check('ledger_sum_equals_wallet_balance_for_every_member', $ledgerVsWallet['mismatches'] === [], 'mismatches=' . count($ledgerVsWallet['mismatches']));

            $nonSynthetic = (int) db_connect()->table('mining_wallets')->like('member_id', 'SYN', 'after')->countAllResults();
            $totalWallets = (int) db_connect()->table('mining_wallets')->countAllResults();
            $this->check('only_synthetic_members_have_wallets', $nonSynthetic === $totalWallets, "synthetic={$nonSynthetic} total={$totalWallets}");

            $ownershipAfter = $this->ownershipSnapshot();
            $this->check('ownership_ledger_untouched_by_mining_post', $ownershipBefore === $ownershipAfter, 'member_coin_balances identical before/after posting');

            // Reconcile flags credits as "unexpected" whenever posting is not authorized, so it must be
            // evaluated inside an authorized window (the runner does the same internally).
            $sandbox = service('financialSandboxService');
            $sandbox->openWindow(60, self::GROUP);
            try {
                foreach (['d1' => $d1, 'd2' => $d2] as $label => $date) {
                    $recon = service('miningDailyEngineService')->reconcile($date, 200);
                    $report['reconciliation'][$label] = $recon['summary'] + ['date' => $date, 'mismatch_rows' => $recon['mismatches']];
                    $this->check('reconcile_' . $label . '_zero_mismatches', $recon['summary']['mismatches'] === 0, 'mismatches=' . $recon['summary']['mismatches']);
                }
            } finally {
                $sandbox->closeWindow();
            }

            $report['run_history'] = db_connect()->table('mining_daily_runs')
                ->select('run_ref, business_date, run_type, status, member_count, eligible_count, total_expected_amount, total_posted_amount, error_summary')
                ->where('run_type', 'post')
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();
            $this->check('run_history_persisted', count($report['run_history']) === 5, 'post runs recorded=' . count($report['run_history']) . ' (expected 5 real executions)');

            $report['log_tail'] = $this->logTail();
            $this->check('scheduler_log_written', $report['log_tail'] !== [], 'lines=' . count($report['log_tail']));

            $report['switches_after'] = service('financialSandboxService')->productionSwitchSnapshot();
            $this->check('kill_switches_still_off_after_run', empty($report['switches_after']['miningPostingEnabled']) && empty($report['switches_after']['sandbox_window_open']), json_encode($report['switches_after']));
        } catch (Throwable $e) {
            $report['errors'][] = ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            $this->check('runner_completed_without_exception', false, $e->getMessage());
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
            service('financialSandboxService')->closeWindow();
        }

        try {
            $report['live_baseline_after'] = $this->liveBaseline();
            $this->check(
                'live_database_untouched',
                ($report['live_baseline_before'] ?? null) === $report['live_baseline_after'],
                'coin_platform mining/ownership counts+sums identical before and after'
            );
        } catch (Throwable $e) {
            $this->check('live_database_untouched', false, $e->getMessage());
        }

        $report['checks']   = $this->checks;
        $report['passed']   = count(array_filter($this->checks, static fn (array $c): bool => $c['ok']));
        $report['failed']   = count($this->checks) - $report['passed'];
        $report['success']  = $report['failed'] === 0 && $report['errors'] === [];
        $report['finished_at'] = date('c');

        return $report;
    }

    // ===================================================================== safety scenarios

    /**
     * @param array<string, mixed> $seed
     * @return array<string, mixed>
     */
    private function safetyScenarios(array $seed, string $d1, string $d2): array
    {
        $out = [];
        $baseRows = $this->ledgerTotals($d1)['rows'] + $this->ledgerTotals($d2)['rows'];
        $baseRuns = (int) db_connect()->table('mining_daily_runs')->countAllResults();

        // In-process kill-switch: window closed + config OFF → engine must refuse.
        $killRefused = false;
        $killMsg = '';
        try {
            service('miningDailyEngineService')->postDay($d1, 200, $seed['admin']);
        } catch (RuntimeException $e) {
            $killRefused = true;
            $killMsg     = $e->getMessage();
        }
        $this->check('kill_switch_blocks_postDay_without_authorization', $killRefused, $killMsg);
        $out['kill_switch_postDay'] = ['refused' => $killRefused, 'message' => $killMsg];

        $directRefused = false;
        try {
            service('miningWalletService')->creditDailyMining($seed['members']['A']['user_id'], $seed['members']['A']['member_id'], [
                'amount' => '1', 'business_date' => $d1, 'idempotency_key' => 'direct-bypass-attempt',
            ]);
        } catch (RuntimeException) {
            $directRefused = true;
        }
        $this->check('kill_switch_blocks_direct_wallet_credit', $directRefused, 'creditDailyMining refused without window');

        $s = [];
        $s['dry_run_default']       = $this->spark(['--date=' . $d1]);
        $s['wrong_confirm']         = $this->spark(['--date=' . $d1, '--confirm=WRONG']);
        $s['scheduled_unauthorized']= $this->spark(['--date=' . $d1, '--scheduled']);
        $s['future_date']           = $this->spark(['--date=2099-01-01', '--confirm=APPLY_STAGING']);
        $s['no_approved_rate']      = $this->spark(['--date=2020-01-15', '--confirm=APPLY_STAGING']);
        $s['population_over_limit'] = $this->spark(['--date=' . $d2, '--confirm=APPLY_STAGING', '--limit=3', '--allow-incomplete-day']);
        $s['bad_flag_post']         = $this->spark(['--date=' . $d1, '--post']);
        $s['live_scheduled_default']= $this->spark(['--scheduled'], false);
        $s['live_manual_full_confirm'] = $this->spark(['--confirm=I_AUTHORIZE_PRODUCTION_CHANGE', '--allow-production'], false);
        $s['unknown_group']         = $this->spark(['--group=not_a_group', '--confirm=APPLY_STAGING'], false);
        // "--opt=value" is not parsed by CodeIgniter's CLI; it must be refused, never silently fall back to live.
        $s['equals_syntax']         = $this->spark(['--group=' . self::GROUP, '--confirm=APPLY_STAGING', '--scheduled'], false, true);

        // Lock contention: another owner holds the per-date lock.
        $locks = new OpsJobLockService();
        $locks->acquire(MiningPostingRunnerService::LOCK_PREFIX . $d1, 'other-scheduler-instance', 600);
        $s['lock_contention'] = $this->spark(['--date=' . $d1, '--scheduled', '--confirm=APPLY_STAGING']);
        $locks->release(MiningPostingRunnerService::LOCK_PREFIX . $d1, 'other-scheduler-instance');

        $rowsAfter = $this->ledgerTotals($d1)['rows'] + $this->ledgerTotals($d2)['rows'];
        $runsAfter = (int) db_connect()->table('mining_daily_runs')->countAllResults();

        $this->check('dry_run_default_exit0_status_dry_run', $s['dry_run_default']['exit'] === 0 && ($s['dry_run_default']['json']['status'] ?? '') === 'dry_run', 'status=' . ($s['dry_run_default']['json']['status'] ?? 'n/a'));
        $this->check('dry_run_reports_expected_total', MoneyMath::compare((string) ($s['dry_run_default']['json']['expected_total'] ?? '0'), $this->sumExpected($seed, $d1), 8) === 0, 'expected_total=' . ($s['dry_run_default']['json']['expected_total'] ?? 'n/a'));
        $this->check('wrong_confirm_refused', $s['wrong_confirm']['exit'] !== 0, 'exit=' . $s['wrong_confirm']['exit']);
        $this->check('scheduled_without_authorization_skipped', $s['scheduled_unauthorized']['exit'] === 0 && ($s['scheduled_unauthorized']['json']['status'] ?? '') === 'skipped_disabled', 'status=' . ($s['scheduled_unauthorized']['json']['status'] ?? 'n/a'));
        $this->check('future_date_refused', $s['future_date']['exit'] !== 0, 'exit=' . $s['future_date']['exit']);
        $this->check('missing_rate_refused', $s['no_approved_rate']['exit'] !== 0, 'exit=' . $s['no_approved_rate']['exit']);
        $this->check('population_over_limit_refused', $s['population_over_limit']['exit'] !== 0, 'exit=' . $s['population_over_limit']['exit']);
        $this->check('post_flag_rejected', $s['bad_flag_post']['exit'] !== 0, 'exit=' . $s['bad_flag_post']['exit']);
        $this->check('live_db_scheduled_default_skipped_disabled', $s['live_scheduled_default']['exit'] === 0 && ($s['live_scheduled_default']['json']['status'] ?? '') === 'skipped_disabled' && ($s['live_scheduled_default']['json']['database'] ?? '') === 'coin_platform', 'status=' . ($s['live_scheduled_default']['json']['status'] ?? 'n/a'));
        $this->check('live_db_manual_full_confirm_still_refused_by_kill_switch', $s['live_manual_full_confirm']['exit'] !== 0 && str_contains($s['live_manual_full_confirm']['raw'], 'kill-switch'), 'exit=' . $s['live_manual_full_confirm']['exit']);
        $this->check('unknown_group_refused', $s['unknown_group']['exit'] !== 0, 'exit=' . $s['unknown_group']['exit']);
        $this->check('equals_syntax_refused_not_silently_live', $s['equals_syntax']['exit'] !== 0 && str_contains($s['equals_syntax']['raw'], 'space-separated'), 'exit=' . $s['equals_syntax']['exit']);
        $this->check('lock_contention_skipped_not_failed', $s['lock_contention']['exit'] === 0 && ($s['lock_contention']['json']['status'] ?? '') === 'skipped_locked', 'status=' . ($s['lock_contention']['json']['status'] ?? 'n/a'));
        $this->check('no_wallet_rows_created_by_safety_scenarios', $rowsAfter === $baseRows, "ledger rows {$baseRows}→{$rowsAfter}");
        $this->check('no_post_run_rows_created_by_refused_scenarios', $runsAfter === $baseRuns, "mining_daily_runs {$baseRuns}→{$runsAfter}");

        foreach ($s as $name => $r) {
            $out[$name] = ['exit' => $r['exit'], 'status' => $r['json']['status'] ?? null, 'message' => $r['json']['message'] ?? trim(substr(strip_tags($r['raw']), -220))];
        }

        return $out;
    }

    // ===================================================================== seeding

    /**
     * @return array{admin: int, approver: int, members: array<string, array<string, mixed>>}
     */
    private function seed(): array
    {
        $sandbox = service('financialSandboxService');
        $sandbox->openWindow(300, self::GROUP);
        $sandbox->applySandboxPlatformOverrides();

        try {
            $migrate = service('migrations');
            $migrate->setNamespace(null);
            $migrate->latest();
            Database::seeder()->call(\App\Database\Seeds\RootSponsorSeeder::class);

            $profiles = new MemberProfileModel();
            $admin    = (int) ($profiles->findByMemberId('ROOT0001')->user_id ?? 0);
            if ($admin <= 0) {
                throw new RuntimeException('ROOT0001 missing after seed.');
            }
            $approver = $this->createApprover();

            service('coinPriceService')->updatePrice('1.00', $admin, 'p13a test price');
            service('coinInventoryService')->addInventory('100000', $admin, 'p13a test stock', null, 'p13a-stock-' . bin2hex(random_bytes(3)));
            $db = db_connect();
            if ($db->tableExists('member_id_sequences') && $db->table('member_id_sequences')->where('prefix', 'SYN')->get()->getRowArray() === null) {
                $db->table('member_id_sequences')->insert(['prefix' => 'SYN', 'last_number' => 0, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
            }

            $platform = config('Platform');
            $members  = [];
            foreach (self::COHORT as $key => $spec) {
                $m = $this->register($key);
                foreach ($spec['buys'] as [$qty, $lock]) {
                    $platform->mandatoryPurchaseLockEnabled = $lock;
                    $this->buyApprove($m, $qty, $admin);
                }
                $platform->mandatoryPurchaseLockEnabled = true;

                // Back-date approvals for everyone except G so "before mining start" is exercised.
                if ($key !== 'G' && $spec['buys'] !== []) {
                    $past = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
                    $db->table('coin_purchase_requests')
                        ->where('member_user_id', $m['user_id'])
                        ->update(['reviewed_at' => $past, 'updated_at' => $past]);
                    // Phase 13B: mining is now computed AS-OF the business date from the ownership ledger, so the ledger
                    // and lock rows must carry the same back-dated timestamp as the approval.
                    $db->table('member_coin_ownership_transactions')->where('member_user_id', $m['user_id'])->update(['created_at' => $past, 'updated_at' => $past]);
                    $db->table('member_coin_locks')->where('member_user_id', $m['user_id'])->update(['locked_at' => $past, 'created_at' => $past, 'updated_at' => $past]);
                }
                if ($spec['deactivate']) {
                    $db->table('member_profiles')->where('user_id', $m['user_id'])->update(['account_status' => 'inactive']);
                }

                $summary = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
                $members[$key] = $m + [
                    'label'     => $spec['label'],
                    'owned'     => (string) $summary['balance'],
                    'available' => (string) $summary['available'],
                    'locked'    => (string) $summary['locked'],
                ];
            }

            // Test-only approved monthly rate (dual control: creator != approver) for both business months.
            $tz     = new \DateTimeZone('Asia/Kolkata');
            $months = array_unique([
                (new \DateTimeImmutable('yesterday', $tz))->format('Y-m'),
                (new \DateTimeImmutable('today', $tz))->format('Y-m'),
            ]);
            $rates = [];
            foreach ($months as $month) {
                $draft   = service('miningRateService')->createDraft([
                    'effective_month' => $month,
                    'rate_percent'    => self::RATE_PERCENT,
                    'rate_basis'      => 'monthly',
                    'notes'           => 'PHASE13A TEST-ONLY RATE (isolated DB; not a business-approved rate)',
                ], $admin);
                $rates[] = service('miningRateService')->approve($draft['rate_ref'], $approver)['rate_ref'];
            }

            $this->check('seed_members_created', count($members) === count(self::COHORT), count($members) . ' synthetic members');
            $this->check('test_only_rate_approved_dual_control', count($rates) === count($months), 'rates=' . implode(',', $rates) . ' @' . self::RATE_PERCENT . '% monthly');
            $this->check('locked_coins_present_in_cohort', MoneyMath::compare($members['A']['locked'], '0', 8) === 1 && MoneyMath::compare($members['D']['locked'], '0', 8) === 1 && MoneyMath::compare($members['D']['available'], '0', 8) === 1, 'A locked=' . $members['A']['locked'] . ' D available/locked=' . $members['D']['available'] . '/' . $members['D']['locked']);

            return ['admin' => $admin, 'approver' => $approver, 'members' => $members, 'rates' => $rates];
        } finally {
            $sandbox->closeWindow();
        }
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p13a_approver', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'p13a.approver@sandbox.local', 'password' => 'ApproverPass123!']);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p13a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p13a-' . $suffix . microtime(true)), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => FinancialSandboxService::SYNTHETIC_NAME_PREFIX . ' 13A-' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'p13a.' . strtolower($suffix) . '.' . bin2hex(random_bytes(2)) . '@sandbox.local',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ], null, 'admin');

        $memberId = (string) $result['member_id'];
        service('financialSandboxService')->assertSyntheticMember($memberId, 'p13a_register');

        return ['user_id' => (int) $result['user']->id, 'member_id' => $memberId];
    }

    /**
     * @param array{user_id: int, member_id: string} $member
     */
    private function buyApprove(array $member, string $qty, int $adminUserId): void
    {
        $req = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'p13a-' . $member['user_id'] . '-' . bin2hex(random_bytes(2)));
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $adminUserId, true, 'p13a');
    }

    // ===================================================================== expected / compare

    /**
     * @param array<string, array<string, mixed>> $members
     * @return array<string, array<string, mixed>>
     */
    private function expectedPerMember(array $members, string $d1, string $d2): array
    {
        $out = [];
        foreach ($members as $key => $m) {
            $spec = self::COHORT[$key];
            $out[$key] = [
                'member_id'   => $m['member_id'],
                'kind'        => $spec['label'],
                'owned'       => $m['owned'],
                'available'   => $m['available'],
                'locked'      => $m['locked'],
                'd1_eligible' => $spec['d1'],
                'd2_eligible' => $spec['d2'],
                'd1_expected' => $spec['d1'] ? $this->exactAmount($m['owned'], $d1) : '0.00000000',
                'd2_expected' => $spec['d2'] ? $this->exactAmount($m['owned'], $d2) : '0.00000000',
            ];
        }

        return $out;
    }

    /** Independent first-principles math: owned × (rate/100) ÷ days_in_month, truncated to 8 dp. */
    private function exactAmount(string $owned, string $date): string
    {
        $days  = (int) (new \DateTimeImmutable(substr($date, 0, 7) . '-01'))->format('t');
        $exact = bcdiv(bcmul($owned, self::RATE_PERCENT, 20), bcmul('100', (string) $days, 20), 8);

        return $exact;
    }

    /**
     * @param array<string, array<string, mixed>> $seed
     */
    private function sumExpected(array $seed, string $date): string
    {
        $sum = '0.00000000';
        foreach ($seed['members'] as $key => $m) {
            if (self::COHORT[$key]['d1']) {
                $sum = MoneyMath::add($sum, $this->exactAmount($m['owned'], $date), 8);
            }
        }

        return $sum;
    }

    /**
     * @param array<string, array<string, mixed>> $members
     * @param array<string, array<string, mixed>> $expected
     * @return array{rows: list<array<string, mixed>>, totals: array<string, string>, failures: list<array{name: string, detail: string}>}
     */
    private function compare(array $members, array $expected, string $d1, string $d2): array
    {
        $rows = [];
        $fail = [];
        $tot  = ['d1_expected' => '0', 'd1_actual' => '0', 'd2_expected' => '0', 'd2_actual' => '0', 'wallet_balances' => '0'];
        $wallets = service('miningWalletService');

        foreach ($members as $key => $m) {
            $e   = $expected[$key];
            $a1  = $wallets->postedAmountForDate($m['user_id'], $d1);
            $a2  = $wallets->postedAmountForDate($m['user_id'], $d2);
            $bal = $wallets->balanceFor($m['user_id']);
            $rows_cnt = (int) db_connect()->table('mining_wallet_ledger')->where('member_user_id', $m['user_id'])->countAllResults();
            $expTotal = MoneyMath::add($e['d1_expected'], $e['d2_expected'], 8);

            $rows[] = [
                'member'          => $m['member_id'],
                'kind'            => $e['kind'],
                'owned'           => $e['owned'],
                'locked'          => $e['locked'],
                'available'       => $e['available'],
                'd1_expected'     => $e['d1_expected'],
                'd1_actual'       => $a1,
                'd2_expected'     => $e['d2_expected'],
                'd2_actual'       => $a2,
                'wallet_balance'  => $bal['available_balance'],
                'total_credited'  => $bal['total_credited'],
                'ledger_rows'     => $rows_cnt,
            ];

            if (MoneyMath::compare($a1, $e['d1_expected'], 8) !== 0) {
                $fail[] = ['name' => 'amount_mismatch_d1_' . $m['member_id'], 'detail' => "expected {$e['d1_expected']} actual {$a1}"];
            }
            if (MoneyMath::compare($a2, $e['d2_expected'], 8) !== 0) {
                $fail[] = ['name' => 'amount_mismatch_d2_' . $m['member_id'], 'detail' => "expected {$e['d2_expected']} actual {$a2}"];
            }
            if (MoneyMath::compare($bal['available_balance'], $expTotal, 8) !== 0) {
                $fail[] = ['name' => 'wallet_balance_mismatch_' . $m['member_id'], 'detail' => "expected {$expTotal} actual {$bal['available_balance']}"];
            }
            $expRows = ((bool) $e['d1_eligible'] ? 1 : 0) + ((bool) $e['d2_eligible'] ? 1 : 0);
            if ($rows_cnt !== $expRows) {
                $fail[] = ['name' => 'ledger_row_count_mismatch_' . $m['member_id'], 'detail' => "expected {$expRows} rows actual {$rows_cnt}"];
            }

            $tot['d1_expected']      = MoneyMath::add($tot['d1_expected'], $e['d1_expected'], 8);
            $tot['d1_actual']        = MoneyMath::add($tot['d1_actual'], $a1, 8);
            $tot['d2_expected']      = MoneyMath::add($tot['d2_expected'], $e['d2_expected'], 8);
            $tot['d2_actual']        = MoneyMath::add($tot['d2_actual'], $a2, 8);
            $tot['wallet_balances']  = MoneyMath::add($tot['wallet_balances'], $bal['available_balance'], 8);
        }

        return ['rows' => $rows, 'totals' => $tot, 'failures' => $fail];
    }

    /**
     * @return array{mismatches: list<array<string, string>>, wallets: int}
     */
    private function ledgerVsWallet(): array
    {
        $mismatch = [];
        $wallets  = db_connect()->table('mining_wallets')->get()->getResultArray();
        foreach ($wallets as $w) {
            $row = db_connect()->table('mining_wallet_ledger')
                ->selectSum('amount', 'credits')
                ->where('member_user_id', $w['member_user_id'])
                ->where('direction', 'credit')
                ->get()->getRowArray();
            $credits = MoneyMath::normalize((string) ($row['credits'] ?? '0'), 8);
            if (MoneyMath::compare($credits, (string) $w['available_balance'], 8) !== 0
                || MoneyMath::compare($credits, (string) $w['total_credited'], 8) !== 0
            ) {
                $mismatch[] = ['member_id' => (string) $w['member_id'], 'ledger' => $credits, 'available' => (string) $w['available_balance'], 'total_credited' => (string) $w['total_credited']];
            }
        }

        return ['mismatches' => $mismatch, 'wallets' => count($wallets)];
    }

    /**
     * Informational: month-length divisors that do not terminate at 8 dp lose a little in the engine.
     *
     * @return array<string, mixed>
     */
    private function precisionNote(): array
    {
        $rate   = ['rate_percent' => self::RATE_PERCENT, 'rate_basis' => 'monthly', 'precision_scale' => 8, 'effective_month' => '2026-12'];
        $engine = (new MiningRateService())->previewAmount('1000.00000000', $rate, '2026-12-15');
        $exact  = $this->exactAmount('1000.00000000', '2026-12-15');

        return [
            'case'                  => '1000 coins @ 3% monthly, 31-day month',
            'engine_amount'         => $engine,
            'exact_amount'          => $exact,
            'engine_shortfall'      => MoneyMath::sub($exact, $engine, 8),
            'cause'                 => 'Phase 13A defect (MoneyMath::mul() cut the 12-dp daily factor to 8 dp) — FIXED in Phase 13B: amounts now use MiningMath (40-dp intermediate, single final rounding); shortfall must be 0.',
            'affects_this_test'     => 'No — test dates fall in a 30-day month where the factor terminates exactly.',
        ];
    }

    // ===================================================================== snapshots / infra

    /**
     * @return array<string, string>
     */
    private function ownershipSnapshot(): array
    {
        $out = [];
        foreach (db_connect()->table('member_coin_balances')->orderBy('member_id')->get()->getResultArray() as $r) {
            $out[(string) $r['member_id']] = $r['balance'] . '|' . $r['available_balance'] . '|' . $r['locked_balance'];
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function walletSnapshot(): array
    {
        $out = [];
        foreach (db_connect()->table('mining_wallets')->orderBy('member_id')->get()->getResultArray() as $r) {
            $out[(string) $r['member_id']] = $r['available_balance'] . '|' . $r['total_credited'];
        }

        return $out;
    }

    /**
     * @return array{rows: int, sum: string}
     */
    private function ledgerTotals(string $date): array
    {
        $row = db_connect()->table('mining_wallet_ledger')
            ->select('COUNT(*) AS c, COALESCE(SUM(amount),0) AS s', false)
            ->where('business_date', $date)
            ->get()->getRowArray();

        return ['rows' => (int) ($row['c'] ?? 0), 'sum' => MoneyMath::normalize((string) ($row['s'] ?? '0'), 8)];
    }

    /**
     * @return list<string>
     */
    private function logTail(): array
    {
        $file = WRITEPATH . 'logs/mining-post.log';
        if (! is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_slice($lines, -12);
    }

    /**
     * Read-only fingerprint of the LIVE database (SELECT only).
     *
     * @return array<string, mixed>
     */
    private function liveBaseline(): array
    {
        $db  = Database::connect('default', false);
        $out = ['database' => (string) $db->getDatabase()];
        foreach (['mining_wallets', 'mining_wallet_ledger', 'mining_daily_runs', 'mining_daily_calculations', 'mining_rate_configs', 'member_coin_balances', 'ops_job_locks'] as $table) {
            $out[$table] = $db->tableExists($table) ? (int) $db->table($table)->countAllResults() : null;
        }
        if ($db->tableExists('mining_wallets')) {
            $out['mining_wallets_sum'] = (string) ($db->query('SELECT COALESCE(SUM(available_balance),0) AS s FROM mining_wallets')->getRow()->s ?? '0');
        }
        if ($db->tableExists('member_coin_balances')) {
            $out['member_coin_balances_sum'] = (string) ($db->query('SELECT COALESCE(SUM(balance),0) AS s FROM member_coin_balances')->getRow()->s ?? '0');
        }

        return $out;
    }

    private function assertTargetIsIsolated(): void
    {
        $info = service('financialSandboxService')->inspectConnection(self::GROUP);
        if ($info['database'] !== self::DATABASE || ! $info['is_allowed'] || $info['is_forbidden']) {
            throw new RuntimeException('Verification refused: group ' . self::GROUP . ' must be ' . self::DATABASE . ' and allowlisted.');
        }
        $live = service('financialSandboxService')->inspectConnection('default');
        if ($live['database'] === self::DATABASE) {
            throw new RuntimeException('Verification refused: default group points at the isolated DB.');
        }
    }

    private function recreateDatabase(): void
    {
        $cfg = config('Database')->{self::GROUP};
        if (($cfg['database'] ?? '') !== self::DATABASE) {
            throw new RuntimeException('Refusing to recreate any database other than ' . self::DATABASE);
        }
        $c = @mysqli_connect((string) $cfg['hostname'], (string) $cfg['username'], (string) $cfg['password'], '', (int) $cfg['port']);
        if ($c === false) {
            throw new RuntimeException('Cannot connect to MySQL to recreate the isolated DB.');
        }
        $name = $c->real_escape_string(self::DATABASE);
        $c->query('DROP DATABASE IF EXISTS `' . $name . '`');
        $c->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->close();
    }

    private function switchToIsolated(): void
    {
        config('Database')->defaultGroup = self::GROUP;
        Database::connect(self::GROUP, false);
        db_connect(self::GROUP, false);
        if ((string) db_connect()->getDatabase() !== self::DATABASE) {
            throw new RuntimeException('Failed to bind default connection to ' . self::DATABASE);
        }
    }

    /**
     * Execute the real Spark command in a child process.
     *
     * @param list<string> $args
     * @return array{exit: int, json: ?array<string, mixed>, raw: string}
     */
    private function spark(array $args, bool $isolated = true, bool $raw = false): array
    {
        $cmd = [PHP_BINARY, ROOTPATH . 'spark', 'mining:post'];
        if ($isolated && ! $raw && ! $this->hasGroup($args)) {
            $cmd[] = '--group';
            $cmd[] = self::GROUP;
        }
        foreach ($args as $a) {
            // Callers write "--key=value"; the CLI needs "--key value" (see MiningPost).
            if (! $raw && str_starts_with($a, '--') && str_contains($a, '=')) {
                [$k, $v] = explode('=', $a, 2);
                $cmd[] = $k;
                $cmd[] = $v;
            } else {
                $cmd[] = $a;
            }
        }

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
        if (! is_resource($proc)) {
            throw new RuntimeException('Cannot start spark child process.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $raw  = $stdout . $stderr;
        $json = null;
        $a    = strpos($stdout, '{');
        $b    = strrpos($stdout, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $decoded = json_decode(substr($stdout, $a, $b - $a + 1), true);
            $json    = is_array($decoded) ? $decoded : null;
        }

        return ['exit' => $exit, 'json' => $json, 'raw' => $raw];
    }

    /**
     * @param list<string> $args
     */
    private function hasGroup(array $args): bool
    {
        foreach ($args as $a) {
            if (str_starts_with($a, '--group=')) {
                return true;
            }
        }

        return false;
    }

    private function check(string $name, bool $ok, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
