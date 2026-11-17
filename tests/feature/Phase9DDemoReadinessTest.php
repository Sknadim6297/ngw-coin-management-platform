<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 9D focused readiness: kill-switches, soft-link cleanup, 9A–9C routes, demo path smoke.
 *
 * @internal
 */
final class Phase9DDemoReadinessTest extends CIUnitTestCase
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
        $platform->requireVerifiedDepositForApproval = true;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p9d price');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'p9d stock', null, 'p9d-stock-' . bin2hex(random_bytes(3)));

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
     * @return array{user_id: int, member_id: string, user: object}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p9d' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p9d-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P9D ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '14' . substr($digits . '00000000', 0, 8),
            'email'       => 'p9d' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
            'user'      => $result['user'],
        ];
    }

    /**
     * @return array{network: array<string, mixed>, wallet: string, contract: string}
     */
    private function enablePayments(): array
    {
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

        return [
            'network'  => service('paymentDetailsService')->ensureBep20(),
            'wallet'   => $wallet,
            'contract' => $contract,
        ];
    }

    public function testFinancialKillSwitchesAndVerifiedDepositGate(): void
    {
        $p = config('Platform');
        $this->assertTrue($p->requireVerifiedDepositForApproval);
        $this->assertFalse($p->commissionPostingEnabled);
        $this->assertFalse($p->commissionWithdrawalEnabled);
        $this->assertFalse($p->commissionBlockchainPayoutEnabled);

        $security = service('platformSettingsService')->securitySnapshot();
        $values   = array_column($security, 'value');
        $this->assertContains('Not Activated', $values);
    }

    public function testPhase9RoutesExistForAdminAndMember(): void
    {
        auth()->login(model(UserModel::class)->findById($this->adminUserId));
        foreach (['admin/reports', 'admin/settings', 'admin/support', 'admin/dashboard'] as $path) {
            $this->withSession()->get($path)->assertOK();
        }

        $member = $this->registerMember('routes');
        auth()->logout();
        auth()->login($member['user']);
        foreach ([
            'member/dashboard',
            'member/profile',
            'member/security',
            'member/wallet',
            'member/buy-coins',
            'member/support',
        ] as $path) {
            $this->withSession()->get($path)->assertOK();
        }
    }

    public function testRejectedDepositClearsSoftPurchaseLink(): void
    {
        $setup  = $this->enablePayments();
        $member = $this->registerMember('softclr');
        $buy    = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '5.00000000',
            null,
            'p9d-soft-' . bin2hex(random_bytes(2))
        );
        $purchaseRef = (string) $buy['request']['purchase_ref'];

        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $setup['network']['id'],
            '5.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'p9d-dep-soft-' . bin2hex(random_bytes(2)),
            null
        );
        $depositRef = (string) $dep['deposit']['deposit_ref'];

        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $purchaseRef)->update([
            'payment_deposit_ref' => $depositRef,
        ]);
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $depositRef)->update([
            'linked_purchase_ref' => $purchaseRef,
        ]);

        service('usdtDepositService')->reject($depositRef, $this->adminUserId, 'Invalid explorer TXN');

        $purchase = db_connect()->table('coin_purchase_requests')->where('purchase_ref', $purchaseRef)->get()->getRowArray();
        $deposit  = db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $depositRef)->get()->getRowArray();
        $this->assertSame('rejected', $deposit['status']);
        $this->assertTrue($deposit['linked_purchase_ref'] === null || $deposit['linked_purchase_ref'] === '');
        $this->assertTrue($purchase['payment_deposit_ref'] === null || $purchase['payment_deposit_ref'] === '');
    }

    public function testDemoHappyPathSoftLinkManualApproveCreditsOnce(): void
    {
        $setup  = $this->enablePayments();
        $member = $this->registerMember('demo');
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByUserId($member['user_id'])->account_status);

        $buy = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '5.00000000',
            null,
            'p9d-demo-' . bin2hex(random_bytes(2))
        );
        $purchaseRef = (string) $buy['request']['purchase_ref'];

        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $setup['network']['id'],
            '5.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'p9d-demo-dep-' . bin2hex(random_bytes(2)),
            null
        );
        $depositRef = (string) $dep['deposit']['deposit_ref'];

        // Soft associate as Buy Coins does (before verification).
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $purchaseRef)->update([
            'payment_deposit_ref' => $depositRef,
        ]);
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $depositRef)->update([
            'linked_purchase_ref' => $purchaseRef,
        ]);

        $verified = service('usdtDepositService')->manuallyApprovePayment($depositRef, $this->adminUserId, [
            'confirm_independent'      => true,
            'confirm_network'          => true,
            'confirm_success'          => true,
            'verified_amount_usdt'     => '5.00000000',
            'confirmed_wallet'         => $setup['wallet'],
            'confirmed_token_contract' => $setup['contract'],
            'confirmed_confirmations'  => 20,
            'verification_evidence'    => 'https://bscscan.com/tx/p9d-demo-verified',
            // No explicit link_purchase_ref — must honor soft association.
        ]);
        $this->assertSame('verified', $verified['deposit']['status']);
        $this->assertSame($purchaseRef, $verified['linked_purchase_ref']);

        $approval = service('coinPurchaseService')->approveRequest($purchaseRef, $this->adminUserId);
        $this->assertTrue($approval['activated']);
        $this->assertSame('active', model(MemberProfileModel::class)->findByUserId($member['user_id'])->account_status);
        $this->assertSame('5.00000000', service('memberCoinOwnershipService')->summaryForUser($member['user_id'])['available']);

        $this->expectException(\InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($purchaseRef, $this->adminUserId);
    }

    public function testSupportAndReportsSmokeForDemo(): void
    {
        $member = $this->registerMember('helpsmoke');
        $ticket = service('supportTicketService')->createTicket($member['user_id'], [
            'category' => 'payment',
            'subject'  => 'Demo support ticket subject',
            'message'  => 'Please help with payment verification.',
        ]);
        service('supportTicketService')->adminReply(
            $this->adminUserId,
            (string) $ticket['ticket']['ticket_ref'],
            'We are reviewing your payment on the explorer.',
            'in_progress'
        );

        $overview = service('adminReportsService')->overviewReport();
        $this->assertNotEmpty($overview['kpis']);

        auth()->login(model(UserModel::class)->findById($this->adminUserId));
        $this->withSession()->get('admin/reports?tab=purchases')->assertOK();
        $this->withSession()->get('admin/support')->assertOK();
    }
}
