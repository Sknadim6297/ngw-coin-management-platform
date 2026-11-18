<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\CommissionPostingRunnerService;
use App\Services\FinancialSandboxService;
use App\Services\MiningPostingRunnerService;
use App\Services\MiningRecoveryService;
use App\Services\ProductionSafetyGate;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 14 — Focused tests for recovery, append-only commission revisions,
 * purchase-event posting, interruption/resume, kill-switches, and DB safety gates.
 *
 * @internal
 */
final class Phase14RecoveryAndAutomationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'mining_recovery_proposal_items', 'mining_recovery_proposals',
            'mining_post_members', 'mining_post_day_runs', 'mining_daily_calculations', 'mining_daily_runs',
            'mining_wallet_ledger', 'mining_wallets', 'mining_rate_approval_events', 'mining_rate_configs',
            'commission_post_items', 'commission_post_batches', 'commission_calculation_revisions',
            'commission_purchase_calculations', 'commission_wallet_ledger_entries', 'commission_wallet_accounts',
            'commission_plan_approval_events', 'commission_plan_levels', 'commission_plans',
            'purchase_sponsor_snapshots', 'member_coin_lock_events', 'member_coin_locks',
            'member_coin_ownership_transactions', 'member_coin_balances', 'coin_purchase_requests',
            'coin_inventory_transactions', 'coin_price_history',
            'sponsor_relationships', 'member_profiles', 'member_id_sequences',
            'auth_identities', 'auth_groups_users', 'auth_logins', 'users', 'audit_logs', 'ops_job_locks',
        ] as $tbl) {
            if ($db->tableExists($tbl)) {
                $db->query("TRUNCATE TABLE `$tbl`");
            }
        }
        if ($db->table('coin_settings')->countAllResults() === 0) {
            $db->query("INSERT INTO coin_settings (id, current_price_usdt, min_purchase_usdt, created_at, updated_at) VALUES (1, NULL, '5.00000000', NOW(), NOW())");
        }
        if ($db->table('coin_inventory')->countAllResults() === 0) {
            $db->query("INSERT INTO coin_inventory (id, available_balance, total_added, total_allocated, created_at, updated_at) VALUES (1, '0.00000000', '0.00000000', '0.00000000', NOW(), NOW())");
        }
        $db->query("UPDATE coin_inventory SET available_balance='0.00000000', total_added='0.00000000', total_allocated='0.00000000' WHERE id=1");
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $p = config('Platform');
        $p->adminEmail                          = 'admin@test.local';
        $p->adminPassword                       = 'AdminPass123!';
        $p->adminName                           = 'Test Admin';
        $p->rootSponsorId                       = 'ROOT0001';
        $p->memberIdPrefix                      = 'NGW';
        $p->memberIdPadding                     = 6;
        $p->memberIdStartNumber                 = 1;
        $p->requireVerifiedDepositForApproval   = false;
        $p->mandatoryPurchaseLockEnabled        = true;
        $p->miningPostingEnabled                = false;
        $p->miningAutoPostEnabled               = false;
        $p->miningP2pEnabled                    = false;
        $p->commissionPostingEnabled            = false;
        $p->commissionAutoPostOnApprovalEnabled = false;
        $p->commissionWithdrawalEnabled         = false;
        $p->commissionBlockchainPayoutEnabled   = false;
        $p->coinSellingEnabled                  = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('0.05', $this->adminUserId, 'p14 price');
        service('coinInventoryService')->addInventory('3000000', $this->adminUserId, 'p14 stock', null, 'p14-stock-' . bin2hex(random_bytes(3)));
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p14approver', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'p14.approver@test.local',
            'password' => 'ApproverPass123!',
        ]);
        $user->addGroup('admin', 'finance_approver');

        return (int) $user->id;
    }

    /** @return array{user_id: int, member_id: string} */
    private function register(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('p14' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p14-' . $suffix), 0, 40), 40, '0');
        $res    = service('registrationService')->register([
            'member_name' => 'P14 ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '19' . substr($digits . '00000000', 0, 8),
            'email'       => 'p14_' . $suffix . '@example.test',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return ['user_id' => (int) $res['user']->id, 'member_id' => (string) $res['member_id']];
    }

    private function buyApprove(array $member, string $coins, string $reviewedAtUtc): string
    {
        $req  = service('coinPurchaseService')->createRequest($member['user_id'], $coins, null, 'p14-' . $member['user_id'] . '-' . bin2hex(random_bytes(3)));
        $pRef = (string) $req['request']['purchase_ref'];
        service('coinPurchaseService')->approveRequest($pRef, $this->adminUserId);

        // Pin deterministic reviewed_at + ledger created_at for date-boundary testing (test DB only)
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['reviewed_at' => $reviewedAtUtc]);
        db_connect()->table('member_coin_ownership_transactions')->where('purchase_ref', $pRef)->update(['created_at' => $reviewedAtUtc]);
        $lock = db_connect()->table('member_coin_locks')->where('purchase_ref', $pRef)->get()->getRowArray();
        if ($lock !== null) {
            db_connect()->table('member_coin_ownership_transactions')->where('lock_ref', (string) $lock['lock_ref'])->update(['created_at' => $reviewedAtUtc]);
        }

        return $pRef;
    }

    public function testProductionSafetyGateAndSandboxClassifyNgDatabaseAsLiveAndPhase14AsIsolated(): void
    {
        $this->assertContains('coin_platform', ProductionSafetyGate::liveDatabases());
        $this->assertContains('ng_database', ProductionSafetyGate::liveDatabases());

        $sandbox = new FinancialSandboxService();
        $this->assertTrue($sandbox->inspectConnection('phase14')['is_allowed']);
        $this->assertFalse($sandbox->inspectConnection('phase14')['is_forbidden']);
        $this->assertFalse($sandbox->inspectConnection('phase14_snapshot')['is_allowed']);
        $this->assertTrue($sandbox->inspectConnection('phase14_snapshot')['is_forbidden']);

        $runner = new MiningPostingRunnerService();
        $this->assertSame('live', $runner->classify('default')['mode'] ?? 'live');
    }

    public function testAllEightFinancialKillSwitchesAreOffAndLivePostingIsRefused(): void
    {
        $p = config('Platform');
        foreach ([
            'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled',
            'commissionPostingEnabled', 'commissionAutoPostOnApprovalEnabled',
            'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $flag) {
            $this->assertFalse((bool) $p->{$flag}, $flag);
        }

        $this->expectException(RuntimeException::class);
        (new CommissionPostingRunnerService())->authorize(['mode' => 'live', 'database' => 'ng_database', 'group' => 'default'], false, true, 'I_AUTHORIZE_PRODUCTION_CHANGE');
    }

    public function testFiltersDisablesToolbarInProductionAndPreflightDetectsMisconfiguredBaseUrl(): void
    {
        $filtersSrc = (string) file_get_contents(APPPATH . 'Config/Filters.php');
        $this->assertStringContainsString("ENVIRONMENT === 'production'", $filtersSrc);

        $app = config('App');
        $old = $app->baseURL;
        $app->baseURL = 'https://ngw.in.net/';
        try {
            $preflight = service('deploymentPreflightService')->run();
            $byId = array_column($preflight['checks'], null, 'id');
            $this->assertSame('fail', $byId['canonical_url_and_debug']['status']);
            $this->assertTrue($byId['canonical_url_and_debug']['blocker']);
        } finally {
            $app->baseURL = $old;
        }
    }

    public function testAppendOnlyRevisionsInterruptResumeIdempotencyAndPostedAmountFix(): void
    {
        $m1 = $this->register('m1');
        $p1 = $this->buyApprove($m1, '100', '2026-11-17 10:00:00');
        $m2 = $this->register('m2', $m1['member_id']);
        $p2 = $this->buyApprove($m2, '200', '2026-11-17 12:00:00');

        // Both purchases occurred before any approved plan -> 6 NO_APPROVED_PLAN rows
        $origNoPlan = db_connect()->table('commission_purchase_calculations')->where('status', 'NO_APPROVED_PLAN')->countAllResults();
        $this->assertSame(6, $origNoPlan);

        // Dual-approve 10/5/3 plan effective 2026-11-17
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'P14 10/5/3',
            'effective_from'  => '2026-11-17',
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);
        $plan = service('commissionPlanService')->approve((string) $draft['plan_ref'], $this->approverUserId);

        $runner = new CommissionPostingRunnerService();
        $rev    = $runner->generateSupersedingRevisions((string) $plan['plan_ref'], true, true, $this->approverUserId);
        $this->assertSame(6, $rev['revisions_created']);
        // p1 ($5 * 10% ROOT0001 = 0.50) + p2 ($10 * 10% m1 = 1.00 + $10 * 5% ROOT0001 = 0.50) = 2.00 USDT
        $this->assertSame('2.00000000', $rev['expected_total_usdt']);
        // Original NO_APPROVED_PLAN rows MUST remain untouched
        $this->assertSame(6, db_connect()->table('commission_purchase_calculations')->where('status', 'NO_APPROVED_PLAN')->countAllResults());

        // Enable commission posting in isolated PHPUnit DB only
        $p = config('Platform');
        $p->commissionPostingEnabled = true;
        try {
            // Interrupt after batch 1 (batch_size=2 => posts 2 items, leaves 1 pending -> PARTIAL)
            $part = $runner->run(['group' => 'tests', 'execute' => true, 'plan_ref' => $plan['plan_ref'], 'batch_size' => 2, 'max_batches' => 1]);
            $this->assertSame('partial', $part['status']);
            $this->assertSame(2, $part['posted_now']);

            // Resume same batch -> completes remaining 1 item
            $res = $runner->run(['group' => 'tests', 'execute' => true, 'plan_ref' => $plan['plan_ref'], 'batch_size' => 2]);
            $this->assertSame('completed', $res['status']);
            $this->assertSame(1, $res['posted_now']);
            $this->assertSame($part['batch_ref'], $res['batch_ref']);

            // Duplicate re-run -> 0 new credits
            $dup = $runner->run(['group' => 'tests', 'execute' => true, 'plan_ref' => $plan['plan_ref']]);
            $this->assertSame('completed', $dup['status']);
            $this->assertSame(0, $dup['posted_now']);
            $this->assertSame('2.00000000', $dup['total_posted_usdt']);

            // Verify fixed postedAmountForLine() + reconcile() returns 0 mismatches
            $calcP2 = service('commissionPurchaseEngineService')->calculateForPurchase($p2, false);
            $this->assertSame('1.50000000', $calcP2['totals']['posted_amount_usdt']);
            $rec = service('commissionPurchaseEngineService')->reconcile(null, 50);
            $this->assertSame(0, $rec['summary']['mismatches']);
        } finally {
            $p->commissionPostingEnabled = false;
        }
    }

    public function testPurchaseEventRespectsAutoPostSwitchAndPostsOnlyWhenExplicitlyAuthorized(): void
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'P14 Event Plan',
            'effective_from'  => '2026-11-01',
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);
        service('commissionPlanService')->approve((string) $draft['plan_ref'], $this->approverUserId);

        $l1    = $this->register('ev_l1');
        $this->buyApprove($l1, '100', '2026-11-17 08:00:00');
        $buyer = $this->register('ev_buyer', $l1['member_id']);

        $p = config('Platform');
        // 1. commissionPostingEnabled=true BUT commissionAutoPostOnApprovalEnabled=false -> NO auto post!
        $p->commissionPostingEnabled = true;
        $p->commissionAutoPostOnApprovalEnabled = false;
        try {
            $req  = service('coinPurchaseService')->createRequest($buyer['user_id'], '100', null, 'p14-ev1-' . bin2hex(random_bytes(2)));
            $out1 = service('coinPurchaseService')->approveRequest((string) $req['request']['purchase_ref'], $this->adminUserId);
            $this->assertNull($out1['commission_post']);
            $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());

            // 2. Enable commissionAutoPostOnApprovalEnabled=true (isolated testing DB only) -> auto-posts $0.75 ($0.50 to ev_l1 + $0.25 to ROOT0001)
            $p->commissionAutoPostOnApprovalEnabled = true;
            $req2 = service('coinPurchaseService')->createRequest($buyer['user_id'], '100', null, 'p14-ev2-' . bin2hex(random_bytes(2)));
            $out2 = service('coinPurchaseService')->approveRequest((string) $req2['request']['purchase_ref'], $this->adminUserId);
            $this->assertSame('completed', $out2['commission_post']['status'] ?? null);
            $this->assertSame('0.75000000', $out2['commission_post']['total_posted_usdt'] ?? null);
        } finally {
            $p->commissionPostingEnabled = false;
            $p->commissionAutoPostOnApprovalEnabled = false;
        }
    }

    public function testMiningRecoveryDualControlProspectiveEffectiveDateAndCalendarMonthMath(): void
    {
        // Calendar days 28/29/30/31 accuracy at 3% down
        $this->assertSame('0.10714285', MiningMath::dailyAmount('100', '3', 28, 8, MiningMath::ROUND_DOWN));
        $this->assertSame('0.10344827', MiningMath::dailyAmount('100', '3', 29, 8, MiningMath::ROUND_DOWN));
        $this->assertSame('0.10000000', MiningMath::dailyAmount('100', '3', 30, 8, MiningMath::ROUND_DOWN));
        $this->assertSame('0.09677419', MiningMath::dailyAmount('100', '3', 31, 8, MiningMath::ROUND_DOWN));

        $rDraft = service('miningRateService')->createDraft([
            'effective_month' => '2026-11',
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        service('miningRateService')->approve((string) $rDraft['rate_ref'], $this->approverUserId);

        $m1 = $this->register('min1');
        $this->buyApprove($m1, '100', '2026-11-17 10:00:00'); // 15:30 IST on 17th
        $m2 = $this->register('min2');
        $this->buyApprove($m2, '500', '2026-11-17 21:00:00'); // 02:30 IST on 18th -> NOT eligible on 18th under following IST day

        $policy = new MiningCalculationPolicy('exclude_approval_day', 'calendar_days', 'down', 'include', 'include_all');
        $rec    = new MiningRecoveryService();

        $a17 = $rec->assessDate('2026-11-17', $policy);
        $this->assertSame(0, $a17['eligible_members']);
        $this->assertSame('0.00000000', $a17['missing_coins']);

        // Backdate before effective 2026-11-18 must fail
        $backdateBlocked = false;
        try {
            $rec->createProposal('2026-11-17', $policy, $this->adminUserId, 'reserve_inventory_tracked', '2026-11-18');
        } catch (InvalidArgumentException) {
            $backdateBlocked = true;
        }
        $this->assertTrue($backdateBlocked);

        $prop = $rec->createProposal('2026-11-18', $policy, $this->adminUserId, 'reserve_inventory_tracked', '2026-11-18');
        $this->assertSame('0.10000000', $prop['missing_coins']);

        // Self-approval refused
        $selfBlocked = false;
        try {
            $rec->approveProposal((string) $prop['proposal_ref'], $this->adminUserId);
        } catch (InvalidArgumentException) {
            $selfBlocked = true;
        }
        $this->assertTrue($selfBlocked);

        $rec->approveProposal((string) $prop['proposal_ref'], $this->approverUserId);

        $p = config('Platform');
        $p->miningPostingEnabled           = true;
        $p->miningApprovalDayPolicy        = 'exclude_approval_day';
        $p->miningMonthDayDivisorMode      = 'calendar_days';
        $p->miningRoundingMode             = 'down';
        $p->miningReservedCoinPolicy       = 'include';
        $p->miningMaturityPolicy           = 'include_all';
        try {
            $exec1 = $rec->executeProposal((string) $prop['proposal_ref'], ['group' => 'tests'], $this->approverUserId);
            $this->assertSame('completed', $exec1['run']['status'] ?? null);
            $this->assertSame('0.10000000', $exec1['run']['total_credited'] ?? null);

            $exec2 = $rec->executeProposal((string) $prop['proposal_ref'], ['group' => 'tests'], $this->approverUserId);
            $this->assertTrue((bool) ($exec2['duplicate'] ?? false));
        } finally {
            $p->miningPostingEnabled      = false;
            $p->miningApprovalDayPolicy   = 'full_day';
            $p->miningRoundingMode        = 'down';
        }
    }
}
