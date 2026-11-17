<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class HttpAuthPagesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        $platform                 = config('Platform');
        $platform->adminEmail     = 'admin@test.local';
        $platform->adminPassword  = 'AdminPass123!';
        $platform->adminName      = 'Test Admin';
        $platform->rootSponsorId  = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->memberIdStartNumber = 1;

        putenv('platform.adminEmail=admin@test.local');
        putenv('platform.adminPassword=AdminPass123!');
        putenv('platform.rootSponsorId=ROOT0001');

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);

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

    public function testLoginPageLoads(): void
    {
        $result = $this->get('login');
        $result->assertStatus(200);
        $result->assertSee('Member Login');
    }

    public function testRegisterPageLoads(): void
    {
        $result = $this->get('register');
        $result->assertStatus(200);
        $result->assertSee('Member Registration');
    }

    public function testAdminLoginPageLoads(): void
    {
        $result = $this->get('admin/login');
        $result->assertStatus(200);
        $result->assertSee('Admin Login');
    }

    public function testForgotPasswordPageLoads(): void
    {
        $result = $this->get('forgot-password');
        $result->assertStatus(200);
        $result->assertSee('Forgot Password');
    }

    public function testMemberDashboardRequiresAuth(): void
    {
        $result = $this->get('member/dashboard');
        $result->assertRedirect();
    }

    public function testAdminDashboardRequiresAuth(): void
    {
        $result = $this->get('admin/dashboard');
        $result->assertRedirect();
    }

    public function testPublicRegistrationViaHttp(): void
    {
        $result = $this->post('register', [
            'member_name'      => 'Http Member',
            'sponsor_id'       => 'ROOT0001',
            'mobile'           => '7777777777',
            'email'            => 'httpmember@example.com',
            'usdt_wallet'      => '0x1111111111111111111111111111111111111111',
            'password'         => 'Password123!',
            'password_confirm' => 'Password123!',
        ]);

        $result->assertRedirectTo(site_url('login'));
    }

    public function testAdminLoginSucceeds(): void
    {
        $result = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);

        $result->assertRedirect();
        $this->assertStringContainsString('admin/dashboard', (string) $result->getRedirectUrl());
    }

    public function testMemberCannotAccessAdminDashboard(): void
    {
        service('registrationService')->register([
            'member_name' => 'Blocked Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '8888888888',
            'email'       => 'blocked@example.com',
            'usdt_wallet' => '0x2222222222222222222222222222222222222222',
            'password'    => 'Password123!',
        ]);

        service('memberAuthService')->attempt('CPM000001', 'Password123!');

        $result = $this->get('admin/dashboard');
        $result->assertRedirect();
    }
}
