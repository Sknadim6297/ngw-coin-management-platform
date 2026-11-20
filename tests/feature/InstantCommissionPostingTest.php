<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AutomationBlockedException;
use App\Models\MemberProfileModel;
use App\Services\CommissionPostingRunnerService;
use App\Services\CommissionWalletService;
use App\Services\FinancialAutomationService;
use App\Services\InstantCommissionService;
use App\Services\MiningProposalService;
use App\Services\ProductionSafetyGate;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;

/**
 * Instant commission posting: L1/L2/L3 commission is posted right after a valid purchase approval/activation,
 * through the SAME CommissionPostingRunnerService, behind the DB-backed "Instant Commission Posting" control.
 *
 * Runs ONLY on the isolated PHPUnit database. Nothing here touches (or can touch) the production database.
 *
 * Chain used: ROOT0001 <- A <- B <- C <- D. D's purchase pays L1 = C (10%), L2 = B (5%), L3 = A (3%).
 * A, B and C bought BEFORE instant posting was switched on; their (unposted) calculations are "history".
 *
 * @internal
 */
final class InstantCommissionPostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private const ADMIN_PASSWORD    = 'AdminPass123!';
    private const APPROVER_PASSWORD = 'ApproverPass123!';

    private int $adminUserId;
    private int $approverUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $p = config('Platform');
        $p->adminEmail                        = 'admin@test.local';
        $p->adminPassword                     = self::ADMIN_PASSWORD;
        $p->adminName                         = 'Test Admin';
        $p->rootSponsorId                     = 'ROOT0001';
        $p->memberIdPrefix                    = 'ICP';
        $p->memberIdStartNumber               = 1;
        $p->requireVerifiedDepositForApproval = false;
        $p->mandatoryPurchaseLockEnabled      = true;
        $this->resetSwitches();
        $p->miningBusinessTimezone            = 'Asia/Kolkata';
        $p->miningMonthDayDivisorMode         = 'calendar_days';
        $p->miningApprovalDayPolicy           = 'exclude_approval_day';
        $p->miningEarningAsset                = 'COIN';
        $p->miningRoundingMode                = 'down';
        $p->miningReservedCoinPolicy          = 'include';
        $p->miningMaturityPolicy              = 'include_all';
        $p->coinPlanLockDays                  = 1000;
        $p->miningPostRetryDelaySeconds       = 0;
        $p->automationDailyRunTime            = '00:00';
        $p->automationHeartbeatMaxAgeSeconds  = 1800;
        $p->automationMaxAttemptsPerDay       = 3;
        $p->automationRetryBackoffSeconds     = 900;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'icp price');
        service('coinInventoryService')->addInventory('100000', $this->adminUserId, 'icp stock', null, 'icp-stock-' . bin2hex(random_bytes(3)));

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        Services::resetSingle('instantCommissionService');
        $this->resetSwitches();
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    // ================================================================ isolation

    public function testRunsOnTheIsolatedTestDatabaseOnly(): void
    {
        $name = (string) db_connect()->getDatabase();
        $this->assertSame('coin_platform_test', $name);
        $this->assertFalse(ProductionSafetyGate::isLiveDatabase($name));
        $this->assertNotContains($name, ProductionSafetyGate::liveDatabases());
        $this->assertNotSame('wwwzeont_ngw_live_db', $name);
    }

    // ================================================================ successful instant posting

    public function testActivationPostsL1L2L3InstantlyThroughTheExistingEngine(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        $this->assertSame('inactive', $this->profileStatus($chain['d']));
        $res = $this->buyApprove($chain['d'], '100');

        // Activation happened in the existing approval flow...
        $this->assertTrue($res['out']['activated']);
        $this->assertSame('active', $this->profileStatus($chain['d']));
        $this->assertSame($res['ref'], db_connect()->table('member_profiles')->where('user_id', $chain['d']['user_id'])->get()->getRow('activation_purchase_ref'));

        // ...and the SAME engine posted the SAME plan percentages (10 / 5 / 3 of the approved USDT amount).
        $post = $res['out']['commission_post'];
        $this->assertSame('completed', $post['status']);
        $this->assertTrue($post['instant']);
        $this->assertSame('18.00000000', $post['total_posted_usdt']);

        $ledger = $this->ledgerFor($res['ref']);
        $this->assertSame([1, 2, 3], array_keys($ledger));
        $this->assertSame([$chain['c']['user_id'], '10.00000000'], $ledger[1]);
        $this->assertSame([$chain['b']['user_id'], '5.00000000'], $ledger[2]);
        $this->assertSame([$chain['a']['user_id'], '3.00000000'], $ledger[3]);
        $this->assertSame('10.00000000', $this->available($chain['c']));
        $this->assertSame('5.00000000', $this->available($chain['b']));
        // A's only commission is the L3 credit from D (A's own earlier downline purchases were history).
        $this->assertSame('3.00000000', $this->available($chain['a']));

        // Calculation rows advanced; audit trail written; ledger idempotency keys follow purchase + level.
        $this->assertSame(3, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'POSTED')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'commission.instant_posted')->where('entity_id', $res['ref'])->countAllResults());
        foreach ([1, 2, 3] as $level) {
            $this->assertSame(1, db_connect()->table('commission_wallet_ledger_entries')->where('idempotency_key', 'comm-post:' . $res['ref'] . ':L' . $level)->countAllResults());
        }

        // Pre-activation history is NOT retroactively posted: only D's three credits exist.
        $this->assertSame(3, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame(6, db_connect()->table('commission_purchase_calculations')->whereIn('purchase_ref', $chain['refs'])->where('status', 'CALCULATED_NOT_POSTED')->countAllResults());

        // Env kill-switches were only raised in-process and are restored; money-out features stay disabled.
        $this->assertEnvSwitchesOff();
    }

    public function testInstantPostingIsOffByDefaultAndPostsNothing(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();

        $res = $this->buyApprove($chain['d'], '100');

        $this->assertTrue($res['out']['activated'], 'activation itself is unchanged');
        $this->assertNull($res['out']['commission_post']);
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        // The existing post-commit calculation still happens (never posts) so the scheduler/next-day flow keeps working.
        $this->assertSame(3, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'CALCULATED_NOT_POSTED')->countAllResults());
        $this->assertEnvSwitchesOff();
    }

    // ================================================================ ineligible / missing sponsor

    public function testInactiveSponsorIsNotPaidAndMissingLevelsAreNotRedistributed(): void
    {
        $this->preparePlan();
        $f = $this->member('f');                        // sponsor of E, never buys
        // Registration requires an active sponsor; F is later inactive (e.g. suspended) when E's purchase is approved.
        db_connect()->table('member_profiles')->where('user_id', $f['user_id'])->update(['account_status' => 'active']);
        $e = $this->member('e', $f['member_id']);
        db_connect()->table('member_profiles')->where('user_id', $f['user_id'])->update(['account_status' => 'inactive']);
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        $res = $this->buyApprove($e, '100');
        $this->assertSame('inactive', $this->profileStatus($f));

        $post = $res['out']['commission_post'];
        $this->assertSame('completed', $post['status']);
        // L1 = F (inactive) -> ineligible; L2 = ROOT0001 -> 5%; L3 = nobody -> unallocated (no redistribution).
        $this->assertSame('5.00000000', $post['total_posted_usdt']);
        $ledger = $this->ledgerFor($res['ref']);
        $this->assertSame([2], array_keys($ledger));
        $this->assertSame([$this->adminUserId, '5.00000000'], $ledger[2]);
        $this->assertSame('0.00000000', $this->available($f));

        $calc = $this->calcStatuses($res['ref']);
        $this->assertSame('INELIGIBLE', $calc[1]['status']);
        $this->assertStringContainsString('sponsor_not_active', $calc[1]['reason_codes']);
        $this->assertSame('POSTED', $calc[2]['status']);
        $this->assertSame('UNALLOCATED', $calc[3]['status']);
        $this->assertStringContainsString('missing_sponsor_level_3', $calc[3]['reason_codes']);
    }

    public function testMemberWithOnlyTheRootSponsorPaysOnlyLevelOne(): void
    {
        $this->preparePlan();
        $g = $this->member('g');                        // sponsor = ROOT0001, so L2/L3 do not exist
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        $res = $this->buyApprove($g, '100');

        $this->assertSame('completed', $res['out']['commission_post']['status']);
        $this->assertSame('10.00000000', $res['out']['commission_post']['total_posted_usdt']);
        $this->assertSame([1], array_keys($this->ledgerFor($res['ref'])));
        $calc = $this->calcStatuses($res['ref']);
        $this->assertSame('UNALLOCATED', $calc[2]['status']);
        $this->assertSame('UNALLOCATED', $calc[3]['status']);
    }

    public function testPurchaseApprovedBeforeTheCutoffIsNeverPostedInstantly(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        $out = service('instantCommissionService')->postApprovedPurchase($chain['refs'][2], $this->adminUserId);

        $this->assertSame('instant_skipped', $out['status']);
        $this->assertSame('before_instant_cutoff', $out['message']);
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'commission.instant_skipped')->countAllResults());
    }

    public function testGatesAreReCheckedAtApprovalTimeAndABlockedPostingNeverUndoesTheApproval(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        // A prerequisite stops being true after the switch was authorized.
        config('Platform')->mandatoryPurchaseLockEnabled = false;
        $res = $this->buyApprove($chain['d'], '100');

        $this->assertSame('instant_blocked', $res['out']['commission_post']['status']);
        $this->assertStringContainsString('Mandatory purchase locking is disabled', $res['out']['commission_post']['message']);
        $this->assertSame('approved', db_connect()->table('coin_purchase_requests')->where('purchase_ref', $res['ref'])->get()->getRow('status'));
        $this->assertSame('active', $this->profileStatus($chain['d']));
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame(3, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'CALCULATED_NOT_POSTED')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'commission.instant_blocked')->countAllResults());
    }

    // ================================================================ duplicate retries

    public function testRetriesRefreshesAndSchedulerAfterwardsNeverDuplicateCommission(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        $res = $this->buyApprove($chain['d'], '100');
        $this->assertSame('18.00000000', $res['out']['commission_post']['total_posted_usdt']);
        $before = $this->ledgerSnapshot();

        // Admin refreshes / resubmits the approval: the request is no longer pending, nothing is re-processed.
        try {
            service('coinPurchaseService')->approveRequest($res['ref'], $this->adminUserId);
            $this->fail('A second approval must be refused.');
        } catch (InvalidArgumentException | \RuntimeException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
        $this->assertSame($before, $this->ledgerSnapshot());

        // The instant service is retried (twice): it finds nothing left to post.
        for ($i = 0; $i < 2; $i++) {
            $retry = service('instantCommissionService')->postApprovedPurchase($res['ref'], $this->adminUserId);
            $this->assertSame('completed', $retry['status']);
            $this->assertSame(0, $retry['posted_now']);
            $this->assertSame($before, $this->ledgerSnapshot());
        }

        // The scheduler runs afterwards (recovery window starts at the cutoff): still no duplicates.
        $this->startSchedulerAndCommission();
        $tick = service('financialAutomationRunnerService')->run();
        $this->assertSame('success', $tick['jobs']['commission']['status'], json_encode($tick));
        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame([], db_connect()->query('SELECT idempotency_key, COUNT(*) c FROM commission_wallet_ledger_entries GROUP BY idempotency_key HAVING c > 1')->getResultArray());
        $this->assertSame(0, (new \App\Services\CommissionWalletReconciliationService())->run()['issue_count']);
    }

    // ================================================================ partial failure / rollback / recovery

    public function testPartialFailureRollsBackEverythingAndTheSchedulerRecoversWithoutDuplicates(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        // The wallet fails on the SECOND credit (L2) -> L1 was already credited inside the transaction.
        $wallet = $this->failingWallet(2);
        Services::injectMock('instantCommissionService', new InstantCommissionService(
            null,
            new CommissionPostingRunnerService(null, null, $wallet, null, config('Platform')),
            null,
            null,
            0,
            0
        ));

        $res = $this->buyApprove($chain['d'], '100');
        $post = $res['out']['commission_post'];

        // The purchase and the activation are NOT rolled back by a commission failure.
        $this->assertSame('approved', db_connect()->table('coin_purchase_requests')->where('purchase_ref', $res['ref'])->get()->getRow('status'));
        $this->assertSame('active', $this->profileStatus($chain['d']));
        $this->assertSame('instant_failed', $post['status']);
        $this->assertGreaterThanOrEqual(2, $wallet->calls);

        // ...but the commission is all-or-nothing: no ledger row, no balance, calculations still payable.
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults(), 'L1 credit must be rolled back with L2');
        foreach (['a', 'b', 'c'] as $k) {
            $this->assertSame('0.00000000', $this->available($chain[$k]));
        }
        $this->assertSame(3, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'CALCULATED_NOT_POSTED')->countAllResults());
        $this->assertSame(0, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'POSTED')->countAllResults());
        $items = db_connect()->table('commission_post_items')->where('purchase_ref', $res['ref'])->get()->getResultArray();
        $this->assertCount(3, $items);
        foreach ($items as $it) {
            $this->assertSame('FAILED', $it['status']);
            $this->assertStringContainsString('simulated wallet failure', (string) $it['last_error']);
        }
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'commission.instant_failed')->where('entity_id', $res['ref'])->countAllResults());
        $this->assertEnvSwitchesOff();

        // Meanwhile something else already credited L1 (e.g. an earlier crashed attempt): same idempotency key.
        $this->creditLevelDirectly($res['ref'], 1, $chain['c'], '10.00000000');
        $this->assertSame(1, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());

        // Scheduler = recovery. It posts the missing levels, treats L1 as an idempotent duplicate, and never
        // touches pre-cutoff history.
        Services::resetSingle('instantCommissionService');
        $this->startSchedulerAndCommission();
        $tick = service('financialAutomationRunnerService')->run();
        $this->assertSame('success', $tick['jobs']['commission']['status'], json_encode($tick));

        $ledger = $this->ledgerFor($res['ref']);
        $this->assertSame([1, 2, 3], array_keys($ledger));
        $this->assertSame('10.00000000', $this->available($chain['c']), 'L1 credited exactly once');
        $this->assertSame('5.00000000', $this->available($chain['b']));
        $this->assertSame('3.00000000', $this->available($chain['a']));
        $this->assertSame(3, db_connect()->table('commission_wallet_ledger_entries')->countAllResults(), 'history before the cutoff is not posted');
        $this->assertSame(3, db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->where('status', 'POSTED')->countAllResults());

        // Next tick / manual re-run: nothing more happens.
        $snap = $this->ledgerSnapshot();
        $again = service('financialAutomationRunnerService')->run();
        $this->assertSame('already_done', $again['jobs']['commission']['status']);
        $this->assertSame($snap, $this->ledgerSnapshot());
        $this->assertSame([], db_connect()->query('SELECT idempotency_key, COUNT(*) c FROM commission_wallet_ledger_entries GROUP BY idempotency_key HAVING c > 1')->getResultArray());
        $this->assertSame(0, (new \App\Services\CommissionWalletReconciliationService())->run()['issue_count']);
    }

    public function testSchedulerBackfillsACalculationThatFailedAfterApproval(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();

        // Approval committed but its post-commit calculation + posting never happened (e.g. a crash).
        $this->withInstantOffTemporarily(function () use ($chain, &$res): void {
            $res = $this->buyApprove($chain['d'], '100');
        });
        db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->delete();
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());

        $this->startSchedulerAndCommission();
        $tick = service('financialAutomationRunnerService')->run();

        $this->assertSame('success', $tick['jobs']['commission']['status'], json_encode($tick));
        $this->assertSame([1, 2, 3], array_keys($this->ledgerFor($res['ref'])));
        $this->assertSame(3, db_connect()->table('commission_wallet_ledger_entries')->countAllResults(), 'only the post-cutoff purchase');
    }

    public function testSchedulerNeverPostsHistoryButAnExplicitReconciliationStillCan(): void
    {
        $this->preparePlan();
        $chain = $this->buildChain();
        $this->approveCommissionPolicy();
        $this->turnOnInstant();
        $res = $this->buyApprove($chain['d'], '100');
        $this->assertSame(3, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());

        $this->startSchedulerAndCommission();
        $tick = service('financialAutomationRunnerService')->run();
        $this->assertSame('success', $tick['jobs']['commission']['status'], json_encode($tick));
        $this->assertSame(3, db_connect()->table('commission_wallet_ledger_entries')->countAllResults(), 'scheduler leaves pre-cutoff history alone');
        $this->assertSame(6, db_connect()->table('commission_purchase_calculations')->whereIn('purchase_ref', $chain['refs'])->where('status', 'CALCULATED_NOT_POSTED')->countAllResults());

        // The explicit, operator-driven reconciliation path (no cutoff) is untouched and stays idempotent.
        $first = $this->explicitReconciliationRun();
        $this->assertSame('completed', $first['status']);
        $this->assertSame(6, $first['posted_now']);
        $this->assertSame(9, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $second = $this->explicitReconciliationRun();
        $this->assertSame(0, $second['posted_now']);
        $this->assertSame(9, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame([], db_connect()->query('SELECT idempotency_key, COUNT(*) c FROM commission_wallet_ledger_entries GROUP BY idempotency_key HAVING c > 1')->getResultArray());
        $this->assertSame($res['ref'], (string) db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $res['ref'])->get()->getRow('purchase_ref'));
    }

    // ================================================================ switch behaviour

    public function testActivationNeedsEveryPrerequisiteReauthenticationAndNeverPostsAnything(): void
    {
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        try {
            $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
            $this->fail('Must be blocked without policy and plan.');
        } catch (AutomationBlockedException $e) {
            $text = implode(' | ', $e->blockers());
            $this->assertStringContainsString('No approved/active Commission policy', $text);
            $this->assertStringContainsString('No approved commission plan', $text);
        }
        $this->assertFalse($svc->instantControl()['enabled']);
        $this->assertNull($svc->instantSince());

        $this->preparePlan();
        $this->approveCommissionPolicy();
        $this->loginAs($this->adminUserId);
        foreach ([
            [self::ADMIN_PASSWORD, 'wrong phrase', 'Type the confirmation phrase'],
            ['not-the-password', $svc->confirmPhrase(), 'reauthentication failed'],
        ] as [$password, $phrase, $needle]) {
            try {
                $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, $password, $phrase);
                $this->fail('Expected refusal: ' . $needle);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(strtolower($needle), strtolower($e->getMessage()));
            }
        }
        $this->assertFalse($svc->instantControl()['active']);

        $svc->heartbeat();
        $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'go');
        $this->assertTrue($svc->instantControl()['active']);
        $this->assertTrue($svc->isActive(FinancialAutomationService::CONTROL_INSTANT));
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'automation.enabled')->countAllResults());
        $this->assertEnvSwitchesOff();
    }

    public function testStartAllNeverEnablesItStopAllAlwaysDisablesItAndTheCutoffNeverMoves(): void
    {
        $this->preparePlan();
        $this->prepareMiningRate();
        $this->approveCommissionPolicy();
        $this->approveMiningPolicy();
        service('financialAutomationService')->heartbeat();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        $out = $svc->setAll(true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'start all');
        $this->assertSame(FinancialAutomationService::CONTROLS, $out['changed']);
        $this->assertFalse($svc->instantControl()['enabled'], 'Start All must never switch instant posting on');

        $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
        $since = $svc->instantSince();
        $this->assertNotNull($since);

        $stop = $svc->setAll(false, $this->adminUserId);
        $this->assertContains(FinancialAutomationService::CONTROL_INSTANT, $stop['changed']);
        $this->assertFalse($svc->instantControl()['enabled'], 'Stop All is the emergency stop');
        $this->assertNull(service('instantCommissionService')->postApprovedPurchase('does-not-matter', $this->adminUserId));

        // Switching OFF then ON again never widens the window (no retroactive posting).
        sleep(1);
        $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
        $this->assertSame($since, $svc->instantSince());
        $this->assertEnvSwitchesOff();
    }

    public function testAdminPageShowsTheInstantControlAndItCanBeToggledThroughTheForm(): void
    {
        $this->preparePlan();
        $this->approveCommissionPolicy();
        service('financialAutomationService')->heartbeat();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        $page = $this->withSession()->get('admin/financial-automation');
        $page->assertOK();
        $page->assertSee('Instant Commission Posting');
        $page->assertSee('records the cutoff');

        $this->withSession()->post('admin/financial-automation/instant_commission/on', [
            'password' => self::ADMIN_PASSWORD, 'confirm' => $svc->confirmPhrase(),
        ])->assertRedirectTo(site_url('admin/financial-automation'));
        $this->assertTrue($svc->instantControl()['active']);

        $this->withSession()->post('admin/financial-automation/instant_commission/off')->assertRedirectTo(site_url('admin/financial-automation'));
        $this->assertFalse($svc->instantControl()['enabled']);
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
    }

    // ================================================================ helpers

    private function resetSwitches(): void
    {
        $p = config('Platform');
        foreach ([
            'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled',
            'commissionAutoPostOnApprovalEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $flag) {
            $p->{$flag} = false;
        }
        foreach ([
            'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus',
            'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus',
            'miningRoundingPolicyStatus', 'commissionBasePolicyStatus', 'commissionRoundingPolicyStatus', 'commissionRootEligibilityPolicyStatus',
        ] as $flag) {
            $p->{$flag} = 'pending_business_decision';
        }
    }

    private function assertEnvSwitchesOff(): void
    {
        $p = config('Platform');
        foreach ([
            'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled',
            'commissionAutoPostOnApprovalEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $flag) {
            $this->assertFalse((bool) $p->{$flag}, $flag . ' must stay false');
        }
        $this->assertSame('pending_business_decision', $p->commissionBasePolicyStatus, 'policy flags must be restored');
        $this->assertSame('pending_business_decision', $p->commissionRootEligibilityPolicyStatus);
        // Daily mining is untouched by instant commission posting.
        $this->assertSame(0, db_connect()->table('mining_wallet_ledger')->countAllResults());
    }

    /** @return array{a: array, b: array, c: array, d: array, refs: list<string>} */
    private function buildChain(): array
    {
        // Only active members may sponsor, so each sponsor activates (pre-cutoff history, approved two hours ago)
        // before its downline registers.
        $refs   = [];
        $a      = $this->member('a');
        $refs[] = $this->buyApprove($a, '100', true)['ref'];
        $b      = $this->member('b', $a['member_id']);
        $refs[] = $this->buyApprove($b, '100', true)['ref'];
        $c      = $this->member('c', $b['member_id']);
        $refs[] = $this->buyApprove($c, '100', true)['ref'];
        $d      = $this->member('d', $c['member_id']);

        return ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'refs' => $refs];
    }

    /**
     * @param array{user_id: int, member_id: string} $member
     * @return array{ref: string, out: array<string, mixed>}
     */
    private function buyApprove(array $member, string $qty, bool $pinTwoHoursBack = false): array
    {
        $req  = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'icp-' . $member['user_id'] . '-' . bin2hex(random_bytes(3)));
        $pRef = (string) $req['request']['purchase_ref'];
        $out  = service('coinPurchaseService')->approveRequest($pRef, $this->adminUserId);

        if ($pinTwoHoursBack) {
            db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['reviewed_at' => date('Y-m-d H:i:s', time() - 7200)]);
        }

        return ['ref' => $pRef, 'out' => $out];
    }

    private function withInstantOffTemporarily(callable $fn): void
    {
        $svc = service('financialAutomationService');
        $this->loginAs($this->adminUserId);
        $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, false, $this->adminUserId);
        try {
            $fn();
        } finally {
            $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
        }
    }

    private function turnOnInstant(): void
    {
        service('financialAutomationService')->heartbeat();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');
        $svc->setControl(FinancialAutomationService::CONTROL_INSTANT, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'instant test');
        $this->assertTrue($svc->instantControl()['active']);
    }

    private function startSchedulerAndCommission(): void
    {
        service('financialAutomationService')->heartbeat();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');
        $svc->setControl(FinancialAutomationService::CONTROL_COMMISSION, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'recovery test');
        $svc->setControl(FinancialAutomationService::CONTROL_SCHEDULER, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'recovery test');
    }

    /**
     * Operator-driven reconciliation: the existing runner WITHOUT the instant-posting cutoff.
     *
     * @return array<string, mixed>
     */
    private function explicitReconciliationRun(): array
    {
        $p = config('Platform');
        $p->commissionPostingEnabled = true;
        foreach (['commissionBasePolicyStatus', 'commissionRoundingPolicyStatus', 'commissionRootEligibilityPolicyStatus'] as $f) {
            $p->{$f} = 'approved';
        }

        try {
            return (new CommissionPostingRunnerService(null, null, null, null, $p))->run([
                'group' => 'tests', 'execute' => true, 'include_root' => true, 'actor' => $this->adminUserId,
            ]);
        } finally {
            $this->resetSwitches();
        }
    }

    /** Credit one level directly with the production idempotency key (simulates a crashed earlier attempt). */
    private function creditLevelDirectly(string $pRef, int $level, array $beneficiary, string $amount): void
    {
        $p = config('Platform');
        $p->commissionPostingEnabled = true;

        try {
            (new CommissionWalletService())->creditCommission($beneficiary['user_id'], $beneficiary['member_id'], [
                'amount'          => $amount,
                'reference_type'  => 'commission_purchase',
                'reference_id'    => $pRef . ':L' . $level,
                'idempotency_key' => 'comm-post:' . $pRef . ':L' . $level,
                'created_by'      => $this->adminUserId,
            ]);
        } finally {
            $p->commissionPostingEnabled = false;
        }
    }

    private function failingWallet(int $failOnCall): CommissionWalletService
    {
        return new class ($failOnCall) extends CommissionWalletService {
            public int $calls = 0;

            public function __construct(private readonly int $failOn)
            {
                parent::__construct();
            }

            public function creditCommission(int $memberUserId, string $memberId, array $data): array
            {
                $this->calls++;
                $entry = parent::creditCommission($memberUserId, $memberId, $data);
                if ($this->calls === $this->failOn) {
                    throw new \RuntimeException('simulated wallet failure after credit #' . $this->calls);
                }

                return $entry;
            }
        };
    }

    /** @return array<int, array{0: int, 1: string}> level => [beneficiary user id, amount] */
    private function ledgerFor(string $pRef): array
    {
        $out = [];
        $rows = db_connect()->table('commission_wallet_ledger_entries')
            ->like('idempotency_key', 'comm-post:' . $pRef . ':L', 'after')->orderBy('idempotency_key')->get()->getResultArray();
        foreach ($rows as $r) {
            $level       = (int) substr((string) $r['idempotency_key'], -1);
            $out[$level] = [(int) $r['member_user_id'], (string) $r['amount']];
        }
        ksort($out);

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function calcStatuses(string $pRef): array
    {
        $out = [];
        foreach (db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $pRef)->get()->getResultArray() as $r) {
            $out[(int) $r['sponsor_level']] = $r;
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function ledgerSnapshot(): array
    {
        return db_connect()->query(
            'SELECT idempotency_key, member_user_id, amount, balance_after_total FROM commission_wallet_ledger_entries ORDER BY id'
        )->getResultArray()
            + ['balances' => db_connect()->query('SELECT member_user_id, available_balance, total_balance FROM commission_wallet_accounts ORDER BY member_user_id')->getResultArray()];
    }

    private function available(array $member): string
    {
        $row = db_connect()->table('commission_wallet_accounts')->where('member_user_id', $member['user_id'])->get()->getRowArray();

        return $row === null ? '0.00000000' : (string) $row['available_balance'];
    }

    private function profileStatus(array $member): string
    {
        return (string) db_connect()->table('member_profiles')->where('user_id', $member['user_id'])->get()->getRow('account_status');
    }

    private function preparePlan(): void
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'ICP 10/5/3',
            'effective_from'  => date('Y-m-d', strtotime('-60 days')),
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);
        service('commissionPlanService')->approve((string) $draft['plan_ref'], $this->approverUserId);
    }

    private function prepareMiningRate(): void
    {
        $bizDate = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->modify('-1 day')->format('Y-m');
        $draft   = service('miningRateService')->createDraft([
            'effective_month' => $bizDate,
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        service('miningRateService')->approve((string) $draft['rate_ref'], $this->approverUserId);
    }

    private function approveCommissionPolicy(): void
    {
        $this->approvePolicy('commission', ['l1' => '10', 'l2' => '5', 'l3' => '3', 'base' => 'approved_usdt_purchase_amount']);
    }

    private function approveMiningPolicy(): void
    {
        $this->approvePolicy('mining', (new MiningProposalService())->ownerApprovedMiningPolicyPayload(), true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function approvePolicy(string $domain, array $payload, bool $activate = false): string
    {
        $policies = service('financialPolicyService');
        $this->loginAs($this->adminUserId);
        $draft = $policies->createDraft($domain, ucfirst($domain) . ' policy', $payload, $this->adminUserId, date('Y-m-d', strtotime('-30 days')), 'instant commission test');
        $policies->submitForApproval((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        $this->loginAs($this->approverUserId);
        $policies->approve((string) $draft['policy_ref'], $this->approverUserId, self::APPROVER_PASSWORD);
        if ($activate) {
            $this->loginAs($this->adminUserId);
            $policies->activate((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        }
        $this->loginAs($this->adminUserId);

        return (string) $draft['policy_ref'];
    }

    private function loginAs(int $userId): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        auth()->login(model(UserModel::class)->findById($userId));
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'icpapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'icp.approver@test.local', 'password' => self::APPROVER_PASSWORD]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function member(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('icp' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'icp-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'ICP ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'icp' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }
}
