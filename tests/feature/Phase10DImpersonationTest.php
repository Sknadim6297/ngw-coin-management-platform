<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\ImpersonationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * Phase 10D focused impersonation / support-view tests.
 *
 * @internal
 */
final class Phase10DImpersonationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private string $adminPassword = 'AdminPass123!';

    protected function setUp(): void
    {
        parent::setUp();

        $platform                 = config('Platform');
        $platform->adminEmail     = 'admin@test.local';
        $platform->adminPassword  = $this->adminPassword;
        $platform->adminName      = 'Test Admin';
        $platform->rootSponsorId  = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        // Ensure admin password hash is usable for reauth.
        $admin = model(UserModel::class)->findById($this->adminUserId);
        $admin->fill(['password' => $this->adminPassword]);
        model(UserModel::class)->save($admin);

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
     * @return array{user_id: int, member_id: string, email: string, password: string}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p10d' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p10d-' . $suffix), 0, 40), 40, '0');
        $password = 'Password123!';
        $result = service('registrationService')->register([
            'member_name' => 'P10D ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p10d' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => $password,
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
            'email'     => 'p10d' . $suffix . '@example.com',
            'password'  => $password,
        ];
    }

    private function loginAdmin(): void
    {
        $user = model(UserModel::class)->findById($this->adminUserId);
        auth()->login($user);
    }

    public function testAuthorizedAdminCanCreateGrant(): void
    {
        $member = $this->registerMember('ok');
        $this->loginAdmin();
        $grant = service('impersonationService')->createGrant(
            $this->adminUserId,
            $member['member_id'],
            $this->adminPassword
        );
        $this->assertNotEmpty($grant['token']);
        $this->assertSame($member['member_id'], $grant['target_member_id']);

        $row = db_connect()->table('audit_logs')
            ->where('action', 'admin.impersonation_requested')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($row);
    }

    public function testUnauthorizedFinanceViewerCannotImpersonate(): void
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p10dviewer', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'p10dviewer@test.local', 'password' => 'ViewerPass123!']);
        $user->addGroup('finance_viewer');

        $member = $this->registerMember('denyfin');
        auth()->login($user);

        $this->expectException(InvalidArgumentException::class);
        service('impersonationService')->createGrant((int) $user->id, $member['member_id'], 'ViewerPass123!');
    }

    public function testMemberCannotImpersonateAnotherMember(): void
    {
        $a = $this->registerMember('ma');
        $b = $this->registerMember('mb');
        $user = model(UserModel::class)->findById($a['user_id']);
        auth()->login($user);

        $this->expectException(InvalidArgumentException::class);
        service('impersonationService')->createGrant($a['user_id'], $b['member_id'], $a['password']);
    }

    public function testCannotImpersonateAdminOrRoot(): void
    {
        $this->loginAdmin();
        $this->expectException(InvalidArgumentException::class);
        service('impersonationService')->createGrant($this->adminUserId, 'ROOT0001', $this->adminPassword);
    }

    public function testInvalidTargetRejected(): void
    {
        $this->loginAdmin();
        $this->expectException(InvalidArgumentException::class);
        service('impersonationService')->createGrant($this->adminUserId, 'NOPE9999', $this->adminPassword);
    }

    public function testExpiredAndReusedGrantRejected(): void
    {
        $member = $this->registerMember('reuse');
        $this->loginAdmin();
        $grant = service('impersonationService')->createGrant(
            $this->adminUserId,
            $member['member_id'],
            $this->adminPassword
        );

        db_connect()->table('admin_impersonation_grants')
            ->where('grant_ref', $grant['grant_ref'])
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 10)]);

        try {
            service('impersonationService')->claimGrant($grant['token'], $this->adminUserId);
            $this->fail('Expected expired grant rejection');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('expired', strtolower($e->getMessage()));
        }

        $grant2 = service('impersonationService')->createGrant(
            $this->adminUserId,
            $member['member_id'],
            $this->adminPassword
        );
        service('impersonationService')->claimGrant($grant2['token'], $this->adminUserId);
        $this->expectException(InvalidArgumentException::class);
        service('impersonationService')->claimGrant($grant2['token'], $this->adminUserId);
    }

    public function testClaimKeepsAdminSessionAndScopesMemberData(): void
    {
        $member = $this->registerMember('scope');
        $this->loginAdmin();
        $grant   = service('impersonationService')->createGrant($this->adminUserId, $member['member_id'], $this->adminPassword);
        $claimed = service('impersonationService')->claimGrant($grant['token'], $this->adminUserId);

        $this->assertSame($this->adminUserId, (int) auth()->id());
        $this->assertNotSame($member['user_id'], (int) auth()->id());

        $ctx = service('impersonationService')->resolveActiveContext($this->adminUserId, $claimed['raw_token']);
        $this->assertSame($member['user_id'], $ctx['target_user_id']);
        $this->assertSame($member['member_id'], $ctx['target_member_id']);
        $this->assertTrue($ctx['read_only']);
    }

    public function testSupportViewBlocksMutatingPostsAndEndsSession(): void
    {
        $member = $this->registerMember('postblk');
        $this->loginAdmin();
        $grant   = service('impersonationService')->createGrant($this->adminUserId, $member['member_id'], $this->adminPassword);
        $claimed = service('impersonationService')->claimGrant($grant['token'], $this->adminUserId);

        $_COOKIE[ImpersonationService::COOKIE_NAME] = $claimed['raw_token'];
        try {
            $this->withSession()
                ->post('support-view/purchases', [
                    'quantity' => '10',
                ])
                ->assertRedirectTo(site_url('support-view/dashboard'));
        } finally {
            unset($_COOKIE[ImpersonationService::COOKIE_NAME]);
        }

        service('impersonationService')->endSessionByToken($claimed['raw_token'], 'ended');
        $row = db_connect()->table('admin_impersonation_sessions')
            ->where('session_ref', $claimed['session']['session_ref'])
            ->get()
            ->getRowArray();
        $this->assertSame('ended', $row['status']);
    }

    public function testAdminLogoutRevokesImpersonation(): void
    {
        $member = $this->registerMember('logout');
        $this->loginAdmin();
        $grant   = service('impersonationService')->createGrant($this->adminUserId, $member['member_id'], $this->adminPassword);
        $claimed = service('impersonationService')->claimGrant($grant['token'], $this->adminUserId);

        $n = service('impersonationService')->revokeAllForAdmin($this->adminUserId, 'admin_logout');
        $this->assertGreaterThanOrEqual(1, $n);

        $this->expectException(\RuntimeException::class);
        service('impersonationService')->resolveActiveContext($this->adminUserId, $claimed['raw_token']);
    }

    public function testHttpGrantRequiresPermissionAndOpensLaunch(): void
    {
        $member = $this->registerMember('http');
        $this->loginAdmin();

        $page = $this->withSession()->post('admin/members/' . $member['member_id'] . '/impersonate', [
            'admin_password' => $this->adminPassword,
        ]);
        $page->assertOK();
        $page->assertSee('Opening support view');
        $page->assertSee($member['member_id']);
    }

    public function testCookiePathIsSupportViewScoped(): void
    {
        $path = service('impersonationService')->cookiePath();
        $this->assertStringEndsWith('/support-view', $path);
    }
}
