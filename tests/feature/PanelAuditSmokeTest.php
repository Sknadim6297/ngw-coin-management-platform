<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Focused smoke audit: admin + member critical routes and buy-coins soft association.
 *
 * @internal
 */
final class PanelAuditSmokeTest extends CIUnitTestCase
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
        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'audit smoke');
        service('coinInventoryService')->addInventory('200', $this->adminUserId, 'audit stock', null, 'audit-stock-' . bin2hex(random_bytes(2)));

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
        auth()->login(model(UserModel::class)->findById($this->adminUserId));
    }

    public function testAdminCriticalPagesOpen(): void
    {
        $this->loginAdmin();
        foreach ([
            'admin/dashboard',
            'admin/members',
            'admin/coins/inventory',
            'admin/coins/price',
            'admin/locking',
            'admin/payment-approvals',
            'admin/payment-details',
            'admin/payment-monitoring',
            'admin/mining',
            'admin/commissions',
            'admin/wallets',
            'admin/reports',
            'admin/settings',
            'admin/support',
        ] as $path) {
            $r = $this->get($path);
            $r->assertStatus(200);
        }

        $this->get('admin/coming-soon/reports')->assertRedirectTo(site_url('admin/reports'));
        $this->get('admin/coming-soon/settings')->assertRedirectTo(site_url('admin/settings'));
    }

    public function testKillSwitchesRemainOff(): void
    {
        $p = config('Platform');
        $this->assertFalse($p->commissionPostingEnabled);
        $this->assertFalse($p->commissionWithdrawalEnabled);
        $this->assertFalse($p->commissionBlockchainPayoutEnabled);
    }

    public function testBuyCoinsSoftAssociatesDepositAndPurchase(): void
    {
        $wallet   = '0x' . str_repeat('a', 40);
        $contract = '0x' . str_repeat('b', 40);
        service('paymentDetailsService')->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $wallet,
            'recipient_name'           => 'Treasury',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'Pay',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);
        $network = service('paymentDetailsService')->ensureBep20();

        $digits = preg_replace('/\D/', '', (string) crc32('aud1') . '7');
        $hex = str_pad(substr(hash('sha256', 'aud1'), 0, 40), 40, '0');
        $reg = service('registrationService')->register([
            'member_name' => 'Audit One',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'aud1@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $uid = (int) $reg['user']->id;
        $created = service('coinPurchaseService')->createRequestFromUsdtAmount($uid, '5.00000000', null, 'aud-pur-' . bin2hex(random_bytes(2)));
        $purchaseRef = $created['request']['purchase_ref'];

        $dep = service('usdtDepositService')->submitRequest(
            $uid,
            (int) $network['id'],
            '5.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'aud-dep-' . bin2hex(random_bytes(2)),
            null
        );

        // Mimic BuyCoins soft association
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $purchaseRef)->update([
            'payment_deposit_ref' => $dep['deposit']['deposit_ref'],
        ]);
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $dep['deposit']['deposit_ref'])->update([
            'linked_purchase_ref' => $purchaseRef,
        ]);

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
                'verification_evidence'    => 'https://bscscan.com/tx/audit-check',
                'link_purchase_ref'        => $purchaseRef,
            ]
        );
        $this->assertSame('verified', $result['deposit']['status']);
        $this->assertSame($purchaseRef, $result['linked_purchase_ref']);

        $approval = service('coinPurchaseService')->approveRequest($purchaseRef, $this->adminUserId);
        $this->assertTrue($approval['activated']);

        $profile = model(MemberProfileModel::class)->findByUserId($uid);
        $this->assertSame('active', $profile->account_status);
    }

    public function testMemberDepositCreateRedirectsToBuyCoins(): void
    {
        $digits = preg_replace('/\D/', '', (string) crc32('aud2') . '7');
        $hex = str_pad(substr(hash('sha256', 'aud2'), 0, 40), 40, '0');
        $reg = service('registrationService')->register([
            'member_name' => 'Audit Two',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '15' . substr($digits . '00000000', 0, 8),
            'email'       => 'aud2@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        auth()->login($reg['user']);
        $this->get('member/deposits/create')->assertRedirectTo(site_url('member/buy-coins'));
    }

    public function testDashboardMiningReasonUpdated(): void
    {
        $dash = service('adminDashboardService')->build();
        $reason = $dash['charts']['mining_trend']['reason'] ?? '';
        $this->assertStringContainsString('Actual Mining: Not Activated', $reason);
        $this->assertStringNotContainsString('Mining module is not implemented', $reason);
    }
}
