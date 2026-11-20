<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class DashboardAnalyticsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $platform                                    = config('Platform');
        $platform->adminEmail                        = 'admin@test.local';
        $platform->adminPassword                     = 'AdminPass123!';
        $platform->adminName                         = 'Test Admin';
        $platform->rootSponsorId                     = 'ROOT0001';
        $platform->memberIdPrefix                    = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'Dash listing');
        service('coinInventoryService')->addInventory('500', $this->adminUserId, 'Dash stock', null, 'dash-stock');

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

    /**
     * @return array{user: object, profile: object, member_id: string}
     */
    private function registerMember(string $suffix, string $sponsorId = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('d' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'dash-' . $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Dash ' . $suffix,
            'sponsor_id'  => $sponsorId,
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'dash' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
        ];
    }

    /**
     * @param list<array<string, mixed>> $kpis
     */
    private function kpiByKey(array $kpis, string $key): array
    {
        foreach ($kpis as $kpi) {
            if (($kpi['key'] ?? null) === $key) {
                return $kpi;
            }
        }

        $this->fail('Missing KPI key: ' . $key);
    }

    public function testAdminKpiAccuracyForMembersInventoryAndPurchases(): void
    {
        $member = $this->registerMember('adminkpi');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '25',
            null,
            'dash-admin-buy-1'
        );
        service('coinPurchaseService')->approveRequest(
            $created['request']['purchase_ref'],
            $this->adminUserId
        );

        $kpis = service('adminDashboardService')->kpis();

        // Root admin/sponsor is excluded from member KPIs.
        $this->assertSame(1, $this->kpiByKey($kpis, 'total_members')['value']);
        $this->assertSame(1, $this->kpiByKey($kpis, 'active_members')['value']); // activated buyer only
        $this->assertSame(0, $this->kpiByKey($kpis, 'inactive_members')['value']);
        $this->assertSame('500.00000000', $this->kpiByKey($kpis, 'total_coins')['value']);
        $this->assertSame('25.00000000', $this->kpiByKey($kpis, 'used_coins')['value']);
        $this->assertSame('475.00000000', $this->kpiByKey($kpis, 'available_inventory')['value']);
        $this->assertSame('25.00000000', $this->kpiByKey($kpis, 'purchased_coins')['value']);
        $this->assertSame(0, $this->kpiByKey($kpis, 'pending_purchases')['value']);
        $this->assertSame(1, $this->kpiByKey($kpis, 'approved_purchases')['value']);
        $this->assertSame('0.00000000', $this->kpiByKey($kpis, 'total_locked_coins')['value']);
        $this->assertSame('25.00000000', $this->kpiByKey($kpis, 'total_available_member_coins')['value']);
    }

    public function testMissingMiningAndCommissionAreUnavailableNotZero(): void
    {
        $adminKpis  = service('adminDashboardService')->kpis();
        $member     = $this->registerMember('nomine');
        $memberKpis = service('memberDashboardService')->kpis((int) $member['user']->id);

        foreach (['today_mining', 'total_mining', 'today_level_commission', 'total_level_commission'] as $key) {
            $kpi = $this->kpiByKey($adminKpis, $key);
            $this->assertSame('unavailable', $kpi['status']);
            $this->assertNull($kpi['value']);
            $this->assertSame('Not available', $kpi['display']);
            $this->assertNotSame(0, $kpi['value']);
        }

        foreach (['my_today_level_commission', 'my_total_level_commission'] as $key) {
            $kpi = $this->kpiByKey($memberKpis, $key);
            $this->assertSame('unavailable', $kpi['status']);
            $this->assertNull($kpi['value']);
        }

        // Actual mining is ledger-backed: no posted credit = exact zero with an explicit "No credit posted" status.
        foreach (['my_today_mining', 'my_total_mining'] as $key) {
            $kpi = $this->kpiByKey($memberKpis, $key);
            $this->assertSame('ok', $kpi['status']);
            $this->assertSame('0.00000000', $kpi['value']);
        }
        $this->assertStringContainsString('No credit posted', (string) $this->kpiByKey($memberKpis, 'my_today_mining')['note']);
    }

    public function testMemberKpisAreScopedAndRespectBalances(): void
    {
        $a = $this->registerMember('scopea');
        $b = $this->registerMember('scopeb');

        $buyA = service('coinPurchaseService')->createRequest((int) $a['user']->id, '40', null, 'dash-a-buy');
        service('coinPurchaseService')->approveRequest($buyA['request']['purchase_ref'], $this->adminUserId);

        $buyB = service('coinPurchaseService')->createRequest((int) $b['user']->id, '10', null, 'dash-b-buy');
        service('coinPurchaseService')->approveRequest($buyB['request']['purchase_ref'], $this->adminUserId);

        $kpisA = service('memberDashboardService')->kpis((int) $a['user']->id);
        $kpisB = service('memberDashboardService')->kpis((int) $b['user']->id);

        $this->assertSame('40.00000000', $this->kpiByKey($kpisA, 'my_total_coins')['value']);
        $this->assertSame('40.00000000', $this->kpiByKey($kpisA, 'my_available_coins')['value']);
        $this->assertSame('0.00000000', $this->kpiByKey($kpisA, 'my_locked_coins')['value']);
        $this->assertSame('40.00000000', $this->kpiByKey($kpisA, 'my_purchased_coins')['value']);
        $this->assertSame(1, $this->kpiByKey($kpisA, 'my_approved_purchases')['value']);
        $this->assertSame(0, $this->kpiByKey($kpisA, 'my_pending_purchases')['value']);

        $this->assertSame('10.00000000', $this->kpiByKey($kpisB, 'my_total_coins')['value']);
        $this->assertNotSame(
            $this->kpiByKey($kpisA, 'my_total_coins')['value'],
            $this->kpiByKey($kpisB, 'my_total_coins')['value']
        );

        $available = (string) $this->kpiByKey($kpisA, 'my_available_coins')['value'];
        $locked    = (string) $this->kpiByKey($kpisA, 'my_locked_coins')['value'];
        $total     = (string) $this->kpiByKey($kpisA, 'my_total_coins')['value'];
        $this->assertSame($total, MoneyMath::add($available, $locked));
    }

    public function testTeamVisibilityUsesReferralTreeWithoutExposingForeignBalances(): void
    {
        $direct = $this->registerMember('direct1');
        // Activate sponsors so downline registration is allowed.
        $buyDirect = service('coinPurchaseService')->createRequest((int) $direct['user']->id, '5', null, 'dash-direct-buy');
        service('coinPurchaseService')->approveRequest($buyDirect['request']['purchase_ref'], $this->adminUserId);

        $child = $this->registerMember('child1', $direct['member_id']);
        $buyChild = service('coinPurchaseService')->createRequest((int) $child['user']->id, '5', null, 'dash-child-buy');
        service('coinPurchaseService')->approveRequest($buyChild['request']['purchase_ref'], $this->adminUserId);

        $grand = $this->registerMember('grand1', $child['member_id']);

        $team = service('referralTeamService')->countsForSponsor((int) $direct['user']->id);
        $this->assertSame(1, $team['direct']);
        $this->assertSame(2, $team['total']); // child + grand
        $this->assertSame(1, $team['active']); // child activated
        $this->assertSame(1, $team['inactive']); // grand still inactive

        $kpis = service('memberDashboardService')->kpis((int) $direct['user']->id);
        $this->assertSame(2, $this->kpiByKey($kpis, 'my_total_team')['value']);
        $this->assertSame(1, $this->kpiByKey($kpis, 'my_direct_referrals')['value']);

        // Ensure KPI payload does not include other members' private balances.
        $encoded = json_encode($kpis);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($grand['member_id'], $encoded);
        $this->assertStringNotContainsString('usdt_wallet', $encoded);
    }

    public function testActiveInactiveStatusOnAdminDashboard(): void
    {
        $inactive = $this->registerMember('inactive1');
        $active   = $this->registerMember('active1');
        $buy      = service('coinPurchaseService')->createRequest((int) $active['user']->id, '8', null, 'dash-act-buy');
        service('coinPurchaseService')->approveRequest($buy['request']['purchase_ref'], $this->adminUserId);

        $kpis = service('adminDashboardService')->kpis();
        $this->assertSame(1, $this->kpiByKey($kpis, 'active_members')['value']); // active1 only (root hidden)
        $this->assertSame(1, $this->kpiByKey($kpis, 'inactive_members')['value']); // inactive1
        $this->assertSame('inactive', $inactive['profile']->account_status);
        $this->assertSame(
            'active',
            model(MemberProfileModel::class)->findByUserId((int) $active['user']->id)->account_status
        );
    }

    public function testTodayDateBoundariesUseAppTimezone(): void
    {
        $tz = (string) config('App')->appTimezone;
        [$start, $end] = service('adminDashboardService')->todayBounds();

        $startDt = new \DateTimeImmutable($start, new \DateTimeZone($tz));
        $endDt   = new \DateTimeImmutable($end, new \DateTimeZone($tz));

        $this->assertSame('00:00:00', $startDt->format('H:i:s'));
        $this->assertSame('00:00:00', $endDt->format('H:i:s'));
        $this->assertSame(1, (int) $startDt->diff($endDt)->format('%a'));
        $this->assertSame($tz, (string) config('App')->appTimezone);
    }

    public function testDashboardHttpAuthorization(): void
    {
        $guestAdmin = $this->get('admin/dashboard');
        $guestAdmin->assertRedirect();

        $guestMember = $this->get('member/dashboard');
        $guestMember->assertRedirect();

        $member = $this->registerMember('httpauth');
        service('memberAuthService')->attempt($member['member_id'], 'Password123!');

        $memberDash = $this->get('member/dashboard');
        $memberDash->assertOK();
        $memberDash->assertSee('My Total Coins');
        $memberDash->assertSee('Not available');

        // Member must not access admin dashboard
        $denied = $this->get('admin/dashboard');
        $denied->assertRedirect();
    }

    public function testAdminDashboardRendersKpisAndUnavailableMining(): void
    {
        // Root sponsor is also in admin group via seeder
        service('memberAuthService')->attempt('ROOT0001', (string) config('Platform')->adminPassword);

        $result = $this->get('admin/dashboard');
        $result->assertOK();
        $result->assertSee('Total Members');
        $result->assertSee('Purchased Coins');
        $result->assertSee('Pending Payment Verification');
        $result->assertSee('Not available');
        $result->assertSee('Member Registration Trend');
    }

    public function testDashboardServicesAreReadOnly(): void
    {
        $member = $this->registerMember('readonly');
        $buy    = service('coinPurchaseService')->createRequest((int) $member['user']->id, '12', null, 'dash-ro-buy');
        service('coinPurchaseService')->approveRequest($buy['request']['purchase_ref'], $this->adminUserId);

        $beforeInv = service('coinInventoryService')->summary();
        $beforeOwn = service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id);
        $pendingBefore = model('CoinPurchaseRequestModel')->countByStatus('pending');

        service('adminDashboardService')->build();
        service('memberDashboardService')->build((int) $member['user']->id);

        $afterInv = service('coinInventoryService')->summary();
        $afterOwn = service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id);
        $pendingAfter = model('CoinPurchaseRequestModel')->countByStatus('pending');

        $this->assertSame($beforeInv, $afterInv);
        $this->assertSame($beforeOwn, $afterOwn);
        $this->assertSame($pendingBefore, $pendingAfter);
    }

    public function testLockCountsOnMemberDashboard(): void
    {
        $member = $this->registerMember('locks');
        $buy    = service('coinPurchaseService')->createRequest((int) $member['user']->id, '30', null, 'dash-lock-buy');
        service('coinPurchaseService')->approveRequest($buy['request']['purchase_ref'], $this->adminUserId);

        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => 'DASH30',
            'name'          => 'Dash 30',
            'description'   => 'Dashboard lock plan',
            'min_quantity'  => '1',
            'max_quantity'  => '1000',
            'duration_days' => 30,
        ], $this->adminUserId);
        $plan = service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);

        service('coinLockingService')->lockCoins(
            (int) $member['user']->id,
            (int) $plan['id'],
            '10',
            'dash-lock-1'
        );

        $kpis = service('memberDashboardService')->kpis((int) $member['user']->id);
        $this->assertSame(1, $this->kpiByKey($kpis, 'my_active_locks')['value']);
        $this->assertSame(0, $this->kpiByKey($kpis, 'my_matured_locks')['value']);
        $this->assertSame('10.00000000', $this->kpiByKey($kpis, 'my_locked_coins')['value']);
        $this->assertSame('20.00000000', $this->kpiByKey($kpis, 'my_available_coins')['value']);
        $this->assertSame(
            '30.00000000',
            MoneyMath::add(
                (string) $this->kpiByKey($kpis, 'my_available_coins')['value'],
                (string) $this->kpiByKey($kpis, 'my_locked_coins')['value']
            )
        );
    }
}
