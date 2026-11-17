<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberEmailChangeTokenModel;
use App\Models\MemberProfileModel;
use App\Models\MemberWalletChangeEventModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * Phase 10A focused: pending email verify, wallet audit, withdrawals locked, sim labels.
 *
 * @internal
 */
final class Phase10AMemberProductionReadinessTest extends CIUnitTestCase
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

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p10a price');
        service('coinInventoryService')->addInventory('2000', $this->adminUserId, 'p10a stock', null, 'p10a-stock-' . bin2hex(random_bytes(3)));

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
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p10a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p10a-' . $suffix), 0, 40), 40, '0');
        $email  = 'p10a' . $suffix . '@example.com';
        $mobile = '17' . substr($digits . '00000000', 0, 8);

        $result = service('registrationService')->register([
            'member_name' => 'P10A ' . $suffix,
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

    public function testEmailChangeRequiresVerificationBeforeIdentitySwitch(): void
    {
        $member = $this->registerMember('emailv');
        $newEmail = 'verifiedp10a@example.com';

        $result = service('memberProfileService')->updateOwnProfile($member['user_id'], [
            'member_name'      => $member['profile']->member_name,
            'mobile'           => $member['mobile'],
            'email'            => $newEmail,
            'usdt_wallet'      => $member['profile']->usdt_wallet,
            'current_password' => 'Password123!',
        ]);

        $this->assertContains('pending_email', $result['changed']);
        $this->assertSame($member['email'], $result['email']);
        $this->assertSame($newEmail, $result['pending_email']);
        $this->assertArrayHasKey('email_verify_token', $result);

        $identity = db_connect()->table('auth_identities')
            ->where('user_id', $member['user_id'])
            ->where('type', 'email_password')
            ->get()
            ->getRowArray();
        $this->assertSame($member['email'], $identity['secret'] ?? null);

        $confirmed = service('memberProfileService')->confirmEmailChange($result['email_verify_token']);
        $this->assertSame($newEmail, $confirmed['email']);

        $identityAfter = db_connect()->table('auth_identities')
            ->where('user_id', $member['user_id'])
            ->where('type', 'email_password')
            ->get()
            ->getRowArray();
        $this->assertSame($newEmail, $identityAfter['secret'] ?? null);

        $fresh = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $this->assertNull($fresh->pending_email);

        // Single-use token
        $this->expectException(InvalidArgumentException::class);
        service('memberProfileService')->confirmEmailChange($result['email_verify_token']);
    }

    public function testWalletChangeRequiresConfirmationAndWritesAudit(): void
    {
        $member = $this->registerMember('wallaud');
        $newWallet = '0x' . str_repeat('d', 40);

        $blocked = false;
        try {
            service('memberProfileService')->updateOwnProfile($member['user_id'], [
                'member_name'      => $member['profile']->member_name,
                'mobile'           => $member['mobile'],
                'email'            => $member['email'],
                'usdt_wallet'      => $newWallet,
                'current_password' => 'Password123!',
            ]);
        } catch (InvalidArgumentException $e) {
            $blocked = str_contains(strtolower($e->getMessage()), 'confirm');
        }
        $this->assertTrue($blocked);

        $result = service('memberProfileService')->updateOwnProfile($member['user_id'], [
            'member_name'           => $member['profile']->member_name,
            'mobile'                => $member['mobile'],
            'email'                 => $member['email'],
            'usdt_wallet'           => $newWallet,
            'current_password'      => 'Password123!',
            'confirm_wallet_change' => '1',
        ], '127.0.0.1', 'PHPUnit');

        $this->assertContains('usdt_wallet', $result['changed']);
        $fresh = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $this->assertSame($newWallet, $fresh->usdt_wallet);
        $this->assertNotEmpty($fresh->usdt_wallet_cooldown_until);

        $events = model(MemberWalletChangeEventModel::class)->recentForUser($member['user_id'], 5);
        $this->assertNotEmpty($events);
        $this->assertSame($newWallet, $events[0]['new_wallet']);
    }

    public function testInvalidBep20WalletRejected(): void
    {
        $member = $this->registerMember('badbep');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('BEP20');

        service('memberProfileService')->updateOwnProfile($member['user_id'], [
            'member_name'           => $member['profile']->member_name,
            'mobile'                => $member['mobile'],
            'email'                 => $member['email'],
            'usdt_wallet'           => 'not-a-wallet',
            'current_password'      => 'Password123!',
            'confirm_wallet_change' => '1',
        ]);
    }

    public function testWithdrawalsUiAndStoreRemainBlocked(): void
    {
        $member = $this->registerMember('wdlock');
        $this->loginMember($member);

        $this->assertFalse(service('commissionWalletService')->withdrawalEnabled());

        $index = $this->withSession()->get('member/withdrawals');
        $index->assertOK();
        $index->assertSee('Not Available');
        $index->assertSee('Request Withdrawal — Not Available');

        $create = $this->withSession()->get('member/withdrawals/create');
        $create->assertRedirectTo(site_url('member/withdrawals'));

        $store = $this->withSession()->post('member/withdrawals', [
            'amount'              => '10',
            'currency'            => 'USDT',
            'network'             => 'BEP20',
            'destination_address' => '0x' . str_repeat('e', 40),
        ]);
        $store->assertRedirectTo(site_url('member/withdrawals'));
    }

    public function testMiningAndCommissionPagesAreSimulationLabeled(): void
    {
        $member = $this->registerMember('simlab');
        $this->loginMember($member);

        $mining = $this->withSession()->get('member/mining');
        $mining->assertOK();
        $mining->assertSee('Simulation Only');
        $mining->assertSee('Not Activated');

        $comm = $this->withSession()->get('member/commissions');
        $comm->assertOK();
        $comm->assertSee('Simulation Only');
        $comm->assertSee('Not Activated');

        $cw = $this->withSession()->get('member/commission-wallet');
        $cw->assertOK();
        $cw->assertSee('Simulation is not payable');
    }

    public function testSupportAndDashboardMemberIsolationSmoke(): void
    {
        $a = $this->registerMember('isoa');
        $b = $this->registerMember('isob');

        $ticket = service('supportTicketService')->createTicket($a['user_id'], [
            'category' => 'account',
            'subject'  => 'Phase10A isolation',
            'message'  => 'Only member A should see this ticket body.',
        ]);
        $ref = (string) $ticket['ticket']['ticket_ref'];

        $this->loginMember($b);
        $leak = $this->withSession()->get('member/support/' . rawurlencode($ref));
        $leak->assertRedirect();

        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $this->loginMember($a);
        $own = $this->withSession()->get('member/support/' . rawurlencode($ref));
        $own->assertOK();
        $own->assertSee('Phase10A isolation');

        $dash = $this->withSession()->get('member/dashboard');
        $dash->assertOK();
        $dash->assertSee($a['member_id']);
        $dash->assertDontSee($b['member_id']);
    }

    public function testPasswordChangeViaSecurity(): void
    {
        $member = $this->registerMember('pwchg');
        $this->loginMember($member);

        $resp = $this->withSession()->post('member/security/password', [
            'current_password' => 'Password123!',
            'password'         => 'NewPassword123!',
            'password_confirm' => 'NewPassword123!',
        ]);
        $resp->assertRedirect();

        $user = model(UserModel::class)->findById($member['user_id']);
        $this->assertTrue(service('passwords')->verify('NewPassword123!', (string) $user->password_hash));
    }

    public function testEmailChangeTokenTableExistsAfterMigrate(): void
    {
        $this->assertTrue(db_connect()->tableExists('member_email_change_tokens'));
        $this->assertTrue(db_connect()->tableExists('member_wallet_change_events'));
        $this->assertInstanceOf(MemberEmailChangeTokenModel::class, model(MemberEmailChangeTokenModel::class));
    }
}
