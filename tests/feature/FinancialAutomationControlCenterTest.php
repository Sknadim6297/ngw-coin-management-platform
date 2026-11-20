<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\AutomationBlockedException;
use App\Libraries\MiningMath;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\FinancialAutomationRunnerService;
use App\Services\FinancialAutomationService;
use App\Services\MiningProposalService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Financial Automation Control Center: activation blockers, activation, deactivation, scheduler execution,
 * idempotency and financial reconciliation. Runs on the isolated PHPUnit database only.
 *
 * @internal
 */
final class FinancialAutomationControlCenterTest extends CIUnitTestCase
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
    private string $bizDate;

    protected function setUp(): void
    {
        parent::setUp();

        $p = config('Platform');
        $p->adminEmail                          = 'admin@test.local';
        $p->adminPassword                       = self::ADMIN_PASSWORD;
        $p->adminName                           = 'Test Admin';
        $p->rootSponsorId                       = 'ROOT0001';
        $p->memberIdPrefix                      = 'CPM';
        $p->memberIdStartNumber                 = 1;
        $p->requireVerifiedDepositForApproval   = false;
        $p->mandatoryPurchaseLockEnabled        = true;
        $this->resetSwitches();
        $p->miningBusinessTimezone              = 'Asia/Kolkata';
        $p->miningMonthDayDivisorMode           = 'calendar_days';
        $p->miningApprovalDayPolicy             = 'exclude_approval_day';
        $p->miningEarningAsset                  = 'COIN';
        $p->miningRoundingMode                  = 'down';
        $p->miningReservedCoinPolicy            = 'include';
        $p->miningMaturityPolicy                = 'include_all';
        $p->coinPlanLockDays                    = 1000;
        $p->miningPostRetryDelaySeconds         = 0;
        $p->automationDailyRunTime              = '00:00';
        $p->automationHeartbeatMaxAgeSeconds    = 1800;
        $p->automationMaxAttemptsPerDay         = 3;
        $p->automationRetryBackoffSeconds       = 900;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();
        $this->bizDate        = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('-1 day')->format('Y-m-d');

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'fa price');
        service('coinInventoryService')->addInventory('100000', $this->adminUserId, 'fa stock', null, 'fa-stock-' . bin2hex(random_bytes(3)));

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        $this->resetSwitches();
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    // ================================================================ activation blockers

    public function testActivationIsBlockedWithClearReasonsAndNothingChanges(): void
    {
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        foreach (FinancialAutomationService::CONTROLS as $control) {
            try {
                $svc->setControl($control, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
                $this->fail('Activation of ' . $control . ' must be blocked.');
            } catch (AutomationBlockedException $e) {
                $text = implode(' | ', $e->blockers());
                $this->assertStringContainsString('cron', strtolower($text), $control);
                if ($control === FinancialAutomationService::CONTROL_MINING) {
                    $this->assertStringContainsString('No approved/active Mining policy', $text);
                    $this->assertStringContainsString('No approved mining rate', $text);
                }
                if ($control === FinancialAutomationService::CONTROL_COMMISSION) {
                    $this->assertStringContainsString('No approved/active Commission policy', $text);
                    $this->assertStringContainsString('No approved commission plan', $text);
                }
            }
        }

        try {
            $svc->setAll(true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
            $this->fail('Start All must be blocked.');
        } catch (AutomationBlockedException $e) {
            $this->assertNotSame([], $e->blockers());
        }

        foreach ($svc->controls() as $c) {
            $this->assertFalse($c['enabled'], $c['key']);
            $this->assertFalse($c['active'], $c['key']);
        }
        $this->assertNoFinancialRows();
        $this->assertEnvSwitchesOff();
        $this->assertGreaterThan(0, db_connect()->table('audit_logs')->where('action', 'automation.activation_blocked')->countAllResults());
    }

    public function testDraftOrSelfApprovedPoliciesDoNotSatisfyActivation(): void
    {
        $this->loginAs($this->adminUserId);
        $policies = service('financialPolicyService');
        $draft    = $policies->createDraft('mining', 'Draft only', (new MiningProposalService())->ownerApprovedMiningPolicyPayload(), $this->adminUserId, date('Y-m-d'));
        $this->assertSame('draft', $draft['status']);
        $this->prepareRateAndPlan();
        service('financialAutomationService')->heartbeat();

        $r = service('financialAutomationService')->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready'], 'a DRAFT policy must never count as approved');
        $this->assertStringContainsString('No approved/active Mining policy', implode(' | ', $r['blockers']));

        // Simulate a tampered record: "approved" by its own author.
        db_connect()->table('financial_policy_versions')->where('policy_ref', $draft['policy_ref'])->update([
            'status' => 'approved', 'submitted_by' => $this->adminUserId, 'approved_by' => $this->adminUserId,
        ]);
        $r = service('financialAutomationService')->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('not independent', implode(' | ', $r['blockers']));
    }

    public function testApprovedPolicyThatDoesNotMatchTheEngineOrBusinessRulesBlocks(): void
    {
        $this->prepareRateAndPlan();
        service('financialAutomationService')->heartbeat();
        $payload                    = (new MiningProposalService())->ownerApprovedMiningPolicyPayload();
        $payload['monthly_percent'] = '5.00000000';
        $this->approvePolicy('mining', $payload);

        $r = service('financialAutomationService')->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('monthly_percent must be 3', implode(' | ', $r['blockers']));
    }

    public function testMissingMandatoryLockAndStaleCronBlock(): void
    {
        $this->prepareEverything();
        $svc = service('financialAutomationService');
        $this->assertTrue($svc->readiness(FinancialAutomationService::CONTROL_MINING)['ready']);

        // An approved purchase without its mandatory lock is a blocker.
        db_connect()->query('SET FOREIGN_KEY_CHECKS = 0');
        db_connect()->query('DELETE FROM member_coin_locks');
        db_connect()->query('SET FOREIGN_KEY_CHECKS = 1');
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('no mandatory lock', implode(' | ', $r['blockers']));

        // Stale cron heartbeat blocks every control.
        db_connect()->table('platform_settings')->where('setting_key', 'automation_heartbeat_at')->update(['setting_value' => (string) (time() - 7200)]);
        $r = $svc->readiness(FinancialAutomationService::CONTROL_SCHEDULER);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('heartbeat', strtolower(implode(' | ', $r['blockers'])));
    }

    public function testReauthenticationAndTypedPhraseAreRequired(): void
    {
        $this->prepareEverything();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        foreach ([
            [self::ADMIN_PASSWORD, 'wrong phrase', 'Type the confirmation phrase'],
            ['not-the-password', $svc->confirmPhrase(), 'reauthentication failed'],
            ['', $svc->confirmPhrase(), 'password reauthentication is required'],
        ] as [$password, $phrase, $needle]) {
            try {
                $svc->setAll(true, $this->adminUserId, $password, $phrase);
                $this->fail('Expected refusal: ' . $needle);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString(strtolower($needle), strtolower($e->getMessage()));
            }
        }
        $this->assertFalse($svc->isActive(FinancialAutomationService::CONTROL_SCHEDULER));
    }

    // ================================================================ activation / deactivation

    public function testSuccessfulActivationRecordsAuthorizationButPostsNothing(): void
    {
        $this->prepareEverything();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        $out = $svc->setAll(true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'go-live test');
        $this->assertSame(FinancialAutomationService::CONTROLS, $out['changed']);

        foreach (FinancialAutomationService::CONTROLS as $control) {
            $this->assertTrue($svc->isActive($control), $control);
        }
        $auth = $svc->authorization();
        $this->assertSame($this->adminUserId, $auth['by']);
        $this->assertSame($svc->databaseName(), $auth['database']);

        // Persistent server-side settings, never only frontend state.
        $this->assertSame('1', db_connect()->table('platform_settings')->where('setting_key', 'automation_scheduler')->get()->getRow('setting_value'));
        $this->assertGreaterThanOrEqual(3, db_connect()->table('settings_audit')->where('changed_by', $this->adminUserId)->like('setting_key', 'automation_', 'after')->countAllResults());
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'automation.start_all')->countAllResults());

        // Toggling creates no financial credits and never touches the env kill-switches.
        $this->assertNoFinancialRows(allowCommissionCalcRows: true);
        $this->assertEnvSwitchesOff();
        $this->assertSame([], array_filter($svc->restrictedFeatures(), static fn (array $f): bool => $f['on']));

        $status = $svc->status();
        $this->assertTrue($status['all_on']);
        $this->assertStringContainsString('Due now', $status['next_run']['label']);
    }

    public function testSingleSwitchCanBeTurnedOnAndOffIndependently(): void
    {
        $this->prepareEverything();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        $svc->setControl(FinancialAutomationService::CONTROL_MINING, true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
        $this->assertTrue($svc->isActive(FinancialAutomationService::CONTROL_MINING));
        $this->assertFalse($svc->isActive(FinancialAutomationService::CONTROL_COMMISSION));
        $this->assertFalse($svc->isActive(FinancialAutomationService::CONTROL_SCHEDULER));

        $off = $svc->setControl(FinancialAutomationService::CONTROL_MINING, false, $this->adminUserId);
        $this->assertTrue($off['changed']);
        $this->assertFalse($svc->isActive(FinancialAutomationService::CONTROL_MINING));
        $again = $svc->setControl(FinancialAutomationService::CONTROL_MINING, false, $this->adminUserId);
        $this->assertFalse($again['changed']);
    }

    public function testDeactivationStopsFuturePostingAndKeepsExistingLedgerRows(): void
    {
        $this->prepareEverything();
        $this->startAll();
        $runner = service('financialAutomationRunnerService');

        $first = $runner->run();
        $this->assertSame('ok', $first['status'], json_encode($first));
        $before = $this->ledgerCounts();
        $this->assertSame(1, $before['mining']);
        $this->assertSame(1, $before['commission']);

        service('financialAutomationService')->setAll(false, $this->adminUserId);
        $this->assertSame($before, $this->ledgerCounts(), 'OFF must not delete or change any ledger row');
        foreach (FinancialAutomationService::CONTROLS as $control) {
            $this->assertFalse(service('financialAutomationService')->isActive($control));
        }

        // A new business day arrives while OFF: nothing may post.
        db_connect()->table('financial_automation_runs')->truncate();
        db_connect()->table('mining_post_day_runs')->truncate();
        $second = $runner->run();
        $this->assertSame('scheduler_off', $second['status']);
        $this->assertSame($before, $this->ledgerCounts());
        $this->assertNotNull(service('financialAutomationService')->heartbeatAt(), 'heartbeat is still written while OFF');
    }

    public function testAuthorizationCopiedFromAnotherDatabaseIsIgnored(): void
    {
        $this->prepareEverything();
        $this->startAll();
        db_connect()->table('platform_settings')->where('setting_key', 'automation_authorized_db')->update(['setting_value' => 'some_other_database']);

        $svc = service('financialAutomationService');
        $this->assertFalse($svc->isActive(FinancialAutomationService::CONTROL_SCHEDULER));
        $this->assertSame('scheduler_off', service('financialAutomationRunnerService')->run()['status']);
        $this->assertNoFinancialRows(allowCommissionCalcRows: true);
    }

    // ================================================================ scheduler execution, idempotency, reconciliation

    public function testSchedulerPostsMiningAndCommissionsOnceAndReconciles(): void
    {
        $this->prepareEverything();
        $this->startAll();
        $runner = service('financialAutomationRunnerService');

        $res = $runner->run();
        $this->assertSame('ok', $res['status'], json_encode($res));
        $this->assertSame($this->bizDate, $res['business_date']);
        $this->assertSame('success', $res['jobs']['mining']['status'], json_encode($res['jobs']['mining']));
        $this->assertSame('success', $res['jobs']['commission']['status'], json_encode($res['jobs']['commission']));

        // Mining: 100 COIN x 3% / actual days of the business month, rounded down, in COIN.
        $days     = (int) (new DateTimeImmutable($this->bizDate))->format('t');
        $expected = MiningMath::dailyAmount('100', '3', $days, 8, MiningMath::ROUND_DOWN);
        $ledger   = db_connect()->table('mining_wallet_ledger')->get()->getResultArray();
        $this->assertCount(1, $ledger);
        $this->assertSame($this->bizDate, $ledger[0]['business_date']);
        $this->assertSame('COIN', $ledger[0]['asset_code']);
        $this->assertSame(0, MoneyMath::compare((string) $ledger[0]['amount'], $expected, 8), 'expected ' . $expected . ' got ' . $ledger[0]['amount']);

        // Commission: L1 10% of the approved 100 USDT purchase -> 10 USDT to the sponsor (ROOT0001).
        $commission = db_connect()->table('commission_wallet_ledger_entries')->where('entry_type', 'commission_credit')->get()->getResultArray();
        $this->assertCount(1, $commission);
        $this->assertSame(0, MoneyMath::compare((string) $commission[0]['amount'], '10', 8));

        // Run log + audit trail.
        $runs = db_connect()->table('financial_automation_runs')->orderBy('id')->get()->getResultArray();
        $this->assertSame(['success', 'success'], array_column($runs, 'status'));
        $this->assertSame(['mining', 'commission'], array_column($runs, 'job'));
        $this->assertSame(1, (int) $runs[0]['credits_count']);
        $this->assertSame(1, db_connect()->table('audit_logs')->where('action', 'automation.job_success')->where('entity_id', $runs[0]['run_ref'])->countAllResults());

        // In-process kill-switch elevation is always restored.
        $this->assertEnvSwitchesOff();

        // Financial reconciliation.
        $rec = service('financialAutomationService')->reconciliation();
        $this->assertTrue($rec['ok'], implode(' | ', $rec['issues']));
        $this->assertSame(0, MoneyMath::compare($rec['mining']['ledger_net'], $rec['mining']['wallet_balance'], 8));
        $this->assertSame(0, MoneyMath::compare($rec['mining']['day_runs_total'], $expected, 8));

        $metrics = service('financialAutomationService')->metrics();
        $this->assertSame(1, $metrics['mining_credit_count']);
        $this->assertSame(0, MoneyMath::compare($metrics['commission_post_total'], '10', 8));
        $this->assertSame([], service('financialAutomationService')->failedJobs());
    }

    public function testExactBusinessDateIsPassedToTheMiningRunnerEvenWhenTheWallClockHasCrossedMidnight(): void
    {
        $tz = new DateTimeZone('Asia/Kolkata');
        // The tick "started" just before midnight IST yesterday; the real clock is already past midnight.
        $tickStart = (new DateTimeImmutable('today', $tz))->modify('-1 second');
        $due       = $tickStart->modify('-1 day')->format('Y-m-d');
        if (substr($due, 0, 7) !== substr($this->bizDate, 0, 7)) {
            $this->markTestSkipped('First days of a month: the seeded approved rate month differs from the simulated due date.');
        }

        $this->prepareEverything();
        $this->startAll();

        $res = service('financialAutomationRunnerService')->run($tickStart);
        $this->assertSame($due, $res['business_date']);
        $this->assertSame('success', $res['jobs']['mining']['status'], json_encode($res['jobs']['mining']));

        // Before the fix the runner re-derived "yesterday" from the CURRENT clock and would have posted $this->bizDate.
        $this->assertNotSame($due, $this->bizDate);
        $ledger = db_connect()->table('mining_wallet_ledger')->get()->getResultArray();
        $this->assertCount(1, $ledger);
        $this->assertSame($due, $ledger[0]['business_date'], 'posted for the exact business date of the run log');
        $this->assertSame($due, (string) db_connect()->table('financial_automation_runs')->where('job', 'mining')->get()->getRow('business_date'));
        $this->assertSame($due, (string) db_connect()->table('mining_post_day_runs')->get()->getRow('business_date'));

        // A retry of the very same tick never posts again, and never posts the other date either.
        $again = service('financialAutomationRunnerService')->run($tickStart);
        $this->assertSame('already_done', $again['jobs']['mining']['status']);
        $this->assertCount(1, db_connect()->table('mining_wallet_ledger')->get()->getResultArray());
        $this->assertSame([], db_connect()->table('mining_wallet_ledger')->where('business_date', $this->bizDate)->get()->getResultArray());
    }

    public function testSecondTickAndEvenALostRunLogNeverDoublePost(): void
    {
        $this->prepareEverything();
        $this->startAll();
        $runner = service('financialAutomationRunnerService');
        $this->assertSame('ok', $runner->run()['status']);
        $counts = $this->ledgerCounts();
        $totals = $this->ledgerTotals();

        // Normal next cron tick: both jobs already done for the business date.
        $tick = $runner->run();
        $this->assertSame('already_done', $tick['jobs']['mining']['status']);
        $this->assertSame('already_done', $tick['jobs']['commission']['status']);
        $this->assertSame($counts, $this->ledgerCounts());

        // Defence in depth: even if the automation run log were lost, the existing runners are idempotent.
        db_connect()->table('financial_automation_runs')->truncate();
        $again = $runner->run();
        $this->assertSame('ok', $again['status'], json_encode($again));
        $this->assertSame($counts, $this->ledgerCounts(), 'no duplicate ledger rows');
        $this->assertSame($totals, $this->ledgerTotals(), 'no duplicate money');
        $dupes = db_connect()->query('SELECT member_user_id, business_date, COUNT(*) c FROM mining_wallet_ledger GROUP BY member_user_id, business_date HAVING c > 1')->getResultArray();
        $this->assertSame([], $dupes);
        $this->assertTrue(service('financialAutomationService')->reconciliation()['ok']);
    }

    public function testNothingRunsBeforeTheDailyRunTimeOrWhenSchedulerIsOffButHeartbeatIsWritten(): void
    {
        $this->prepareEverything();
        $this->startAll();
        config('Platform')->automationDailyRunTime = '01:30';
        $early = new DateTimeImmutable('today 00:10', new DateTimeZone('Asia/Kolkata'));

        db_connect()->table('platform_settings')->where('setting_key', 'automation_heartbeat_at')->delete();
        $res = service('financialAutomationRunnerService')->run($early);
        $this->assertSame('not_due', $res['status']);
        $this->assertSame(0, db_connect()->table('financial_automation_runs')->countAllResults());
        $this->assertNotNull(service('financialAutomationService')->heartbeatAt());
        $this->assertNoFinancialRows(allowCommissionCalcRows: true);
    }

    public function testFailedJobIsLoggedAlertedRetriedWithBackoffAndDoesNotPost(): void
    {
        $this->prepareEverything();
        $this->startAll();

        // Break one prerequisite after activation: the approved rate is disabled.
        $rateRef = (string) db_connect()->table('mining_rate_configs')->where('status', 'approved')->get()->getRow('rate_ref');
        service('miningRateService')->disable($rateRef, $this->adminUserId);

        $runner = service('financialAutomationRunnerService');
        $res    = $runner->run();
        $this->assertSame('attention', $res['status']);
        $this->assertSame('blocked', $res['jobs']['mining']['status']);
        $this->assertStringContainsString('No approved mining rate', $res['jobs']['mining']['message']);
        $this->assertSame('success', $res['jobs']['commission']['status'], 'jobs are independent');
        $this->assertSame(0, $this->ledgerCounts()['mining']);

        $run = db_connect()->table('financial_automation_runs')->where('job', 'mining')->get()->getRowArray();
        $this->assertSame('blocked', $run['status']);
        $this->assertSame(1, db_connect()->table('financial_alerts')->where('alert_type', 'automation_job_blocked')->countAllResults());
        $this->assertCount(1, service('financialAutomationService')->failedJobs());
        $this->assertNotSame([], array_filter(service('financialAutomationService')->status()['blockers'], static fn (string $b): bool => str_contains($b, 'Failed job mining')));

        // Immediate next tick: backoff, no new attempt.
        $this->assertSame('backoff', $runner->run()['jobs']['mining']['status']);
        $this->assertSame(1, db_connect()->table('financial_automation_runs')->where('job', 'mining')->countAllResults());

        // Operator fixes the rate; after the back-off the retry succeeds and the failure clears.
        $this->approveRate();
        db_connect()->table('financial_automation_runs')->where('job', 'mining')->update(['finished_at' => date('Y-m-d H:i:s', time() - 7200)]);
        $retry = $runner->run();
        $this->assertSame('success', $retry['jobs']['mining']['status'], json_encode($retry['jobs']));
        $this->assertSame(1, $this->ledgerCounts()['mining']);
        $this->assertSame([], service('financialAutomationService')->failedJobs());
        $this->assertTrue(service('financialAutomationService')->reconciliation()['ok']);
    }

    public function testRunnerExhaustsAttemptsInsteadOfRetryingForever(): void
    {
        $this->prepareEverything();
        $this->startAll();
        config('Platform')->automationMaxAttemptsPerDay = 2;
        $rateRef = (string) db_connect()->table('mining_rate_configs')->where('status', 'approved')->get()->getRow('rate_ref');
        service('miningRateService')->disable($rateRef, $this->adminUserId);
        $runner = service('financialAutomationRunnerService');

        for ($i = 0; $i < 2; $i++) {
            $runner->run();
            db_connect()->table('financial_automation_runs')->where('job', 'mining')->update(['finished_at' => date('Y-m-d H:i:s', time() - 7200)]);
        }
        $this->assertSame('attempts_exhausted', $runner->run()['jobs']['mining']['status']);
        $this->assertSame(2, db_connect()->table('financial_automation_runs')->where('job', 'mining')->countAllResults());
    }

    // ================================================================ admin UI

    public function testAdminPageShowsControlsRulesAndRequiresPermission(): void
    {
        $this->loginAs($this->adminUserId);
        $page = $this->withSession()->get('admin/financial-automation');
        $page->assertOK();
        foreach ([
            'Daily Coin Mining', 'Level Commission', 'Automatic Scheduler', 'Start All Automation', 'Stop All Automation',
            '3% monthly', 'Actual calendar days', 'No compounding', 'Approval day excluded', 'COIN',
            '10%', '5%', '3%', 'Approved USDT purchase amount', 'Subject to formal policy approval',
            'Last successful run', 'Next scheduled run', 'Generated mining credits', 'Posted commissions', 'Failed jobs', 'Current blockers',
            'Advanced configuration',
        ] as $needle) {
            $page->assertSee($needle);
        }
        // Sidebar: automation is primary, policies are demoted.
        $page->assertSee('Advanced: Financial Policies');

        auth()->logout();
        $member = $this->member('uiuser');
        $this->loginAs($member['user_id']);
        $this->withSession()->get('admin/financial-automation')->assertRedirect();
    }

    public function testBlockedActivationThroughTheWebFormExplainsAndChangesNothing(): void
    {
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');
        $res = $this->withSession()->post('admin/financial-automation/start-all', [
            'password' => self::ADMIN_PASSWORD, 'confirm' => $svc->confirmPhrase(),
        ]);
        $res->assertRedirectTo(site_url('admin/financial-automation'));
        foreach ($svc->controls() as $c) {
            $this->assertFalse($c['enabled']);
        }
        $this->assertNoFinancialRows();

        $page = $this->withSession()->get('admin/financial-automation');
        $page->assertSee('No approved/active Mining policy');
    }

    public function testActivationAndStopThroughTheWebForm(): void
    {
        $this->prepareEverything();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');

        $this->withSession()->post('admin/financial-automation/start-all', [
            'password' => self::ADMIN_PASSWORD, 'confirm' => $svc->confirmPhrase(),
        ])->assertRedirectTo(site_url('admin/financial-automation'));
        $this->assertTrue($svc->status()['all_on']);

        $this->withSession()->post('admin/financial-automation/stop-all')->assertRedirectTo(site_url('admin/financial-automation'));
        $this->assertFalse($svc->status()['any_on']);
        $this->assertNoFinancialRows(allowCommissionCalcRows: true);
    }

    public function testSupportViewCannotChangeSwitches(): void
    {
        $this->prepareEverything();
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');
        $ref = new \ReflectionClass(service('impersonationService'));
        $prop = $ref->getProperty('activeContext');
        $prop->setAccessible(true);
        $prop->setValue(service('impersonationService'), ['member_user_id' => 1]);

        try {
            $this->expectException(\RuntimeException::class);
            $svc->setAll(true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase());
        } finally {
            $prop->setValue(service('impersonationService'), null);
        }
    }

    // ================================================================ cron command

    public function testCronCommandIsRegisteredAndRunnerServiceResolves(): void
    {
        $this->assertInstanceOf(FinancialAutomationRunnerService::class, service('financialAutomationRunnerService'));
        $this->assertTrue(class_exists(\App\Commands\AutomationRun::class));
        $this->assertSame('automation:run', (new \ReflectionProperty(\App\Commands\AutomationRun::class, 'name'))->getDefaultValue());
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
        foreach (['miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus',
            'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus',
            'miningRoundingPolicyStatus', 'commissionBasePolicyStatus', 'commissionRoundingPolicyStatus', 'commissionRootEligibilityPolicyStatus'] as $flag) {
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
        $this->assertSame('pending_business_decision', $p->miningMonthlyPercentStatus, 'policy flags must be restored after a run');
        $this->assertSame('pending_business_decision', $p->commissionBasePolicyStatus);
    }

    private function assertNoFinancialRows(bool $allowCommissionCalcRows = false): void
    {
        $this->assertSame(0, db_connect()->table('mining_wallet_ledger')->countAllResults(), 'no mining credits');
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults(), 'no commission credits');
        $this->assertSame(0, db_connect()->table('financial_automation_runs')->where('status', 'success')->countAllResults(), 'no successful automation run');
    }

    /** @return array{mining: int, commission: int} */
    private function ledgerCounts(): array
    {
        return [
            'mining'     => db_connect()->table('mining_wallet_ledger')->countAllResults(),
            'commission' => db_connect()->table('commission_wallet_ledger_entries')->where('entry_type', 'commission_credit')->countAllResults(),
        ];
    }

    /** @return array{mining: string, commission: string} */
    private function ledgerTotals(): array
    {
        return [
            'mining'     => MoneyMath::normalize((string) db_connect()->table('mining_wallet_ledger')->selectSum('amount', 't')->get()->getRow('t'), 8),
            'commission' => MoneyMath::normalize((string) db_connect()->table('commission_wallet_ledger_entries')->selectSum('amount', 't')->get()->getRow('t'), 8),
        ];
    }

    private function startAll(): void
    {
        $this->loginAs($this->adminUserId);
        $svc = service('financialAutomationService');
        $svc->setAll(true, $this->adminUserId, self::ADMIN_PASSWORD, $svc->confirmPhrase(), 'test start');
        $this->assertTrue($svc->status()['all_on']);
    }

    private function loginAs(int $userId): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        auth()->login(model(UserModel::class)->findById($userId));
    }

    /**
     * Members + purchases first (so the commission plan arrives AFTER the purchase, the real recovery path),
     * then policies, rate, plan and a fresh cron heartbeat.
     */
    private function prepareEverything(): void
    {
        $m = $this->member('buyer');
        $this->buyApprove($m, '100');
        $this->prepareRateAndPlan();
        $this->approvePolicy('mining', (new MiningProposalService())->ownerApprovedMiningPolicyPayload(), true);
        $this->approvePolicy('commission', ['l1' => '10', 'l2' => '5', 'l3' => '3', 'base' => 'approved_usdt_purchase_amount']);
        service('financialAutomationService')->heartbeat();
        $this->loginAs($this->adminUserId);
    }

    private function prepareRateAndPlan(): void
    {
        $this->approveRate();
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'FA 10/5/3',
            'effective_from'  => date('Y-m-d', strtotime('-60 days')),
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);
        service('commissionPlanService')->approve((string) $draft['plan_ref'], $this->approverUserId);
    }

    private function approveRate(): void
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => substr($this->bizDate, 0, 7),
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        service('miningRateService')->approve((string) $draft['rate_ref'], $this->approverUserId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function approvePolicy(string $domain, array $payload, bool $activate = false): string
    {
        $policies = service('financialPolicyService');
        $this->loginAs($this->adminUserId);
        $draft = $policies->createDraft($domain, ucfirst($domain) . ' policy', $payload, $this->adminUserId, date('Y-m-d', strtotime('-30 days')), 'automation test');
        $policies->submitForApproval((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        $this->loginAs($this->approverUserId);
        $policies->approve((string) $draft['policy_ref'], $this->approverUserId, self::APPROVER_PASSWORD);
        if ($activate) {
            $this->loginAs($this->adminUserId);
            $policies->activate((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        }

        return (string) $draft['policy_ref'];
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'faapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'fa.approver@test.local', 'password' => self::APPROVER_PASSWORD]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function member(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('fa' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'fa-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'FA ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'fa' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }

    /**
     * Buy + approve, then pin the approval three days back so the member already earns on the business date.
     *
     * @param array{user_id: int, member_id: string} $member
     */
    private function buyApprove(array $member, string $qty): string
    {
        $req  = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'fa-' . $member['user_id'] . '-' . bin2hex(random_bytes(3)));
        $pRef = (string) $req['request']['purchase_ref'];
        service('coinPurchaseService')->approveRequest($pRef, $this->adminUserId);

        $when = gmdate('Y-m-d H:i:s', time() - 3 * 86400);
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['reviewed_at' => $when]);
        db_connect()->table('member_coin_ownership_transactions')->where('purchase_ref', $pRef)->update(['created_at' => $when]);
        $lock = db_connect()->table('member_coin_locks')->where('purchase_ref', $pRef)->get()->getRowArray();
        if ($lock !== null) {
            db_connect()->table('member_coin_ownership_transactions')->where('lock_ref', (string) $lock['lock_ref'])->update(['created_at' => $when]);
        }

        return $pRef;
    }
}
