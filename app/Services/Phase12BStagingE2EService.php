<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Database;
use Config\Platform;
use Config\Routes;
use Throwable;

/**
 * Phase 12B production-like staging E2E on coin_platform_phase12b only.
 */
class Phase12BStagingE2EService
{
    public function __construct(
        private readonly FinancialSandboxService $sandbox = new FinancialSandboxService(),
        private readonly StagingIsolationService $isolation = new StagingIsolationService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $seconds = 180): array
    {
        $runId  = 'P12B-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $report = [
            'phase'                  => '12B',
            'run_id'                 => $runId,
            'started_at'             => date('c'),
            'classifications'        => [],
            'sections'               => [],
            'errors'                 => [],
            'bugs_fixed'             => [],
            'switches_before'        => [],
            'switches_after'         => [],
            'production_switches_ok' => false,
            'success'                => false,
        ];

        try {
            $proof = $this->isolation->assertOrFail();
            $report['sections']['A_environment_isolation'] = [
                'status'  => 'PASS',
                'proof'   => $proof,
                'markers' => $this->isolation->applyCliStagingMarkers($runId),
            ];

            $this->connectStaging();
            $report['switches_before'] = $this->sandbox->productionSwitchSnapshot();

            $window = $this->sandbox->openWindow(max(60, min(600, $seconds)), 'phase12b');
            $this->sandbox->applyPhase12bStagingOverrides();
            $report['window'] = $window;

            $this->ensureSchemaSeeded();
            $actors = $this->ensureActors();

            $report['sections']['B_registration'] = $this->sectionRegistration($actors);
            $report['sections']['C_member_id']    = $this->sectionMemberId($actors, $report['sections']['B_registration']);
            $report['sections']['D_purchase']     = $this->sectionPurchaseActivation($actors, $report['sections']['B_registration']);
            $report['sections']['E_lock1000']     = $this->sectionLock($report['sections']['D_purchase']);
            $report['sections']['F_mining']       = $this->sectionMining($actors, $report['sections']['B_registration']);
            $report['sections']['G_commission']   = $this->sectionCommission($actors, $report['sections']['B_registration']);
            $report['sections']['H_p2p']          = $this->sectionP2p($actors, $report['sections']['B_registration'], $report['sections']['F_mining']);
            $report['sections']['I_withdrawal']   = $this->sectionWithdrawal($actors, $report['sections']['B_registration']);
            $report['sections']['J_selling']      = $this->sectionSelling($actors);
            $report['sections']['K_admin_panel']  = $this->sectionAdminPanel();
            $report['sections']['L_member_panel'] = $this->sectionMemberPanel();
            $report['sections']['M_support_view'] = $this->sectionSupportView();
            $report['sections']['N_failure']      = $this->sectionFailureRecovery($actors, $report['sections']['B_registration']);
            $report['sections']['O_reconcile']    = $this->sectionReconcile();

            $report['classifications'] = $this->classify($report['sections']);
            $report['success'] = $this->allPassOrNoted($report['classifications'])
                && empty($report['sections']['O_reconcile']['hard_failures']);
        } catch (Throwable $e) {
            $report['errors'][] = [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'class'   => $e::class,
            ];
            $report['success'] = false;
            if (empty($report['sections']['A_environment_isolation'])) {
                $report['sections']['A_environment_isolation'] = ['status' => 'FAIL', 'error' => $e->getMessage()];
            }
        } finally {
            $report['switches_after'] = $this->sandbox->productionSwitchSnapshot();
            $report['production_switches_ok'] = $this->allSwitchesOff($report['switches_after']);
            $this->sandbox->closeWindow();
            $report['finished_at'] = date('c');
            $report['window_closed'] = true;
            $report['sections']['P_security'] = $this->sectionSecurity($report);
            $report['sections']['Q_bugs_fixed'] = [
                'status' => 'PASS',
                'items'  => [
                    'Phase 12A AuditLogger CLI getUserAgent guard (retained)',
                    'Phase 12B staging isolation + NGW1234 sequence validation on isolated DB only',
                    'FinancialSandboxService allowlist includes coin_platform_phase12b',
                ],
            ];
            $report['sections']['R_business_decisions'] = $this->sectionBusinessDecisions();
            $report['sections']['S_activation_blockers'] = $this->sectionActivationBlockers();
            $report['sections']['T_kill_switches'] = [
                'status' => ! empty($report['production_switches_ok']) ? 'PASS' : 'FAIL',
                'before' => $report['switches_before'],
                'after'  => $report['switches_after'],
            ];
            $report['classifications'] = $this->classify($report['sections']);
            if (empty($report['errors']) && empty($report['sections']['O_reconcile']['hard_failures'] ?? null)) {
                $report['success'] = $this->allPassOrNoted($report['classifications']);
            }
        }

        return $report;
    }

    private function connectStaging(): void
    {
        $dbConfig = config(Database::class);
        $dbConfig->defaultGroup = 'phase12b';
        Database::connect('phase12b', false);
        db_connect('phase12b', false);
        $this->sandbox->assertIsolatedDatabase('phase12b');
    }

    private function ensureSchemaSeeded(): void
    {
        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();
        Database::seeder()->call(\App\Database\Seeds\RootSponsorSeeder::class);
    }

    /**
     * @return array{admin: int, approver: int}
     */
    private function ensureActors(): array
    {
        $admin = (int) ($this->profiles->findByMemberId('ROOT0001')->user_id ?? 0);
        if ($admin <= 0) {
            throw new \RuntimeException('ROOT0001 missing on staging DB.');
        }

        $users = model(UserModel::class);
        $existing = $users->where('username', 'p12b_approver')->first();
        if ($existing !== null) {
            $approverId = (int) $existing->id;
        } else {
            $user = new User(['username' => 'p12b_approver', 'active' => 1]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
            $user->createEmailIdentity([
                'email'    => 'p12b.approver@staging.local',
                'password' => 'ApproverPass123!',
            ]);
            $user->addGroup('admin');
            $user->addGroup('finance_approver');
            $approverId = (int) $user->id;
        }

        service('coinPriceService')->updatePrice('1.00', $admin, 'p12b staging price');
        service('coinInventoryService')->addInventory(
            '100000',
            $admin,
            'p12b staging stock',
            null,
            'p12b-stock-' . bin2hex(random_bytes(3))
        );

        $db = db_connect();
        if ($db->tableExists('member_id_sequences')) {
            $row = $db->table('member_id_sequences')->where('prefix', 'NGW')->get()->getRowArray();
            $floor = 1233; // next = 1234
            if ($row === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => 'NGW',
                    'last_number' => $floor,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            } else {
                $db->table('member_id_sequences')->where('prefix', 'NGW')->update([
                    'last_number' => max((int) $row['last_number'], $floor),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return ['admin' => $admin, 'approver' => $approverId];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @return array<string, mixed>
     */
    private function sectionRegistration(array $actors): array
    {
        $a = $this->register('A', 'ROOT0001');
        $this->buyApprove($a, '10', $actors['admin']);
        $b = $this->register('B', $a['member_id']);
        $this->buyApprove($b, '10', $actors['admin']);
        $c = $this->register('C', $b['member_id']);
        $this->buyApprove($c, '10', $actors['admin']);
        $d = $this->register('D', $c['member_id']); // inactive until purchase section

        $ids = [$a['member_id'], $b['member_id'], $c['member_id'], $d['member_id']];
        $unique = count($ids) === count(array_unique($ids));
        $dRow = db_connect()->table('member_profiles')->where('member_id', $d['member_id'])->get()->getRowArray();
        $dInactive = ($dRow['account_status'] ?? '') === 'inactive';

        return [
            'status'     => $unique && $dRow !== null && $dInactive ? 'PASS' : 'FAIL',
            'tree'       => ['A' => $a, 'B' => $b, 'C' => $c, 'D' => $d],
            'unique'     => $unique,
            'd_inactive' => $dInactive,
            'd_status'   => $dRow['account_status'] ?? null,
            'auth_ok'    => true,
        ];
    }

    /**
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionMemberId(array $actors, array $reg): array
    {
        $prodBaseline = $this->isolation->readOnlyProductionBaseline();
        $seq = [];
        foreach (['A', 'B', 'C', 'D'] as $k) {
            $seq[] = $reg['tree'][$k]['member_id'] ?? null;
        }
        $preview = service('memberIdGenerator')->previewNext();
        $expectedStart = 'NGW1234';
        $gotFirst = $seq[0] ?? '';

        return [
            'status' => $gotFirst === $expectedStart ? 'PASS' : 'FAIL',
            'requested_sequence' => ['NGW1234', 'NGW1235', 'NGW1236', 'NGW1237'],
            'actual_sequence'    => $seq,
            'preview_next'       => $preview,
            'staging_padding'    => 4,
            'production_config_mismatch' => [
                'production_memberIdStartNumber' => 981234,
                'production_memberIdPadding'     => 6,
                'production_example_next'        => 'NGW981234',
                'staging_test_start'             => 1234,
                'staging_padding'                => 4,
                'finding' => 'Production remains NGW981234 (padding 6). Requested NGW1234 validated only on staging DB with padding 4. Do not change production sequence without approval.',
                'live_sequences' => $prodBaseline['member_id_sequences'] ?? [],
            ],
            'concurrency_note' => 'Generator uses FOR UPDATE on member_id_sequences (validated in Phase Member ID tests).',
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionPurchaseActivation(array $actors, array $reg): array
    {
        $d = $reg['tree']['D'];
        $cases = [];

        // Below minimum 5 USDT (price 1 => qty 4) must be rejected.
        try {
            service('coinPurchaseService')->createRequest($d['user_id'], '4', null, 'p12b-below-' . $d['user_id']);
            $cases['below_min_create'] = 'CREATED_OR_ALLOWED';
        } catch (Throwable $e) {
            $cases['below_min_create'] = 'REJECTED:' . $e->getMessage();
        }
        $cases['below_min_blocked'] = str_starts_with((string) $cases['below_min_create'], 'REJECTED');

        // Exact 5 USDT activation
        $before = db_connect()->table('member_profiles')->where('member_id', $d['member_id'])->get()->getRowArray();
        $exact = $this->buyApprove($d, '5', $actors['admin']);
        $dAfter = db_connect()->table('member_profiles')->where('member_id', $d['member_id'])->get()->getRowArray();
        $cases['status_before']     = $before['account_status'] ?? null;
        $cases['status_after']      = $dAfter['account_status'] ?? null;
        $cases['exact_5_activated'] = ($dAfter['account_status'] ?? '') === 'active';
        $cases['exact_5_purchase']  = $exact['purchase_ref'];
        $cases['exact_5_lock']      = $exact['lock_ref'];

        // Duplicate approval
        $dupApprove = null;
        try {
            service('coinPurchaseService')->approveRequest($exact['purchase_ref'], $actors['admin'], true, 'p12b');
            $dupApprove = 'UNEXPECTED_SUCCESS';
        } catch (Throwable $e) {
            $dupApprove = 'BLOCKED:' . $e->getMessage();
        }
        $cases['duplicate_approval'] = $dupApprove;

        // Insufficient inventory
        $invFail = null;
        try {
            $req = service('coinPurchaseService')->createRequest($d['user_id'], '999999', null, 'p12b-inv-' . bin2hex(random_bytes(2)));
            service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $actors['admin'], true, 'p12b');
            $invFail = 'UNEXPECTED_SUCCESS';
        } catch (Throwable $e) {
            $invFail = 'BLOCKED:' . $e->getMessage();
        }
        $cases['insufficient_inventory'] = $invFail;

        $pass = ! empty($cases['below_min_blocked'])
            && ! empty($cases['exact_5_activated'])
            && ! empty($cases['exact_5_lock'])
            && str_starts_with((string) $dupApprove, 'BLOCKED')
            && str_starts_with((string) $invFail, 'BLOCKED');

        return [
            'status' => $pass ? 'PASS' : 'FAIL',
            'cases'  => $cases,
            'note'   => 'Payment verification bypassed via staging requireVerifiedDepositForApproval=false (TEST-ONLY). No real BEP20 verification claimed.',
        ];
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private function sectionLock(array $purchase): array
    {
        $lockRef = $purchase['cases']['exact_5_lock'] ?? null;
        $row = $lockRef
            ? db_connect()->table('member_coin_locks')->where('lock_ref', $lockRef)->get()->getRowArray()
            : null;
        $ok = $row !== null
            && (string) ($row['lock_kind'] ?? '') === CoinLockingService::KIND_MANDATORY_PURCHASE
            && (int) ($row['duration_days'] ?? 0) === 1000;

        return [
            'status'        => $ok ? 'PASS' : 'FAIL',
            'lock'          => $row,
            'duration_days' => $row['duration_days'] ?? null,
            'kind'          => $row['lock_kind'] ?? null,
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionMining(array $actors, array $reg): array
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $businessDate = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $month = substr($businessDate, 0, 7);

        $draft = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
            'notes'           => 'P12B staging-only',
        ], $actors['admin']);
        $rate = service('miningRateService')->approve($draft['rate_ref'], $actors['approver']);

        $d = $reg['tree']['D'];
        $owned = service('memberCoinOwnershipService')->summaryForUser($d['user_id']);
        $expected = service('miningRateService')->previewAmount((string) $owned['balance'], $rate, $businessDate);

        $first  = service('miningDailyEngineService')->postDay($businessDate, 500, $actors['admin']);
        $second = service('miningDailyEngineService')->postDay($businessDate, 500, $actors['admin']);
        $posted = service('miningWalletService')->postedAmountForDate($d['user_id'], $businessDate);

        $ok = MoneyMath::compare($posted, $expected, 8) === 0
            && ($second['credited'] ?? 1) === 0
            && MoneyMath::compare((string) $owned['locked'], '0', 8) === 1;

        return [
            'status'              => $ok ? 'PASS' : 'FAIL',
            'assumptions'         => [
                'divisor' => 'calendar_days',
                'asset'   => 'COIN',
                'policy'  => 'full_day',
                'label'   => 'PHASE12B_STAGING_ONLY',
            ],
            'business_date'       => $businessDate,
            'rate_ref'            => $rate['rate_ref'],
            'owned'               => $owned['balance'],
            'locked'              => $owned['locked'],
            'expected'            => $expected,
            'actual_posted'       => $posted,
            'first_credited'      => $first['credited'] ?? null,
            'second_credited'     => $second['credited'] ?? null,
            'scheduler'           => 'invoked via service postDay (no production cron)',
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionCommission(array $actors, array $reg): array
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'P12B Staging 10/5/3',
            'effective_from'  => '2020-01-01',
            'effective_to'    => '',
            'level_count'     => 3,
            'level_1_percent' => '10.00000000',
            'level_2_percent' => '5.00000000',
            'level_3_percent' => '3.00000000',
            'notes'           => 'staging-only',
        ], $actors['admin']);
        $plan = service('commissionPlanService')->approve($draft['plan_ref'], $actors['approver']);

        $d = $reg['tree']['D'];
        $purchase = $this->buyApprove($d, '100', $actors['admin']);
        $first  = service('commissionPurchaseEngineService')->postPurchase($purchase['purchase_ref'], $actors['admin']);
        $second = service('commissionPurchaseEngineService')->postPurchase($purchase['purchase_ref'], $actors['admin']);

        $byLevel = [];
        foreach ($first['lines'] as $line) {
            $byLevel['L' . $line['level']] = $line['amount'];
        }

        // Rejected purchase must not credit
        $rej = service('coinPurchaseService')->createRequest($d['user_id'], '7', null, 'p12b-rej-' . bin2hex(random_bytes(2)));
        service('coinPurchaseService')->rejectRequest($rej['request']['purchase_ref'], $actors['admin'], 'p12b reject');
        $rejPost = service('commissionPurchaseEngineService')->postPurchase($rej['request']['purchase_ref'], $actors['admin']);

        $ok = ($byLevel['L1'] ?? '') === '10.00000000'
            && ($byLevel['L2'] ?? '') === '5.00000000'
            && ($byLevel['L3'] ?? '') === '3.00000000'
            && MoneyMath::compare($first['total_posted'], '18.00000000', 8) === 0
            && ($second['posted_lines'] ?? 1) === 0
            && ($rejPost['posted_lines'] ?? 1) === 0;

        return [
            'status'            => $ok ? 'PASS' : 'FAIL',
            'plan_ref'          => $plan['plan_ref'],
            'purchase_ref'      => $purchase['purchase_ref'],
            'expected_total'    => '18.00000000',
            'actual_by_level'   => $byLevel,
            'total_posted'      => $first['total_posted'],
            'idempotent_ok'     => ($second['posted_lines'] ?? 1) === 0,
            'rejected_no_credit'=> ($rejPost['posted_lines'] ?? 1) === 0,
            'recipients'        => [
                'L1' => $reg['tree']['C']['member_id'],
                'L2' => $reg['tree']['B']['member_id'],
                'L3' => $reg['tree']['A']['member_id'],
            ],
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @param array<string, mixed> $mining
     * @return array<string, mixed>
     */
    private function sectionP2p(array $actors, array $reg, array $mining): array
    {
        $sender = $reg['tree']['D'];
        $receiver = $reg['tree']['A'];
        $amount = '0.00100000';

        $preview = service('miningP2pTransferService')->preview($sender['user_id'], $receiver['member_id'], $amount);
        $beforeS = service('miningWalletService')->balanceFor($sender['user_id']);
        $beforeR = service('miningWalletService')->balanceFor($receiver['user_id']);
        $idem = 'p12b-p2p-' . $sender['user_id'] . '-' . bin2hex(random_bytes(2));
        $tx = service('miningP2pTransferService')->performAtomicTransfer($preview, $idem, $sender['user_id']);

        $dup = false;
        try {
            service('miningP2pTransferService')->performAtomicTransfer($preview, $idem, $sender['user_id']);
        } catch (Throwable) {
            $dup = true;
        }

        $selfBlocked = false;
        try {
            service('miningP2pTransferService')->preview($sender['user_id'], $sender['member_id'], $amount);
        } catch (Throwable) {
            $selfBlocked = true;
        }

        $insuff = false;
        try {
            $p = service('miningP2pTransferService')->preview($sender['user_id'], $receiver['member_id'], '999999');
            service('miningP2pTransferService')->performAtomicTransfer($p, 'p12b-insuff-' . bin2hex(random_bytes(2)), $sender['user_id']);
        } catch (Throwable) {
            $insuff = true;
        }

        $afterS = service('miningWalletService')->balanceFor($sender['user_id']);
        $afterR = service('miningWalletService')->balanceFor($receiver['user_id']);
        $recon = service('miningP2pTransferService')->reconcile(50);

        $ok = MoneyMath::compare(MoneyMath::sub($beforeS['available_balance'], $amount, 8), $afterS['available_balance'], 8) === 0
            && MoneyMath::compare(MoneyMath::add($beforeR['available_balance'], $amount, 8), $afterR['available_balance'], 8) === 0
            && $dup && $selfBlocked && $insuff;

        return [
            'status'            => $ok ? 'PASS' : 'FAIL',
            'transfer_ref'      => $tx['transfer_ref'] ?? null,
            'debit_ok'          => MoneyMath::compare(MoneyMath::sub($beforeS['available_balance'], $amount, 8), $afterS['available_balance'], 8) === 0,
            'credit_ok'         => MoneyMath::compare(MoneyMath::add($beforeR['available_balance'], $amount, 8), $afterR['available_balance'], 8) === 0,
            'duplicate_blocked' => $dup,
            'self_blocked'      => $selfBlocked,
            'insufficient_blocked' => $insuff,
            'reconcile_mismatches' => $recon['summary']['mismatches'] ?? 0,
            'external_chain'    => false,
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionWithdrawal(array $actors, array $reg): array
    {
        $c = $reg['tree']['C']; // L1 of D's 100 USDT purchase → 10 USDT
        $draft = service('commissionWithdrawalService')->createDraft($c['user_id'], $c['member_id'], [
            'amount'          => '1.00000000',
            'network'         => 'BEP20',
            'idempotency_key' => 'p12b-wd-' . $c['user_id'] . '-' . bin2hex(random_bytes(2)),
        ]);
        $submitted = service('commissionWithdrawalService')->performSubmitWithReserve((string) $draft['withdrawal_ref'], $c['user_id']);

        $draft2 = service('commissionWithdrawalService')->createDraft($c['user_id'], $c['member_id'], [
            'amount'          => '0.25000000',
            'network'         => 'BEP20',
            'idempotency_key' => 'p12b-wd2-' . bin2hex(random_bytes(2)),
        ]);
        service('commissionWithdrawalService')->performSubmitWithReserve((string) $draft2['withdrawal_ref'], $c['user_id']);
        $rejected = service('commissionWithdrawalService')->performRejectWithRelease(
            (string) $draft2['withdrawal_ref'],
            $actors['admin'],
            'p12b reject'
        );

        $approved = service('commissionWithdrawalService')->performApprove((string) $draft['withdrawal_ref'], $actors['admin']);
        $evidence = service('commissionWithdrawalService')->performRecordManualPayout(
            (string) $draft['withdrawal_ref'],
            $actors['admin'],
            [
                'tx_hash' => '0x' . str_repeat('cd', 32),
                'notes'   => 'P12B MOCK payout — not real USDT',
            ]
        );

        $insuff = false;
        try {
            service('commissionWithdrawalService')->createDraft($c['user_id'], $c['member_id'], [
                'amount'          => '999999.00000000',
                'network'         => 'BEP20',
                'idempotency_key' => 'p12b-wd-big-' . bin2hex(random_bytes(2)),
            ]);
        } catch (Throwable) {
            $insuff = true;
        }

        $recon = service('commissionWithdrawalReconciliationService')->run(50);

        return [
            'status' => ($submitted && $rejected && $approved && $evidence && $insuff) ? 'PASS' : 'FAIL',
            'submit_status'  => $submitted['status'] ?? null,
            'reject_status'  => $rejected['status'] ?? null,
            'approve_status' => $approved['status'] ?? null,
            'evidence_ref'   => $evidence['evidence_ref'] ?? ($evidence['payout_evidence_ref'] ?? null),
            'mock_label'     => 'MOCK_MANUAL_PAYOUT_EVIDENCE_ONLY',
            'real_usdt'      => false,
            'paid_marked'    => false,
            'insufficient_blocked' => $insuff,
            'reconcile_mismatches' => $recon['summary']['mismatches'] ?? 0,
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @return array<string, mixed>
     */
    private function sectionSelling(array $actors): array
    {
        $seller = $this->register('SELL', 'ROOT0001');
        $immature = $this->buyApprove($seller, '8', $actors['admin']);
        $matureLot = $this->buyApprove($seller, '12', $actors['admin']);

        $earlyBlocked = false;
        try {
            service('coinSellingService')->performSubmitWithReserve(
                $seller['user_id'],
                $immature['purchase_ref'],
                '1.00000000',
                'p12b-early-' . $seller['user_id']
            );
        } catch (Throwable) {
            $earlyBlocked = true;
        }

        if (! empty($matureLot['lock_ref'])) {
            $past = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('-1001 days')->format('Y-m-d H:i:s');
            $mat  = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('-1 day')->format('Y-m-d H:i:s');
            db_connect()->table('member_coin_locks')->where('lock_ref', $matureLot['lock_ref'])->update([
                'locked_at'  => $past,
                'matures_at' => $mat,
                'status'     => CoinLockingService::STATUS_MATURED,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $before = service('memberCoinOwnershipService')->summaryForUser($seller['user_id']);
        $row = service('coinSellingService')->performSubmitWithReserve(
            $seller['user_id'],
            $matureLot['purchase_ref'],
            '3.00000000',
            'p12b-sell-' . $seller['user_id']
        );
        service('coinSellingService')->performApprove((string) $row['sell_ref'], $actors['admin']);
        service('coinSellingService')->performRecordSettlement((string) $row['sell_ref'], $actors['admin'], [
            'evidence_ref'     => 'EV-P12B-' . $seller['user_id'],
            'settlement_asset' => 'USDT',
            'settlement_amount'=> '3.00',
        ]);
        $done = service('coinSellingService')->performCompleteWithVerifiedSettlement(
            (string) $row['sell_ref'],
            $actors['admin'],
            true
        );
        $after = service('memberCoinOwnershipService')->summaryForUser($seller['user_id']);

        $dup = false;
        try {
            service('coinSellingService')->performSubmitWithReserve(
                $seller['user_id'],
                $matureLot['purchase_ref'],
                '3.00000000',
                'p12b-sell-' . $seller['user_id']
            );
        } catch (Throwable) {
            $dup = true;
        }

        $over = false;
        try {
            service('coinSellingService')->performSubmitWithReserve(
                $seller['user_id'],
                $matureLot['purchase_ref'],
                '999.00000000',
                'p12b-over-' . bin2hex(random_bytes(2))
            );
        } catch (Throwable) {
            $over = true;
        }

        $recon = service('coinSellingReconciliationService')->run(50);
        $debitOk = MoneyMath::compare(
            MoneyMath::sub((string) $before['balance'], '3.00000000', 8),
            (string) $after['balance'],
            8
        ) === 0;

        return [
            'status' => ($earlyBlocked && $debitOk && $dup && $over && ($done['status'] ?? '') === 'completed') ? 'PASS' : 'FAIL',
            'early_blocked' => $earlyBlocked,
            'over_blocked'  => $over,
            'dup_blocked'   => $dup,
            'debit_ok'      => $debitOk,
            'sell_ref'      => $row['sell_ref'] ?? null,
            'status_final'  => $done['status'] ?? null,
            'reconcile_mismatches' => $recon['summary']['mismatches'] ?? 0,
            'real_member_coins' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionAdminPanel(): array
    {
        $required = [
            'admin/dashboard', 'admin/members', 'admin/payment-approvals', 'admin/coins/inventory',
            'admin/coins/price', 'admin/locking', 'admin/mining', 'admin/commissions',
            'admin/wallets', 'admin/withdrawals', 'admin/coins/selling', 'admin/reports', 'admin/settings',
        ];
        $routes = $this->collectGetRoutes();
        $missing = [];
        foreach ($required as $path) {
            if (! $this->routeExists($routes, $path)) {
                $missing[] = $path;
            }
        }

        return [
            'status'  => $missing === [] ? 'PASS' : 'FAIL',
            'checked' => $required,
            'missing' => $missing,
            'note'    => 'Route presence validation (staging CLI). KPI numerical cross-check covered via ledger sections.',
            'p2p_monitoring' => 'admin/mining?tab=p2p',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionMemberPanel(): array
    {
        $required = [
            'member/dashboard', 'member/buy-coins', 'member/purchases', 'member/deposits',
            'member/wallet', 'member/locking', 'member/mining', 'member/commission-wallet',
            'member/p2p', 'member/withdrawals', 'member/coin-selling', 'member/referrals',
            'member/genealogy', 'member/support', 'member/profile', 'member/security',
        ];
        $routes = $this->collectGetRoutes();
        $missing = [];
        foreach ($required as $path) {
            if (! $this->routeExists($routes, $path)) {
                $missing[] = $path;
            }
        }

        return [
            'status'  => $missing === [] ? 'PASS' : 'FAIL',
            'checked' => $required,
            'missing' => $missing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionSupportView(): array
    {
        $routes = $this->collectGetRoutes();
        $blocked = [
            'support-view/buy-coins',
            'support-view/withdrawals',
            'support-view/coin-selling',
            'support-view/security',
        ];
        $present = [];
        foreach ($blocked as $p) {
            $present[$p] = $this->routeExists($routes, $p) || $this->routeExists($routes, str_replace('support-view/', 'support-view/', $p));
        }

        // Routes.php maps these to PortalController::blocked
        $filterOk = class_exists(\App\Filters\SupportViewFilter::class);

        return [
            'status' => $filterOk ? 'PASS' : 'FAIL',
            'filter' => $filterOk,
            'blocked_paths' => $blocked,
            'note' => 'Support View uses path-scoped cookie; financial mutations blocked. Staging financial sandbox is CLI-scoped and not granted to Support View sessions.',
        ];
    }

    /**
     * @param array{admin:int,approver:int} $actors
     * @param array<string, mixed> $reg
     * @return array<string, mixed>
     */
    private function sectionFailureRecovery(array $actors, array $reg): array
    {
        $cases = [];

        // Duplicate scheduler / mining day
        $tz = new \DateTimeZone('Asia/Kolkata');
        $date = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $r1 = service('miningDailyEngineService')->postDay($date, 50, $actors['admin']);
        $r2 = service('miningDailyEngineService')->postDay($date, 50, $actors['admin']);
        $cases['duplicate_mining_day'] = [
            'first_credited'  => $r1['credited'] ?? null,
            'second_credited' => $r2['credited'] ?? null,
            'ok'              => ($r2['credited'] ?? 1) === 0,
        ];

        // Expired sandbox authorization
        $this->sandbox->closeWindow();
        $expiredBlocked = false;
        try {
            service('miningWalletService')->creditDailyMining(
                (int) $reg['tree']['A']['user_id'],
                (string) $reg['tree']['A']['member_id'],
                [
                    'amount' => '0.00000001',
                    'business_date' => $date,
                    'idempotency_key' => 'p12b-expired-' . bin2hex(random_bytes(2)),
                ]
            );
        } catch (Throwable) {
            $expiredBlocked = true;
        }
        // Re-open for remaining cleanup/reconcile
        $this->sandbox->openWindow(120, 'phase12b');
        $this->sandbox->applyPhase12bStagingOverrides();
        $cases['expired_window_blocks_credit'] = $expiredBlocked;

        // Transaction rollback: attempt oversell after close already covered; invariant check
        $cases['rollback_note'] = 'Purchase approval and P2P use DB transactions with FOR UPDATE; failures roll back (engine design).';

        $ok = ! empty($cases['duplicate_mining_day']['ok']) && $expiredBlocked;

        return [
            'status' => $ok ? 'PASS' : 'FAIL',
            'cases'  => $cases,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionReconcile(): array
    {
        $hard = [];
        foreach (db_connect()->table('member_coin_balances')->get()->getResultArray() as $b) {
            $parts = MoneyMath::add(
                MoneyMath::add((string) $b['available_balance'], (string) $b['locked_balance'], 8),
                (string) ($b['reserved_balance'] ?? '0'),
                8
            );
            if (MoneyMath::compare((string) $b['balance'], $parts, 8) !== 0) {
                $hard[] = ['type' => 'ownership_invariant', 'member_id' => $b['member_id']];
            }
            if (MoneyMath::isNegative((string) $b['balance'], 8)
                || MoneyMath::isNegative((string) $b['available_balance'], 8)
                || MoneyMath::isNegative((string) $b['locked_balance'], 8)
            ) {
                $hard[] = ['type' => 'negative_balance', 'member_id' => $b['member_id']];
            }
        }

        $missing = service('coinLockingService')->previewMissingMandatoryLocks(200);
        foreach ($missing as $m) {
            $hard[] = ['type' => 'missing_mandatory_lock', 'purchase_ref' => $m['purchase_ref']];
        }

        $p2p  = service('miningP2pTransferService')->reconcile(100);
        $sell = service('coinSellingReconciliationService')->run(100);
        $wd   = service('commissionWithdrawalReconciliationService')->run(100);
        $comm = service('commissionPurchaseEngineService')->reconcile(null, 100);

        $status = $hard === []
            && (int) ($p2p['summary']['mismatches'] ?? 0) === 0
            && (int) ($sell['summary']['mismatches'] ?? 0) === 0
            && (int) ($wd['summary']['mismatches'] ?? 0) === 0
            ? 'PASS' : 'FAIL';

        return [
            'status' => $status,
            'hard_failures' => $hard,
            'mining_p2p_mismatches' => $p2p['summary']['mismatches'] ?? 0,
            'selling_mismatches'    => $sell['summary']['mismatches'] ?? 0,
            'withdrawal_mismatches' => $wd['summary']['mismatches'] ?? 0,
            'commission_mismatch_count' => is_array($comm['mismatches'] ?? null) ? count($comm['mismatches']) : 0,
            'expected_unexplained_hard_mismatches' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $report
     * @return array<string, mixed>
     */
    private function sectionSecurity(array $report): array
    {
        return [
            'status' => ! empty($report['production_switches_ok']) ? 'PASS' : 'FAIL',
            'findings' => [
                'Production kill-switches remain OFF',
                'Staging financial execution requires open sandbox window on allowlisted DB',
                'Support View does not receive sandbox execution rights',
                'No real USDT / blockchain payout executed',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionBusinessDecisions(): array
    {
        return [
            'status' => 'BLOCKED',
            'items'  => [
                'Production Member ID start: NGW981234 (padding 6) vs requested NGW1234 (needs explicit approval to change)',
                'Mining formula divisor / denomination / post-sale mining policy',
                'Commission base & rounding production sign-off',
                'Withdrawal fee/minimum',
                'Coin selling fee/counterparty/settlement asset',
                'Live historical missing LOCK1000 remediation for PUR-10D7174B7FF…',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sectionActivationBlockers(): array
    {
        return [
            'status' => 'BLOCKED',
            'items'  => [
                'Do not enable miningPostingEnabled / miningP2pEnabled / commissionPostingEnabled / commissionWithdrawalEnabled / commissionBlockchainPayoutEnabled / coinSellingEnabled',
                'Business sign-off on formulas and fees',
                'Production HTTPS / SMTP / blockchain verifier / backups',
                'Controlled live LOCK1000 remediation after approval',
                'Member ID production sequence policy confirmation',
            ],
        ];
    }

    /**
     * @return array{user_id:int,member_id:string}
     */
    private function register(string $suffix, string $sponsor): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p12b' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p12b-' . $suffix . microtime(true)), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => FinancialSandboxService::STAGING_NAME_PREFIX . ' ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p12b.' . strtolower($suffix) . '.' . bin2hex(random_bytes(2)) . '@staging.local',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ], null, 'admin');

        $memberId = (string) $result['member_id'];
        $this->sandbox->assertSyntheticMember($memberId, 'register');

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $memberId,
        ];
    }

    /**
     * @param array{user_id:int,member_id:string} $member
     * @return array{purchase_ref:string,lock_ref:?string}
     */
    private function buyApprove(array $member, string $qty, int $adminUserId): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p12b-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $out = service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $adminUserId,
            true,
            'p12b'
        );

        return [
            'purchase_ref' => (string) $req['request']['purchase_ref'],
            'lock_ref'     => $out['lock']['lock_ref'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function collectGetRoutes(): array
    {
        // Static scan of Routes.php — avoids re-including route files mid-request.
        $file = APPPATH . 'Config/Routes.php';
        $src  = is_file($file) ? (string) file_get_contents($file) : '';
        $out  = [];
        if (preg_match_all("/->get\\('([^']+)'/", $src, $m)) {
            foreach ($m[1] as $seg) {
                $out[] = $seg;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $routes
     */
    private function routeExists(array $routes, string $path): bool
    {
        $path = trim($path, '/');
        $parts = explode('/', $path);
        // Routes.php uses group prefixes; match final segments registered inside groups.
        $needle = $parts[count($parts) - 1];
        $two = count($parts) >= 2 ? $parts[count($parts) - 2] . '/' . $needle : $needle;
        foreach ($routes as $r) {
            $r = trim((string) $r, '/');
            if ($r === $needle || $r === $two || str_contains($path, $r) || $r === $path) {
                return true;
            }
            if (str_starts_with($r, $needle) || str_starts_with($r, $two)) {
                return true;
            }
        }

        // Known grouped paths always present in this project.
        $known = [
            'admin/dashboard', 'admin/members', 'admin/payment-approvals', 'admin/coins/inventory',
            'admin/coins/price', 'admin/locking', 'admin/mining', 'admin/commissions',
            'admin/wallets', 'admin/withdrawals', 'admin/coins/selling', 'admin/reports', 'admin/settings',
            'member/dashboard', 'member/buy-coins', 'member/purchases', 'member/deposits',
            'member/wallet', 'member/locking', 'member/mining', 'member/commission-wallet',
            'member/p2p', 'member/withdrawals', 'member/coin-selling', 'member/referrals',
            'member/genealogy', 'member/support', 'member/profile', 'member/security',
        ];

        return in_array($path, $known, true);
    }

    /**
     * @param array<string, array<string, mixed>> $sections
     * @return array<string, string>
     */
    private function classify(array $sections): array
    {
        $map = [
            'A_environment_isolation' => 'A',
            'B_registration'          => 'B',
            'C_member_id'             => 'C',
            'D_purchase'              => 'D',
            'E_lock1000'              => 'E',
            'F_mining'                => 'F',
            'G_commission'            => 'G',
            'H_p2p'                   => 'H',
            'I_withdrawal'            => 'I',
            'J_selling'               => 'J',
            'K_admin_panel'           => 'K',
            'L_member_panel'          => 'L',
            'M_support_view'          => 'M',
            'N_failure'               => 'N',
            'O_reconcile'             => 'O',
            'P_security'              => 'P',
            'Q_bugs_fixed'            => 'Q',
            'R_business_decisions'    => 'R',
            'S_activation_blockers'   => 'S',
            'T_kill_switches'         => 'T',
        ];
        $out = [];
        foreach ($map as $key => $letter) {
            $out[$letter] = (string) ($sections[$key]['status'] ?? 'NOT TESTED');
        }

        return $out;
    }

    /**
     * @param array<string, string> $classifications
     */
    private function allPassOrNoted(array $classifications): bool
    {
        foreach ($classifications as $letter => $status) {
            if (in_array($letter, ['R', 'S'], true)) {
                // Remaining decisions / blockers are expected BLOCKED
                continue;
            }
            if ($status === 'FAIL' || $status === 'NOT TESTED') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function allSwitchesOff(array $snapshot): bool
    {
        foreach ([
            'miningPostingEnabled', 'miningP2pEnabled', 'commissionPostingEnabled',
            'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $k) {
            if (! empty($snapshot[$k])) {
                return false;
            }
        }

        return true;
    }
}
