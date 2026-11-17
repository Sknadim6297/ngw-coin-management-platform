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
 * Phase 10B focused: admin RBAC, payment-details dual-control, kill-switches, sidebar routes.
 *
 * @internal
 */
final class Phase10BAdminProductionReadinessTest extends CIUnitTestCase
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

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p10b price');
        service('coinInventoryService')->addInventory('2000', $this->adminUserId, 'p10b stock', null, 'p10b-stock-' . bin2hex(random_bytes(3)));

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
     * @param list<string> $groups
     */
    private function makeUser(string $email, array $groups): User
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => strstr($email, '@', true), 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => $email, 'password' => 'Password123!']);
        foreach ($groups as $g) {
            $user->addGroup($g);
        }

        return $user;
    }

    private function loginAdmin(): void
    {
        $user = model(UserModel::class)->findById($this->adminUserId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    public function testAdminCriticalPagesLoad(): void
    {
        $this->loginAdmin();
        $pages = [
            'admin/dashboard',
            'admin/members',
            'admin/members/create',
            'admin/support',
            'admin/coins/inventory',
            'admin/coins/price',
            'admin/locking',
            'admin/payment-approvals',
            'admin/payment-details',
            'admin/payment-monitoring',
            'admin/mining',
            'admin/commissions',
            'admin/wallets',
            'admin/withdrawals',
            'admin/reports',
            'admin/settings',
        ];
        foreach ($pages as $path) {
            $this->withSession()->get($path)->assertOK();
        }
    }

    public function testFinanceViewerCannotApprovePaymentsOrManageMembers(): void
    {
        $viewer = $this->makeUser('viewer10b@example.com', ['finance_viewer']);
        $this->assertTrue($viewer->can('finance.payment.review'));
        $this->assertFalse($viewer->can('finance.payment.approve'));
        $this->assertFalse($viewer->can('admin.members.manage'));
        $this->assertFalse($viewer->can('admin.coins.manage'));

        auth()->login($viewer);

        $this->withSession()->get('admin/payment-approvals')->assertOK();
        $this->withSession()->post('admin/purchases/P-FAKE/approve', [
            'confirm' => '1',
        ])->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->get('admin/members')->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->get('admin/members/create')->assertRedirectTo(site_url('admin/dashboard'));
        $this->withSession()->post('admin/coins/inventory/add', [
            'quantity'        => '10',
            'idempotency_key' => bin2hex(random_bytes(8)),
        ])->assertRedirectTo(site_url('admin/dashboard'));
    }

    public function testPaymentDetailsDualControlAfterBootstrap(): void
    {
        $svc = service('paymentDetailsService');
        $svc->ensureBep20();
        $walletA = '0x' . str_repeat('a', 40);
        $walletB = '0x' . str_repeat('b', 40);
        $contract = '0x' . str_repeat('c', 40);

        $first = $svc->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $walletA,
            'recipient_name'           => 'Treasury A',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'BEP20 only',
            'is_enabled'               => true,
        ], $this->adminUserId);
        $this->assertNull($first['proposal']);
        $this->assertSame($walletA, $svc->ensureBep20()['destination_wallet']);

        $second = $svc->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $walletB,
            'recipient_name'           => 'Treasury B',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'BEP20 only',
            'is_enabled'               => true,
        ], $this->adminUserId);
        $this->assertNotNull($second['proposal']);
        $this->assertSame($walletA, $svc->ensureBep20()['destination_wallet']);

        $approver = $this->makeUser('approver10b@example.com', ['finance_approver']);
        service('paymentDestinationChangeService')->approve(
            (string) $second['proposal']['proposal_ref'],
            (int) $approver->id
        );
        $this->assertSame($walletB, $svc->ensureBep20()['destination_wallet']);
        $this->assertNull($svc->pendingDestinationChange());
    }

    public function testKillSwitchesRemainOff(): void
    {
        $this->assertFalse((bool) config('Platform')->commissionPostingEnabled);
        $this->assertFalse((bool) config('Platform')->commissionWithdrawalEnabled);
        $this->assertFalse((bool) config('Platform')->commissionBlockchainPayoutEnabled);
        $this->assertFalse(service('commissionWalletService')->postingEnabled());
        $this->assertFalse(service('commissionWalletService')->withdrawalEnabled());
    }

    public function testPlaceholderRedirectsToRealModules(): void
    {
        $this->loginAdmin();
        $this->withSession()->get('admin/coming-soon/mining')->assertRedirectTo(site_url('admin/mining'));
        $this->withSession()->get('admin/coming-soon/wallets')->assertRedirectTo(site_url('admin/wallets'));
        $this->withSession()->get('admin/coming-soon/reports')->assertRedirectTo(site_url('admin/reports'));
    }

    public function testWithdrawalsAdminReadOnlyAndDisabled(): void
    {
        $this->loginAdmin();
        $page = $this->withSession()->get('admin/withdrawals');
        $page->assertOK();
        $body = $page->getBody();
        $this->assertStringNotContainsString('Execute Payout', $body);
        $this->assertStringNotContainsString('name="approve_payout"', $body);
    }

    public function testSupportAdminAndMemberIntegration(): void
    {
        $digits = preg_replace('/\D/', '', (string) crc32('p10bsup') . '00000000');
        $result = service('registrationService')->register([
            'member_name' => 'P10B Support',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits, 0, 8),
            'email'       => 'p10bsup@example.com',
            'usdt_wallet' => '0x' . str_repeat('9', 40),
            'password'    => 'Password123!',
        ]);
        $userId = (int) $result['user']->id;
        $created = service('supportTicketService')->createTicket($userId, [
            'category' => 'account',
            'subject'  => 'Phase10B support bridge',
            'message'  => 'Please acknowledge this ticket.',
        ]);
        $ref = (string) $created['ticket']['ticket_ref'];

        $this->loginAdmin();
        $list = $this->withSession()->get('admin/support');
        $list->assertOK();
        $list->assertSee($ref);

        service('supportTicketService')->adminReply($this->adminUserId, $ref, 'Admin reply for Phase 10B.');
        $updated = service('supportTicketService')->updateStatus($this->adminUserId, $ref, 'in_progress');
        $this->assertSame('in_progress', $updated['status']);
    }
}
