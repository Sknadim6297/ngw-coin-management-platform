<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Focused UI consolidation: sidebar routes, redirects, payment approvals listing.
 *
 * @internal
 */
final class AdminSidebarConsolidationTest extends CIUnitTestCase
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

        $platform = config('Platform');
        $platform->adminEmail = 'admin@test.local';
        $platform->adminPassword = 'AdminPass123!';
        $platform->adminName = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = true;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'sidebar consol price');
        service('coinInventoryService')->addInventory('500', $this->adminUserId, 'sidebar stock', null, 'side-stock-' . bin2hex(random_bytes(3)));

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

    private function loginAdmin(): void
    {
        $user = model(UserModel::class)->findById($this->adminUserId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    public function testPaymentApprovalsIndexLoads(): void
    {
        $this->loginAdmin();
        $result = $this->get('admin/payment-approvals');
        $result->assertStatus(200);
        $result->assertSee('Payment Approvals');
        $result->assertSee('Pending');
        $result->assertSee('Unlinked Deposits');
    }

    public function testPurchasesGetRedirectsToPaymentApprovals(): void
    {
        $this->loginAdmin();
        $result = $this->get('admin/purchases?status=pending');
        $result->assertRedirectTo(site_url('admin/payment-approvals?tab=pending'));
    }

    public function testDepositsGetRedirectsToPaymentApprovals(): void
    {
        $this->loginAdmin();
        $result = $this->get('admin/deposits?status=submitted');
        $result->assertRedirectTo(site_url('admin/payment-approvals?tab=pending'));
    }

    public function testCoinInventoryAndPriceTabs(): void
    {
        $this->loginAdmin();
        $r = $this->get('admin/coins/inventory');
        $r->assertStatus(200);
        $r->assertSee('Add Coins');
        $r = $this->get('admin/coins/inventory?tab=add');
        $r->assertStatus(200);
        $r->assertSee('Credit Inventory');
        $r = $this->get('admin/coins/price');
        $r->assertStatus(200);
        $r->assertSee('Current Coin Price');
        $r = $this->get('admin/coins/price?tab=history');
        $r->assertStatus(200);
        $r->assertSee('Price History');
        $this->get('admin/coins')->assertRedirectTo(site_url('admin/coins/inventory'));
    }

    public function testMembersTabsAndSearch(): void
    {
        $this->loginAdmin();
        $r = $this->get('admin/members');
        $r->assertStatus(200);
        $r->assertSee('All Members');
        $r = $this->get('admin/members?status=inactive');
        $r->assertStatus(200);
        $r->assertSee('Inactive Members');
        $this->get('admin/members/active')->assertStatus(200);
    }

    public function testPaymentMonitoringAndKillSwitches(): void
    {
        $this->loginAdmin();
        $r = $this->get('admin/payment-monitoring');
        $r->assertStatus(200);
        $r->assertSee('Payment Monitoring');
        $this->assertFalse(config('Platform')->commissionPostingEnabled);
        $this->assertFalse(config('Platform')->commissionWithdrawalEnabled);
        $this->assertFalse(config('Platform')->commissionBlockchainPayoutEnabled);
    }

    public function testUnifiedReviewAfterManualPayment(): void
    {
        $this->loginAdmin();

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

        $digits = preg_replace('/\D/', '', (string) crc32('sideok') . '9');
        $hex    = str_pad(substr(hash('sha256', 'sideok'), 0, 40), 40, '0');
        $reg = service('registrationService')->register([
            'member_name' => 'Side Ok',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'sideok@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $uid = (int) $reg['user']->id;

        $created = service('coinPurchaseService')->createRequestFromUsdtAmount($uid, '5.00000000', null, 'side-pur-' . bin2hex(random_bytes(2)));
        $dep = service('usdtDepositService')->submitRequest(
            $uid,
            (int) $network['id'],
            '5.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'side-dep-' . bin2hex(random_bytes(2)),
            null
        );

        $result = service('usdtDepositService')->manuallyApprovePayment(
            $dep['deposit']['deposit_ref'],
            $this->adminUserId,
            [
                'confirm_independent'      => true,
                'confirm_network'          => true,
                'confirm_success'          => true,
                'verified_amount_usdt'     => '5.00000000',
                'confirmed_wallet'         => $wallet,
                'confirmed_token_contract' => $contract,
                'confirmed_confirmations'  => 20,
                'verification_evidence'    => 'https://bscscan.com/tx/side-checked',
                'link_purchase_ref'        => $created['request']['purchase_ref'],
            ]
        );
        $this->assertSame('verified', $result['deposit']['status']);

        $page = $this->get('admin/payment-approvals/' . $created['request']['purchase_ref']);
        $page->assertStatus(200);
        $page->assertSee('Step 1');
        $page->assertSee('Step 2');
        $page->assertSee('Approve Purchase');

        $approval = service('coinPurchaseService')->approveRequest(
            $created['request']['purchase_ref'],
            $this->adminUserId
        );
        $this->assertTrue($approval['activated']);
    }

    public function testPaymentDetailsStillWorks(): void
    {
        $this->loginAdmin();
        $this->get('admin/payment-details')->assertStatus(200);
    }
}
