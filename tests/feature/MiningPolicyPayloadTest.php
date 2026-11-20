<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningPolicyPayload;
use App\Models\MemberProfileModel;
use App\Services\FinancialAutomationService;
use App\Services\FinancialGovernanceService;
use App\Services\MiningProposalService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Canonical Mining policy payload: the draft form / service save exactly the fields the approval validator needs,
 * strict eligibility rejects missing / wrongly named / inconsistent terms, and previously submitted payloads are only
 * ever replaced through an explicit, audited revision (the original stays byte-for-byte). Isolated PHPUnit DB only.
 *
 * @internal
 */
final class MiningPolicyPayloadTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private const ADMIN_PASSWORD = 'AdminPass123!';
    private const OTHER_PASSWORD = 'OtherPass123!';
    private const KILL_SWITCHES  = [
        'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled',
        'commissionAutoPostOnApprovalEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
    ];

    /** What the old Create Policy Draft form stored (no canonical divisor field, no compounding/timezone/version). */
    private const LEGACY = [
        'monthly_percent' => '3', 'day_divisor_mode' => 'calendar_days', 'earning_asset' => 'COIN',
        'approval_day_policy' => 'exclude_approval_day', 'post_maturity_eligibility' => null, 'post_sale_eligibility' => null,
        'decimal_precision' => '8', 'rounding_mode' => null,
    ];

    private int $adminUserId;
    private int $otherAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $p = config('Platform');
        $p->adminEmail                           = 'admin@test.local';
        $p->adminPassword                        = self::ADMIN_PASSWORD;
        $p->adminName                            = 'Test Admin';
        $p->rootSponsorId                        = 'ROOT0001';
        $p->memberIdPrefix                       = 'CPM';
        $p->memberIdStartNumber                  = 1;
        $p->requireVerifiedDepositForApproval    = false;
        $p->mandatoryPurchaseLockEnabled         = true;
        foreach (self::KILL_SWITCHES as $flag) {
            $p->{$flag} = false;
        }
        $p->miningBusinessTimezone               = 'Asia/Kolkata';
        $p->miningMonthDayDivisorMode            = 'calendar_days';
        $p->miningApprovalDayPolicy              = 'exclude_approval_day';
        $p->miningEarningAsset                   = 'COIN';
        $p->miningRoundingMode                   = 'down';
        $p->miningReservedCoinPolicy             = 'include';
        $p->miningMaturityPolicy                 = 'include_all';
        $p->coinPlanLockDays                     = 1000;
        $p->financialGovernanceMode              = 'dual_control';
        $p->financialSingleAdminUserId           = 0;
        $p->financialSingleAdminReviewTtlSeconds = 900;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId  = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->otherAdminId = $this->createAdmin();
        $this->loginAs($this->adminUserId);
    }

    protected function tearDown(): void
    {
        $p                             = config('Platform');
        $p->financialGovernanceMode    = 'dual_control';
        $p->financialSingleAdminUserId = 0;
        $p->miningRoundingMode         = 'down';
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    // ================================================================ canonical save (valid policies)

    public function testFormStylePayloadIsSavedWithCanonicalMandatoryTerms(): void
    {
        $draft = service('financialPolicyService')->createDraft('mining', 'Form draft', [
            'monthly_percent' => '3', 'day_divisor_mode' => 'calendar_days', 'earning_asset' => 'COIN',
            'approval_day_policy' => 'exclude_approval_day', 'decimal_precision' => '8', 'rounding_mode' => 'down',
            'policy_version' => 'MPOL-spoofed0000', // never trusted
        ], $this->adminUserId, date('Y-m-d', strtotime('-30 days')));

        $p = $draft['payload'];
        $this->assertSame('calendar_days', $p['month_day_divisor_mode']);
        $this->assertArrayNotHasKey('day_divisor_mode', $p, 'legacy field name must not be stored');
        $this->assertFalse($p['compounding_enabled']);
        $this->assertSame('Asia/Kolkata', $p['business_timezone']);
        $this->assertSame('monthly', $p['rate_basis']);
        $this->assertSame('down', $p['rounding_mode']);
        $this->assertSame(MiningCalculationPolicy::fromPlatform(config('Platform'))->version(), $p['policy_version'], 'derived from the engine, not input');
        $this->assertNotSame('MPOL-spoofed0000', $p['policy_version']);

        $terms = service('financialGovernanceService')->terms('financial_policy', (string) $draft['policy_ref']);
        $this->assertSame([], $terms['rule_problems']);
        $this->assertNotSame([], $terms['requirements']);
        foreach ($terms['requirements'] as $r) {
            $this->assertTrue($r['ok'], $r['key']);
        }
        foreach (MiningPolicyPayload::mandatoryKeys() as $k) {
            $this->assertArrayHasKey('payload.' . $k, $terms['terms'], 'every mandatory term is listed for review');
        }
    }

    public function testUpdateDraftUsesTheSameCanonicalMapping(): void
    {
        $svc   = service('financialPolicyService');
        $draft = $svc->createDraft('mining', 'Edit me', ['monthly_percent' => '3'], $this->adminUserId);
        $edit  = $svc->updateDraft((string) $draft['policy_ref'], [
            'monthly_percent' => '3', 'day_divisor_mode' => 'calendar_days', 'approval_day_policy' => 'exclude_approval_day',
            'earning_asset' => 'COIN', 'rounding_mode' => 'down',
        ], $this->adminUserId);

        $this->assertSame('calendar_days', $edit['payload']['month_day_divisor_mode']);
        $this->assertArrayNotHasKey('day_divisor_mode', $edit['payload']);
        $this->assertFalse($edit['payload']['compounding_enabled']);
        $this->assertSame(MiningPolicyPayload::runtimeVersion(), $edit['payload']['policy_version']);
        $this->assertSame([], service('financialGovernanceService')->terms('financial_policy', (string) $draft['policy_ref'])['rule_problems']);
    }

    public function testRoundingModeIsSavedWhenSelectedAndValidated(): void
    {
        $svc = service('financialPolicyService');
        $this->assertSame('down', $svc->createDraft('mining', 'r1', ['rounding_mode' => 'down'], $this->adminUserId)['payload']['rounding_mode']);
        $this->assertSame('half_up', $svc->createDraft('mining', 'r2', ['rounding_mode' => 'half_up'], $this->adminUserId)['payload']['rounding_mode']);
        $this->assertNull($svc->createDraft('mining', 'r3', ['rounding_mode' => ''], $this->adminUserId)['payload']['rounding_mode']);
        $this->assertThrowsMessage(fn () => $svc->createDraft('mining', 'r4', ['rounding_mode' => 'banker'], $this->adminUserId), 'rounding_mode must be one of');

        // half_up differs from the engine (down): saved as chosen, but not eligible.
        $half = $svc->createDraft('mining', 'r5', ['rounding_mode' => 'half_up'], $this->adminUserId);
        $this->assertStringContainsString('does not match the engine configuration', implode(' | ', service('financialGovernanceService')->terms('financial_policy', (string) $half['policy_ref'])['rule_problems']));
    }

    public function testDraftRejectsConflictingAndInvalidFields(): void
    {
        $svc = service('financialPolicyService');
        $this->assertThrowsMessage(fn () => $svc->createDraft('mining', 'c1', ['day_divisor_mode' => 'fixed_30', 'month_day_divisor_mode' => 'calendar_days'], $this->adminUserId), 'Conflicting values');
        $this->assertThrowsMessage(fn () => $svc->createDraft('mining', 'c2', ['month_day_divisor_mode' => 'every_other_day'], $this->adminUserId), 'month_day_divisor_mode must be one of');
        $this->assertThrowsMessage(fn () => $svc->createDraft('mining', 'c3', ['approval_day_policy' => 'whenever'], $this->adminUserId), 'approval_day_policy must be');
        $this->assertSame(0, db_connect()->table('financial_policy_versions')->where('title', 'c1')->countAllResults());
    }

    public function testCreateFormPostsCanonicalFieldNamesAndSavesThem(): void
    {
        $page = $this->withSession()->get('admin/financial-policies/create?domain=mining');
        $page->assertOK();
        $page->assertSee('payload[month_day_divisor_mode]');
        $page->assertDontSee('payload[day_divisor_mode]');
        $page->assertSee('policy_version');

        $this->withSession()->post('admin/financial-policies', [
            'domain' => 'mining', 'title' => 'Web mining draft', 'effective_from' => date('Y-m-d', strtotime('-30 days')),
            'payload' => [
                'monthly_percent' => '3', 'month_day_divisor_mode' => 'calendar_days', 'earning_asset' => 'COIN',
                'approval_day_policy' => 'exclude_approval_day', 'post_maturity_eligibility' => 'include_all', 'post_sale_eligibility' => 'include',
                'decimal_precision' => '8', 'rounding_mode' => 'down',
            ],
        ])->assertRedirect();

        $row = db_connect()->table('financial_policy_versions')->where('title', 'Web mining draft')->get()->getRowArray();
        $this->assertNotNull($row);
        $p = json_decode((string) $row['payload_json'], true);
        $this->assertSame('calendar_days', $p['month_day_divisor_mode']);
        $this->assertSame('down', $p['rounding_mode']);
        $this->assertFalse($p['compounding_enabled']);
        $this->assertSame('Asia/Kolkata', $p['business_timezone']);
        $this->assertSame(MiningPolicyPayload::runtimeVersion(), $p['policy_version']);
        $this->assertSame([], service('financialGovernanceService')->terms('financial_policy', (string) $row['policy_ref'])['rule_problems']);
    }

    // ================================================================ strict eligibility

    public function testMissingMandatoryTermsAreRejectedWithPreciseReasons(): void
    {
        $canonical = (new MiningProposalService())->ownerApprovedMiningPolicyPayload();
        $this->assertSame([], MiningPolicyPayload::problems($canonical), 'baseline payload must be eligible');

        foreach (['monthly_percent', 'month_day_divisor_mode', 'approval_day_policy', 'earning_asset', 'compounding_enabled', 'business_timezone', 'rounding_mode', 'policy_version'] as $key) {
            $p = $canonical;
            unset($p[$key]);
            $ref = $this->pendingRaw($p);
            $problems = implode(' | ', service('financialGovernanceService')->terms('financial_policy', $ref)['rule_problems']);
            $this->assertStringContainsString($key . ' is missing', $problems, $key);

            $this->enableSingleAdmin();
            $this->assertThrowsMessage(fn () => service('financialGovernanceService')->review('financial_policy', $ref, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), $key . ' is missing');
            config('Platform')->financialGovernanceMode = 'dual_control';
        }
        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->countAllResults());
    }

    public function testWrongFieldNamesInvalidValuesAndWrongVersionsAreRejected(): void
    {
        $canonical = (new MiningProposalService())->ownerApprovedMiningPolicyPayload();

        $cases = [
            'legacy divisor name only'    => [array_diff_key($canonical, ['month_day_divisor_mode' => 1]) + ['day_divisor_mode' => 'calendar_days'], ['month_day_divisor_mode is missing', 'Legacy field day_divisor_mode']],
            'legacy name next to canonical' => [$canonical + ['day_divisor_mode' => 'calendar_days'], ['Legacy field day_divisor_mode is present']],
            'fixed 30 divisor'            => [['month_day_divisor_mode' => 'fixed_30'] + $canonical, ['Day divisor must be the actual calendar days']],
            'compounding on'              => [['compounding_enabled' => true] + $canonical, ['Compounding must be disabled']],
            'compounding as string'       => [['compounding_enabled' => 'false'] + $canonical, ['Compounding must be disabled']],
            'wrong timezone'              => [['business_timezone' => 'UTC'] + $canonical, ['Business timezone must be Asia/Kolkata']],
            'wrong policy version'        => [['policy_version' => 'MPOL-000000000000'] + $canonical, ['policy_version must match the engine configuration']],
            'wrong percent'               => [['monthly_percent' => '3.5'] + $canonical, ['monthly_percent must be 3']],
            'non numeric percent'         => [['monthly_percent' => 'abc'] + $canonical, ['monthly_percent must be 3']],
            'approval day included'       => [['approval_day_policy' => 'full_day'] + $canonical, ['Approval day must be excluded']],
            'wrong asset'                 => [['earning_asset' => 'USDT'] + $canonical, ['Reward asset must be COIN']],
            'rounding differs from engine' => [['rounding_mode' => 'half_up'] + $canonical, ['does not match the engine configuration']],
            'unknown rounding'            => [['rounding_mode' => 'banker'] + $canonical, ['rounding_mode must be one of']],
            'wrong precision'             => [['decimal_precision' => '6'] + $canonical, ['decimal_precision must be 8']],
            'daily basis'                 => [['rate_basis' => 'daily'] + $canonical, ['rate_basis must be monthly']],
            'post-sale differs from engine' => [['post_sale_eligibility' => 'exclude'] + $canonical, ['post_sale_eligibility must match the engine configuration']],
            'post-maturity unknown value' => [['post_maturity_eligibility' => 'eligible'] + $canonical, ['post_maturity_eligibility must match the engine configuration']],
        ];
        foreach ($cases as $name => [$payload, $needles]) {
            $ref      = $this->pendingRaw($payload);
            $problems = implode(' | ', service('financialGovernanceService')->terms('financial_policy', $ref)['rule_problems']);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $problems, $name);
            }
        }
    }

    public function testPlatformConfigurationDriftBlocksEligibility(): void
    {
        $ref = $this->pendingRaw((new MiningProposalService())->ownerApprovedMiningPolicyPayload());
        $this->assertSame([], service('financialGovernanceService')->terms('financial_policy', $ref)['rule_problems']);

        config('Platform')->miningRoundingMode = 'half_up'; // engine now runs a different policy than the payload's version
        $problems = implode(' | ', service('financialGovernanceService')->terms('financial_policy', $ref)['rule_problems']);
        $this->assertStringContainsString('policy_version must match the engine configuration', $problems);
        $this->assertStringContainsString('rounding_mode down does not match', $problems);

        $this->enableSingleAdmin();
        $this->assertThrowsMessage(fn () => service('financialGovernanceService')->review('financial_policy', $ref, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'policy_version must match');
    }

    // ================================================================ unauthorized / stale

    public function testUnauthorizedApprovalAndStaleReviewFingerprint(): void
    {
        $ref = $this->pendingRaw((new MiningProposalService())->ownerApprovedMiningPolicyPayload());

        // dual_control: the author cannot approve; single_admin: nobody else may.
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($ref, $this->adminUserId, self::ADMIN_PASSWORD), 'Self-approval is not allowed');
        $this->enableSingleAdmin();
        $this->loginAs($this->otherAdminId);
        $this->assertThrowsMessage(fn () => service('financialGovernanceService')->review('financial_policy', $ref, 'approve', $this->otherAdminId, self::OTHER_PASSWORD), 'Only the designated single administrator');
        // A second admin approving the author's policy is ordinary dual-control; what is refused is a NON-designated
        // admin approving their OWN policy.
        $svc = service('financialPolicyService');
        $own = (string) $svc->createDraft('mining', 'Other admin own policy', ['monthly_percent' => '3'], $this->otherAdminId)['policy_ref'];
        $svc->submitForApproval($own, $this->otherAdminId, self::OTHER_PASSWORD);
        $this->assertThrowsMessage(fn () => $svc->approve($own, $this->otherAdminId, self::OTHER_PASSWORD, ['review_ref' => 'x']), 'Only the designated single administrator');
        $this->assertSame('pending_approval', $this->policyStatus($own));

        // A review of a valid policy goes stale when any reviewed term changes afterwards.
        $input = $this->reviewAndBuildInput($ref);
        $row   = db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->get()->getRowArray();
        $p     = json_decode((string) $row['payload_json'], true);
        $p['supply_source'] = 'changed_after_review';
        db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->update(['payload_json' => json_encode($p)]);
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($ref, $this->adminUserId, self::ADMIN_PASSWORD, $input), 'stale version');

        // Tampering with a rule-bearing term is refused by the strict eligibility check even earlier.
        $p['policy_version'] = 'MPOL-tampered00000';
        db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->update(['payload_json' => json_encode($p)]);
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($ref, $this->adminUserId, self::ADMIN_PASSWORD, $input), 'policy_version must match');
        $this->assertSame('pending_approval', $this->policyStatus($ref));
        $this->assertKillSwitchesOff();
    }

    // ================================================================ audited revision of an existing pending policy

    public function testPendingLegacyPolicyIsRevisedThroughAnAuditedNewVersionWithoutMutatingTheOriginal(): void
    {
        $old = $this->pendingRaw(self::LEGACY);
        $before = db_connect()->table('financial_policy_versions')->where('policy_ref', $old)->get()->getRowArray();
        $eventsBefore = db_connect()->table('financial_policy_events')->where('policy_ref', $old)->orderBy('id', 'ASC')->get()->getResultArray();
        $svc  = service('financialPolicyService');
        $fp   = $svc->payloadFingerprint($before);
        $rows = db_connect()->table('financial_policy_versions')->countAllResults();

        // The legacy payload is not eligible, and the page offers the revision.
        $problems = implode(' | ', service('financialGovernanceService')->terms('financial_policy', $old)['rule_problems']);
        foreach (['month_day_divisor_mode is missing', 'Legacy field day_divisor_mode', 'compounding_enabled is missing', 'business_timezone is missing', 'policy_version is missing', 'rounding_mode is missing'] as $needle) {
            $this->assertStringContainsString($needle, $problems);
        }
        $page = $this->withSession()->get('admin/financial-policies/' . $old);
        $page->assertOK();
        $page->assertSee('Mandatory financial terms');
        $page->assertSee('MISSING');
        $page->assertSee('Corrected revision');

        // Refusals change nothing.
        $this->assertThrowsMessage(fn () => $svc->revisePending($old, $this->adminUserId, 'wrong-password', 'Canonical field names and terms', ['rounding_mode' => 'down']), 'eauthentication failed');
        $this->assertThrowsMessage(fn () => $svc->revisePending($old, $this->adminUserId, self::ADMIN_PASSWORD, 'short', ['rounding_mode' => 'down']), 'at least 10 characters');
        $this->assertThrowsMessage(fn () => $svc->revisePending($old, $this->adminUserId, self::ADMIN_PASSWORD, 'Canonical field names and terms', ['monthly_percent' => '3']), 'Only rounding_mode');
        $this->assertSame($rows, db_connect()->table('financial_policy_versions')->countAllResults());
        $this->assertSame('pending_approval', $this->policyStatus($old));

        $new = $svc->revisePending($old, $this->adminUserId, self::ADMIN_PASSWORD, 'Canonical field names and mandatory mining terms', ['rounding_mode' => 'down']);

        // Original: payload and fingerprint untouched, history preserved, closed with a reason.
        $after = db_connect()->table('financial_policy_versions')->where('policy_ref', $old)->get()->getRowArray();
        $this->assertSame($before['payload_json'], $after['payload_json']);
        $this->assertSame($fp, $svc->payloadFingerprint($after));
        $this->assertSame($before['title'], $after['title']);
        $this->assertSame($before['created_by'], $after['created_by']);
        $this->assertSame('rejected', $after['status']);
        $this->assertStringContainsString((string) $new['policy_ref'], (string) $after['rejection_reason']);
        $eventsAfter = db_connect()->table('financial_policy_events')->where('policy_ref', $old)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertSame($eventsBefore, array_slice($eventsAfter, 0, count($eventsBefore)), 'earlier events are untouched');
        $this->assertSame('superseded_by_revision', end($eventsAfter)['event_type']);

        // New version: a draft with the canonical payload, linked to the original and its fingerprint.
        $this->assertSame('draft', $new['status']);
        $this->assertNotSame($old, $new['policy_ref']);
        $this->assertSame($old, $new['payload']['_revision_of']);
        $this->assertSame($fp, $new['payload']['_revision_of_fingerprint']);
        $this->assertSame('calendar_days', $new['payload']['month_day_divisor_mode']);
        $this->assertArrayNotHasKey('day_divisor_mode', $new['payload']);
        $this->assertSame('down', $new['payload']['rounding_mode']);
        $this->assertFalse($new['payload']['compounding_enabled']);
        $this->assertSame(MiningPolicyPayload::runtimeVersion(), $new['payload']['policy_version']);
        $this->assertSame('8', (string) $new['payload']['decimal_precision']);
        $this->assertSame([], service('financialGovernanceService')->terms('financial_policy', (string) $new['policy_ref'])['rule_problems']);

        $ev = db_connect()->table('financial_policy_events')->where('policy_ref', $new['policy_ref'])->where('event_type', 'revision_created')->get()->getRowArray();
        $meta = json_decode((string) $ev['payload_json'], true);
        $this->assertSame($fp, $meta['original_fingerprint']);
        $this->assertSame($svc->payloadFingerprint($new), $meta['revised_fingerprint']);
        $this->assertContains('month_day_divisor_mode', array_column($meta['changes'], 'field'));
        $log = db_connect()->table('audit_logs')->where('action', 'financial_policy.revised')->get()->getRowArray();
        $this->assertNotNull($log);
        $this->assertTrue(json_decode((string) $log['metadata'], true)['kill_switches_unchanged']);

        // The revision approves nothing and creates no governance approval; the closed original can never be approved.
        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->countAllResults());
        $this->assertThrowsMessage(fn () => $svc->approve($old, $this->adminUserId, self::ADMIN_PASSWORD), 'Only pending_approval');
        $this->assertThrowsMessage(fn () => $svc->revisePending((string) $new['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD, 'Revise a draft again', []), 'Only pending_approval');
        $this->assertThrowsMessage(fn () => $svc->revisePending($old, $this->adminUserId, self::ADMIN_PASSWORD, 'Revise the closed one again', []), 'Only pending_approval');

        // Drafts are never accepted by readiness, nor is the rejected original.
        $r = service('financialAutomationService')->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertStringContainsString('No approved/active Mining policy', implode(' | ', $r['blockers']));

        // Normal governance continues with the new version: submit, review, separate confirmation.
        $svc->submitForApproval((string) $new['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        $this->enableSingleAdmin();
        $input = $this->reviewAndBuildInput((string) $new['policy_ref']);
        $svc->approve((string) $new['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD, $input);
        $this->assertSame('approved', $this->policyStatus((string) $new['policy_ref']));
        $rec = db_connect()->table(FinancialGovernanceService::TABLE)->where('subject_ref', $new['policy_ref'])->where('action', 'approve')->get()->getRowArray();
        $this->assertSame('single_admin', $rec['governance_mode']);

        $this->assertKillSwitchesOff();
        $this->assertSame(0, db_connect()->table('mining_wallet_ledger')->countAllResults());
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        foreach (service('financialAutomationService')->controls() as $c) {
            $this->assertFalse($c['enabled'], $c['key']);
        }
    }

    public function testRevisionRefusesUnsuitablePolicies(): void
    {
        $svc = service('financialPolicyService');

        $canonicalPending = $this->pendingRaw((new MiningProposalService())->ownerApprovedMiningPolicyPayload());
        $this->assertThrowsMessage(fn () => $svc->revisePending($canonicalPending, $this->adminUserId, self::ADMIN_PASSWORD, 'Nothing to change here', []), 'nothing to revise');

        $draft = $svc->createDraft('mining', 'Still a draft', ['monthly_percent' => '3'], $this->adminUserId);
        $this->assertThrowsMessage(fn () => $svc->revisePending((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD, 'It is only a draft', []), 'Only pending_approval');

        $commission = $svc->createDraft('commission', 'Comm', ['l1' => '10', 'l2' => '5', 'l3' => '3', 'base' => 'approved_usdt_purchase_amount'], $this->adminUserId);
        $svc->submitForApproval((string) $commission['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);
        $this->assertThrowsMessage(fn () => $svc->revisePending((string) $commission['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD, 'Wrong domain for this', []), 'only supported for Mining');

        // An already chosen rounding mode cannot be swapped by a revision.
        $withRounding = $this->pendingRaw(array_merge(self::LEGACY, ['rounding_mode' => 'half_up']));
        $this->assertThrowsMessage(fn () => $svc->revisePending($withRounding, $this->adminUserId, self::ADMIN_PASSWORD, 'Try to swap the rounding', ['rounding_mode' => 'down']), 'cannot be changed by a revision');
        $this->assertSame('pending_approval', $this->policyStatus($withRounding));
    }

    public function testRevisionOverHttpIsPermissionCheckedAndCsrfPathWorksForTheAdmin(): void
    {
        // Two pending legacy policies: one for the administrator's web flow, one untouched target for the intruders.
        $old    = $this->pendingRaw(self::LEGACY);
        $target = $this->pendingRaw(self::LEGACY);
        $this->assertSame(2, db_connect()->table('financial_policy_versions')->countAllResults());

        // Administrator first (a member/guest request leaves its own Shield session behind, so those steps run last).
        $this->loginAs($this->adminUserId);
        $this->withSession()->post('admin/financial-policies/' . $old . '/revise', ['password' => 'nope', 'reason' => 'Canonical field names and terms', 'rounding_mode' => 'down'])->assertRedirect();
        $this->assertSame('pending_approval', $this->policyStatus($old), 'wrong password changes nothing');
        $this->assertSame(2, db_connect()->table('financial_policy_versions')->countAllResults());

        $this->withSession()->post('admin/financial-policies/' . $old . '/revise', ['password' => self::ADMIN_PASSWORD, 'reason' => 'Canonical field names and terms', 'rounding_mode' => 'down'])->assertRedirect();
        $this->assertSame('rejected', $this->policyStatus($old));
        $this->assertSame(3, db_connect()->table('financial_policy_versions')->countAllResults());
        $this->assertSame('pending_approval', $this->policyStatus($target), 'only the revised policy is closed');

        // Members and guests cannot revise.
        $member = $this->member('intruder');
        $this->loginAs($member['user_id']);
        $this->withSession()->post('admin/financial-policies/' . $target . '/revise', ['password' => 'x', 'reason' => 'Intruder attempt here', 'rounding_mode' => 'down']);
        $this->assertSame('pending_approval', $this->policyStatus($target));
        auth()->logout();
        $this->post('admin/financial-policies/' . $target . '/revise', ['password' => 'x', 'reason' => 'Guest attempt here', 'rounding_mode' => 'down']);
        $this->assertSame('pending_approval', $this->policyStatus($target));
        $this->assertSame(3, db_connect()->table('financial_policy_versions')->countAllResults());
        $this->assertKillSwitchesOff();
    }

    // ================================================================ helpers

    /**
     * A pending Mining policy whose stored payload is exactly $payload (simulates data saved by an older form).
     * Only the payload_json of a still-DRAFT row is written directly; the status change goes through the service.
     *
     * @param array<string, mixed> $payload
     */
    private function pendingRaw(array $payload): string
    {
        $this->loginAs($this->adminUserId);
        $svc   = service('financialPolicyService');
        $draft = $svc->createDraft('mining', 'Mining fixture ' . bin2hex(random_bytes(3)), ['monthly_percent' => '3'], $this->adminUserId, date('Y-m-d', strtotime('-30 days')), 'fixture');
        $ref   = (string) $draft['policy_ref'];
        $payload += ['_domain' => 'mining', '_phase' => '12E', '_does_not_enable_kill_switches' => true];
        db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->update(['payload_json' => json_encode($payload)]);
        $svc->submitForApproval($ref, $this->adminUserId, self::ADMIN_PASSWORD);

        return $ref;
    }

    private function enableSingleAdmin(): void
    {
        $p                             = config('Platform');
        $p->financialGovernanceMode    = 'single_admin';
        $p->financialSingleAdminUserId = $this->adminUserId;
    }

    /** @return array<string, mixed> */
    private function reviewAndBuildInput(string $ref): array
    {
        $this->loginAs($this->adminUserId);
        $r = service('financialGovernanceService')->review('financial_policy', $ref, 'approve', $this->adminUserId, self::ADMIN_PASSWORD);

        return [
            'password' => self::ADMIN_PASSWORD, 'review_ref' => $r['review_ref'], 'terms_hash' => $r['terms_hash'],
            'confirm' => $r['confirm_phrase'], 'confirmed_terms' => array_keys($r['terms']), 'acknowledge' => true,
        ];
    }

    private function policyStatus(string $ref): string
    {
        return (string) db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->get()->getRow('status');
    }

    private function assertThrowsMessage(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException | RuntimeException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        } catch (Throwable $e) {
            $this->fail('Unexpected ' . $e::class . ': ' . $e->getMessage());
        }
        $this->fail('Expected an exception containing "' . $needle . '".');
    }

    private function assertKillSwitchesOff(): void
    {
        foreach (self::KILL_SWITCHES as $flag) {
            $this->assertFalse((bool) config('Platform')->{$flag}, $flag . ' must stay false');
        }
    }

    private function loginAs(int $userId): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        auth()->login(model(UserModel::class)->findById($userId));
    }

    private function createAdmin(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'mpother', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'mp.other@test.local', 'password' => self::OTHER_PASSWORD]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /** @return array{user_id: int, member_id: string} */
    private function member(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('mp' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'mp-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'MP ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'mp' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }
}
