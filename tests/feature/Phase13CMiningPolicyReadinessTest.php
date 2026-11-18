<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\CliArgs;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Commands\MiningPost;
use App\Services\FinancialPolicyService;
use App\Services\MiningEligibleOwnershipSnapshotService;
use App\Services\MiningPostAlertService;
use App\Services\MiningPostingRunnerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Phase 13C focused tests (PHPUnit test database only).
 *
 * Snapshot restore, LOCK1000 audit/simulation, migration rehearsal and mock-alert scenarios against restored data run in
 * `php spark mining:phase13c-verify` (isolated databases) — they are NOT repeated here.
 *
 * @internal
 */
final class Phase13CMiningPolicyReadinessTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private const FLAGS = [
        'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus',
        'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus', 'miningRoundingPolicyStatus',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $p = config('Platform');
        foreach (self::FLAGS as $f) {
            $p->{$f} = 'pending';
        }
        $p->miningPostingEnabled              = false;
        $p->miningAutoPostEnabled             = false;
        $p->miningP2pEnabled                  = false;
        $p->commissionPostingEnabled          = false;
        $p->commissionWithdrawalEnabled       = false;
        $p->commissionBlockchainPayoutEnabled = false;
        $p->coinSellingEnabled                = false;
        $p->miningBusinessTimezone            = 'Asia/Kolkata';
    }

    // ===================================================================== worksheet

    public function testWorksheetDecisionsArePendingAndNeverAutoApproved(): void
    {
        $w = service('miningPolicyWorksheetService', false)->build();
        $this->assertCount(12, $w['decisions']);
        foreach ($w['decisions'] as $d) {
            $this->assertSame('PENDING_BUSINESS_APPROVAL', $d['approval_state'], $d['id']);
            $this->assertSame('PENDING_BUSINESS_APPROVAL', $d['approved_value'], $d['id']);
            $this->assertNull($d['effective_date']);
        }
        $ids = array_column($w['blockers'], 'id');
        $this->assertContains('RATE-APPROVED', $ids);
        $this->assertContains('POLICY-DENOMINATION', $ids);
    }

    public function testFlagApprovedWithoutPolicyRecordIsNotTreatedAsApproved(): void
    {
        config('Platform')->miningMonthlyPercentStatus = 'approved';
        $w = service('miningPolicyWorksheetService', false)->build();
        $row = array_values(array_filter($w['decisions'], static fn (array $d): bool => $d['id'] === 'monthly_percent'))[0];
        $this->assertSame('FLAG_APPROVED_WITHOUT_POLICY_RECORD', $row['approval_state']);
        $this->assertSame('PENDING_BUSINESS_APPROVAL', $row['approved_value']);
    }

    public function testConfirmedRulesArePreserved(): void
    {
        $text = json_encode(service('miningPolicyWorksheetService', false)->confirmedRequirements(), JSON_UNESCAPED_SLASHES);
        foreach (['daily', 'locked', 'Commission Wallet', 'externally withdrawn', 'P2P', 'Asia/Kolkata', 'No duplicate credit', 'explicit activation'] as $needle) {
            $this->assertStringContainsString($needle, (string) $text);
        }
    }

    public function testMarkdownMatrixHasRequiredColumnsAndPendingMarkers(): void
    {
        $svc = service('miningPolicyWorksheetService', false);
        $md  = $svc->toMarkdown($svc->build());
        foreach (['Decision', 'Current Status', 'Available Options', 'Recommended Technical Default', 'Financial Impact', 'Historical Data Impact', 'Approval Required', 'Approved Value', 'Effective Date'] as $col) {
            $this->assertStringContainsString($col, $md);
        }
        $this->assertStringContainsString('PENDING_BUSINESS_APPROVAL', $md);
        $this->assertStringNotContainsString('APPROVED_VERIFIED', $md);
    }

    // ===================================================================== previews / precision

    public function testSpecDivisorIllustrationAndHubPreviewMatchEngine(): void
    {
        $hub = new FinancialPolicyService();
        $this->assertSame('1.00000000', $hub->miningPreview('1000', '3', 'fixed_30')['daily_earning']);
        $this->assertSame('0.96774194', $hub->miningPreview('1000', '3', 'calendar_days', 31)['daily_earning']);
        $this->assertSame('0.96774193', $hub->miningPreview('1000', '3', 'calendar_days', 31, MiningMath::ROUND_DOWN)['daily_earning']);
        // Hub preview == engine for awkward values
        $this->assertSame(
            MiningMath::dailyAmount('123456789.12345678', '2.5', 31, 8, MiningMath::ROUND_HALF_UP),
            $hub->miningPreview('123456789.12345678', '2.5', 'calendar_days', 31)['daily_earning']
        );
        $this->expectException(InvalidArgumentException::class);
        $hub->miningPreview('1000', '3', 'fixed_30', null, 'banker');
    }

    public function testDivisorAndRoundingMatricesForAllMonthLengths(): void
    {
        $p = service('miningPolicyWorksheetService', false)->previews();
        $this->assertSame([28, 29, 30, 31], array_column($p['divisor_matrix'], 'days_in_month'));
        foreach ($p['divisor_matrix'] as $row) {
            $this->assertSame('1.00000000', $row['half_up']['fixed_30_daily']);
            $exact = bcdiv(bcmul('1000', '3', 12), '100', 8);
            $this->assertSame(0, bccomp($exact, '30.00000000', 8));
            $this->assertSame(0, bccomp($row['exact_monthly_income'], $exact, 8));
        }
        $thirtyOne = $p['divisor_matrix'][3];
        $this->assertSame('0.96774194', $thirtyOne['half_up']['calendar_daily']);
        $this->assertSame('0.96774193', $thirtyOne['down']['calendar_daily']);
        // independent daily rounding is not exactly the monthly income; alternatives are only illustrated
        $this->assertNotSame('0.000000000000', $p['rounding_matrix_31_day_month']['half_up']['independent_vs_exact_difference']);
        $this->assertArrayHasKey('carry_forward_month_total', $p['rounding_matrix_31_day_month']['down']);
        $this->assertArrayHasKey('final_day_adjustment_last_day_credit', $p['rounding_matrix_31_day_month']['down']);
    }

    public function testNoFloatArithmeticInPolicyServices(): void
    {
        foreach (['MiningPolicyWorksheetService', 'FinancialPolicyService', 'MiningMath'] as $c) {
            $file = is_file(APPPATH . 'Services/' . $c . '.php') ? APPPATH . 'Services/' . $c . '.php' : APPPATH . 'Libraries/' . $c . '.php';
            $src  = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\(float\)|floatval\(|(?<![:>\w ])round\(|(?<![:>\w ])floor\(|(?<![:>\w ])ceil\(/', $src, $c . ' must use bcmath only');
        }
    }

    // ===================================================================== approval-day options (cutoff semantics, pure)

    /** @return array{a: bool, b: bool} eligible on $businessDate under Option A (same day) / B (next day) */
    private function eligibleOn(string $approvedUtc, string $businessDate): array
    {
        $svc = new MiningEligibleOwnershipSnapshotService();
        $A   = $svc->cutoff($businessDate, MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP'), new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')))['cutoff_utc'];
        $B   = $svc->cutoff($businessDate, MiningCalculationPolicy::fixture('T-NEXTDAY-DOWN'), new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')))['cutoff_utc'];

        return ['a' => $approvedUtc < $A, 'b' => $approvedUtc < $B];
    }

    public function testApprovalDayOptionsAtIstBoundaries(): void
    {
        // 00:01 IST on 2026-11-17 = 2026-11-16 18:31 UTC
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn('2026-11-16 18:31:00', '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => true], $this->eligibleOn('2026-11-16 18:31:00', '2026-11-18'));
        // 23:59 IST on 2026-11-17 = 2026-11-17 18:29 UTC
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn('2026-11-17 18:29:00', '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => true], $this->eligibleOn('2026-11-17 18:29:00', '2026-11-18'));
        // exactly midnight IST (2026-11-17 18:30:00 UTC) belongs to the NEXT business day
        $this->assertSame(['a' => false, 'b' => false], $this->eligibleOn('2026-11-17 18:30:00', '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn('2026-11-17 18:30:00', '2026-11-18'));
    }

    public function testCrossMidnightUtcUsesBusinessDateNotUtcDate(): void
    {
        // 2026-11-17 19:00 UTC == 2026-11-18 00:30 IST: business date is the 18th even though the UTC date is the 17th.
        $this->assertSame(['a' => false, 'b' => false], $this->eligibleOn('2026-11-17 19:00:00', '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn('2026-11-17 19:00:00', '2026-11-18'));
        $this->assertSame(['a' => true, 'b' => true], $this->eligibleOn('2026-11-17 19:00:00', '2026-11-19'));
    }

    public function testBackdatedBusinessDateNeverSeesLaterApprovals(): void
    {
        $this->assertSame(['a' => false, 'b' => false], $this->eligibleOn('2026-11-17 06:45:44', '2026-11-10'));
        $this->assertSame(['a' => false, 'b' => false], $this->eligibleOn('2026-11-17 06:45:44', '2026-11-16'));
    }

    public function testMultipleApprovalsSameDayAreIndependentlyEvaluated(): void
    {
        $first  = '2026-11-17 02:00:00';
        $second = '2026-11-17 17:00:00';
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn($first, '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => false], $this->eligibleOn($second, '2026-11-17'));
        $this->assertSame(['a' => true, 'b' => true], $this->eligibleOn($second, '2026-11-18'));
    }

    // ===================================================================== denomination

    public function testDenominationIsUnresolvedAndSeparatedFromMarketPrice(): void
    {
        $impact = service('miningPolicyWorksheetService', false)->denominationImpact();
        $this->assertNotEmpty($impact);
        $json = (string) json_encode($impact);
        $this->assertStringContainsString('USDT', $json);
        $this->assertStringContainsString('COIN', $json);
        $this->assertStringNotContainsString('conversion_rate"', $json);

        $w   = service('miningPolicyWorksheetService', false)->build();
        $row = array_values(array_filter($w['decisions'], static fn (array $d): bool => $d['id'] === 'denomination'))[0];
        $this->assertSame('PENDING_BUSINESS_APPROVAL', $row['approval_state']);
    }

    // ===================================================================== legacy commands

    public function testLegacyMiningCommandsCannotPostAndPointToTheSafeCommand(): void
    {
        foreach (['MiningDaily', 'MiningReconcile'] as $cmd) {
            $src = (string) file_get_contents(APPPATH . 'Commands/' . $cmd . '.php');
            $this->assertStringContainsString('LEGACY', $src);
            $this->assertStringContainsString('mining:post', $src);
            $this->assertStringNotContainsString('MiningBatchPostingService', $src);
            $this->assertDoesNotMatchRegularExpression('/->(insert|update|delete)\(/', $src, $cmd . ' must not write wallet data');
        }
    }

    // ===================================================================== CLI parser + scheduler readiness

    public function testPlannedSchedulerArgumentsParseAndEqualsSyntaxIsRefused(): void
    {
        $ok  = CliArgs::parse(['--scheduled', '--strict-exit', '--allow-production', '--confirm', 'I_AUTHORIZE_PRODUCTION_CHANGE'], MiningPost::argSpec());
        $bad = CliArgs::parse(['--scheduled', '--confirm=I_AUTHORIZE_PRODUCTION_CHANGE'], MiningPost::argSpec());
        $grp = CliArgs::parse(['--group', 'phase13c', '--dry-run'], MiningPost::argSpec());
        $this->assertSame([], $ok['errors']);
        $this->assertNotSame([], $bad['errors']);
        $this->assertSame([], $grp['errors']);
        $this->assertNotSame([], CliArgs::parse(['--group', 'phase13c_snapshot'], MiningPost::argSpec())['errors'], 'the audit-only snapshot group must not be postable');
    }

    public function testSchedulerReadinessIsBlockedUntilEverythingIsApprovedAndNeverActivates(): void
    {
        $r  = service('miningSchedulerReadinessService', false)->check();
        $by = array_column($r['checks'], 'status', 'id');
        $this->assertFalse($r['activated']);
        $this->assertSame('BLOCKED', $by['policies_approved']);
        $this->assertSame('BLOCKED', $by['approved_rate']);
        $this->assertSame('BLOCKED', $by['posting_gate']);
        $this->assertSame('BLOCKED', $by['auto_post_gate']);
        $this->assertSame('BLOCKED', $by['alert_destination']);
        $this->assertSame('NOT_VERIFIED', $by['php_cli_deployment_path']);
        $this->assertSame('PASS', $by['no_production_schedule']);
        $this->assertSame('PASS', $by['lock_ttl_vs_runtime']);
        $this->assertSame('PASS', $by['timezone']);
        $this->assertStringContainsString('--allow-production', $r['planned_command']);
    }

    public function testSchedulerRefusedWhileKillSwitchesAreOff(): void
    {
        $runner = new MiningPostingRunnerService();
        $ctx    = ['mode' => 'live', 'database' => 'coin_platform', 'group' => 'default'];
        $msg    = $runner->authorize($ctx, true, true, 'I_AUTHORIZE_PRODUCTION_CHANGE');
        $this->assertNotNull($msg, 'a live scheduled run must be refused while the switches are false');
    }

    // ===================================================================== monitoring (mock, local only)

    public function testMonitoringRaisesLocalAlertsForEveryFailureClass(): void
    {
        $file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p13c-alert-' . bin2hex(random_bytes(4)) . '.log';
        $svc  = new MiningPostAlertService(null, $file);
        $cases = [
            'nonzero_exit'                => [[], 'FAILED', 50, 'boom'],
            'partial_run'                 => [['status' => 'partial'], 'PARTIAL', 40, null],
            'missing_rate'                => [[], 'BLOCKED', 30, 'No approved mining rate for 2026-11'],
            'blocked_historical_data'     => [['blocked' => 2], 'PARTIAL', 40, null],
            'reconciliation_failed'       => [['reconcile_status' => 'MISMATCH'], 'PARTIAL', 40, null],
            'lock_contention'             => [['status' => 'skipped_locked'], 'SKIPPED', 20, null],
            'job_timeout'                 => [['interrupted' => 'timeout'], 'PARTIAL', 40, null],
            'excessive_retry'             => [['retry_passes' => 50], 'POSTED', 0, null],
            'database_connection_failure' => [[], 'FAILED', 50, 'Connection refused'],
        ];
        foreach ($cases as $code => [$res, $status, $exit, $err]) {
            $raised = array_column($svc->evaluate($res, $status, $exit, $err), 'code');
            $this->assertContains($code, $raised, $code);
        }
        $this->assertSame([], $svc->evaluate([], 'POSTED', 0, null), 'a clean run must stay silent');
        $this->assertSame([], $svc->evaluate([], 'SKIPPED', 20, null), 'a normal skip must stay silent');
        $this->assertSame('missing_scheduled_run', $svc->checkScheduledRun('2026-11-17')['code']);
        @unlink($file);
    }

    public function testNoRealRecipientIsConfigured(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Services/MiningPostAlertService.php');
        $this->assertDoesNotMatchRegularExpression('/mail\(|->send\(|curl_|https?:\/\/[a-z0-9.-]+\.(com|io|net)/i', $src);
    }

    // ===================================================================== isolation

    public function testPhase13cIsolatedDatabasesAreClassifiedCorrectly(): void
    {
        $sandbox = service('financialSandboxService');
        $clone = $sandbox->inspectConnection('phase13c');
        $snap  = $sandbox->inspectConnection('phase13c_snapshot');
        $this->assertTrue($clone['is_allowed']);
        $this->assertFalse($clone['is_forbidden']);
        $this->assertFalse($snap['is_allowed'], 'snapshot DB is audit-only and must never be a financial-write target');
        $this->assertFalse(in_array('coin_platform', [$clone['database'], $snap['database']], true));
    }
}
