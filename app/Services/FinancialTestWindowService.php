<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Database;
use Config\Platform;
use RuntimeException;
use Throwable;

/**
 * Phase 12A orchestrated 120-second financial engine test on an isolated DB.
 */
class FinancialTestWindowService
{
    public function __construct(
        private readonly FinancialSandboxService $sandbox = new FinancialSandboxService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(int $seconds = 120): array
    {
        $seconds = max(1, min(300, $seconds));
        $report  = [
            'phase'                    => '12A',
            'started_at'               => date('c'),
            'seconds_authorized'       => $seconds,
            'environment'              => [],
            'switches_before'          => [],
            'switches_after'           => [],
            'audit_findings'           => $this->auditFindings(),
            'steps'                    => [],
            'reconciliation'           => [],
            'errors'                   => [],
            'production_switches_ok'   => false,
            'success'                  => false,
        ];

        try {
            $this->connectIsolatedDatabase();
            $report['environment'] = $this->sandbox->inspectConnection('phase12a');
            $this->sandbox->assertIsolatedDatabase('phase12a');

            $window = $this->sandbox->openWindow($seconds, 'phase12a');
            $report['window'] = $window;
            $this->sandbox->applySandboxPlatformOverrides();
            $report['switches_before'] = $this->sandbox->productionSwitchSnapshot();

            $this->ensureSchemaSeeded();
            $actors = $this->ensureActors();
            $report['steps']['seed'] = ['ok' => true, 'actors' => array_keys($actors)];

            $hierarchy = $this->seedSyntheticHierarchy($actors);
            $report['steps']['hierarchy'] = $hierarchy['summary'];

            $commission = $this->runCommissionTest($actors, $hierarchy);
            $report['steps']['commission'] = $commission;

            $mining = $this->runMiningTest($actors, $hierarchy);
            $report['steps']['mining'] = $mining;

            $p2p = $this->runP2pTest($actors, $hierarchy, $mining);
            $report['steps']['p2p'] = $p2p;

            $withdrawal = $this->runWithdrawalTest($actors, $hierarchy, $commission);
            $report['steps']['withdrawal'] = $withdrawal;

            $selling = $this->runSellingTest($actors, $hierarchy);
            $report['steps']['selling'] = $selling;

            $report['reconciliation'] = $this->reconcileAll();
            $report['success'] = empty($report['reconciliation']['hard_failures'])
                && empty($report['errors']);
        } catch (Throwable $e) {
            $report['errors'][] = [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'class'   => $e::class,
            ];
            $report['success'] = false;
        } finally {
            $report['switches_after'] = $this->sandbox->productionSwitchSnapshot();
            $report['production_switches_ok'] = $this->allProductionSwitchesOff(
                $report['switches_after']
            );
            $this->sandbox->closeWindow();
            $report['finished_at'] = date('c');
            $report['window_closed'] = true;
        }

        return $report;
    }

    /**
     * @return list<array{finding: string, status: string, proposal: string}>
     */
    public function auditFindings(): array
    {
        return [
            [
                'finding'  => 'PUR-10D7174B7FF… on coin_platform has no mandatory lock; NGW000001 has 5 available / 0 locked',
                'status'   => 'PENDING_LIVE_REMEDIATION',
                'proposal' => 'Do not auto-fix live DB. Controlled remediation: CoinLockingService::lockMandatoryForPurchase for that purchase_ref after admin approval. Phase 12A validates LOCK1000 on synthetic purchases only.',
            ],
            [
                'finding'  => 'Approved purchase missing commission calculation / no approved plan on live DB',
                'status'   => 'SANDBOX_COVERED',
                'proposal' => 'Approve 10/5/3 plan in sandbox and post via CommissionPurchaseEngineService::postPurchase. Live plan approval remains a business decision.',
            ],
            [
                'finding'  => 'Mining formula decisions unresolved',
                'status'   => 'SANDBOX_TEMPORARY_ASSUMPTIONS',
                'proposal' => 'Sandbox uses calendar_days divisor, full_day approval policy, COIN asset, total-owned base. Not applied to production .env.',
            ],
            [
                'finding'  => 'Member ID start NGW1234 vs NGW981234',
                'status'   => 'PENDING_BUSINESS_CONFIRMATION',
                'proposal' => 'Production remains NGW981234 per prior confirmed policy. Sandbox uses SYN* prefix only. Change production start only after explicit business confirmation.',
            ],
        ];
    }

    private function connectIsolatedDatabase(): void
    {
        $dbConfig = config(Database::class);
        $dbConfig->defaultGroup = 'phase12a';
        // Force fresh connection on the isolated group (never coin_platform).
        Database::connect('phase12a', false);
        db_connect('phase12a', false);
    }

    private function ensureSchemaSeeded(): void
    {
        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
    }

    private function seed(string $seederClass): void
    {
        $seeder = \Config\Database::seeder();
        $seeder->call($seederClass);
    }

    /**
     * @return array{admin: int, approver: int}
     */
    private function ensureActors(): array
    {
        $admin = (int) ($this->profiles->findByMemberId('ROOT0001')->user_id ?? 0);
        if ($admin <= 0) {
            throw new RuntimeException('ROOT0001 missing after seed.');
        }

        $users = model(UserModel::class);
        $existing = $users->where('username', 'p12a_approver')->first();
        if ($existing !== null) {
            $approverId = (int) $existing->id;
        } else {
            $user = new User(['username' => 'p12a_approver', 'active' => 1]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
            $user->createEmailIdentity([
                'email'    => 'p12a.approver@sandbox.local',
                'password' => 'ApproverPass123!',
            ]);
            $user->addGroup('admin');
            $user->addGroup('finance_approver');
            $approverId = (int) $user->id;
        }

        service('coinPriceService')->updatePrice('1.00', $admin, 'p12a sandbox price');
        service('coinInventoryService')->addInventory(
            '100000',
            $admin,
            'p12a sandbox stock',
            null,
            'p12a-stock-' . bin2hex(random_bytes(3))
        );

        // Ensure SYN sequence starts cleanly.
        $db = db_connect();
        if ($db->tableExists('member_id_sequences')) {
            $row = $db->table('member_id_sequences')->where('prefix', 'SYN')->get()->getRowArray();
            if ($row === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => 'SYN',
                    'last_number' => 0,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
            }
        }

        return ['admin' => $admin, 'approver' => $approverId];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @return array{members: array<string, array{user_id:int,member_id:string}>, summary: array<string, mixed>}
     */
    private function seedSyntheticHierarchy(array $actors): array
    {
        // Activate each sponsor before the next downline registers.
        $l1 = $this->register('L1', 'ROOT0001');
        $this->buyApprove($l1, '10', $actors['admin']);

        $l2 = $this->register('L2', $l1['member_id']);
        $this->buyApprove($l2, '10', $actors['admin']);

        $l3 = $this->register('L3', $l2['member_id']);
        $this->buyApprove($l3, '10', $actors['admin']);

        $buyer = $this->register('BUYER', $l3['member_id']);
        $p2pPeer = $this->register('P2P', 'ROOT0001');
        $this->buyApprove($p2pPeer, '10', $actors['admin']);

        return [
            'members' => compact('l1', 'l2', 'l3', 'buyer', 'p2pPeer'),
            'summary' => [
                'l1' => $l1['member_id'],
                'l2' => $l2['member_id'],
                'l3' => $l3['member_id'],
                'buyer' => $buyer['member_id'],
                'p2p_peer' => $p2pPeer['member_id'],
            ],
        ];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @param array{members: array<string, array{user_id:int,member_id:string}>} $hierarchy
     * @return array<string, mixed>
     */
    private function runCommissionTest(array $actors, array $hierarchy): array
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'P12A Sandbox 10/5/3',
            'effective_from'  => '2020-01-01',
            'effective_to'    => '',
            'level_count'     => 3,
            'level_1_percent' => '10.00000000',
            'level_2_percent' => '5.00000000',
            'level_3_percent' => '3.00000000',
            'notes'           => 'sandbox-only',
        ], $actors['admin']);
        $plan = service('commissionPlanService')->approve($draft['plan_ref'], $actors['approver']);

        $buyer = $hierarchy['members']['buyer'];
        // 100 USDT base at $1/coin => 100 coins
        $purchase = $this->buyApprove($buyer, '100', $actors['admin']);

        $first  = service('commissionPurchaseEngineService')->postPurchase($purchase['purchase_ref'], $actors['admin']);
        $second = service('commissionPurchaseEngineService')->postPurchase($purchase['purchase_ref'], $actors['admin']);

        $expected = [
            'L1' => '10.00000000',
            'L2' => '5.00000000',
            'L3' => '3.00000000',
            'total' => '18.00000000',
        ];

        $byLevel = [];
        foreach ($first['lines'] as $line) {
            $byLevel['L' . $line['level']] = $line['amount'];
        }

        return [
            'plan_ref'           => $plan['plan_ref'],
            'purchase_ref'       => $purchase['purchase_ref'],
            'expected'           => $expected,
            'actual_by_level'    => $byLevel,
            'total_posted'       => $first['total_posted'],
            'first_posted_lines' => $first['posted_lines'],
            'second_duplicates'  => $second['duplicates'],
            'second_new_posts'   => $second['posted_lines'],
            'math_ok'            => ($byLevel['L1'] ?? '') === $expected['L1']
                && ($byLevel['L2'] ?? '') === $expected['L2']
                && ($byLevel['L3'] ?? '') === $expected['L3']
                && MoneyMath::compare($first['total_posted'], $expected['total'], 8) === 0,
            'idempotent_ok'      => $second['posted_lines'] === 0 && $second['duplicates'] >= 3,
            'lock_ref'           => $purchase['lock_ref'],
            'lock_ok'            => $purchase['lock_ref'] !== null,
        ];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @param array{members: array<string, array{user_id:int,member_id:string}>} $hierarchy
     * @return array<string, mixed>
     */
    private function runMiningTest(array $actors, array $hierarchy): array
    {
        $tz = new \DateTimeZone('Asia/Kolkata');
        $businessDate = (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $month = substr($businessDate, 0, 7);

        $draft = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
            'notes'           => 'p12a sandbox temporary assumption',
        ], $actors['admin']);
        $rate = service('miningRateService')->approve($draft['rate_ref'], $actors['approver']);

        $buyer = $hierarchy['members']['buyer'];
        $owned = service('memberCoinOwnershipService')->summaryForUser($buyer['user_id']);
        $preview = service('miningRateService')->previewAmount(
            (string) $owned['balance'],
            $rate,
            $businessDate
        );

        $first  = service('miningDailyEngineService')->postDay($businessDate, 200, $actors['admin']);
        $second = service('miningDailyEngineService')->postDay($businessDate, 200, $actors['admin']);

        $buyerPosted = service('miningWalletService')->postedAmountForDate($buyer['user_id'], $businessDate);

        return [
            'assumptions' => [
                'base'               => 'total_owned_coins_includes_locked',
                'divisor_mode'       => 'calendar_days',
                'approval_day_policy'=> 'full_day',
                'earning_asset'      => 'COIN',
                'monthly_rate'       => '3.00000000',
                'label'              => 'SANDBOX_TEMPORARY_ONLY',
            ],
            'business_date'       => $businessDate,
            'rate_ref'            => $rate['rate_ref'],
            'buyer_owned'         => $owned['balance'],
            'buyer_locked'        => $owned['locked'],
            'expected_buyer'      => $preview,
            'actual_buyer_posted' => $buyerPosted,
            'first_credited'      => $first['credited'],
            'second_duplicates'   => $second['duplicates'],
            'second_credited'     => $second['credited'],
            'math_ok'             => MoneyMath::compare($buyerPosted, $preview, 8) === 0,
            'idempotent_ok'       => $second['credited'] === 0,
            'includes_locked'     => MoneyMath::compare((string) $owned['locked'], '0', 8) === 1,
        ];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @param array{members: array<string, array{user_id:int,member_id:string}>} $hierarchy
     * @param array<string, mixed> $mining
     * @return array<string, mixed>
     */
    private function runP2pTest(array $actors, array $hierarchy, array $mining): array
    {
        $sender = $hierarchy['members']['buyer'];
        $receiver = $hierarchy['members']['p2pPeer'];
        $amount = '0.00100000';

        $preview = service('miningP2pTransferService')->preview($sender['user_id'], $receiver['member_id'], $amount);
        $beforeSender = service('miningWalletService')->balanceFor($sender['user_id']);
        $beforeRecv   = service('miningWalletService')->balanceFor($receiver['user_id']);

        $idem = 'p12a-p2p-' . $sender['user_id'] . '-' . bin2hex(random_bytes(2));
        $tx = service('miningP2pTransferService')->performAtomicTransfer($preview, $idem, $sender['user_id']);

        $dupError = null;
        try {
            service('miningP2pTransferService')->performAtomicTransfer($preview, $idem, $sender['user_id']);
        } catch (Throwable $e) {
            $dupError = $e->getMessage();
        }

        $afterSender = service('miningWalletService')->balanceFor($sender['user_id']);
        $afterRecv   = service('miningWalletService')->balanceFor($receiver['user_id']);
        $reconcile   = service('miningP2pTransferService')->reconcile(50);

        return [
            'transfer_ref'     => $tx['transfer_ref'] ?? ($tx['ref'] ?? null),
            'amount'           => $amount,
            'sender_before'    => $beforeSender['available_balance'],
            'sender_after'     => $afterSender['available_balance'],
            'receiver_before'  => $beforeRecv['available_balance'],
            'receiver_after'   => $afterRecv['available_balance'],
            'debit_ok'         => MoneyMath::compare(
                MoneyMath::sub($beforeSender['available_balance'], $amount, 8),
                $afterSender['available_balance'],
                8
            ) === 0,
            'credit_ok'        => MoneyMath::compare(
                MoneyMath::add($beforeRecv['available_balance'], $amount, 8),
                $afterRecv['available_balance'],
                8
            ) === 0,
            'duplicate_blocked'=> $dupError !== null,
            'reconcile_mismatches' => $reconcile['summary']['mismatches'] ?? count($reconcile['mismatches'] ?? []),
        ];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @param array{members: array<string, array{user_id:int,member_id:string}>} $hierarchy
     * @param array<string, mixed> $commission
     * @return array<string, mixed>
     */
    private function runWithdrawalTest(array $actors, array $hierarchy, array $commission): array
    {
        $l1 = $hierarchy['members']['l1'];
        $draft = service('commissionWithdrawalService')->createDraft($l1['user_id'], $l1['member_id'], [
            'amount'          => '1.00000000',
            'network'         => 'BEP20',
            'idempotency_key' => 'p12a-wd-' . $l1['user_id'] . '-' . bin2hex(random_bytes(2)),
        ]);

        $submitted = service('commissionWithdrawalService')->performSubmitWithReserve(
            (string) $draft['withdrawal_ref'],
            $l1['user_id']
        );

        // Rejection path on a second draft.
        $draft2 = service('commissionWithdrawalService')->createDraft($l1['user_id'], $l1['member_id'], [
            'amount'          => '0.50000000',
            'network'         => 'BEP20',
            'idempotency_key' => 'p12a-wd-rej-' . $l1['user_id'] . '-' . bin2hex(random_bytes(2)),
        ]);
        service('commissionWithdrawalService')->performSubmitWithReserve(
            (string) $draft2['withdrawal_ref'],
            $l1['user_id']
        );
        $rejected = service('commissionWithdrawalService')->performRejectWithRelease(
            (string) $draft2['withdrawal_ref'],
            $actors['admin'],
            'p12a sandbox reject test'
        );

        $approved = service('commissionWithdrawalService')->performApprove(
            (string) $draft['withdrawal_ref'],
            $actors['admin']
        );

        $evidence = service('commissionWithdrawalService')->performRecordManualPayout(
            (string) $draft['withdrawal_ref'],
            $actors['admin'],
            [
                'tx_hash' => '0x' . str_repeat('ab', 32),
                'notes'   => 'p12a mock payout — not real USDT',
            ]
        );

        // Do NOT mark PAID on any live path; sandbox may verify with mock if available.
        $paid = null;
        try {
            if (method_exists(service('commissionWithdrawalService'), 'performVerifyAndMarkPaid')) {
                // Explicitly skip live PAID marking — document mock-only evidence validation.
                $paid = ['skipped' => true, 'reason' => 'Phase12A does not mark PAID; evidence recorded only'];
            }
        } catch (Throwable $e) {
            $paid = ['error' => $e->getMessage()];
        }

        $wdReconcile = service('commissionWithdrawalReconciliationService')->run(50);

        return [
            'submit_status'   => $submitted['status'] ?? null,
            'reject_status'   => $rejected['status'] ?? null,
            'approve_status'  => $approved['status'] ?? null,
            'evidence_ref'    => $evidence['evidence_ref'] ?? ($evidence['payout_evidence_ref'] ?? null),
            'paid'            => $paid,
            'reconcile_mismatches' => $wdReconcile['summary']['mismatches'] ?? ($wdReconcile['mismatches'] ?? 0),
            'real_usdt_sent'  => false,
        ];
    }

    /**
     * @param array{admin: int, approver: int} $actors
     * @param array{members: array<string, array{user_id:int,member_id:string}>} $hierarchy
     * @return array<string, mixed>
     */
    private function runSellingTest(array $actors, array $hierarchy): array
    {
        $seller = $this->register('SELLER', 'ROOT0001');
        $purchase = $this->buyApprove($seller, '20', $actors['admin']);

        // Force maturity for sandbox lot only.
        if (! empty($purchase['lock_ref'])) {
            $past = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))
                ->modify('-1001 days')
                ->format('Y-m-d H:i:s');
            $matureAt = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))
                ->modify('-1 day')
                ->format('Y-m-d H:i:s');
            db_connect()->table('member_coin_locks')->where('lock_ref', $purchase['lock_ref'])->update([
                'locked_at'  => $past,
                'matures_at' => $matureAt,
                'status'     => CoinLockingService::STATUS_MATURED,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $ownedBefore = service('memberCoinOwnershipService')->summaryForUser($seller['user_id']);
        $row = service('coinSellingService')->performSubmitWithReserve(
            $seller['user_id'],
            $purchase['purchase_ref'],
            '5.00000000',
            'p12a-sell-' . $seller['user_id']
        );
        service('coinSellingService')->performApprove((string) $row['sell_ref'], $actors['admin']);
        service('coinSellingService')->performRecordSettlement((string) $row['sell_ref'], $actors['admin'], [
            'evidence_ref'      => 'EV-P12A-' . $seller['user_id'],
            'settlement_asset'  => 'USDT',
            'settlement_amount' => '5.00',
        ]);
        $completed = service('coinSellingService')->performCompleteWithVerifiedSettlement(
            (string) $row['sell_ref'],
            $actors['admin'],
            true
        );

        $ownedAfter = service('memberCoinOwnershipService')->summaryForUser($seller['user_id']);
        $sellReconcile = service('coinSellingReconciliationService')->run(50);

        $dupBlocked = false;
        try {
            service('coinSellingService')->performSubmitWithReserve(
                $seller['user_id'],
                $purchase['purchase_ref'],
                '5.00000000',
                'p12a-sell-' . $seller['user_id']
            );
        } catch (Throwable) {
            $dupBlocked = true;
        }

        return [
            'sell_ref'            => $row['sell_ref'] ?? null,
            'status'              => $completed['status'] ?? null,
            'owned_before'        => $ownedBefore['balance'],
            'owned_after'         => $ownedAfter['balance'],
            'debit_ok'            => MoneyMath::compare(
                MoneyMath::sub((string) $ownedBefore['balance'], '5.00000000', 8),
                (string) $ownedAfter['balance'],
                8
            ) === 0,
            'duplicate_blocked'   => $dupBlocked,
            'reconcile_mismatches'=> $sellReconcile['summary']['mismatches'] ?? ($sellReconcile['mismatches'] ?? 0),
            'real_member_coins'   => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reconcileAll(): array
    {
        $hard = [];
        $notes = [];

        $balances = db_connect()->table('member_coin_balances')->get()->getResultArray();
        foreach ($balances as $b) {
            $parts = MoneyMath::add(
                MoneyMath::add((string) $b['available_balance'], (string) $b['locked_balance'], 8),
                (string) ($b['reserved_balance'] ?? '0'),
                8
            );
            if (MoneyMath::compare((string) $b['balance'], $parts, 8) !== 0) {
                $hard[] = [
                    'type'      => 'ownership_invariant',
                    'member_id' => $b['member_id'],
                    'balance'   => $b['balance'],
                    'parts'     => $parts,
                ];
            }
            if (! str_starts_with(strtoupper((string) $b['member_id']), 'SYN')) {
                $hard[] = [
                    'type'      => 'non_synthetic_balance',
                    'member_id' => $b['member_id'],
                ];
            }
        }

        $missingLocks = service('coinLockingService')->previewMissingMandatoryLocks(50);
        foreach ($missingLocks as $m) {
            if (str_starts_with(strtoupper((string) $m['member_id']), 'SYN')) {
                $hard[] = ['type' => 'missing_mandatory_lock', 'purchase_ref' => $m['purchase_ref']];
            } else {
                $notes[] = ['type' => 'ignored_non_synthetic_missing_lock', 'purchase_ref' => $m['purchase_ref']];
            }
        }

        $mining = service('miningP2pTransferService')->reconcile(50);
        $sell   = service('coinSellingReconciliationService')->run(50);
        $wd     = service('commissionWithdrawalReconciliationService')->run(50);
        $comm   = service('commissionPurchaseEngineService')->reconcile(null, 50);

        return [
            'ownership_rows'           => count($balances),
            'hard_failures'            => $hard,
            'notes'                    => $notes,
            'mining_p2p_mismatches'    => $mining['summary']['mismatches'] ?? 0,
            'selling_mismatches'       => $sell['summary']['mismatches'] ?? 0,
            'withdrawal_mismatches'    => $wd['summary']['mismatches'] ?? 0,
            'commission_mismatches'    => is_array($comm['mismatches'] ?? null) ? count($comm['mismatches']) : 0,
        ];
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function register(string $suffix, string $sponsor): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p12a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p12a-' . $suffix . microtime(true)), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => FinancialSandboxService::SYNTHETIC_NAME_PREFIX . ' ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '19' . substr($digits . '00000000', 0, 8),
            'email'       => 'p12a.' . strtolower($suffix) . '.' . bin2hex(random_bytes(2)) . '@sandbox.local',
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
     * @param array{user_id: int, member_id: string} $member
     * @return array{purchase_ref: string, lock_ref: ?string}
     */
    private function buyApprove(array $member, string $qty, int $adminUserId): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p12a-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $out = service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $adminUserId,
            true,
            'p12a'
        );

        return [
            'purchase_ref' => (string) $req['request']['purchase_ref'],
            'lock_ref'     => $out['lock']['lock_ref'] ?? ($out['mandatory_lock_ref'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    private function allProductionSwitchesOff(array $snapshot): bool
    {
        foreach ([
            'miningPostingEnabled',
            'miningP2pEnabled',
            'commissionPostingEnabled',
            'commissionWithdrawalEnabled',
            'commissionBlockchainPayoutEnabled',
            'coinSellingEnabled',
        ] as $key) {
            if (! empty($snapshot[$key])) {
                return false;
            }
        }

        return true;
    }
}
