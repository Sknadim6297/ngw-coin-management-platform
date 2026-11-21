<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\MemberAuthService;
use App\Services\MemberIdGenerator;
use App\Services\PasswordResetService;
use App\Services\RegistrationService;
use App\Services\RegistrationEmailVerificationService;
use App\Services\SponsorValidator;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Email\Email;
use Config\Email as EmailConfig;
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
    private string $verificationMessage = '';

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
        $this->assertSame(0, (int) $result['user']->active);
        $this->assertFalse($result['verification_email_sent']);
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

    public function testMemberLoginIsBlockedUntilEmailVerification(): void
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

        $this->assertFalse($auth['success']);
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByMemberId($created['member_id'])->account_status);
    }

    public function testSuccessfulEmailVerificationIsSingleUseAndEnablesLogin(): void
    {
        $created = service('registrationService')->register([
            'member_name' => 'Verified Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '4545454545',
            'email'       => 'verified@example.com',
            'usdt_wallet' => '0x1111111111111111111111111111111111111111',
            'password'    => 'Password123!',
        ]);
        $verification = $this->verificationService(true);
        $sent = $verification->sendForUser((int) $created['user']->id);
        $this->assertTrue($sent['sent']);
        $token = $this->verificationToken();
        $this->assertFalse(service('memberAuthService')->attempt($created['member_id'], 'Password123!')['success']);
        $this->assertTrue($verification->verify($token));
        $this->assertFalse($verification->verify($token));
        $this->assertTrue(service('memberAuthService')->attempt($created['member_id'], 'Password123!')['success']);
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByMemberId($created['member_id'])->account_status);
    }

    public function testFailedEmailTransportLeavesMemberUnverifiedAndRecordsFailure(): void
    {
        $created = service('registrationService')->register([
            'member_name' => 'Unsent Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '4646464646',
            'email'       => 'unsent@example.com',
            'usdt_wallet' => '0x2222222222222222222222222222222222222222',
            'password'    => 'Password123!',
        ]);

        $result = $this->verificationService(false)->sendForUser((int) $created['user']->id);
        $row = db_connect()->table('registration_email_verification_tokens')
            ->where('user_id', (int) $created['user']->id)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $this->assertFalse($result['sent']);
        $this->assertSame(RegistrationEmailVerificationService::STATUS_FAILED, $row['status']);
        $this->assertSame('transport_failed', $row['last_error']);
        $this->assertFalse(service('memberAuthService')->attempt($created['member_id'], 'Password123!')['success']);
    }

    public function testExpiredVerificationTokenIsMarkedExpired(): void
    {
        $created = service('registrationService')->register([
            'member_name' => 'Expired Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '4747474747',
            'email'       => 'expired@example.com',
            'usdt_wallet' => '0x3333333333333333333333333333333333333333',
            'password'    => 'Password123!',
        ]);
        $verification = $this->verificationService(true);
        $verification->sendForUser((int) $created['user']->id);
        $token = $this->verificationToken();
        $tokenHash = hash('sha256', $token);
        db_connect()->table('registration_email_verification_tokens')
            ->where('token_hash', $tokenHash)
            ->update(['expires_at' => date('Y-m-d H:i:s', time() - 1)]);

        $this->assertFalse($verification->verify($token));
        $row = db_connect()->table('registration_email_verification_tokens')
            ->where('token_hash', $tokenHash)
            ->get()
            ->getRowArray();
        $this->assertSame(RegistrationEmailVerificationService::STATUS_EXPIRED, $row['status']);
        $this->assertFalse(service('memberAuthService')->attempt($created['member_id'], 'Password123!')['success']);
    }

    public function testVerificationResendIsThrottledPerMember(): void
    {
        $created = service('registrationService')->register([
            'member_name' => 'Throttle Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '4848484848',
            'email'       => 'throttle@example.com',
            'usdt_wallet' => '0x4444444444444444444444444444444444444444',
            'password'    => 'Password123!',
        ]);
        config('Platform')->registrationEmailMaxPerHour = 2;
        $verification = $this->verificationService(true);
        $first = $verification->sendForUser((int) $created['user']->id);
        $second = $verification->sendForUser((int) $created['user']->id);

        $this->assertTrue($first['sent']);
        $this->assertFalse($second['sent']);
        $this->assertTrue($second['throttled']);
        $this->assertSame(2, (int) db_connect()->table('registration_email_verification_tokens')
            ->where('user_id', (int) $created['user']->id)
            ->countAllResults());
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
        $user = auth()->getProvider()->findById((int) $created['user']->id);
        $user->activate();
        auth()->getProvider()->save($user);

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

    private function verificationService(bool $sendResult): RegistrationEmailVerificationService
    {
        $mailer = $this->createMock(Email::class);
        $test = $this;
        $mailer->method('clear')->willReturnSelf();
        $mailer->method('setTo')->willReturnSelf();
        $mailer->method('setSubject')->willReturnSelf();
        $mailer->method('setMailType')->willReturnSelf();
        $mailer->method('setMessage')->willReturnCallback(static function (string $message) use ($mailer, $test): Email {
            $test->verificationMessage = $message;

            return $mailer;
        });
        $mailer->method('send')->willReturn($sendResult);

        $emailConfig = new EmailConfig();
        $emailConfig->protocol = 'smtp';
        $emailConfig->SMTPHost = 'smtp.test.local';
        $emailConfig->SMTPUser = 'test-user';
        $emailConfig->SMTPPass = 'test-password';
        $emailConfig->fromEmail = 'noreply@example.test';

        return new RegistrationEmailVerificationService($mailer, $emailConfig, config('Platform'));
    }

    private function verificationToken(): string
    {
        $safeMessage = preg_replace('/[a-f0-9]{64}/i', '[redacted]', $this->verificationMessage);
        $this->assertNotSame('', $safeMessage, 'Verification email body was empty.');
        $this->assertStringContainsString('verify-email', (string) $safeMessage, 'Verification URL was missing from the email body.');
        $this->assertSame(1, preg_match('/verify-email\/([a-f0-9]{64})/i', $this->verificationMessage, $matches), (string) $safeMessage);

        return strtolower($matches[1]);
    }
}
