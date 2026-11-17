<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\MemberAuthService;
use App\Services\MemberIdGenerator;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;
use App\Services\SponsorValidator;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Platform;

/**
 * @internal
 */
final class RegistrationAuthTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null; // migrate all namespaces including Shield

    protected function setUp(): void
    {
        parent::setUp();

        putenv('platform.adminEmail=admin@test.local');
        putenv('platform.adminPassword=AdminPass123!');
        putenv('platform.adminName=Test Admin');
        putenv('platform.rootSponsorId=ROOT0001');
        putenv('platform.memberIdPrefix=CPM');
        putenv('platform.memberIdStartNumber=1');

        $platform                      = config('Platform');
        $platform->adminEmail          = 'admin@test.local';
        $platform->adminPassword       = 'AdminPass123!';
        $platform->adminName           = 'Test Admin';
        $platform->rootSponsorId       = 'ROOT0001';
        $platform->memberIdPrefix      = 'CPM';
        $platform->memberIdStartNumber = 1;

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

    public function testRootSponsorCanBeValidated(): void
    {
        $result = service('sponsorValidator')->validate('ROOT0001');

        $this->assertTrue($result['valid']);
        $this->assertNotSame('', (string) $result['profile']->member_name);
        $this->assertTrue($result['profile']->is_root_sponsor);
    }

    public function testInvalidSponsorIsRejected(): void
    {
        $result = service('sponsorValidator')->validate('NOPE999');

        $this->assertFalse($result['valid']);
        $this->assertSame(SponsorValidator::REASON_NOT_FOUND, $result['reason']);
    }

    public function testPublicRegistrationCreatesInactiveMember(): void
    {
        $service = service('registrationService');
        $result  = $service->register([
            'member_name' => 'Alice Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '9876543210',
            'email'       => 'alice@example.com',
            'usdt_wallet' => '0x1234567890abcdef1234567890abcdef12345678',
            'password'    => 'Password123!',
        ]);

        $this->assertSame('CPM000001', $result['member_id']);
        $this->assertSame('inactive', $result['profile']->account_status);
        $this->assertTrue($result['user']->inGroup('member'));
        $this->assertFalse($result['user']->inGroup('admin'));
    }

    public function testMemberIdGenerationIsUniqueAndSequential(): void
    {
        $generator = service('memberIdGenerator');
        $first     = $generator->generate();
        $second    = $generator->generate();

        $this->assertSame('CPM000001', $first);
        $this->assertSame('CPM000002', $second);
        $this->assertNotSame($first, $second);
    }

    public function testInactiveSponsorCannotSponsor(): void
    {
        $registration = service('registrationService');
        $inactive     = $registration->register([
            'member_name' => 'Inactive Sponsor',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1111111111',
            'email'       => 'inactive@example.com',
            'usdt_wallet' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'password'    => 'Password123!',
        ]);

        $result = service('sponsorValidator')->validate($inactive['member_id']);

        $this->assertFalse($result['valid']);
        $this->assertSame(SponsorValidator::REASON_INACTIVE, $result['reason']);
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $registration = service('registrationService');
        $payload      = [
            'member_name' => 'Dup One',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '2222222222',
            'email'       => 'dup@example.com',
            'usdt_wallet' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'password'    => 'Password123!',
        ];

        $registration->register($payload);

        $this->expectException(\InvalidArgumentException::class);
        $registration->register([
            ...$payload,
            'member_name' => 'Dup Two',
            'mobile'      => '3333333333',
            'usdt_wallet' => '0xcccccccccccccccccccccccccccccccccccccccc',
        ]);
    }

    public function testMemberLoginWorksForInactiveAccount(): void
    {
        $registration = service('registrationService');
        $created      = $registration->register([
            'member_name' => 'Login Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '4444444444',
            'email'       => 'login@example.com',
            'usdt_wallet' => '0xdddddddddddddddddddddddddddddddddddddddd',
            'password'    => 'Password123!',
        ]);

        $auth = service('memberAuthService')->attempt($created['member_id'], 'Password123!');

        $this->assertTrue($auth['success']);
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByMemberId($created['member_id'])->account_status);
    }

    public function testAdminCreatedRegistrationWritesAuditTrail(): void
    {
        $admin = model(MemberProfileModel::class)->findByMemberId('ROOT0001');

        $result = service('registrationService')->register([
            'member_name' => 'Admin Created',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '5555555555',
            'email'       => 'admincreated@example.com',
            'usdt_wallet' => '0xeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee',
            'password'    => 'Password123!',
        ], (int) $admin->user_id, 'admin');

        $this->assertSame('inactive', $result['profile']->account_status);

        $log = db_connect()->table('audit_logs')
            ->where('action', 'admin.member_created')
            ->where('entity_id', $result['member_id'])
            ->get()
            ->getRowArray();

        $this->assertNotNull($log);
    }

    public function testPasswordResetCompletesWithValidToken(): void
    {
        $registration = service('registrationService');
        $created      = $registration->register([
            'member_name' => 'Reset Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '6666666666',
            'email'       => 'reset@example.com',
            'usdt_wallet' => '0xffffffffffffffffffffffffffffffffffffffff',
            'password'    => 'Password123!',
        ]);

        $reset   = service('passwordResetService')->requestReset('reset@example.com');
        $this->assertTrue($reset['queued']);
        $this->assertArrayHasKey('token', $reset);

        $ok = service('passwordResetService')->resetPassword($reset['token'], 'NewPassword123!');
        $this->assertTrue($ok);

        $authOld = service('memberAuthService')->attempt($created['member_id'], 'Password123!');
        $authNew = service('memberAuthService')->attempt($created['member_id'], 'NewPassword123!');

        $this->assertFalse($authOld['success']);
        $this->assertTrue($authNew['success']);
    }

    public function testForgotPasswordDoesNotRevealMissingEmail(): void
    {
        $result = service('passwordResetService')->requestReset('missing@example.com');

        $this->assertTrue($result['queued']);
        $this->assertArrayNotHasKey('token', $result);
    }
}
