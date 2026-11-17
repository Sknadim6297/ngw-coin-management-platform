<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 7C commission plan configuration & simulation (non-payable).
 *
 * @internal
 */
final class CommissionSimulationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $platform = config('Platform');
        $platform->adminEmail    = 'admin@test.local';
        $platform->adminPassword = 'AdminPass123!';
        $platform->adminName     = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApproverOnly();

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'commission listing');
        service('coinInventoryService')->addInventory('20000', $this->adminUserId, 'commission stock', null, 'comm-stock-' . bin2hex(random_bytes(3)));

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    private function createApproverOnly(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'commapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'comm.approver@test.local',
            'password' => 'ApproverPass123!',
        ]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    private function createOperator(): int
    {
        $suffix = bin2hex(random_bytes(3));
        $users  = model(UserModel::class);
        $user   = new User(['username' => 'commop' . $suffix, 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'comm.op.' . $suffix . '@test.local',
            'password' => 'OperatorPass123!',
        ]);
        $user->addGroup('admin');
        $user->addGroup('finance_operator');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string, profile: object, email: string}
     */
    private function registerMember(string $suffix, string $sponsorId = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('c' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'Comm ' . $suffix,
            'sponsor_id'  => $sponsorId,
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'comm' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);
        // Activate so they can sponsor downlines and receive commissions under active policy.
        db_connect()->table('member_profiles')->where('user_id', (int) $result['user']->id)->update([
            'account_status' => 'active',
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
            'profile'   => $profile,
            'email'     => 'comm' . $suffix . '@example.com',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvePurchase(array $member, string $qty = '200'): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'comm-buy-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $approved = service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'comm credit');
        // Mark payment verified for approved_verified_usdt policy tests.
        db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->update(['payment_status' => 'verified']);

        $row = db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->get()
            ->getRowArray();

        return $row ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function createAndApprovePlan(array $overrides = []): array
    {
        $operatorId = $this->createOperator();
        $draft = service('commissionPlanService')->createDraft(array_merge([
            'plan_name'                  => 'Test Plan',
            'effective_from'             => date('Y-m-d'),
            'effective_to'               => '',
            'level_count'                => 3,
            'level_1_percent'            => '10',
            'level_2_percent'            => '5',
            'level_3_percent'            => '3',
            'commission_currency'        => 'USDT',
            'eligible_purchase_policy'   => 'approved_verified_usdt',
            'sponsor_eligibility_policy' => 'active_sponsors_only',
        ], $overrides), $operatorId);

        return service('commissionPlanService')->approve($draft['plan_ref'], $this->approverUserId);
    }

    public function testDraftCreation(): void
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'Draft A',
            'effective_from'  => date('Y-m-d'),
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);

        $this->assertSame('draft', $draft['status']);
        $this->assertSame('10.00000000', $draft['levels'][1]);
    }

    public function testInvalidPercentageRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        service('commissionPlanService')->createDraft([
            'plan_name'       => 'Bad %',
            'effective_from'  => date('Y-m-d'),
            'level_count'     => 1,
            'level_1_percent' => '0',
        ], $this->adminUserId);
    }

    public function testSelfApprovalBlocked(): void
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'Self',
            'effective_from'  => date('Y-m-d'),
            'level_count'     => 1,
            'level_1_percent' => '10',
        ], $this->adminUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('commissionPlanService')->approve($draft['plan_ref'], $this->adminUserId);
    }

    public function testOverlappingApprovedPlansRejected(): void
    {
        $this->createAndApprovePlan(['plan_name' => 'Overlap A']);
        $operatorId = $this->createOperator();
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'       => 'Overlap B',
            'effective_from'  => date('Y-m-d'),
            'level_count'     => 1,
            'level_1_percent' => '8',
        ], $operatorId);

        $this->expectException(\InvalidArgumentException::class);
        service('commissionPlanService')->approve($draft['plan_ref'], $this->approverUserId);
    }

    public function testApprovedPlanImmutable(): void
    {
        $plan = $this->createAndApprovePlan(['plan_name' => 'Immutable']);
        $this->expectException(\InvalidArgumentException::class);
        service('commissionPlanService')->updateDraft($plan['plan_ref'], [
            'plan_name'       => 'Changed',
            'effective_from'  => date('Y-m-d'),
            'level_count'     => 1,
            'level_1_percent' => '9',
        ], $this->adminUserId);
    }

    public function testLevelCalculationsAndMissingSponsor(): void
    {
        $plan = $this->createAndApprovePlan();
        $l1   = $this->registerMember('l1a');
        $l2   = $this->registerMember('l2a', $l1['member_id']);
        $l3   = $this->registerMember('l3a', $l2['member_id']);
        $buyer = $this->registerMember('buyera', $l3['member_id']);

        $purchase = $this->approvePurchase($buyer, '200'); // 200 * 0.50 = 100 USDT
        $this->assertSame('100.00000000', MoneyMath::normalize((string) $purchase['total_usdt'], 8));

        $eval = service('commissionEligibilityService')->evaluatePurchase($purchase, $plan);
        $this->assertTrue($eval['purchase_eligible']);

        $levels = [];
        foreach ($eval['levels'] as $lvl) {
            $levels[(int) $lvl['level']] = $lvl;
        }
        $this->assertTrue($levels[1]['eligible']);
        $this->assertTrue($levels[2]['eligible']);
        $this->assertTrue($levels[3]['eligible']);
        $this->assertSame($l3['member_id'], $levels[1]['sponsor_member_id']);
        $this->assertSame($l2['member_id'], $levels[2]['sponsor_member_id']);
        $this->assertSame($l1['member_id'], $levels[3]['sponsor_member_id']);

        $sim = service('commissionSimulationService');
        $this->assertSame('10.00000000', $sim->previewAmount('100', '10'));
        $this->assertSame('5.00000000', $sim->previewAmount('100', '5'));
        $this->assertSame('3.00000000', $sim->previewAmount('100', '3'));

        // Missing sponsor: direct under root has only partial chain for higher levels
        $orphan = $this->registerMember('orphan');
        $p2 = $this->approvePurchase($orphan, '200');
        $eval2 = service('commissionEligibilityService')->evaluatePurchase($p2, $plan);
        $this->assertTrue($eval2['purchase_eligible']);
        $this->assertTrue($eval2['levels'][0]['eligible']); // L1 = root
        $this->assertFalse($eval2['levels'][1]['eligible']); // L2 missing
        $this->assertFalse($eval2['levels'][2]['eligible']); // L3 missing
    }

    public function testCircularAndDuplicateBeneficiaryDetection(): void
    {
        $a = $this->registerMember('circa');
        $b = $this->registerMember('circb', $a['member_id']);

        // Corrupt edge: make A sponsored by B (cycle)
        db_connect()->table('sponsor_relationships')->where('member_user_id', $a['user_id'])->update([
            'sponsor_user_id'   => $b['user_id'],
            'sponsor_member_id' => $b['member_id'],
        ]);

        $chain = service('commissionGenealogyService')->resolveUpline($b['user_id'], 3);
        $codes = $chain['reason_codes'];
        $this->assertTrue(
            in_array('circular_or_duplicate_beneficiary', $codes, true)
            || in_array('circular_sponsorship', $codes, true),
            'Expected circular detection, got: ' . implode(',', $codes)
        );
    }

    public function testUnverifiedAndWrongStatusExcluded(): void
    {
        $plan = $this->createAndApprovePlan();
        $member = $this->registerMember('unv');
        $req = service('coinPurchaseService')->createRequest($member['user_id'], '200', null, 'pend-' . bin2hex(random_bytes(2)));
        $pending = db_connect()->table('coin_purchase_requests')->where('purchase_ref', $req['request']['purchase_ref'])->get()->getRowArray();
        $eval = service('commissionEligibilityService')->evaluatePurchase($pending, $plan);
        $this->assertFalse($eval['purchase_eligible']);
        $this->assertContains('purchase_not_approved', $eval['reason_codes']);

        $approved = $this->approvePurchase($member, '200');
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $approved['purchase_ref'])->update([
            'payment_status' => 'unpaid',
        ]);
        $approved = db_connect()->table('coin_purchase_requests')->where('purchase_ref', $approved['purchase_ref'])->get()->getRowArray();
        $eval2 = service('commissionEligibilityService')->evaluatePurchase($approved, $plan);
        $this->assertFalse($eval2['purchase_eligible']);
        $this->assertContains('payment_not_verified', $eval2['reason_codes']);
    }

    public function testHistoricalAmountAndPlanDateSelection(): void
    {
        $operatorId = $this->createOperator();
        $past = service('commissionPlanService')->createDraft([
            'plan_name'       => 'Past',
            'effective_from'  => date('Y-m-d', strtotime('-10 days')),
            'effective_to'    => date('Y-m-d', strtotime('-1 day')),
            'level_count'     => 1,
            'level_1_percent' => '7',
        ], $operatorId);
        service('commissionPlanService')->approve($past['plan_ref'], $this->approverUserId);

        $current = $this->createAndApprovePlan([
            'plan_name'       => 'Current',
            'effective_from'  => date('Y-m-d'),
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ]);

        $found = service('commissionPlanService')->findApplicablePlan(date('Y-m-d'));
        $this->assertSame($current['plan_ref'], $found['plan_ref']);
        $pastFound = service('commissionPlanService')->findApplicablePlan(date('Y-m-d', strtotime('-5 days')));
        $this->assertSame($past['plan_ref'], $pastFound['plan_ref']);

        $member = $this->registerMember('histamt');
        $purchase = $this->approvePurchase($member, '200');
        // Snapshot preserved — do not use live price
        service('coinPriceService')->updatePrice('9.99', $this->adminUserId, 'price change');
        $this->assertSame('100.00000000', MoneyMath::normalize((string) $purchase['total_usdt'], 8));
    }

    public function testSponsorEligibilityUnresolvedBlocks(): void
    {
        $plan = $this->createAndApprovePlan([
            'sponsor_eligibility_policy' => 'unresolved',
            'plan_name' => 'Unresolved policy',
        ]);
        $member = $this->registerMember('unres');
        $purchase = $this->approvePurchase($member, '200');
        $eval = service('commissionEligibilityService')->evaluatePurchase($purchase, $plan);
        $this->assertFalse($eval['purchase_eligible']);
        $this->assertContains('sponsor_eligibility_unresolved', $eval['reason_codes']);
    }

    public function testIdempotentSimulationNoDuplicatesNoLedgerMutation(): void
    {
        $this->createAndApprovePlan();
        $l1 = $this->registerMember('idl1');
        $buyer = $this->registerMember('idbuy', $l1['member_id']);
        $this->approvePurchase($buyer, '200');

        $walletBefore = service('memberCoinOwnershipService')->summaryForUser($l1['user_id']);
        $ownBefore = db_connect()->table('member_coin_ownership_transactions')->countAllResults();
        $mineBefore = db_connect()->table('mining_simulation_items')->countAllResults();

        $r1 = service('commissionSimulationService')->simulateDay(date('Y-m-d'), $this->adminUserId);
        $r2 = service('commissionSimulationService')->simulateDay(date('Y-m-d'), $this->adminUserId);
        $this->assertFalse($r1['reused']);
        $this->assertTrue($r2['reused']);
        $this->assertSame($r1['run']['run_ref'], $r2['run']['run_ref']);
        $this->assertSame('0', (string) $r1['run']['is_payable']);
        $this->assertSame('0', (string) $r1['run']['is_posted']);

        $itemCount = db_connect()->table('commission_simulation_items')
            ->where('run_ref', $r1['run']['run_ref'])
            ->countAllResults();
        $uniqueKeys = db_connect()->table('commission_simulation_items')
            ->select('idempotency_key')
            ->where('run_ref', $r1['run']['run_ref'])
            ->get()
            ->getResultArray();
        $this->assertSame(count($uniqueKeys), count(array_unique(array_column($uniqueKeys, 'idempotency_key'))));

        $walletAfter = service('memberCoinOwnershipService')->summaryForUser($l1['user_id']);
        $this->assertSame($walletBefore['balance'], $walletAfter['balance']);
        $this->assertSame($ownBefore, db_connect()->table('member_coin_ownership_transactions')->countAllResults());
        $this->assertSame($mineBefore, db_connect()->table('mining_simulation_items')->countAllResults());

        // No withdrawal / payout tables mutated — commission items remain non-posted
        $posted = db_connect()->table('commission_simulation_items')->where('is_posted', 1)->countAllResults();
        $this->assertSame(0, $posted);
        $this->assertGreaterThan(0, $itemCount);
    }

    public function testMemberScopedHistoryAndAdminPermission(): void
    {
        $this->createAndApprovePlan();
        $l1 = $this->registerMember('authl1');
        $buyer = $this->registerMember('authbuy', $l1['member_id']);
        $this->approvePurchase($buyer, '200');
        service('commissionSimulationService')->simulateDay(date('Y-m-d'), $this->adminUserId);

        $other = $this->registerMember('other');
        $owned = service('commissionSimulationService')->historyForBeneficiary($l1['user_id'], 50);
        $this->assertNotEmpty($owned);
        foreach ($owned as $row) {
            $this->assertSame($l1['user_id'], (int) $row['beneficiary_user_id']);
        }
        $otherHistory = service('commissionSimulationService')->historyForBeneficiary($other['user_id'], 50);
        $this->assertSame([], $otherHistory);

        $this->post('login', [
            'member_id' => $other['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('admin/commissions/plans/create')->assertRedirect();
    }

    public function testCsvExportSanitizesFormulas(): void
    {
        $this->createAndApprovePlan();
        $l1 = $this->registerMember('csvl1');
        $buyer = $this->registerMember('csvbuy', $l1['member_id']);
        $this->approvePurchase($buyer, '200');
        service('commissionSimulationService')->simulateDay(date('Y-m-d'), $this->adminUserId);

        // Inject formula-like member id into a row for export safety check
        db_connect()->table('commission_simulation_items')->limit(1)->update([
            'beneficiary_member_id' => '=1+1',
        ]);
        $csv = service('commissionSimulationService')->exportCsv([]);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringNotContainsString("\n=1+1", $csv);
    }

    public function testDecimalPrecision(): void
    {
        $sim = service('commissionSimulationService');
        $this->assertSame('0.33333333', $sim->previewAmount('10.00000000', '3.33333333'));
    }
}
