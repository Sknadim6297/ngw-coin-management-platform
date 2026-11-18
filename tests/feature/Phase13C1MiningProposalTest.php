<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Services\MiningEligibleOwnershipSnapshotService;
use App\Services\MiningPolicyWorksheetService;
use App\Services\MiningProposalService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 13C.1 focused tests (PHPUnit test database only). The synthetic-ledger posting simulation, real `mining:post`
 * runs, duplicate/backdated/rate-change checks and the live read-only fingerprint run in
 * `php spark mining:phase13c1-verify` (isolated clone) and are not repeated here.
 *
 * @internal
 */
final class Phase13C1MiningProposalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();
        $p = config('Platform');
        foreach (['miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus', 'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus', 'miningRoundingPolicyStatus'] as $f) {
            $p->{$f} = 'pending';
        }
        foreach (['miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled'] as $f) {
            $p->{$f} = false;
        }
        $p->miningBusinessTimezone = 'Asia/Kolkata';
        $this->cleanHub();
    }

    protected function tearDown(): void
    {
        $this->cleanHub();
        parent::tearDown();
    }

    private function cleanHub(): void
    {
        $db = db_connect();
        if ($db->tableExists('financial_policy_events')) {
            $db->query('DELETE FROM financial_policy_events');
        }
        if ($db->tableExists('financial_policy_versions')) {
            $db->query('DELETE FROM financial_policy_versions');
        }
        $db->query("DELETE FROM audit_logs WHERE actor_user_id IN (SELECT id FROM users WHERE username LIKE 'p13c1\\_%')");
        $db->query("DELETE FROM users WHERE username LIKE 'p13c1\\_%'");
    }

    // ===================================================================== proposals

    public function testFourProposalsMapToExistingDecisionIdsAndEnums(): void
    {
        $svc = new MiningProposalService();
        $p   = [];
        foreach ($svc->proposals() as $row) {
            $p[$row['decision_id']] = $row;
            $this->assertSame('PROPOSED_NOT_APPROVED', $row['state']);
        }
        $this->assertSame(['monthly_percent', 'day_divisor', 'denomination', 'approval_day'], array_keys($p));
        $this->assertSame('3.00000000', $p['monthly_percent']['proposed_value']);
        $this->assertSame(MiningMath::DIVISOR_CALENDAR, $p['day_divisor']['proposed_value']);
        $this->assertContains($p['day_divisor']['proposed_value'], MiningMath::divisorModes());
        $this->assertSame('COIN', $p['denomination']['proposed_value']);
        $this->assertSame(MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY, $p['approval_day']['proposed_value']);
        $this->assertSame('ACTUAL_CALENDAR_DAYS', $p['day_divisor']['hub_payload']['proposed_owner_label']);
        $this->assertSame('FOLLOWING_BUSINESS_DATE', $p['approval_day']['hub_payload']['proposed_owner_label']);

        // the 12 ids are exactly the ones the real worksheet exposes
        $ws = service('miningPolicyWorksheetService', false)->build();
        $this->assertSame(MiningProposalService::DECISION_IDS, array_column($ws['decisions'], 'id'));
        foreach ($ws['decisions'] as $d) {
            $this->assertSame('PENDING_BUSINESS_APPROVAL', $d['approval_state']);
        }
    }

    public function testCanonicalValueValidationRejectsOwnerLabelsAndBadNumbers(): void
    {
        $ok = static fn (string $id, mixed $v): bool => MiningPolicyWorksheetService::valueIsCanonical($id, $v);
        $this->assertTrue($ok('day_divisor', 'calendar_days'));
        $this->assertFalse($ok('day_divisor', 'ACTUAL_CALENDAR_DAYS'));
        $this->assertTrue($ok('approval_day', 'exclude_approval_day'));
        $this->assertFalse($ok('approval_day', 'FOLLOWING_BUSINESS_DATE'));
        $this->assertTrue($ok('denomination', 'COIN'));
        $this->assertFalse($ok('denomination', 'coins'));
        $this->assertTrue($ok('monthly_percent', '3.00000000'));
        $this->assertTrue($ok('monthly_percent', '3'));
        $this->assertFalse($ok('monthly_percent', '0'));
        $this->assertFalse($ok('monthly_percent', '100.5'));
        $this->assertFalse($ok('monthly_percent', '-3'));
        $this->assertFalse($ok('monthly_percent', '3%'));
        $this->assertTrue($ok('rounding', 'half_up'));
        $this->assertFalse($ok('rounding', 'bankers'));
    }

    // ===================================================================== calendar days / math

    public function testEveryMonthUsesItsActualCalendarDays(): void
    {
        $rows = (new MiningProposalService())->calendarDays();
        $this->assertCount(13, $rows);
        foreach ($rows as $r) {
            $this->assertTrue($r['ok'], $r['month']);
        }
        $by = array_column($rows, 'calendar_days', 'month');
        $this->assertSame(28, $by['2027-02']);
        $this->assertSame(29, $by['2028-02']);
        $this->assertSame([31, 30, 31, 30, 31, 31, 30, 31, 30, 31], [$by['2026-01'], $by['2026-04'], $by['2026-05'], $by['2026-06'], $by['2026-07'], $by['2026-08'], $by['2026-09'], $by['2026-10'], $by['2026-11'], $by['2026-12']]);
        // divisor flips exactly at month boundaries (IST dates)
        $this->assertSame(31, MiningMath::divisor('monthly', '2026-10-31', null, MiningMath::DIVISOR_CALENDAR));
        $this->assertSame(30, MiningMath::divisor('monthly', '2026-11-01', null, MiningMath::DIVISOR_CALENDAR));
        $this->assertSame(31, MiningMath::divisor('monthly', '2026-12-01', null, MiningMath::DIVISOR_CALENDAR));
        $this->assertSame(28, MiningMath::divisor('monthly', '2027-02-28', null, MiningMath::DIVISOR_CALENDAR));
        $this->assertSame(29, MiningMath::divisor('monthly', '2028-02-29', null, MiningMath::DIVISOR_CALENDAR));
    }

    public function testEconomicPreviewIsExactAndCompoundingIsOnlyHypothetical(): void
    {
        $e = (new MiningProposalService())->economicPreview('3');
        $this->assertSame(['100.00000000', '500.00000000', '1000.00000000', '5000.00000000', '10000.00000000'], array_column($e['holdings'], 'eligible_coins'));
        $k = $e['holdings'][2]; // 1000 coins
        $this->assertSame('1.07142857', $k['daily_28_days']['half_up']);
        $this->assertSame('1.03448276', $k['daily_29_days']['half_up']);
        $this->assertSame('1.00000000', $k['daily_30_days']['half_up']);
        $this->assertSame('0.96774194', $k['daily_31_days']['half_up']);
        $this->assertSame('1.03448275', $k['daily_29_days']['down']);
        $this->assertSame('30.00000000', $k['theoretical_monthly_issuance']);
        $this->assertSame('360.00000000', $k['annualized_simple_issuance']);
        $this->assertSame('36.0000', $k['annual_simple_percent_of_holding']);
        $this->assertSame('HYPOTHETICAL_NOT_ENABLED', $k['compounding_hypothetical']['status']);
        // independent check of monthly compounding: 1000 x 1.03^12
        $this->assertSame(bcadd(bcmul('1000', bcpow('1.03', '12', 16), 16), '0', 8), $k['compounding_hypothetical']['after_12_months_monthly_compounding']);
        $this->assertStringContainsString('NOT enabled', $e['compounding_note']);
        // linear in holdings
        $this->assertSame('300.00000000', $e['holdings'][4]['theoretical_monthly_issuance']);
        $this->assertSame('3.00000000', $e['holdings'][0]['theoretical_monthly_issuance']);
        // 1000 calendar days of 3% a month ~ 98.56% of the holding (lock-horizon exposure)
        $this->assertSame('985.58064516', $k['lock_1000_day_simple_issuance']);
    }

    public function testNoFloatArithmeticInProposalService(): void
    {
        $src = (string) file_get_contents(APPPATH . 'Services/MiningProposalService.php');
        $this->assertDoesNotMatchRegularExpression('/\(float\)|floatval\(|(?<![:>\w ])round\(|(?<![:>\w ])floor\(|(?<![:>\w ])ceil\(/', $src);
    }

    // ===================================================================== following business date

    public function testFollowingBusinessDateRuleAtBoundaries(): void
    {
        $svc = new MiningProposalService();
        $now = '2026-11-18 08:00:00';
        $cases = [
            ['2026-11-17 06:45:44', '2026-11-18'],
            ['2026-11-16 18:31:00', '2026-11-18'], // 00:01 IST on the 17th
            ['2026-11-17 18:29:59', '2026-11-18'], // 23:59:59 IST on the 17th
            ['2026-11-17 18:30:00', '2026-11-19'], // 00:00:00 IST on the 18th
            ['2026-11-17 19:00:00', '2026-11-19'], // UTC 17th but IST 18th
            ['2026-10-31 18:29:00', '2026-11-01'], // month end
        ];
        foreach ($cases as [$ts, $first]) {
            $this->assertSame($first, $svc->firstEligibleDate($ts, $now)['first_eligible_date'], $ts);
        }
        $this->assertSame('BLOCKED_MISSING_APPROVAL_TIMESTAMP', $svc->firstEligibleDate(null, $now)['state']);
        $this->assertSame('BLOCKED_MISSING_APPROVAL_TIMESTAMP', $svc->firstEligibleDate('', $now)['state']);
        $this->assertSame('FUTURE_DATED_APPROVAL', $svc->firstEligibleDate('2027-01-15 04:30:00', $now)['state']);
        $this->expectException(InvalidArgumentException::class);
        $svc->firstEligibleDate('not-a-date', $now);
    }

    public function testRuleAgreesWithTheEngineCutoffForTheExcludeApprovalDayPolicy(): void
    {
        $engine = new MiningEligibleOwnershipSnapshotService();
        $policy = MiningCalculationPolicy::fromArray(['approval_day' => 'exclude_approval_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'half_up', 'reserved' => 'include', 'maturity' => 'include_all']);
        $rule   = new MiningProposalService();
        $far    = new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC'));
        foreach (['2026-10-30 18:00:00', '2026-10-30 18:29:59', '2026-10-30 18:30:00', '2026-10-30 18:35:00', '2026-11-17 06:45:44', '2026-12-31 18:30:00'] as $ts) {
            $first = $rule->firstEligibleDate($ts, '2030-01-01 00:00:00')['first_eligible_date'];
            foreach (['2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01', '2026-11-17', '2026-11-18', '2026-11-19', '2026-12-31', '2027-01-01', '2027-01-02'] as $date) {
                $cut      = $engine->cutoff($date, $policy, $far)['cutoff_utc'];
                $eligible = $ts < $cut;
                $this->assertSame($date >= $first, $eligible, $ts . ' on ' . $date);
            }
        }
    }

    // ===================================================================== hub drafts stay drafts

    public function testHubDraftsAreCreatedUnapprovedAndWorksheetStaysPending(): void
    {
        $db = db_connect();
        $db->table('users')->insert(['username' => 'p13c1_proposer', 'active' => 1, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
        $uid = (int) $db->insertID();

        // The service method itself only runs on allowlisted isolated DBs (verified in mining:phase13c1-verify on the clone);
        // the PHPUnit test database is intentionally not allowlisted, so create the same drafts through the Hub directly.
        $hub    = new \App\Services\FinancialPolicyService();
        $drafts = [];
        foreach ((new MiningProposalService())->proposals() as $p) {
            $drafts[] = $hub->createDraft('mining', 'PROPOSAL: ' . $p['decision_id'], $p['hub_payload'] + ['proposal_state' => MiningProposalService::STATE], $uid);
        }
        $this->assertCount(4, $drafts);
        $rows = $db->table('financial_policy_versions')->get()->getResultArray();
        $this->assertCount(4, $rows);
        foreach ($rows as $r) {
            $this->assertSame('draft', $r['status']);
            $this->assertNull($r['approved_by']);
            $this->assertNull($r['effective_from'], 'no effective date and no backdating');
            $this->assertSame($uid, (int) $r['created_by']);
            $payload = json_decode((string) $r['payload_json'], true);
            $this->assertTrue($payload['_does_not_enable_kill_switches']);
            $this->assertSame('PROPOSED_NOT_APPROVED', $payload['proposal_state']);
        }
        $ws = service('miningPolicyWorksheetService', false)->build();
        foreach ($ws['decisions'] as $d) {
            $this->assertSame('PENDING_BUSINESS_APPROVAL', $d['approval_state'], $d['id']);
        }
    }

    public function testDraftCreationIsRefusedOnTheLiveDatabaseGroup(): void
    {
        $dbc = config('Database');
        $was = $dbc->defaultGroup;
        $dbc->defaultGroup = 'default';
        try {
            $this->expectException(RuntimeException::class);
            (new MiningProposalService())->createHubDrafts(1);
        } finally {
            $dbc->defaultGroup = $was;
        }
    }

    public function testApprovedRecordWithOwnerLabelIsRejectedByTheWorksheet(): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $db->table('users')->insert(['username' => 'p13c1_a', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $u1 = (int) $db->insertID();
        $db->table('users')->insert(['username' => 'p13c1_b', 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $u2 = (int) $db->insertID();
        $insert = static fn (string $ref, int $v, array $payload): bool => $db->table('financial_policy_versions')->insert([
            'policy_ref' => $ref, 'domain' => 'mining', 'version_no' => $v, 'title' => 't', 'status' => 'approved', 'payload_json' => json_encode($payload),
            'baseline_json' => '{}', 'created_by' => $u1, 'approved_by' => $u2, 'approved_at' => $now, 'lock_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        config('Platform')->miningDayDivisorStatus = 'approved';

        $insert('FPV-TEST-1', 1, ['month_day_divisor_mode' => 'ACTUAL_CALENDAR_DAYS']);
        $row = static fn (): array => array_values(array_filter(service('miningPolicyWorksheetService', false)->build()['decisions'], static fn (array $d): bool => $d['id'] === 'day_divisor'))[0];
        $this->assertSame('POLICY_VALUE_INVALID', $row()['approval_state']);
        $this->assertSame('PENDING_BUSINESS_APPROVAL', $row()['approved_value']);

        $db->query('DELETE FROM financial_policy_versions');
        $insert('FPV-TEST-2', 1, ['month_day_divisor_mode' => 'calendar_days']);
        $this->assertSame('APPROVED_VERIFIED', $row()['approval_state']);
        $this->assertSame('calendar_days', $row()['approved_value']);
    }

    // ===================================================================== switches

    public function testAllSevenSwitchesAreOffAndProductionPostingIsRefused(): void
    {
        $p = config('Platform');
        foreach (['miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled'] as $f) {
            $this->assertFalse((bool) $p->{$f}, $f);
        }
        $msg = (new \App\Services\MiningPostingRunnerService())->authorize(['mode' => 'live', 'database' => 'coin_platform', 'group' => 'default'], true, true, 'I_AUTHORIZE_PRODUCTION_CHANGE');
        $this->assertNotNull($msg);
    }
}
