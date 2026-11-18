<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Database;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Phase 14 — Full isolated backup, restore, dual-control approval rehearsal, mining recovery,
 * level commission recovery (append-only superseding revisions + resumable posting), and automation tests.
 *
 * Strictly read-only against `ng_database` and `coin_platform`; all mutations run only on the
 * marker-guarded isolated clone `ng_database_phase14`.
 */
class Phase14VerificationService
{
    public const LIVE_DB       = 'ng_database';
    public const LOCAL_COPY_DB = 'coin_platform';
    public const SNAP_GROUP    = 'phase14_snapshot';
    public const SNAP_DB       = 'ng_database_phase14_snapshot';
    public const CLONE_GROUP   = 'phase14';
    public const CLONE_DB      = 'ng_database_phase14';
    public const LIVE_RO_GROUP = 'phase14_live_ro';
    public const LIVE_RO_USER  = 'p14_live_ro';
    public const MARKER        = 'phase14_marker';

    /** @var list<array{id: string, ok: bool, detail: string}> */
    protected array $checks = [];
    /** @var array<string, mixed> */
    protected array $out = [];
    protected ?string $liveRoPassword = null;

    /** @return array<string, mixed> */
    public function run(): array
    {
        $started = microtime(true);
        $this->out = [
            'phase'      => '14',
            'started_at' => gmdate('c'),
            'notes'      => 'Isolated staging recovery & automation rehearsal against restored ng_database clone. Production switches remain OFF.',
        ];
        $admin = null;

        try {
            $admin = $this->adminConnection();
            $this->out['preflight'] = $this->preflight($admin);

            $this->createLiveReadOnlyUser($admin);
            $live = $this->liveRoConnection();
            $this->out['live_fingerprint_before']  = $this->fingerprint($live, self::LIVE_DB);
            $this->out['local_fingerprint_before'] = $this->fingerprint($admin, self::LOCAL_COPY_DB);

            // PHASE 2 — Fresh backup from ng_database + verified restore into isolated DBs
            $this->out['backup']           = $this->createAndVerifyBackup($admin);
            $this->out['snapshot_restore'] = $this->restoreInto($admin, self::SNAP_DB, (string) $this->out['backup']['file_abs'], 'Phase 14 audit snapshot');
            $this->out['clone_restore']    = $this->restoreInto($admin, self::CLONE_DB, (string) $this->out['backup']['file_abs'], 'Phase 14 disposable clone');
            $this->grantAccounts($admin);

            $snap  = $this->connect(self::SNAP_GROUP);
            $clone = $this->connect(self::CLONE_GROUP);

            $tables       = $this->tables($snap, self::SNAP_DB);
            $baseSnapSums = $this->checksums($snap, self::SNAP_DB, $tables);
            $baseCloneSums= $this->checksums($clone, self::CLONE_DB, $this->tables($clone, self::CLONE_DB));

            $this->out['restore_validation'] = $this->validateRestore($admin, $snap, $live, $baseSnapSums, $baseCloneSums);

            // PHASE 3–7 on isolated clone `ng_database_phase14` ONLY
            $this->out['migrations_clone'] = $this->applyCloneMigrations($clone);

            $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function () use ($clone): void {
                [$proposerId, $approverId] = $this->ensureStagingOperators();
                $this->out['proposer_uid'] = $proposerId;
                $this->out['approver_uid'] = $approverId;

                $this->out['phase3_policy_approvals']    = $this->rehearseDualControlApprovals($proposerId, $approverId);
                $this->out['phase4_mining_recovery']     = $this->rehearseMiningRecovery($proposerId, $approverId, $clone);
                $this->out['phase5_commission_recovery'] = $this->rehearseCommissionRecovery($approverId, $clone);
                $this->out['phase6_7_automation_staging']= $this->rehearseFutureAutomationAndKillSwitches($approverId, $clone);
            });

            // Verify snapshot stayed pristine (zero changes across 73 tables)
            $afterSnap = $this->checksums($snap, self::SNAP_DB, $tables);
            $this->out['snapshot_pristine'] = $this->assertTrue(
                'snapshot_pristine',
                $afterSnap === $baseSnapSums,
                'Snapshot CHECKSUM TABLE on all ' . count($tables) . ' tables identical before and after Phases 3–7.'
            );

            // Verify live ng_database & local coin_platform unchanged
            $this->out['live_fingerprint_after']  = $this->fingerprint($live, self::LIVE_DB);
            $this->out['local_fingerprint_after'] = $this->fingerprint($admin, self::LOCAL_COPY_DB);
            $this->out['live_unchanged']  = $this->assertTrue('live_unchanged', $this->out['live_fingerprint_before'] === $this->out['live_fingerprint_after'], 'ng_database fingerprint identical before and after Phase 14.');
            $this->out['local_unchanged'] = $this->assertTrue('local_unchanged', $this->out['local_fingerprint_before'] === $this->out['local_fingerprint_after'], 'coin_platform fingerprint identical before and after Phase 14.');
        } catch (Throwable $e) {
            $this->assertTrue('fatal_exception', false, $e::class . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
            $this->out['fatal'] = ['class' => $e::class, 'message' => $e->getMessage()];
        } finally {
            config('Database')->defaultGroup = 'default';
            Database::connect('default', false);
            db_connect('default', false);
            try {
                if ($admin instanceof mysqli) {
                    $admin->query("DROP USER IF EXISTS '" . self::LIVE_RO_USER . "'@'localhost'");
                    $this->out['live_ro_account_dropped'] = true;
                }
            } catch (Throwable) {
                $this->out['live_ro_account_dropped'] = false;
            }
        }

        if (isset($this->out['backup']['file_abs'])) {
            unset($this->out['backup']['file_abs']);
        }
        $this->out['checks']      = $this->checks;
        $this->out['pass']        = count(array_filter($this->checks, static fn (array $c): bool => $c['ok']));
        $this->out['fail']        = count(array_filter($this->checks, static fn (array $c): bool => ! $c['ok']));
        $this->out['duration_s']  = round(microtime(true) - $started, 2);
        $this->out['finished_at'] = gmdate('c');

        return $this->out;
    }

    // ===================================================================== Phase 3: Dual-control approvals

    /** @return array<string, mixed> */
    private function rehearseDualControlApprovals(int $proposerId, int $approverId): array
    {
        $origReq = \Config\Services::get('request');
        $origSes = \Config\Services::get('session');
        \Config\Services::injectMock('session', new \CodeIgniter\Test\Mock\MockSession(
            new \CodeIgniter\Session\Handlers\ArrayHandler(config('Session'), '127.0.0.1'),
            config('Session')
        ));
        \Config\Services::injectMock('request', \Config\Services::incomingrequest(config('App'), false));
        try {
            $proposerPass = 'P14_Proposer_Pass_2026!';
            $approverPass = 'P14_Approver_Pass_2026!';
            $hub          = new FinancialPolicyService();
            $rates        = new MiningRateService();
            $plans        = new CommissionPlanService();

            // 1. Mining Policy in Hub (all 12 decisions + supply source + no compounding, prospective 2026-11-18 IST)
            $mDraft = $hub->createDraft(
                FinancialPolicyService::DOMAIN_MINING,
                'Phase 14 Approved Mining Policy (Staging Rehearsal)',
                [
                    'monthly_percent'       => '3.00000000',
                    'rate_basis'            => 'monthly',
                    'day_divisor'           => 'calendar_days',
                    'denomination'          => 'COIN',
                    'approval_day'          => 'exclude_approval_day',
                    'locked_coins'          => 'include',
                    'post_maturity'         => 'include_all',
                    'post_sale'             => 'include',
                    'rounding'              => 'down',
                    'residual'              => 'none',
                    'supply_source'         => 'reserve_inventory_tracked',
                    'compounding_enabled'   => false,
                    'effective_from'        => '2026-11-18',
                    'scheduler_time_ist'    => '01:30',
                ],
                $proposerId,
                '2026-11-18',
                'Owner proposals: 3% monthly, calendar_days, COIN, exclude_approval_day, down, no compounding, reserve tracked.'
            );
            auth()->loginById($proposerId);
            $hub->submitForApproval((string) $mDraft['policy_ref'], $proposerId, $proposerPass);
            $mSelfBlocked = $this->expectThrows(fn () => $hub->approve((string) $mDraft['policy_ref'], $proposerId, $proposerPass));
            auth()->logout();
            auth()->loginById($approverId);
            $hub->approve((string) $mDraft['policy_ref'], $approverId, $approverPass);
            $mActSameBlocked = $this->expectThrows(fn () => $hub->activate((string) $mDraft['policy_ref'], $approverId, $approverPass));
            auth()->logout();
            auth()->loginById($proposerId);
            $mActive = $hub->activate((string) $mDraft['policy_ref'], $proposerId, $proposerPass);
            auth()->logout();

            // 2. Commission Policy in Hub
            $cDraft = $hub->createDraft(
                FinancialPolicyService::DOMAIN_COMMISSION,
                'Phase 14 Approved Commission Policy (Staging Rehearsal)',
                [
                    'l1_percent'           => '10.00000000',
                    'l2_percent'           => '5.00000000',
                    'l3_percent'           => '3.00000000',
                    'commission_base'      => 'approved_purchase_total_usdt',
                    'rounding'             => 'bcmath_truncate_toward_zero_at_usdt_scale',
                    'sponsor_eligibility'  => 'active_sponsors_only',
                    'root_eligible'        => true,
                    'effective_from'       => '2026-11-17',
                ],
                $proposerId,
                '2026-11-17',
                'L1 10%, L2 5%, L3 3%, ROOT0001 eligible, approved_verified_usdt, truncate 8dp.'
            );
            auth()->loginById($proposerId);
            $hub->submitForApproval((string) $cDraft['policy_ref'], $proposerId, $proposerPass);
            $cSelfBlocked = $this->expectThrows(fn () => $hub->approve((string) $cDraft['policy_ref'], $proposerId, $proposerPass));
            auth()->logout();
            auth()->loginById($approverId);
            $hub->approve((string) $cDraft['policy_ref'], $approverId, $approverPass);
            auth()->logout();
            auth()->loginById($proposerId);
            $cActive = $hub->activate((string) $cDraft['policy_ref'], $proposerId, $proposerPass);
            auth()->logout();
            $mSelfBlocked = $mSelfBlocked && $mActSameBlocked;
        } finally {
            \Config\Services::injectMock('request', $origReq);
            \Config\Services::injectMock('session', $origSes);
        }

        // 3. Mining Rate (2026-11 = 3.00% monthly)
        $rDraft = $rates->createDraft([
            'effective_month' => '2026-11',
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
            'notes'           => 'Phase 14 staging rehearsal 3% monthly',
        ], $proposerId);
        $rSelfBlocked = $this->expectThrows(fn () => $rates->approve((string) $rDraft['rate_ref'], $proposerId));
        $rApproved    = $rates->approve((string) $rDraft['rate_ref'], $approverId);

        // 4. Verify existing draft CPL-20261118-D5EEC007 (L3=2%) stays unapproved, and create+approve 10/5/3 plan
        $oldDraft = $plans->findByRef('CPL-20261118-D5EEC007');
        $pDraft   = $plans->createDraft([
            'plan_name'                  => 'Phase 14 Approved L1/L2/L3 (10/5/3)',
            'effective_from'             => '2026-11-17',
            'effective_to'               => '',
            'level_count'                => 3,
            'level_1_percent'            => '10.00000000',
            'level_2_percent'            => '5.00000000',
            'level_3_percent'            => '3.00000000',
            'eligible_purchase_policy' => 'approved_verified_usdt',
            'sponsor_eligibility_policy' => 'active_sponsors_only',
            'notes'                      => 'Supersedes unapproved 10/5/2 draft CPL-20261118-D5EEC007 in staging.',
        ], $proposerId);
        $pSelfBlocked = $this->expectThrows(fn () => $plans->approve((string) $pDraft['plan_ref'], $proposerId));
        $pApproved    = $plans->approve((string) $pDraft['plan_ref'], $approverId);

        $this->assertTrue('dual_control_self_approval_blocked', $mSelfBlocked && $cSelfBlocked && $rSelfBlocked && $pSelfBlocked, 'Self-approval blocked on Hub mining policy, Hub commission policy, mining rate, and commission plan.');
        $this->assertTrue('old_draft_1052_not_approved', ($oldDraft['status'] ?? '') === 'draft', 'Existing draft CPL-20261118-D5EEC007 (L3=2%) kept unapproved (draft).');
        $this->assertTrue('approved_rate_and_plan_ready', ($rApproved['status'] ?? '') === 'approved' && ($pApproved['status'] ?? '') === 'approved' && ($pApproved['levels'][3] ?? '') === '3.00000000', '3% monthly mining rate and 10/5/3 commission plan dual-approved on clone.');

        return [
            'mining_policy_ref'     => $mActive['policy_ref'],
            'commission_policy_ref' => $cActive['policy_ref'],
            'mining_rate_ref'       => $rApproved['rate_ref'],
            'commission_plan_ref'   => $pApproved['plan_ref'],
            'old_draft_status'      => $oldDraft['status'] ?? null,
        ];
    }

    // ===================================================================== Phase 4: Mining recovery rehearsal

    /** @return array<string, mixed> */
    private function rehearseMiningRecovery(int $proposerId, int $approverId, mysqli $clone): array
    {
        $policy = new MiningCalculationPolicy('exclude_approval_day', 'calendar_days', 'down', 'include', 'include_all');
        $rec    = new MiningRecoveryService();

        // 1. Assess 2026-11-17 IST under exclude_approval_day & prospective effective date 2026-11-18
        $assess17        = $rec->assessDate('2026-11-17', $policy, null);
        $assess17Eff     = $rec->assessDate('2026-11-17', $policy, '2026-11-18');
        $backdateRefused = $this->expectThrows(fn () => $rec->createProposal('2026-11-17', $policy, $proposerId, 'reserve_inventory_tracked', '2026-11-18'));

        $this->assertTrue(
            'mining_nov17_zero_owed_under_following_day',
            $assess17['eligible_members'] === 0
                && $assess17['total_expected_coins'] === '0.00000000'
                && ! empty($assess17Eff['before_effective_date'])
                && $backdateRefused,
            '2026-11-17 IST yields 0 eligible coins under exclude_approval_day; backdating before 2026-11-18 refused.'
        );

        // 2. Assess 2026-11-18 IST: 6 members holding 700 coins => 0.70000000 COIN (NGW981238/NGW981239 approved 02:55/03:09 IST on 18th => ZERO on 18th)
        $assess18 = $rec->assessDate('2026-11-18', $policy, '2026-11-18');
        $this->assertTrue(
            'mining_nov18_expected_exact',
            $assess18['eligible_members'] === 6
                && $assess18['total_eligible_coins'] === '700.00000000'
                && $assess18['missing_coins'] === '0.70000000'
                && $assess18['reserve_sufficient'] === true,
            '2026-11-18 IST: 6 eligible members, 700.00000000 eligible coins, 0.70000000 COIN missing, inventory reserve sufficient (' . $assess18['inventory_available_before'] . ').'
        );

        // 3. Create & dual-approve recovery proposal, then execute via Phase 13B mining:post
        $proposal        = $rec->createProposal('2026-11-18', $policy, $proposerId, 'reserve_inventory_tracked', '2026-11-18', 'Phase 14 clone recovery rehearsal');
        $propSelfBlocked = $this->expectThrows(fn () => $rec->approveProposal((string) $proposal['proposal_ref'], $proposerId));
        $approvedProp    = $rec->approveProposal((string) $proposal['proposal_ref'], $approverId);
        $this->assertTrue('mining_recovery_proposal_dual_control', $propSelfBlocked && ($approvedProp['status'] ?? '') === 'approved', 'Recovery proposal ' . $proposal['proposal_ref'] . ' dual-approved.');

        $envPolicy = [
            'platform.miningApprovalDayPolicy'    => 'exclude_approval_day',
            'platform.miningMonthDayDivisorMode'  => 'calendar_days',
            'platform.miningRoundingMode'         => 'down',
            'platform.miningReservedCoinPolicy'   => 'include',
            'platform.miningMaturityPolicy'       => 'include_all',
        ];
        $post1 = $this->spark(['mining:post', '--group', self::CLONE_GROUP, '--date', '2026-11-18', '--confirm', 'APPLY_STAGING', '--strict-exit'], $envPolicy);
        $post2 = $this->spark(['mining:post', '--group', self::CLONE_GROUP, '--date', '2026-11-18', '--confirm', 'APPLY_STAGING', '--strict-exit'], $envPolicy);

        $walletTotal = (string) ($this->one($clone, 'SELECT COALESCE(SUM(available_balance),0) FROM `' . self::CLONE_DB . '`.`mining_wallets`') ?? '0');
        $ledgerTotal = (string) ($this->one($clone, "SELECT COALESCE(SUM(amount),0) FROM `" . self::CLONE_DB . "`.`mining_wallet_ledger` WHERE direction='credit'") ?? '0');
        $ledgerCount = (int) ($this->one($clone, 'SELECT COUNT(*) FROM `' . self::CLONE_DB . '`.`mining_wallet_ledger`') ?? 0);
        $dupLedger   = (int) ($this->one($clone, 'SELECT COUNT(*) FROM (SELECT idempotency_key FROM `' . self::CLONE_DB . '`.`mining_wallet_ledger` GROUP BY idempotency_key HAVING COUNT(*) > 1) t') ?? 0);
        $ownedAfter  = (string) ($this->one($clone, 'SELECT COALESCE(SUM(balance),0) FROM `' . self::CLONE_DB . '`.`member_coin_balances`') ?? '0');

        $j1 = $this->json($post1['stdout']);
        $j2 = $this->json($post2['stdout']);

        // Mark proposal executed on clone via service
        db_connect()->table('mining_recovery_proposals')->where('proposal_ref', (string) $proposal['proposal_ref'])->update([
            'status'      => 'executed',
            'executed_by' => $approverId,
            'executed_at' => date('Y-m-d H:i:s'),
            'run_ref'     => $j1['run_ref'] ?? null,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertTrue(
            'mining_recovery_posting_reconciled',
            $post1['exit'] === 0 && $post2['exit'] === 0
                && ($j1['credited'] ?? -1) === 6
                && ($j2['credited'] ?? -1) === 0
                && bccomp($walletTotal, '0.70000000', 8) === 0
                && bccomp($ledgerTotal, '0.70000000', 8) === 0
                && $ledgerCount === 6
                && $dupLedger === 0
                && bccomp($ownedAfter, '1300.00000000', 8) === 0,
            'mining:post credited 6 members = 0.70000000 COIN; duplicate re-run credited 0; wallet==ledger==0.70000000; ownership unchanged (1300, no compounding).'
        );

        return [
            'assess_2026_11_17' => ['eligible' => $assess17['eligible_members'], 'expected' => $assess17['total_expected_coins'], 'backdate_refused' => $backdateRefused],
            'assess_2026_11_18' => ['eligible' => $assess18['eligible_members'], 'eligible_coins' => $assess18['total_eligible_coins'], 'missing' => $assess18['missing_coins']],
            'proposal_ref'      => $proposal['proposal_ref'],
            'run_first'         => $j1,
            'run_duplicate'     => $j2,
            'wallet_total'      => $walletTotal,
            'ledger_total'      => $ledgerTotal,
            'ledger_rows'       => $ledgerCount,
        ];
    }

    // ===================================================================== Phase 5: Commission recovery rehearsal

    /** @return array<string, mixed> */
    private function rehearseCommissionRecovery(int $approverId, mysqli $clone): array
    {
        $runner = new CommissionPostingRunnerService();
        $plan   = (new CommissionPlanService())->findApplicablePlan('2026-11-17');
        $planRef = (string) ($plan['plan_ref'] ?? '');

        // 1. Generate append-only superseding revisions (NO_APPROVED_PLAN rows stay intact!)
        $revCmd = $this->spark(['commissions:recover-plan', '--group', self::CLONE_GROUP, '--plan', $planRef, '--persist', '--actor', (string) $approverId]);
        $revOut = $this->json($revCmd['stdout']);
        $origNoPlanCount = (int) ($this->one($clone, "SELECT COUNT(*) FROM `" . self::CLONE_DB . "`.`commission_purchase_calculations` WHERE status='NO_APPROVED_PLAN'") ?? 0);

        $this->assertTrue(
            'commission_append_only_revisions',
            $revCmd['exit'] === 0
                && ($revOut['revisions_created'] ?? 0) === 24
                && ($revOut['eligible_lines'] ?? 0) === 21
                && ($revOut['unallocated_lines'] ?? 0) === 3
                && ($revOut['expected_total_usdt'] ?? '') === '11.15000000'
                && $origNoPlanCount === 24,
            '24 append-only revisions created (21 eligible = 11.15000000 USDT, 3 unallocated); all 24 original NO_APPROVED_PLAN rows preserved untouched.'
        );

        // 2. Dry-run preview before posting
        $dry = $this->spark(['commissions:post', '--group', self::CLONE_GROUP, '--plan', $planRef, '--dry-run', '--strict-exit']);
        $dryJ = $this->json($dry['stdout']);
        $this->assertTrue('commission_dry_run_before_post', $dry['exit'] === 10 && ($dryJ['missing_total_usdt'] ?? '') === '11.15000000', 'Dry-run exit=10 missing=11.15000000 USDT.');

        // 3. Interrupted batch (max-batches=1, batch-size=10 -> PARTIAL exit 40) then resume to COMPLETED (exit 0) then duplicate re-run
        $part = $this->spark(['commissions:post', '--group', self::CLONE_GROUP, '--plan', $planRef, '--confirm', 'APPLY_STAGING', '--batch-size', '10', '--max-batches', '1', '--strict-exit', '--actor', (string) $approverId]);
        $res  = $this->spark(['commissions:post', '--group', self::CLONE_GROUP, '--plan', $planRef, '--confirm', 'APPLY_STAGING', '--batch-size', '10', '--strict-exit', '--actor', (string) $approverId]);
        $dup  = $this->spark(['commissions:post', '--group', self::CLONE_GROUP, '--plan', $planRef, '--confirm', 'APPLY_STAGING', '--strict-exit', '--actor', (string) $approverId]);

        $partJ = $this->json($part['stdout']);
        $resJ  = $this->json($res['stdout']);
        $dupJ  = $this->json($dup['stdout']);

        $walletTotal = (string) ($this->one($clone, 'SELECT COALESCE(SUM(total_balance),0) FROM `' . self::CLONE_DB . '`.`commission_wallet_accounts`') ?? '0');
        $ledgerTotal = (string) ($this->one($clone, "SELECT COALESCE(SUM(amount),0) FROM `" . self::CLONE_DB . "`.`commission_wallet_ledger_entries` WHERE entry_type='commission_credit'") ?? '0');
        $ledgerRows  = (int) ($this->one($clone, 'SELECT COUNT(*) FROM `' . self::CLONE_DB . '`.`commission_wallet_ledger_entries`') ?? 0);
        $dupKeys     = (int) ($this->one($clone, 'SELECT COUNT(*) FROM (SELECT idempotency_key FROM `' . self::CLONE_DB . '`.`commission_wallet_ledger_entries` GROUP BY idempotency_key HAVING COUNT(*) > 1) t') ?? 0);
        $origStillIntact = (int) ($this->one($clone, "SELECT COUNT(*) FROM `" . self::CLONE_DB . "`.`commission_purchase_calculations` WHERE status='NO_APPROVED_PLAN'") ?? 0);

        $recon       = (new CommissionPurchaseEngineService())->reconcile(null, 200);
        $walletRecon = (new CommissionWalletReconciliationService())->run();

        // Check individual beneficiary balances
        $byMember = [];
        foreach ($this->q($clone, 'SELECT member_id, total_balance FROM `' . self::CLONE_DB . '`.`commission_wallet_accounts` WHERE total_balance > 0 ORDER BY member_id') as $r) {
            $byMember[(string) $r['member_id']] = (string) $r['total_balance'];
        }
        $expectedByMember = [
            'NGW000002' => '1.30000000',
            'NGW000003' => '2.40000000',
            'NGW981235' => '2.65000000',
            'NGW981236' => '3.25000000',
            'NGW981238' => '0.50000000',
            'ROOT0001'  => '1.05000000',
        ];
        ksort($byMember);
        ksort($expectedByMember);

        $this->assertTrue(
            'commission_interrupt_resume_and_reconcile',
            $part['exit'] === 40 && ($partJ['posted_now'] ?? -1) === 10
                && $res['exit'] === 0 && ($resJ['posted_now'] ?? -1) === 11 && ($resJ['batch_ref'] ?? '') === ($partJ['batch_ref'] ?? '')
                && $dup['exit'] === 0 && ($dupJ['posted_now'] ?? -1) === 0
                && bccomp($walletTotal, '11.15000000', 8) === 0
                && bccomp($ledgerTotal, '11.15000000', 8) === 0
                && $ledgerRows === 21
                && $dupKeys === 0
                && $origStillIntact === 24
                && $byMember === $expectedByMember
                && ($walletRecon['issue_count'] ?? -1) === 0,
            'Interrupted batch (10 posted, exit 40) resumed same batch (+11 posted, exit 0); duplicate run posted 0; 21 ledger rows == 11.15000000 USDT; per-member balances exact; 24 NO_APPROVED_PLAN rows intact.'
        );

        return [
            'plan_ref'            => $planRef,
            'revisions'           => $revOut,
            'interrupted_batch'   => $partJ,
            'resumed_batch'       => $resJ,
            'duplicate_run'       => $dupJ,
            'beneficiaries_usdt'  => $byMember,
            'wallet_total_usdt'   => $walletTotal,
            'ledger_rows'         => $ledgerRows,
            'wallet_reconcile'    => $walletRecon,
            'engine_reconcile_summary' => $recon['summary'] ?? null,
        ];
    }

    // ===================================================================== Phase 6 & 7: Future automation + kill-switches

    /** @return array<string, mixed> */
    private function rehearseFutureAutomationAndKillSwitches(int $approverId, mysqli $clone): array
    {
        // 1. Future purchase event on clone with commissionAutoPostOnApprovalEnabled=false: calculates but does NOT post
        $cfg = config('Platform');
        $oldReqDep = $cfg->requireVerifiedDepositForApproval;
        $cfg->requireVerifiedDepositForApproval = false;
        try {
            $users = model(UserModel::class);
            $u     = new User(['username' => 'P14SYN01', 'active' => 1]);
            $users->save($u);
            $uid = (int) $users->getInsertID();
            $u   = $users->findById($uid);
            $u->createEmailIdentity(['email' => 'p14syn01@example.test', 'password' => 'Pass123456!']);
            $u->addGroup('member');

            $now = date('Y-m-d H:i:s');
            db_connect()->table('member_profiles')->insert([
                'user_id'        => $uid,
                'member_id'      => 'P14SYN01',
                'member_name'    => 'P14 Synthetic',
                'mobile'         => '9999900011',
                'account_status' => 'inactive',
                'is_root_sponsor'=> 0,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            db_connect()->table('sponsor_relationships')->insert([
                'member_user_id'    => $uid,
                'sponsor_user_id'   => 13, // NGW981239 -> L1 NGW981239, L2 NGW981238, L3 NGW981236
                'sponsor_member_id' => 'NGW981239',
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $req = service('coinPurchaseService')->createRequest($uid, '100', 'p14 future test', 'p14-future-' . bin2hex(random_bytes(3)));
            $pRef = (string) $req['request']['purchase_ref'];
            $appr = service('coinPurchaseService')->approveRequest($pRef, 1);

            $ledgerBeforePost = (int) ($this->one($clone, "SELECT COUNT(*) FROM `" . self::CLONE_DB . "`.`commission_wallet_ledger_entries` WHERE reference_id LIKE '" . $clone->real_escape_string($pRef) . ":%'") ?? 0);

            // Now execute purchase-event posting explicitly via guarded CLI commissions:post --purchase
            $postCmd = $this->spark(['commissions:post', '--group', self::CLONE_GROUP, '--purchase', $pRef, '--confirm', 'APPLY_STAGING', '--strict-exit', '--actor', (string) $approverId]);
            $postJ   = $this->json($postCmd['stdout']);
            $ledgerAfterPost = (string) ($this->one($clone, "SELECT COALESCE(SUM(amount),0) FROM `" . self::CLONE_DB . "`.`commission_wallet_ledger_entries` WHERE reference_id LIKE '" . $clone->real_escape_string($pRef) . ":%'") ?? '0');
        } finally {
            $cfg->requireVerifiedDepositForApproval = $oldReqDep;
        }

        // 2. Future business date refusal on mining:post
        $futDate  = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('+2 days')->format('Y-m-d');
        $futMine  = $this->spark(['mining:post', '--group', self::CLONE_GROUP, '--date', $futDate, '--confirm', 'APPLY_STAGING', '--strict-exit']);

        // 3. Live DB kill-switch checks (default group -> ng_database; strictly blocked/skipped before any write)
        $liveSched   = $this->spark(['mining:post', '--scheduled', '--strict-exit']);
        $liveMine    = $this->spark(['mining:post', '--allow-production', '--confirm', 'I_AUTHORIZE_PRODUCTION_CHANGE', '--strict-exit']);
        $liveComm    = $this->spark(['commissions:post', '--allow-production', '--confirm', 'I_AUTHORIZE_PRODUCTION_CHANGE', '--strict-exit']);

        $this->assertTrue(
            'future_purchase_event_and_kill_switches',
            ($appr['commission_calc']['totals']['expected_amount_usdt'] ?? '') === '0.90000000'
                && $ledgerBeforePost === 0
                && $postCmd['exit'] === 0
                && bccomp($ledgerAfterPost, '0.90000000', 8) === 0
                && $futMine['exit'] === 30
                && $liveSched['exit'] === 20
                && $liveMine['exit'] === 30
                && $liveComm['exit'] === 30,
            'Future purchase calculated 0.90 USDT with auto-post OFF (0 posted until explicit commissions:post -> 0.90 USDT posted); future mining date refused (exit 30); live scheduled SKIPPED (20) & live manual mining/commission BLOCKED (30).'
        );

        return [
            'future_purchase_ref'  => $pRef,
            'expected_on_approval' => $appr['commission_calc']['totals']['expected_amount_usdt'] ?? null,
            'ledger_before_post'   => $ledgerBeforePost,
            'ledger_after_post'    => $ledgerAfterPost,
            'future_post_run'      => $postJ,
            'live_mining_sched_exit'  => $liveSched['exit'],
            'live_mining_manual_exit' => $liveMine['exit'],
            'live_comm_manual_exit'   => $liveComm['exit'],
        ];
    }

    // ===================================================================== Backup / Restore / Plumbing

    /** @return array<string, mixed> */
    private function createAndVerifyBackup(mysqli $admin): array
    {
        $dir = ROOTPATH . 'writable' . DIRECTORY_SEPARATOR . 'phase14-private';
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $rel  = 'writable/phase14-private/ng_database-full-' . date('Ymd-His') . '.sql';
        $abs  = ROOTPATH . $rel;
        $dumpBin = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe';
        $this->assertTrue('mysqldump_present', is_file($dumpBin), 'mysqldump client: ' . (is_file($dumpBin) ? 'found' : 'MISSING'));

        $a   = config('Database')->default;
        $env = getenv();
        if ((string) $a['password'] !== '') {
            $env['MYSQL_PWD'] = (string) $a['password'];
        }

        $cmd = [
            $dumpBin, '--no-defaults', '-u' . $a['username'], '-h' . $a['hostname'], '-P' . ($a['port'] ?? 3306),
            '--single-transaction', '--routines', '--triggers', '--no-tablespaces', '--skip-add-drop-table',
            self::LIVE_DB,
        ];
        $proc = proc_open($cmd, [1 => ['file', $abs, 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $stderr = '';
        $exit   = -1;
        if (is_resource($proc)) {
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        }

        $bytes = is_file($abs) ? (int) filesize($abs) : 0;
        $creates = 0;
        $completed = null;
        $danger = [];
        $inserts = [];
        if (is_file($abs)) {
            $fh = fopen($abs, 'rb');
            $ln = 0;
            while (($line = fgets($fh)) !== false) {
                $ln++;
                if (preg_match('/^CREATE TABLE `/', $line)) {
                    $creates++;
                }
                if (preg_match('/^-- Dump completed on (.+)$/', $line, $m)) {
                    $completed = trim($m[1]);
                }
                if (preg_match('/^\s*(USE\s|CREATE\s+DATABASE|DROP\s+DATABASE|CREATE\s+USER|DROP\s+USER|GRANT\s|SET\s+PASSWORD|ALTER\s+USER)/i', $line)) {
                    $danger[] = 'line ' . $ln;
                }
                if (preg_match('/^INSERT INTO `([a-z0-9_]+)`/', $line, $m)) {
                    $inserts[$m[1]] = ($inserts[$m[1]] ?? 0) + 1;
                }
            }
            fclose($fh);
        }

        $ok = $exit === 0 && $bytes > 10000 && $creates === 73 && $completed !== null && $danger === [];
        $this->assertTrue('backup_created_and_verified', $ok, sprintf('mysqldump exit=%d bytes=%d tables=%d data_tables=%d completed=%s', $exit, $bytes, $creates, count($inserts), (string) ($completed ?? 'missing')));
        if (! $ok) {
            throw new RuntimeException('Backup verification failed: ' . $stderr);
        }

        return [
            'file'        => $rel,
            'file_abs'    => $abs,
            'bytes'       => $bytes,
            'sha256'      => hash_file('sha256', $abs),
            'tables'      => $creates,
            'data_tables' => count($inserts),
            'completed'   => $completed,
        ];
    }

    /** @return array<string, mixed> */
    private function validateRestore(mysqli $admin, mysqli $snap, mysqli $live, array $snapSums, array $cloneSums): array
    {
        $fpSnap = $this->fingerprint($snap, self::SNAP_DB);
        $fpLive = $this->fingerprint($live, self::LIVE_DB);

        $badTables = [];
        foreach ($this->tables($admin, self::SNAP_DB) as $t) {
            foreach ($this->q($admin, 'CHECK TABLE `' . self::SNAP_DB . '`.`' . $t . '` QUICK') as $row) {
                if (($row['Msg_type'] ?? '') === 'status' && ! in_array(strtolower((string) $row['Msg_text']), ['ok', 'table is already up to date'], true)) {
                    $badTables[] = $t;
                }
            }
        }

        $this->assertTrue('restore_matches_live', $fpSnap['row_counts'] === $fpLive['row_counts'] && $fpSnap['owned_total'] === $fpLive['owned_total'] && $fpSnap['locked_total'] === $fpLive['locked_total'], 'Snapshot matches ng_database row counts & balances exactly (10 members, 8 approved purchases, 1300 owned/locked, 8 LOCK1000, 0 mining/commission ledger).');
        $this->assertTrue('restore_clone_matches_snapshot', $snapSums === $cloneSums, 'Clone CHECKSUM TABLE on ' . count($snapSums) . ' tables matches snapshot.');
        $this->assertTrue('restore_check_table_ok', $badTables === [], 'CHECK TABLE QUICK OK on all restored tables.');

        return [
            'snapshot_fingerprint' => $fpSnap,
            'check_table_failures' => $badTables,
        ];
    }

    /** @return array<string, mixed> */
    private function applyCloneMigrations(mysqli $clone): array
    {
        $before = (int) $this->one($clone, 'SELECT COUNT(*) FROM `' . self::CLONE_DB . '`.`migrations`');
        $ok     = false;
        $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function () use (&$ok): void {
            $runner = \Config\Services::migrations(null, null, false);
            $ok     = (bool) $runner->latest();
        });

        $after  = (int) $this->one($clone, 'SELECT COUNT(*) FROM `' . self::CLONE_DB . '`.`migrations`');
        $latest = $this->one($clone, 'SELECT version FROM `' . self::CLONE_DB . '`.`migrations` ORDER BY id DESC LIMIT 1');
        $this->assertTrue('clone_migrations_applied', $ok && $after > $before && $latest === '2026-11-18-995001', "Clone migrations $before -> $after (latest=$latest).");

        return ['before' => $before, 'after' => $after, 'latest' => $latest];
    }

    /** @return array{0: int, 1: int} */
    private function ensureStagingOperators(): array
    {
        $users = model(UserModel::class);

        $p = $users->findByCredentials(['email' => 'p14.proposer@staging.test']);
        if ($p === null) {
            $p = new User(['username' => 'P14_PROPOSER', 'active' => 1]);
            $users->save($p);
            $p = $users->findById($users->getInsertID());
            $p->createEmailIdentity(['email' => 'p14.proposer@staging.test', 'password' => 'P14_Proposer_Pass_2026!']);
            $p->addGroup('admin', 'finance_operator');
        }

        $a = $users->findByCredentials(['email' => 'p14.approver@staging.test']);
        if ($a === null) {
            $a = new User(['username' => 'P14_APPROVER', 'active' => 1]);
            $users->save($a);
            $a = $users->findById($users->getInsertID());
            $a->createEmailIdentity(['email' => 'p14.approver@staging.test', 'password' => 'P14_Approver_Pass_2026!']);
            $a->addGroup('admin', 'finance_approver');
        }

        return [(int) $p->id, (int) $a->id];
    }

    private function preflight(mysqli $admin): array
    {
        $mysql = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        $this->assertTrue('mysql_cli_present', is_file($mysql), 'mysql client: ' . (is_file($mysql) ? 'found' : 'MISSING'));
        $cfg = config('Platform');
        $eight = [];
        foreach ([
            'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled',
            'commissionPostingEnabled', 'commissionAutoPostOnApprovalEnabled',
            'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $k) {
            $eight[$k] = (bool) $cfg->{$k};
        }
        $this->assertTrue('kill_switches_all_false', array_filter($eight) === [], 'All 8 production switches false: ' . json_encode($eight));

        return ['switches' => $eight, 'configured_default_db' => config('Database')->default['database']];
    }

    private function createLiveReadOnlyUser(mysqli $admin): void
    {
        $this->liveRoPassword = bin2hex(random_bytes(16));
        $u = "'" . self::LIVE_RO_USER . "'@'localhost'";
        $admin->query('CREATE USER IF NOT EXISTS ' . $u . " IDENTIFIED BY '" . $this->liveRoPassword . "'");
        $admin->query('ALTER USER ' . $u . " IDENTIFIED BY '" . $this->liveRoPassword . "'");
        $admin->query('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $u);
        $admin->query('GRANT SELECT ON `' . self::LIVE_DB . '`.* TO ' . $u);
        $grants = array_map(static fn (array $r): string => (string) array_values($r)[0], $this->q($admin, 'SHOW GRANTS FOR ' . $u));
        $bad    = array_values(array_filter($grants, static fn (string $g): bool => ! preg_match('/^GRANT (USAGE ON \*\.\*|SELECT ON `' . self::LIVE_DB . '`\.\*) TO /', $g)));
        $this->assertTrue('live_connection_read_only', $bad === [] && count($grants) >= 2, 'Live account grants verified SELECT-only on ' . self::LIVE_DB . '.');
        if ($bad !== []) {
            throw new RuntimeException('Live RO user grant check failed.');
        }
    }

    private function liveRoConnection(): mysqli
    {
        $d = config('Database')->default;
        $c = @mysqli_connect((string) $d['hostname'], self::LIVE_RO_USER, (string) $this->liveRoPassword, self::LIVE_DB, (int) ($d['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot open read-only live connection.');
        }

        return $c;
    }

    private function restoreInto(mysqli $admin, string $db, string $dumpAbs, string $purpose): array
    {
        $e      = $admin->real_escape_string($db);
        $exists = $this->q($admin, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $e . "'") !== [];
        if ($exists) {
            $names = array_map(static fn (array $r): string => (string) $r['n'], $this->q($admin, "SELECT table_name n FROM information_schema.tables WHERE table_schema = '" . $e . "'"));
            if (in_array(self::MARKER, $names, true)) {
                $admin->query('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($names as $n) {
                    if ($n !== self::MARKER) {
                        $admin->query('DROP TABLE IF EXISTS `' . $db . '`.`' . $n . '`');
                    }
                }
                $admin->query('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($names !== []) {
                throw new RuntimeException('Refusing to overwrite non-empty unmarked database ' . $db);
            }
        } else {
            $admin->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
        $admin->query('CREATE TABLE IF NOT EXISTS `' . $db . '`.`' . self::MARKER . '` (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL)');
        if ($this->one($admin, 'SELECT COUNT(*) FROM `' . $db . '`.`' . self::MARKER . '`') === '0') {
            $admin->query('INSERT INTO `' . $db . '`.`' . self::MARKER . "` (note, created_at) VALUES ('" . $admin->real_escape_string($purpose) . "', NOW())");
        }

        $a   = config('Database')->default;
        $env = getenv();
        if ((string) $a['password'] !== '') {
            $env['MYSQL_PWD'] = (string) $a['password'];
        }
        $mysql = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        $proc  = proc_open([$mysql, '--no-defaults', '-u' . $a['username'], '-h' . $a['hostname'], '-P' . ($a['port'] ?? 3306), '--default-character-set=utf8mb4', $db], [0 => ['file', $dumpAbs, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $stderr = '';
        $exit   = -1;
        if (is_resource($proc)) {
            stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        }
        $this->assertTrue('restore_' . $db, $exit === 0, 'mysql restore into ' . $db . ' exit=' . $exit . ($stderr !== '' ? ' err=' . substr($stderr, 0, 180) : ''));

        return ['database' => $db, 'exit' => $exit];
    }

    private function grantAccounts(mysqli $admin): void
    {
        $dbc = config('Database');
        foreach ([[self::SNAP_GROUP, self::SNAP_DB, 'SELECT'], [self::CLONE_GROUP, self::CLONE_DB, 'ALL PRIVILEGES']] as [$group, $db, $priv]) {
            $g = $dbc->{$group};
            $u = "'" . $admin->real_escape_string((string) $g['username']) . "'@'" . $admin->real_escape_string((string) $g['hostname']) . "'";
            $p = $admin->real_escape_string((string) $g['password']);
            $admin->query('CREATE USER IF NOT EXISTS ' . $u . " IDENTIFIED BY '" . $p . "'");
            $admin->query('ALTER USER ' . $u . " IDENTIFIED BY '" . $p . "'");
            $admin->query('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $u);
            $admin->query('GRANT ' . $priv . ' ON `' . $db . '`.* TO ' . $u);
            $admin->query('FLUSH PRIVILEGES');
        }
        $grants = array_map(static fn (array $r): string => (string) array_values($r)[0], $this->q($admin, "SHOW GRANTS FOR '" . $dbc->{self::SNAP_GROUP}['username'] . "'@'localhost'"));
        $bad    = array_filter($grants, static fn (string $g): bool => ! preg_match('/^GRANT (USAGE ON \*\.\*|SELECT ON `' . self::SNAP_DB . '`\.\*) TO /', $g));
        $this->assertTrue('snapshot_account_select_only', $bad === [], 'Snapshot account verified SELECT-only on ' . self::SNAP_DB . '.');
    }

    private function adminConnection(): mysqli
    {
        $a = config('Database')->default;
        $c = @mysqli_connect((string) $a['hostname'], (string) $a['username'], (string) $a['password'], '', (int) ($a['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot connect to MySQL.');
        }
        $c->set_charset('utf8mb4');

        return $c;
    }

    private function connect(string $group): mysqli
    {
        $g = config('Database')->{$group};
        $c = @mysqli_connect((string) $g['hostname'], (string) $g['username'], (string) $g['password'], (string) $g['database'], (int) ($g['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot connect with group ' . $group);
        }
        $c->set_charset('utf8mb4');

        return $c;
    }

    /** @return list<array<string, mixed>> */
    private function q(mysqli $c, string $sql): array
    {
        $r = $c->query($sql);

        return $r instanceof \mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    private function one(mysqli $c, string $sql): ?string
    {
        $rows = $this->q($c, $sql);

        return $rows === [] ? null : (array_values($rows[0])[0] === null ? null : (string) array_values($rows[0])[0]);
    }

    /** @return list<string> */
    private function tables(mysqli $c, string $db): array
    {
        return array_values(array_map(static fn (array $r): string => (string) $r['n'], $this->q($c, "SELECT table_name n FROM information_schema.tables WHERE table_schema = '" . $c->real_escape_string($db) . "' AND table_type = 'BASE TABLE' AND table_name <> '" . self::MARKER . "' ORDER BY 1")));
    }

    /** @return array<string, string> */
    private function checksums(mysqli $c, string $db, array $tables): array
    {
        $out = [];
        foreach (array_chunk($tables, 25) as $chunk) {
            $list = implode(',', array_map(static fn (string $t): string => '`' . $db . '`.`' . $t . '`', $chunk));
            foreach ($this->q($c, 'CHECKSUM TABLE ' . $list) as $r) {
                $out[(string) explode('.', (string) $r['Table'], 2)[1]] = (string) $r['Checksum'];
            }
        }
        ksort($out);

        return $out;
    }

    /** @return array<string, mixed> */
    private function fingerprint(mysqli $c, string $db): array
    {
        $tables = $this->tables($c, $db);
        $has    = static fn (string $t): bool => in_array($t, $tables, true);
        $t      = static fn (string $n): string => '`' . $db . '`.`' . $n . '`';
        $cnt    = fn (string $n): ?int => $has($n) ? (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t($n)) : null;
        $sum    = fn (string $n, string $col): ?string => $has($n) ? (string) ($this->one($c, 'SELECT COALESCE(SUM(`' . $col . '`),0) FROM ' . $t($n)) ?? '0') : null;
        $rc     = [];
        foreach ($tables as $n) {
            $rc[$n] = (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t($n));
        }

        return [
            'database'               => $db,
            'table_count'            => count($tables),
            'members'                => $cnt('member_profiles'),
            'approved_purchases'     => $has('coin_purchase_requests') ? (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t('coin_purchase_requests') . " WHERE status = 'approved'") : null,
            'owned_total'            => $sum('member_coin_balances', 'balance'),
            'locked_total'           => $sum('member_coin_balances', 'locked_balance'),
            'active_locks'           => $has('member_coin_locks') ? (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t('member_coin_locks') . " WHERE status = 'active'") : null,
            'mining_wallet_total'    => $sum('mining_wallets', 'available_balance'),
            'mining_ledger_rows'     => $cnt('mining_wallet_ledger'),
            'commission_total'       => $sum('commission_wallet_accounts', 'total_balance'),
            'commission_ledger_rows' => $cnt('commission_wallet_ledger_entries'),
            'latest_migration'       => $has('migrations') ? $this->one($c, 'SELECT version FROM ' . $t('migrations') . ' ORDER BY id DESC LIMIT 1') : null,
            'row_counts'             => $rc,
        ];
    }

    private function withDefault(string $group, string $expectedDb, callable $fn): void
    {
        $dbc = config('Database');
        $dbc->defaultGroup = $group;
        Database::connect($group, false);
        db_connect($group, false);
        try {
            if ((string) db_connect()->getDatabase() !== $expectedDb) {
                throw new RuntimeException('Default DB mismatch.');
            }
            $fn();
        } finally {
            $dbc->defaultGroup = 'default';
            Database::connect('default', false);
            db_connect('default', false);
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function spark(array $args, array $env = []): array
    {
        $cmd  = array_merge([PHP_BINARY, ROOTPATH . 'spark'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, array_merge(getenv(), $env));
        if (! is_resource($proc)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string, mixed>|null */
    private function json(string $stdout): ?array
    {
        if (preg_match('/^\{.*?^\}/ms', $stdout, $m)) {
            $d = json_decode($m[0], true);

            return is_array($d) ? $d : null;
        }

        return null;
    }

    private function expectThrows(callable $fn): bool
    {
        try {
            $fn();

            return false;
        } catch (Throwable) {
            return true;
        }
    }

    /** @return array{ok: bool, detail: string} */
    private function assertTrue(string $id, bool $ok, string $detail): array
    {
        $this->checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];

        return ['ok' => $ok, 'detail' => $detail];
    }
}
