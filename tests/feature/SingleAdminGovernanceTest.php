<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\FinancialAutomationService;
use App\Services\FinancialGovernanceService;
use App\Services\MiningProposalService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Single-admin financial governance: opt-in self-approval of the owner-approved Mining / Commission terms with
 * review → separate confirmation, fresh password, typed phrase, per-term confirmation, stale-version and replay
 * protection, readiness recognition, and no switch / ledger side effects. PHPUnit database only.
 *
 * @internal
 */
final class SingleAdminGovernanceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private const ADMIN_PASSWORD  = 'AdminPass123!';
    private const OTHER_PASSWORD  = 'OtherPass123!';
    private const KILL_SWITCHES   = [
        'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled',
        'commissionAutoPostOnApprovalEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
    ];

    private int $adminUserId;
    private int $otherAdminId;
    private string $bizDate;

    protected function setUp(): void
    {
        parent::setUp();

        $p = config('Platform');
        $p->adminEmail                        = 'admin@test.local';
        $p->adminPassword                     = self::ADMIN_PASSWORD;
        $p->adminName                         = 'Test Admin';
        $p->rootSponsorId                     = 'ROOT0001';
        $p->memberIdPrefix                    = 'CPM';
        $p->memberIdStartNumber               = 1;
        $p->requireVerifiedDepositForApproval = false;
        $p->mandatoryPurchaseLockEnabled      = true;
        foreach (self::KILL_SWITCHES as $flag) {
            $p->{$flag} = false;
        }
        $p->miningBusinessTimezone            = 'Asia/Kolkata';
        $p->miningMonthDayDivisorMode         = 'calendar_days';
        $p->miningApprovalDayPolicy           = 'exclude_approval_day';
        $p->miningEarningAsset                = 'COIN';
        $p->miningRoundingMode                = 'down';
        $p->miningReservedCoinPolicy          = 'include';
        $p->miningMaturityPolicy              = 'include_all';
        $p->coinPlanLockDays                  = 1000;
        $p->automationHeartbeatMaxAgeSeconds  = 1800;
        $p->financialGovernanceMode           = 'dual_control';
        $p->financialSingleAdminUserId        = 0;
        $p->financialSingleAdminReviewTtlSeconds = 900;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId  = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->otherAdminId = $this->createAdmin();
        $this->bizDate      = (new DateTimeImmutable('now', new DateTimeZone('Asia/Kolkata')))->modify('-1 day')->format('Y-m-d');

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'sa price');
        service('coinInventoryService')->addInventory('100000', $this->adminUserId, 'sa stock', null, 'sa-stock-' . bin2hex(random_bytes(3)));
        $this->loginAs($this->adminUserId);
    }

    protected function tearDown(): void
    {
        $p = config('Platform');
        $p->financialGovernanceMode    = 'dual_control';
        $p->financialSingleAdminUserId = 0;
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    // ================================================================ mode configuration

    public function testDualControlIsTheDefaultAndStillRefusesSelfApproval(): void
    {
        $this->assertSame('dual_control', (new \Config\Platform())->financialGovernanceMode, 'shipped default must stay dual_control');
        $gov = service('financialGovernanceService');
        $this->assertFalse($gov->isSingleAdmin());

        $policy = $this->pendingMiningPolicy();
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($policy, $this->adminUserId, self::ADMIN_PASSWORD), 'Self-approval is not allowed');

        $rate = $this->draftRate();
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $this->fullInputPlaceholder()), 'Self-approval of mining rates is not allowed');

        $plan = $this->draftPlan();
        $this->assertThrowsMessage(fn () => service('commissionPlanService')->approve($plan, $this->adminUserId, $this->fullInputPlaceholder()), 'Self-approval is not allowed');

        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'dual_control');

        $this->assertSame('pending_approval', $this->policyStatus($policy));
        $this->assertSame('draft', $this->rateRow($rate)['status']);
        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->where('governance_mode', 'single_admin')->countAllResults());
    }

    public function testSingleAdminModeWithoutDesignatedUserIsRefused(): void
    {
        config('Platform')->financialGovernanceMode = 'single_admin';
        $gov = service('financialGovernanceService');
        $this->assertFalse($gov->status()['ready']);
        $this->assertStringContainsString('financialSingleAdminUserId', implode(' ', $gov->status()['problems']));

        $rate = $this->draftRate();
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'not fully configured');
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $this->fullInputPlaceholder()), 'not fully configured');
        $this->assertSame('draft', $this->rateRow($rate)['status']);
    }

    public function testOnlyTheDesignatedAdministratorMaySelfApprove(): void
    {
        $this->enableSingleAdmin();
        $this->loginAs($this->otherAdminId);
        $rate = service('miningRateService')->createDraft(['effective_month' => substr($this->bizDate, 0, 7), 'rate_percent' => '3', 'rate_basis' => 'monthly'], $this->otherAdminId)['rate_ref'];

        $this->assertThrowsMessage(
            fn () => service('financialGovernanceService')->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->otherAdminId, self::OTHER_PASSWORD),
            'Only the designated single administrator'
        );
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->otherAdminId, ['password' => self::OTHER_PASSWORD]), 'Only the designated single administrator');
        $this->assertSame('draft', $this->rateRow($rate)['status']);

        // A different admin approving someone else's draft is ordinary dual-control and still works.
        $this->loginAs($this->adminUserId);
        service('miningRateService')->approve($rate, $this->adminUserId);
        $this->assertSame('approved', $this->rateRow($rate)['status']);
        $rec = db_connect()->table(FinancialGovernanceService::TABLE)->where('subject_ref', $rate)->where('action', 'approve')->get()->getRowArray();
        $this->assertSame('dual_control', $rec['governance_mode']);
    }

    // ================================================================ happy path + readiness

    public function testFullSingleAdminLifecycleIsRecordedAndRecognisedByReadiness(): void
    {
        $this->enableSingleAdmin();
        $m = $this->member('buyer');
        $this->buyApprove($m, '100');
        $ledgerBefore = $this->ledgerCounts();

        $rate   = $this->draftRate();
        $plan   = $this->draftPlan();
        $mining = $this->pendingMiningPolicy();
        $comm   = $this->pendingCommissionPolicy();

        $this->soleApprove(FinancialGovernanceService::SUBJECT_RATE, $rate);
        $this->soleApprove(FinancialGovernanceService::SUBJECT_PLAN, $plan);
        $this->soleApprove(FinancialGovernanceService::SUBJECT_POLICY, $mining);
        $this->soleApprove(FinancialGovernanceService::SUBJECT_POLICY, $mining, 'activate');
        $this->soleApprove(FinancialGovernanceService::SUBJECT_POLICY, $comm);

        $this->assertSame('approved', $this->rateRow($rate)['status']);
        $this->assertSame('approved', service('commissionPlanService')->findByRef($plan)['status']);
        $this->assertSame('active', $this->policyStatus($mining));
        $this->assertSame('approved', $this->policyStatus($comm));

        // Every approval/activation records admin, timestamp, version, action and governance mode.
        $trail = db_connect()->table(FinancialGovernanceService::TABLE)->where('subject_ref', $mining)->orderBy('id', 'ASC')->get()->getResultArray();
        $this->assertSame(['review_approve', 'approve', 'review_activate', 'activate'], array_column($trail, 'action'));
        foreach ($trail as $t) {
            $this->assertSame('single_admin', $t['governance_mode']);
            $this->assertSame($this->adminUserId, (int) $t['actor_user_id']);
            $this->assertNotEmpty($t['created_at']);
            $this->assertNotSame('', (string) $t['subject_version']);
            $this->assertSame(64, strlen((string) $t['terms_hash']));
        }
        $this->assertSame($trail[0]['action_ref'], $trail[1]['review_ref']);
        $this->assertSame($trail[2]['action_ref'], $trail[3]['review_ref']);

        $events = db_connect()->table('financial_policy_events')->where('policy_ref', $mining)->whereIn('event_type', ['approved', 'activated'])->get()->getResultArray();
        $this->assertCount(2, $events);
        foreach ($events as $ev) {
            $this->assertSame('single_admin', json_decode((string) $ev['payload_json'], true)['governance_mode']);
        }
        foreach (['financial_policy.approved', 'financial_policy.activated', 'mining.rate_approved', 'commission.plan_approved'] as $action) {
            $log = db_connect()->table('audit_logs')->where('action', $action)->orderBy('id', 'DESC')->get()->getRowArray();
            $this->assertNotNull($log, $action);
            $this->assertSame('single_admin', json_decode((string) $log['metadata'], true)['governance_mode'] ?? null, $action);
        }

        // Readiness recognises the valid single-admin approvals.
        service('financialAutomationService')->heartbeat();
        $svc = service('financialAutomationService');
        foreach ([FinancialAutomationService::CONTROL_MINING, FinancialAutomationService::CONTROL_COMMISSION] as $control) {
            $r = $svc->readiness($control);
            $this->assertTrue($r['ready'], $control . ': ' . implode(' | ', $r['blockers']));
        }
        $detail = implode(' | ', array_column($svc->readiness(FinancialAutomationService::CONTROL_MINING)['checks'], 'detail'));
        $this->assertStringContainsString('single_admin governance', $detail);

        // ... but nothing was switched on or posted.
        foreach ($svc->controls() as $c) {
            $this->assertFalse($c['enabled'], $c['key']);
        }
        $this->assertKillSwitchesOff();
        $this->assertSame($ledgerBefore, $this->ledgerCounts());
    }

    // ================================================================ reauthentication / confirmation

    public function testInvalidPasswordIsRefusedAtReviewAndAtConfirmation(): void
    {
        $this->enableSingleAdmin();
        $gov  = service('financialGovernanceService');
        $rate = $this->draftRate();

        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId, 'wrong-password'), 'Password reauthentication failed');
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId, ''), 'reauthentication is required');
        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->countAllResults());

        $input             = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_RATE, $rate);
        $input['password'] = 'wrong-password';
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $input), 'Password reauthentication failed');

        $policy = $this->pendingMiningPolicy();
        $pInput = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_POLICY, $policy);
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($policy, $this->adminUserId, 'wrong-password', $pInput), 'Password');

        $this->assertSame('draft', $this->rateRow($rate)['status']);
        $this->assertSame('pending_approval', $this->policyStatus($policy));
    }

    public function testConfirmationNeedsThePhraseEveryTermAndTheAcknowledgement(): void
    {
        $this->enableSingleAdmin();
        $plan  = $this->draftPlan();
        $input = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_PLAN, $plan);
        $svc   = service('commissionPlanService');

        $this->assertThrowsMessage(fn () => $svc->approve($plan, $this->adminUserId, ['confirm' => 'approve'] + $input), 'Type the confirmation exactly');
        $missing                    = $input;
        $missing['confirmed_terms'] = array_values(array_diff($input['confirmed_terms'], ['level_3_percent']));
        $this->assertThrowsMessage(fn () => $svc->approve($plan, $this->adminUserId, $missing), 'level_3_percent');
        $this->assertThrowsMessage(fn () => $svc->approve($plan, $this->adminUserId, ['acknowledge' => false] + $input), 'Acknowledge');
        $this->assertThrowsMessage(fn () => $svc->approve($plan, $this->adminUserId, ['review_ref' => ''] + $input), 'Review the terms first');
        $this->assertSame('draft', $svc->findByRef($plan)['status']);

        // The review is still unused, so the correct confirmation succeeds.
        $svc->approve($plan, $this->adminUserId, $input);
        $this->assertSame('approved', $svc->findByRef($plan)['status']);
    }

    // ================================================================ stale versions / replay

    public function testStaleOrTamperedVersionsAreRefused(): void
    {
        $this->enableSingleAdmin();
        $policies = service('financialPolicyService');

        // Payload changed after the review.
        $policy = $this->pendingMiningPolicy();
        $input  = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_POLICY, $policy);
        $row    = db_connect()->table('financial_policy_versions')->where('policy_ref', $policy)->get()->getRowArray();
        $p      = json_decode((string) $row['payload_json'], true);
        $p['residual_policy'] = 'carry_forward';
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policy)->update(['payload_json' => json_encode($p)]);
        $this->assertThrowsMessage(fn () => $policies->approve($policy, $this->adminUserId, self::ADMIN_PASSWORD, $input), 'stale version');

        // Optimistic lock mismatch.
        $input = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_POLICY, $policy);
        $this->assertThrowsMessage(fn () => $policies->approve($policy, $this->adminUserId, self::ADMIN_PASSWORD, $input, (int) $row['lock_version'] + 5), 'Optimistic lock mismatch');

        // Client posts the hash of an older version.
        $this->assertThrowsMessage(fn () => $policies->approve($policy, $this->adminUserId, self::ADMIN_PASSWORD, ['terms_hash' => str_repeat('a', 64)] + $input), 'stale version');
        $this->assertSame('pending_approval', $this->policyStatus($policy));

        // Plan edited after the review.
        $plan  = $this->draftPlan();
        $pIn   = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_PLAN, $plan);
        service('commissionPlanService')->updateDraft($plan, [
            'plan_name' => 'Renamed after review', 'effective_from' => date('Y-m-d', strtotime('-60 days')), 'level_count' => 3,
            'level_1_percent' => '10', 'level_2_percent' => '5', 'level_3_percent' => '3',
        ], $this->adminUserId);
        $this->assertThrowsMessage(fn () => service('commissionPlanService')->approve($plan, $this->adminUserId, $pIn), 'stale version');
        $this->assertSame('draft', service('commissionPlanService')->findByRef($plan)['status']);
    }

    public function testDuplicateApprovalAndReviewReuseAreRefused(): void
    {
        $this->enableSingleAdmin();
        $rate  = $this->draftRate();
        $input = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_RATE, $rate);
        service('miningRateService')->approve($rate, $this->adminUserId, $input);

        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $input), 'Only draft rates');

        // Even if the row were forced back to draft, the consumed review cannot be replayed.
        db_connect()->table('mining_rate_configs')->where('rate_ref', $rate)->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $input), 'already used');

        // An approve review cannot be used to activate.
        $policy = $this->pendingMiningPolicy();
        $pIn    = $this->soleApprove(FinancialGovernanceService::SUBJECT_POLICY, $policy);
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->activate($policy, $this->adminUserId, self::ADMIN_PASSWORD, $pIn), 'No matching single-admin review');
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($policy, $this->adminUserId, self::ADMIN_PASSWORD, $pIn), 'Only pending_approval');
        $this->assertSame('approved', $this->policyStatus($policy));
        $this->assertSame(1, db_connect()->table(FinancialGovernanceService::TABLE)->where('subject_ref', $policy)->where('action', 'approve')->countAllResults());
    }

    public function testReviewExpires(): void
    {
        $this->enableSingleAdmin();
        $rate  = $this->draftRate();
        $input = $this->reviewAndBuildInput(FinancialGovernanceService::SUBJECT_RATE, $rate);
        db_connect()->table(FinancialGovernanceService::TABLE)->where('action_ref', $input['review_ref'])->update(['created_at' => date('Y-m-d H:i:s', time() - 3600)]);

        $this->assertNull(service('financialGovernanceService')->pendingReview(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId));
        $this->assertThrowsMessage(fn () => service('miningRateService')->approve($rate, $this->adminUserId, $input), 'expired');
        $this->assertSame('draft', $this->rateRow($rate)['status']);
    }

    // ================================================================ business-rule limits

    public function testTermsOutsideTheOwnerApprovedRulesCannotBeSingleAdminApproved(): void
    {
        $this->enableSingleAdmin();
        $gov = service('financialGovernanceService');

        $rate = service('miningRateService')->createDraft(['effective_month' => substr($this->bizDate, 0, 7), 'rate_percent' => '4', 'rate_basis' => 'monthly'], $this->adminUserId)['rate_ref'];
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'exactly 3% monthly');

        $plan = service('commissionPlanService')->createDraft([
            'plan_name' => 'Bad', 'effective_from' => date('Y-m-d', strtotime('-60 days')), 'level_count' => 3,
            'level_1_percent' => '10', 'level_2_percent' => '5', 'level_3_percent' => '2',
        ], $this->adminUserId)['plan_ref'];
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_PLAN, $plan, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'Level 3 must be 3%');

        $payload                        = (new MiningProposalService())->ownerApprovedMiningPolicyPayload();
        $payload['compounding_enabled'] = true;
        $bad                            = $this->pendingPolicy('mining', $payload);
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_POLICY, $bad, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'Compounding must be disabled');

        $p2p = $this->pendingPolicy('p2p', ['min_transfer' => '1']);
        $this->assertThrowsMessage(fn () => $gov->review(FinancialGovernanceService::SUBJECT_POLICY, $p2p, 'approve', $this->adminUserId, self::ADMIN_PASSWORD), 'needs dual-control');
        $this->assertThrowsMessage(fn () => service('financialPolicyService')->approve($p2p, $this->adminUserId, self::ADMIN_PASSWORD, ['review_ref' => 'x']), 'needs dual-control');

        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->countAllResults());
        $this->assertSame('pending_approval', $this->policyStatus($p2p));
    }

    // ================================================================ readiness blockers

    public function testReadinessRejectsDraftsAndSelfApprovalsWithoutAValidGovernanceRecord(): void
    {
        $this->enableSingleAdmin();
        $m = $this->member('buyer');
        $this->buyApprove($m, '100');
        $this->soleApprove(FinancialGovernanceService::SUBJECT_RATE, $this->draftRate());
        $this->soleApprove(FinancialGovernanceService::SUBJECT_PLAN, $this->draftPlan());
        service('financialAutomationService')->heartbeat();
        $svc = service('financialAutomationService');

        // A draft is never treated as approved.
        $draft = service('financialPolicyService')->createDraft('mining', 'Draft only', (new MiningProposalService())->ownerApprovedMiningPolicyPayload(), $this->adminUserId, date('Y-m-d', strtotime('-30 days')));
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('No approved/active Mining policy', implode(' | ', $r['blockers']));

        // Forced "approved" by its author without the governance workflow.
        db_connect()->table('financial_policy_versions')->where('policy_ref', $draft['policy_ref'])->update([
            'status' => 'approved', 'submitted_by' => $this->adminUserId, 'approved_by' => $this->adminUserId,
        ]);
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('no single-admin governance record', implode(' | ', $r['blockers']));
        db_connect()->table('financial_policy_versions')->where('policy_ref', $draft['policy_ref'])->update(['status' => 'rejected']);

        // Valid approval, then terms tampered afterwards.
        $policy = $this->pendingMiningPolicy();
        $this->soleApprove(FinancialGovernanceService::SUBJECT_POLICY, $policy);
        $this->assertTrue($svc->readiness(FinancialAutomationService::CONTROL_MINING)['ready'], implode(' | ', $svc->readiness(FinancialAutomationService::CONTROL_MINING)['blockers']));
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policy)->update(['title' => 'Edited after approval']);
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('terms changed after the single-admin approve', implode(' | ', $r['blockers']));

        // Switching back to dual_control withdraws recognition of self-approvals.
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policy)->update(['title' => 'Mining policy']);
        $this->assertTrue($svc->readiness(FinancialAutomationService::CONTROL_MINING)['ready']);
        config('Platform')->financialGovernanceMode = 'dual_control';
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('not independent', implode(' | ', $r['blockers']));

        // A self-approval by somebody other than the designated admin is never recognised.
        $this->enableSingleAdmin();
        config('Platform')->financialSingleAdminUserId = $this->otherAdminId;
        $r = $svc->readiness(FinancialAutomationService::CONTROL_MINING);
        $this->assertFalse($r['ready']);
        $this->assertStringContainsString('not the designated single administrator', implode(' | ', $r['blockers']));
        $this->assertKillSwitchesOff();
    }

    // ================================================================ HTTP flow + access control

    public function testWebReviewThenSeparateConfirmation(): void
    {
        $this->enableSingleAdmin();
        $rate = $this->draftRate();
        $base = 'admin/financial-governance/mining_rate/' . $rate . '/approve';
        $gov  = service('financialGovernanceService');

        $page = $this->withSession()->get($base);
        $page->assertOK();
        $page->assertSee('Step 1');
        $page->assertSee('rate_percent');

        $this->withSession()->post($base . '/review', ['password' => 'nope'])->assertRedirectTo(site_url($base));
        $this->assertNull($gov->pendingReview(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId));

        $this->withSession()->post($base . '/review', ['password' => self::ADMIN_PASSWORD])->assertRedirectTo(site_url($base));
        $review = $gov->pendingReview(FinancialGovernanceService::SUBJECT_RATE, $rate, 'approve', $this->adminUserId);
        $this->assertNotNull($review);
        $this->assertSame('draft', $this->rateRow($rate)['status'], 'the review step alone never approves');
        $this->withSession()->get($base)->assertSee('Step 2');

        $terms = $gov->terms(FinancialGovernanceService::SUBJECT_RATE, $rate);
        $post  = [
            'review_ref'      => $review['action_ref'],
            'terms_hash'      => $terms['terms_hash'],
            'confirmed_terms' => array_keys($terms['terms']),
            'acknowledge'     => '1',
            'confirm'         => 'APPROVE ' . $rate,
            'password'        => self::ADMIN_PASSWORD,
        ];
        $this->withSession()->post($base . '/confirm', ['acknowledge' => '0'] + $post)->assertRedirectTo(site_url($base));
        $this->assertSame('draft', $this->rateRow($rate)['status']);

        $this->withSession()->post($base . '/confirm', $post)->assertRedirectTo(site_url('admin/mining/rates/' . $rate));
        $this->assertSame('approved', $this->rateRow($rate)['status']);
        $this->assertKillSwitchesOff();
    }

    public function testUnauthorizedAccessIsRefused(): void
    {
        $rate = $this->draftRate();
        $base = 'admin/financial-governance/mining_rate/' . $rate . '/approve';

        // dual_control: even the administrator cannot reach the single-admin workflow.
        $this->withSession()->get($base)->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->post($base . '/review', ['password' => self::ADMIN_PASSWORD])->assertRedirectTo(site_url('admin/dashboard'));

        $this->enableSingleAdmin();
        // Unsupported subject / intent.
        $this->withSession()->get('admin/financial-governance/mining_rate/' . $rate . '/activate')->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->get('admin/financial-governance/withdrawal/' . $rate . '/approve')->assertRedirectTo(site_url('admin/dashboard'));

        // Another admin is not the designated administrator.
        $this->loginAs($this->otherAdminId);
        $this->withSession()->get($base)->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->post($base . '/review', ['password' => self::OTHER_PASSWORD])->assertRedirectTo(site_url('admin/dashboard'));

        // A member account has no admin access at all.
        $m = $this->member('intruder');
        $this->loginAs($m['user_id']);
        $res = $this->withSession()->get($base);
        $this->assertTrue($res->isRedirect());
        $this->assertStringNotContainsString('Step 1', (string) $res->getBody());

        // Guests are redirected to login.
        auth()->logout();
        $this->assertTrue($this->get($base)->isRedirect());

        $this->assertSame('draft', $this->rateRow($rate)['status']);
        $this->assertSame(0, db_connect()->table(FinancialGovernanceService::TABLE)->countAllResults());
    }

    public function testGovernanceStatusCommandIsReadOnly(): void
    {
        $this->assertSame('governance:status', (new \ReflectionProperty(\App\Commands\GovernanceStatus::class, 'name'))->getDefaultValue());
        $bad = \App\Libraries\CliArgs::parse(['--ref', 'X', '--type', 'withdrawal'], \App\Commands\GovernanceStatus::argSpec());
        $this->assertNotSame([], $bad['errors']);
        $ok = \App\Libraries\CliArgs::parse(['--type', 'financial_policy', '--ref', 'FPV-MIN-20261118-6611BC', '--group', 'phase14'], \App\Commands\GovernanceStatus::argSpec());
        $this->assertSame([], $ok['errors']);
    }

    // ================================================================ helpers

    private function enableSingleAdmin(): void
    {
        $p                             = config('Platform');
        $p->financialGovernanceMode    = 'single_admin';
        $p->financialSingleAdminUserId = $this->adminUserId;
        $this->assertTrue(service('financialGovernanceService')->status()['ready'], implode(' ', service('financialGovernanceService')->status()['problems']));
    }

    /** @return array<string, mixed> */
    private function reviewAndBuildInput(string $type, string $ref, string $intent = 'approve'): array
    {
        $this->loginAs($this->adminUserId);
        $r = service('financialGovernanceService')->review($type, $ref, $intent, $this->adminUserId, self::ADMIN_PASSWORD);

        return [
            'password'        => self::ADMIN_PASSWORD,
            'review_ref'      => $r['review_ref'],
            'terms_hash'      => $r['terms_hash'],
            'confirm'         => $r['confirm_phrase'],
            'confirmed_terms' => array_keys($r['terms']),
            'acknowledge'     => true,
        ];
    }

    /** @return array<string, mixed> the input used */
    private function soleApprove(string $type, string $ref, string $intent = 'approve'): array
    {
        $input = $this->reviewAndBuildInput($type, $ref, $intent);
        match (true) {
            $type === FinancialGovernanceService::SUBJECT_RATE => service('miningRateService')->approve($ref, $this->adminUserId, $input),
            $type === FinancialGovernanceService::SUBJECT_PLAN => service('commissionPlanService')->approve($ref, $this->adminUserId, $input),
            $intent === 'activate'                            => service('financialPolicyService')->activate($ref, $this->adminUserId, self::ADMIN_PASSWORD, $input),
            default                                           => service('financialPolicyService')->approve($ref, $this->adminUserId, self::ADMIN_PASSWORD, $input),
        };

        return $input;
    }

    /** @return array<string, mixed> */
    private function fullInputPlaceholder(): array
    {
        return ['password' => self::ADMIN_PASSWORD, 'review_ref' => 'FGA-X', 'terms_hash' => str_repeat('0', 64), 'confirm' => 'x', 'confirmed_terms' => [], 'acknowledge' => true];
    }

    private function draftRate(): string
    {
        return (string) service('miningRateService')->createDraft([
            'effective_month' => substr($this->bizDate, 0, 7), 'rate_percent' => '3.00000000', 'rate_basis' => 'monthly',
        ], $this->adminUserId)['rate_ref'];
    }

    private function draftPlan(): string
    {
        return (string) service('commissionPlanService')->createDraft([
            'plan_name' => 'NGW 10/5/3', 'effective_from' => date('Y-m-d', strtotime('-60 days')), 'level_count' => 3,
            'level_1_percent' => '10', 'level_2_percent' => '5', 'level_3_percent' => '3',
        ], $this->adminUserId)['plan_ref'];
    }

    private function pendingMiningPolicy(): string
    {
        return $this->pendingPolicy('mining', (new MiningProposalService())->ownerApprovedMiningPolicyPayload());
    }

    private function pendingCommissionPolicy(): string
    {
        return $this->pendingPolicy('commission', ['l1' => '10', 'l2' => '5', 'l3' => '3', 'base' => 'approved_usdt_purchase_amount']);
    }

    /** @param array<string, mixed> $payload */
    private function pendingPolicy(string $domain, array $payload): string
    {
        $this->loginAs($this->adminUserId);
        $policies = service('financialPolicyService');
        $draft    = $policies->createDraft($domain, ucfirst($domain) . ' policy', $payload, $this->adminUserId, date('Y-m-d', strtotime('-30 days')), 'single-admin test');
        $policies->submitForApproval((string) $draft['policy_ref'], $this->adminUserId, self::ADMIN_PASSWORD);

        return (string) $draft['policy_ref'];
    }

    private function policyStatus(string $ref): string
    {
        return (string) db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->get()->getRow('status');
    }

    /** @return array<string, mixed> */
    private function rateRow(string $ref): array
    {
        return db_connect()->table('mining_rate_configs')->where('rate_ref', $ref)->get()->getRowArray();
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

    /** @return array{mining: int, commission: int} */
    private function ledgerCounts(): array
    {
        return [
            'mining'     => db_connect()->table('mining_wallet_ledger')->countAllResults(),
            'commission' => db_connect()->table('commission_wallet_ledger_entries')->countAllResults(),
        ];
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
        $user  = new User(['username' => 'saother', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'sa.other@test.local', 'password' => self::OTHER_PASSWORD]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /** @return array{user_id: int, member_id: string} */
    private function member(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('sa' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'sa-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'SA ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'sa' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }

    /** @param array{user_id: int, member_id: string} $member */
    private function buyApprove(array $member, string $qty): string
    {
        $req  = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'sa-' . $member['user_id'] . '-' . bin2hex(random_bytes(3)));
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
