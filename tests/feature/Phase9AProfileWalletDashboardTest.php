<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * Phase 9A focused: profile edit, wallet tabs, dashboard KPIs.
 *
 * @internal
 */
final class Phase9AProfileWalletDashboardTest extends CIUnitTestCase
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
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p9a price');
        service('coinInventoryService')->addInventory('2000', $this->adminUserId, 'p9a stock', null, 'p9a-stock-' . bin2hex(random_bytes(3)));

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
     * @return array{user: object, profile: object, member_id: string, user_id: int, email: string, mobile: string}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p9a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p9a-' . $suffix), 0, 40), 40, '0');
        $email  = 'p9a' . $suffix . '@example.com';
        $mobile = '18' . substr($digits . '00000000', 0, 8);

        $result = service('registrationService')->register([
            'member_name' => 'P9A ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => $mobile,
            'email'       => $email,
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
            'user_id'   => (int) $result['user']->id,
            'email'     => $email,
            'mobile'    => $mobile,
        ];
    }

    private function loginMember(array $member): void
    {
        $user = model(UserModel::class)->findById($member['user_id']);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    private function creditCoins(array $member, string $qty = '50'): string
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p9a-buy-' . $member['user_id'] . '-' . $qty . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p9a credit');

        return (string) $req['request']['purchase_ref'];
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

    public function testProfileLoadsWithSavedValues(): void
    {
        $member = $this->registerMember('pload');
        $this->loginMember($member);

        $page = $this->withSession()->get('member/profile');
        $page->assertOK();
        $page->assertSee($member['member_id']);
        $page->assertSee('P9A pload');
        $page->assertSee($member['email']);
        $page->assertSee($member['mobile']);
        $page->assertSee('ROOT0001');
        $page->assertSee('Edit Profile');
        $page->assertSee('Member ID');
        $page->assertSee('Sponsor ID');
    }

    public function testProfileUpdatesValidFields(): void
    {
        $member = $this->registerMember('pupdate');
        $result = service('memberProfileService')->updateOwnProfile($member['user_id'], [
            'member_name'           => 'Updated Name P9A',
            'mobile'                => '18999888777',
            'email'                 => 'updatedp9a@example.com',
            'usdt_wallet'           => '0x' . str_repeat('c', 40),
            'current_password'      => 'Password123!',
            'confirm_wallet_change' => '1',
        ]);

        $this->assertContains('member_name', $result['changed']);
        $this->assertContains('mobile', $result['changed']);
        $this->assertContains('pending_email', $result['changed']);
        $this->assertContains('usdt_wallet', $result['changed']);
        $this->assertSame('Updated Name P9A', $result['profile']->member_name);
        $this->assertSame('18999888777', $result['profile']->mobile);
        // Login identity must remain current until email is verified.
        $this->assertSame($member['email'], $result['email']);
        $this->assertSame('updatedp9a@example.com', $result['pending_email']);

        $fresh = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $this->assertSame($member['member_id'], $fresh->member_id);
        $this->assertSame('Updated Name P9A', $fresh->member_name);
        $this->assertSame('updatedp9a@example.com', $fresh->pending_email);
    }

    public function testDuplicateEmailRejected(): void
    {
        $a = $this->registerMember('dupmaila');
        $b = $this->registerMember('dupmailb');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('email');

        service('memberProfileService')->updateOwnProfile($b['user_id'], [
            'member_name'      => $b['profile']->member_name,
            'mobile'           => $b['mobile'],
            'email'            => $a['email'],
            'usdt_wallet'      => $b['profile']->usdt_wallet,
            'current_password' => 'Password123!',
        ]);
    }

    public function testUnauthorizedProfileEditBlocked(): void
    {
        $guest = $this->get('member/profile');
        $guest->assertRedirect();

        $a = $this->registerMember('owna');
        $b = $this->registerMember('ownb');
        $this->loginMember($a);

        // Member A can only update own scoped profile via service userId.
        $beforeB = model(MemberProfileModel::class)->findByUserId($b['user_id']);
        service('memberProfileService')->updateOwnProfile($a['user_id'], [
            'member_name'      => 'Only A Changed',
            'mobile'           => $a['mobile'],
            'email'            => $a['email'],
            'usdt_wallet'      => $a['profile']->usdt_wallet,
            'current_password' => 'Password123!',
        ]);
        $afterB = model(MemberProfileModel::class)->findByUserId($b['user_id']);
        $this->assertSame($beforeB->member_name, $afterB->member_name);
        $this->assertSame($beforeB->mobile, $afterB->mobile);

        $wrongPass = false;
        try {
            service('memberProfileService')->updateOwnProfile($a['user_id'], [
                'member_name'      => 'Hacker',
                'mobile'           => $a['mobile'],
                'email'            => $a['email'],
                'usdt_wallet'      => $a['profile']->usdt_wallet,
                'current_password' => 'WrongPassword!',
            ]);
        } catch (InvalidArgumentException $e) {
            $wrongPass = str_contains($e->getMessage(), 'password');
        }
        $this->assertTrue($wrongPass);
    }

    public function testSponsorAndMemberIdRemainImmutable(): void
    {
        $member = $this->registerMember('immut');
        $before = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $relBefore = model(SponsorRelationshipModel::class)->findByMemberUserId($member['user_id']);

        service('memberProfileService')->updateOwnProfile($member['user_id'], [
            'member_name'      => 'Name Only',
            'mobile'           => $member['mobile'],
            'email'            => $member['email'],
            'usdt_wallet'      => $before->usdt_wallet,
            'current_password' => 'Password123!',
            'member_id'        => 'HACKED99',
            'sponsor_id'       => 'HACKED01',
            'account_status'   => 'active',
            'balance'          => '9999',
        ]);

        $after = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $relAfter = model(SponsorRelationshipModel::class)->findByMemberUserId($member['user_id']);

        $this->assertSame($before->member_id, $after->member_id);
        $this->assertSame($before->account_status, $after->account_status);
        $this->assertSame($relBefore['sponsor_member_id'] ?? null, $relAfter['sponsor_member_id'] ?? null);
        $this->assertSame('Name Only', $after->member_name);
    }

    public function testWalletHistoryMatchesOwnershipLedger(): void
    {
        $member = $this->registerMember('wled');
        $this->creditCoins($member, '35');

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $wallet  = service('memberWalletService')->overview($member['user_id']);
        $history = service('memberWalletService')->history($member['user_id']);

        $this->assertSame($summary['balance'], $wallet['balance']);
        $this->assertSame($summary['available'], $wallet['available']);
        $this->assertSame($summary['locked'], $wallet['locked']);
        $this->assertNotEmpty($history['rows']);

        $this->loginMember($member);
        $txPage = $this->withSession()->get('member/wallet?tab=transactions');
        $txPage->assertOK();
        $txPage->assertSee('Coin Transactions');
        $txPage->assertSee('Credit');
        $txPage->assertSee('Debit');
        $txPage->assertSee('35.00000000');
    }

    public function testLockingHistoryDisplaysCorrectly(): void
    {
        $member = $this->registerMember('wlock');
        $this->creditCoins($member, '60');

        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => 'P9A' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
            'name'          => 'P9A Lock',
            'description'   => 'Phase 9A',
            'min_quantity'  => '1',
            'max_quantity'  => '1000',
            'duration_days' => 30,
        ], $this->adminUserId);
        service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);

        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '12');
        $ref  = (string) $lock['lock']['lock_ref'];

        $this->loginMember($member);
        $page = $this->withSession()->get('member/wallet?tab=locking');
        $page->assertOK();
        $page->assertSee('Locking History');
        $page->assertSee($ref);
        $page->assertSee('12.00000000');
        $page->assertSee('Active');
    }

    public function testAdminKpiValuesMatchDatabase(): void
    {
        $inactive = $this->registerMember('kpiinact');
        $active   = $this->registerMember('kpiact');
        $this->creditCoins($active, '20');

        $dbMembers = (int) db_connect()->table('member_profiles')
            ->where('is_root_sponsor', 0)
            ->countAllResults();
        $dbActive = (int) db_connect()->table('member_profiles')
            ->where('is_root_sponsor', 0)
            ->where('account_status', 'active')
            ->countAllResults();
        $dbInactive = (int) db_connect()->table('member_profiles')
            ->where('is_root_sponsor', 0)
            ->where('account_status', 'inactive')
            ->countAllResults();
        $inventory = service('coinInventoryService')->summary();

        $kpis = service('adminDashboardService')->kpis();
        $this->assertSame($dbMembers, $this->kpiByKey($kpis, 'total_members')['value']);
        $this->assertSame($dbActive, $this->kpiByKey($kpis, 'active_members')['value']);
        $this->assertSame($dbInactive, $this->kpiByKey($kpis, 'inactive_members')['value']);
        $this->assertSame($inventory['total_added'], $this->kpiByKey($kpis, 'total_coins')['value']);
        $this->assertSame($inventory['available_balance'], $this->kpiByKey($kpis, 'available_inventory')['value']);
        $this->assertSame($inventory['total_allocated'], $this->kpiByKey($kpis, 'used_coins')['value']);
        $this->assertSame('Total Inventory', $this->kpiByKey($kpis, 'total_coins')['label']);
        $this->assertSame('Allocated Coins', $this->kpiByKey($kpis, 'used_coins')['label']);
        $this->assertStringContainsString('payment-approvals', (string) $this->kpiByKey($kpis, 'pending_payment_verification')['href']);
        $this->assertSame('unavailable', $this->kpiByKey($kpis, 'today_mining')['status']);
        $this->assertNull($this->kpiByKey($kpis, 'today_mining')['value']);

        $dash = service('adminDashboardService')->build();
        $this->assertArrayHasKey('recent_transactions', $dash);

        $this->assertSame($inactive['member_id'], $inactive['profile']->member_id);
        unset($inactive);

        $adminUser = model(UserModel::class)->findById($this->adminUserId);
        auth()->login($adminUser);
        $page = $this->withSession()->get('admin/dashboard');
        $page->assertOK();
        $page->assertSee('Recent Transactions');
        $page->assertSee('Pending Payments');
        $page->assertSee('Total Inventory');
        $page->assertSee('Not available');
    }

    public function testMemberDashboardReflectsActivation(): void
    {
        $member = $this->registerMember('mdash');
        $this->loginMember($member);

        $inactivePage = $this->withSession()->get('member/dashboard');
        $inactivePage->assertOK();
        $inactivePage->assertSee('ACCOUNT STATUS: INACTIVE');
        $inactivePage->assertSee('Buy Coins');
        $inactivePage->assertSee($member['member_id']);

        $this->creditCoins($member, '15');
        $activePage = $this->withSession()->get('member/dashboard');
        $activePage->assertOK();
        $activePage->assertSee('ACCOUNT STATUS: ACTIVE');
        $activePage->assertSee('15.00000000');
    }

    public function testPendingPaymentDisplaysCorrectly(): void
    {
        $member = $this->registerMember('pendpay');

        $wallet   = '0x' . str_repeat('a', 40);
        $contract = '0x' . str_repeat('b', 40);
        service('paymentDetailsService')->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $wallet,
            'recipient_name'           => 'Treasury',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'Pay BEP20',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);
        $network = service('paymentDetailsService')->ensureBep20();

        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $network['id'],
            '25.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'p9a-dep-pend-' . bin2hex(random_bytes(2)),
            null
        );
        $this->assertSame('submitted', $dep['deposit']['status']);

        $dash = service('memberDashboardService')->build($member['user_id']);
        $this->assertNotEmpty($dash['pending_payments']);
        $this->assertSame('submitted', $dash['pending_payments'][0]['status']);

        $kpi = $this->kpiByKey($dash['kpis'], 'my_pending_payments');
        $this->assertSame(1, $kpi['value']);

        $this->loginMember($member);
        $page = $this->withSession()->get('member/dashboard');
        $page->assertOK();
        $page->assertSee('Payment Status: Pending Verification');
        $page->assertSee('25.00');
    }

    public function testNoSimulatedIncomeDisplayedAsActual(): void
    {
        $member = $this->registerMember('simsafe');
        $adminKpis  = service('adminDashboardService')->kpis();
        $memberKpis = service('memberDashboardService')->kpis($member['user_id']);

        foreach (['today_mining', 'total_mining', 'today_level_commission', 'total_level_commission'] as $key) {
            $kpi = $this->kpiByKey($adminKpis, $key);
            $this->assertSame('unavailable', $kpi['status']);
            $this->assertNull($kpi['value']);
        }

        foreach (['my_today_level_commission', 'my_total_level_commission'] as $key) {
            $kpi = $this->kpiByKey($memberKpis, $key);
            $this->assertSame('unavailable', $kpi['status']);
            $this->assertNull($kpi['value']);
        }

        // Actual mining reflects posted ledger only: with no credits it is an explicit 0, never a simulated value.
        foreach (['my_today_mining', 'my_total_mining'] as $key) {
            $kpi = $this->kpiByKey($memberKpis, $key);
            $this->assertSame('0.00000000', $kpi['value']);
            $this->assertStringNotContainsString('Simulation', $kpi['label']);
        }

        $this->assertStringContainsString('Simulation', $this->kpiByKey($adminKpis, 'today_mining_preview')['label']);
        $this->assertStringContainsString('Simulation', $this->kpiByKey($memberKpis, 'my_today_commission_preview')['label']);

        $this->loginMember($member);
        $page = $this->withSession()->get('member/dashboard');
        $page->assertOK();
        $page->assertSee('simulations only');
        $page->assertSee('Not Activated');
    }
}
