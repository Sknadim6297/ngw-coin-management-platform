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
 * Phase 7B mining configuration & simulation (non-payable).
 *
 * @internal
 */
final class MiningSimulationTest extends CIUnitTestCase
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

        $platform                = config('Platform');
        $platform->adminEmail    = 'admin@test.local';
        $platform->adminPassword = 'AdminPass123!';
        $platform->adminName     = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApproverOnly();

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'mining listing');
        service('coinInventoryService')->addInventory('5000', $this->adminUserId, 'mining stock', null, 'mine-stock-' . bin2hex(random_bytes(3)));

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
        $user  = new User(['username' => 'mineapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'mine.approver@test.local',
            'password' => 'ApproverPass123!',
        ]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('m' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'Mine ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '19' . substr($digits . '00000000', 0, 8),
            'email'       => 'mine' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
            'profile'   => $profile,
            'email'     => 'mine' . $suffix . '@example.com',
        ];
    }

    private function credit(array $member, string $qty = '100'): void
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'mine-buy-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'mine credit');
    }

    public function testAdminCanCreateDraftRate(): void
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        $this->assertSame('draft', $draft['status']);
    }

    public function testUnauthorizedMemberCannotCreateRate(): void
    {
        $member = $this->registerMember('norate');
        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('admin/mining/rates/create')->assertRedirect();
    }

    public function testSelfApprovalBlocked(): void
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '2.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('miningRateService')->approve($draft['rate_ref'], $this->adminUserId);
    }

    public function testOverlappingApprovedRatesRejected(): void
    {
        $month = date('Y-m');
        $a = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => '1.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        service('miningRateService')->approve($a['rate_ref'], $this->approverUserId);

        $b = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => '2.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('miningRateService')->approve($b['rate_ref'], $this->approverUserId);
    }

    public function testHistoricalApprovedRatesImmutable(): void
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '1.50000000',
            'rate_basis'      => 'daily',
        ], $this->adminUserId);
        service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('miningRateService')->assertImmutable($draft['rate_ref']);
    }

    public function testEligibilityUsesAvailableOnlyAndRequiresActive(): void
    {
        $member = $this->registerMember('elig');
        $inactive = service('miningEligibilityService')->evaluate($member['user_id'], date('Y-m-d'), null);
        $this->assertFalse($inactive['eligible']);
        $this->assertContains('member_inactive', $inactive['reason_codes']);

        $this->credit($member, '80');
        $plan = service('coinLockingService')->createPlan([
            'plan_code' => 'M' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name' => 'Mine Lock',
            'min_quantity' => '1',
            'max_quantity' => '1000',
            'duration_days' => 30,
        ], $this->adminUserId);
        service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '30');

        $rate = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
        ], $this->adminUserId);
        service('miningRateService')->approve($rate['rate_ref'], $this->approverUserId);
        $rate = service('miningRateService')->findByRef($rate['rate_ref']);

        $eval = service('miningEligibilityService')->evaluate($member['user_id'], date('Y-m-d'), $rate);
        $this->assertTrue($eval['eligible']);
        $this->assertSame('50.00000000', $eval['eligible_coins']); // 80 - 30 locked
        $this->assertSame('30.00000000', $eval['locked']);
        $this->assertContains('locked_excluded', $eval['reason_codes']);
    }

    public function testMonthlyRateConversionAndPrecision(): void
    {
        $rate = [
            'rate_percent'    => '3.00000000',
            'rate_basis'      => 'monthly',
            'precision_scale' => 8,
            'effective_month' => '2026-11',
        ];
        $days = (string) (int) (new \DateTimeImmutable('2026-11-01'))->format('t');
        $expectedFactor = MoneyMath::div(MoneyMath::div('3.00000000', '100', 12), $days, 12);
        $factor = service('miningRateService')->dailyFactor($rate, '2026-11-15');
        $this->assertSame(MoneyMath::normalize($expectedFactor, 12), MoneyMath::normalize($factor, 12));

        $amount = service('miningRateService')->previewAmount('100.00000000', $rate, '2026-11-15');
        $this->assertSame(MoneyMath::mul('100.00000000', $factor, 8), $amount);
    }

    public function testSimulationIdempotentAndNeverCreditsWallet(): void
    {
        $member = $this->registerMember('sim1');
        $this->credit($member, '100');
        $draft = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '1.00000000',
            'rate_basis'      => 'daily',
        ], $this->adminUserId);
        service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);

        $beforeBal = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $beforeTxn = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $member['user_id'])->countAllResults();

        $date = date('Y-m-d');
        $first  = service('miningSimulationService')->simulateDay($date, $this->adminUserId);
        $second = service('miningSimulationService')->simulateDay($date, $this->adminUserId);
        $this->assertFalse($first['reused']);
        $this->assertTrue($second['reused']);
        $this->assertSame(0, (int) $first['run']['is_payable']);
        $this->assertSame(0, (int) $first['run']['is_posted']);

        $afterBal = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $afterTxn = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $member['user_id'])->countAllResults();
        $this->assertSame($beforeBal['balance'], $afterBal['balance']);
        $this->assertSame($beforeBal['available'], $afterBal['available']);
        $this->assertSame($beforeTxn, $afterTxn);
    }

    public function testMemberCannotViewAnotherSimulationViaHistoryScope(): void
    {
        $a = $this->registerMember('owna');
        $b = $this->registerMember('ownb');
        $this->credit($a, '40');
        $draft = service('miningRateService')->createDraft([
            'effective_month' => date('Y-m'),
            'rate_percent'    => '1.00000000',
            'rate_basis'      => 'daily',
        ], $this->adminUserId);
        service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);
        service('miningSimulationService')->simulateDay(date('Y-m-d'), $this->adminUserId);

        $this->post('login', [
            'member_id' => $b['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $page = $this->withSession()->get('member/mining/history');
        $page->assertOK();
        $page->assertDontSee($a['member_id']);
    }

    public function testNoCommissionOrLedgerMutationMethods(): void
    {
        $this->assertFalse(method_exists(service('miningSimulationService'), 'postRewards'));
        $this->assertFalse(method_exists(service('miningSimulationService'), 'creditWallet'));
        $this->assertFalse(method_exists(service('miningRateService'), 'postMining'));
    }
}
