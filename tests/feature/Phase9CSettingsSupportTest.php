<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * Phase 9C focused: platform settings + support tickets.
 *
 * @internal
 */
final class Phase9CSettingsSupportTest extends CIUnitTestCase
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
        $platform->requireVerifiedDepositForApproval = true;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p9c price');
        service('coinInventoryService')->addInventory('800', $this->adminUserId, 'p9c stock', null, 'p9c-stock-' . bin2hex(random_bytes(3)));

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
     * @return array{user_id: int, member_id: string, profile: object}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p9c' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p9c-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P9C ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '15' . substr($digits . '00000000', 0, 8),
            'email'       => 'p9c' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
            'profile'   => $profile,
        ];
    }

    private function loginAdmin(): void
    {
        auth()->login(model(UserModel::class)->findById($this->adminUserId));
    }

    private function loginMember(array $member): void
    {
        auth()->login(model(UserModel::class)->findById($member['user_id']));
    }

    public function testAuthorizedSettingsUpdateAndAudit(): void
    {
        $this->loginAdmin();
        $page = $this->withSession()->get('admin/settings');
        $page->assertOK();
        $page->assertSee('General');
        $page->assertSee('Audit History');

        $result = service('platformSettingsService')->updateGroup('general', [
            'platform_name'   => 'NGW Test Platform',
            'contact_email'   => 'ops@example.com',
            'support_contact' => 'support@example.com',
            'timezone'        => 'Asia/Kolkata',
            'date_format'     => 'd/m/Y H:i',
        ], $this->adminUserId);

        $this->assertContains('platform_name', $result['changed']);
        $this->assertSame('NGW Test Platform', service('platformSettingsService')->get('platform_name'));

        $audit = service('platformSettingsService')->auditHistory();
        $this->assertGreaterThanOrEqual(1, $audit['total']);
        $keys = array_column($audit['rows'], 'setting_key');
        $this->assertContains('platform_name', $keys);

        $legacy = $this->withSession()->get('admin/coming-soon/settings');
        $legacy->assertRedirectTo(site_url('admin/settings'));
    }

    public function testUnauthorizedSettingsUpdateRejected(): void
    {
        $member = $this->registerMember('setdeny');
        $this->loginMember($member);

        $blocked = $this->withSession()->get('admin/settings');
        $blocked->assertRedirect();

        $this->expectException(InvalidArgumentException::class);
        service('platformSettingsService')->updateMany([
            'platform_name' => 'Hacked',
        ], 0);
    }

    public function testMemberCreatesAndViewsOwnTicket(): void
    {
        $member = $this->registerMember('tixown');
        $created = service('supportTicketService')->createTicket($member['user_id'], [
            'category' => 'payment',
            'subject'  => 'Payment question test',
            'message'  => 'I need help verifying my payment.',
        ]);

        $this->assertSame('open', $created['ticket']['status']);
        $ref = (string) $created['ticket']['ticket_ref'];

        $thread = service('supportTicketService')->memberThread($member['user_id'], $ref);
        $this->assertCount(1, $thread['messages']);

        $this->loginMember($member);
        $list = $this->withSession()->get('member/support');
        $list->assertOK();
        $list->assertSee($ref);

        $show = $this->withSession()->get('member/support/' . $ref);
        $show->assertOK();
        $show->assertSee('Payment question test');
    }

    public function testCrossMemberTicketAccessBlocked(): void
    {
        $a = $this->registerMember('tixa');
        $b = $this->registerMember('tixb');
        $created = service('supportTicketService')->createTicket($a['user_id'], [
            'category' => 'account',
            'subject'  => 'Private ticket subject',
            'message'  => 'This belongs to member A only.',
        ]);
        $ref = (string) $created['ticket']['ticket_ref'];

        $this->expectException(InvalidArgumentException::class);
        service('supportTicketService')->memberThread($b['user_id'], $ref);
    }

    public function testAdminReplyAndStatusChange(): void
    {
        $member = $this->registerMember('tixadm');
        $created = service('supportTicketService')->createTicket($member['user_id'], [
            'category' => 'wallet',
            'subject'  => 'Wallet balance question',
            'message'  => 'Please check my available coins.',
        ]);
        $ref = (string) $created['ticket']['ticket_ref'];

        $reply = service('supportTicketService')->adminReply(
            $this->adminUserId,
            $ref,
            'We reviewed your wallet balances.',
            'in_progress'
        );
        $this->assertSame('in_progress', $reply['ticket']['status']);
        $this->assertSame('admin', $reply['message']['sender_role']);

        $closed = service('supportTicketService')->updateStatus($this->adminUserId, $ref, 'closed');
        $this->assertSame('closed', $closed['status']);

        $this->loginAdmin();
        $page = $this->withSession()->get('admin/support?status=closed');
        $page->assertOK();
        $page->assertSee($ref);
    }

    public function testInvalidTicketInputRejected(): void
    {
        $member = $this->registerMember('tixbad');

        $this->expectException(InvalidArgumentException::class);
        service('supportTicketService')->createTicket($member['user_id'], [
            'category' => 'not-real',
            'subject'  => 'Hi',
            'message'  => 'Yo',
        ]);
    }

    public function testPaymentAndRegistrationConfigUnaffected(): void
    {
        $beforePrice = service('coinPriceService')->settingsSummary();
        $beforeGate  = (bool) config('Platform')->requireVerifiedDepositForApproval;
        $beforePost  = (bool) config('Platform')->commissionPostingEnabled;

        service('platformSettingsService')->updateGroup('notifications', [
            'notify_admin_alerts'       => '1',
            'notify_member_events'      => '1',
            'notify_payment_submission' => '0',
            'notify_purchase_approval'  => '1',
        ], $this->adminUserId);

        $afterPrice = service('coinPriceService')->settingsSummary();
        $this->assertSame($beforePrice['current_price_usdt'], $afterPrice['current_price_usdt']);
        $this->assertSame($beforePrice['min_purchase_usdt'], $afterPrice['min_purchase_usdt']);
        $this->assertSame($beforeGate, (bool) config('Platform')->requireVerifiedDepositForApproval);
        $this->assertSame($beforePost, (bool) config('Platform')->commissionPostingEnabled);
        $this->assertFalse((bool) config('Platform')->commissionPostingEnabled);

        $security = service('platformSettingsService')->securitySnapshot();
        $labels   = array_column($security, 'label');
        $this->assertContains('Commission Posting', $labels);
        $this->assertContains('Mining Payouts', $labels);

        $this->loginAdmin();
        $details = $this->withSession()->get('admin/payment-details');
        $details->assertOK();
    }

    public function testRegistrationDisabledBlocksPublicSignup(): void
    {
        service('platformSettingsService')->updateGroup('registration', [
            'registration_enabled'      => '0',
            'sponsor_required'          => '1',
            'registration_instructions' => 'Closed for maintenance.',
        ], $this->adminUserId);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('disabled');
        service('registrationService')->register([
            'member_name' => 'Blocked User',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '15999888777',
            'email'       => 'blockedp9c@example.com',
            'usdt_wallet' => '0x' . str_repeat('1', 40),
            'password'    => 'Password123!',
        ], null, 'public');
    }
}
