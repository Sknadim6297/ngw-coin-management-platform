<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\FinancialAlertService;
use App\Services\FinancialNotificationService;
use App\Services\FinancialRemediationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Email\Email;
use Config\FinanceNotifications;
use Config\Services;
use InvalidArgumentException;

/**
 * Phase 4E finance roles, notifications, remediation safety.
 *
 * @internal
 */
final class FinanceRolesAndNotificationsTest extends CIUnitTestCase
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

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
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

    public function testFinanceViewerReadOnlyCannotAckOrPropose(): void
    {
        $viewer = $this->makeUser('viewer4e@example.com', ['finance_viewer']);
        $this->assertTrue($viewer->can('finance.monitor.view'));
        $this->assertFalse($viewer->can('finance.alerts.acknowledge'));
        $this->assertFalse($viewer->can('finance.remediation.propose'));
        $this->assertFalse($viewer->can('finance.remediation.approve'));
    }

    public function testFinanceOperatorCanProposeNotApprove(): void
    {
        $op = $this->makeUser('op4e@example.com', ['finance_operator']);
        $this->assertTrue($op->can('finance.remediation.propose'));
        $this->assertTrue($op->can('finance.alerts.acknowledge'));
        $this->assertFalse($op->can('finance.remediation.approve'));
    }

    public function testFinanceApproverCanApproveNotPropose(): void
    {
        $ap = $this->makeUser('ap4e@example.com', ['finance_approver']);
        $this->assertTrue($ap->can('finance.remediation.approve'));
        $this->assertFalse($ap->can('finance.remediation.propose'));
    }

    public function testAuditorCannotMutate(): void
    {
        $aud = $this->makeUser('aud4e@example.com', ['finance_auditor']);
        $this->assertTrue($aud->can('finance.monitor.view'));
        $this->assertFalse($aud->can('finance.alerts.acknowledge'));
        $this->assertFalse($aud->can('finance.remediation.propose'));
        $this->assertFalse($aud->can('finance.remediation.approve'));
    }

    public function testAdminGroupDoesNotGetBlanketFinanceStar(): void
    {
        $adminOnly = $this->makeUser('adminonly4e@example.com', ['admin']);
        // Without finance_* groups, admin must not inherit finance.* matrix.
        $this->assertFalse($adminOnly->can('finance.remediation.approve'));
        $this->assertFalse($adminOnly->can('finance.monitor.view'));
    }

    public function testSelfApprovalStillBlocked(): void
    {
        $rem = service('financialRemediationService');
        $p = $rem->propose(
            $this->adminUserId,
            'ledger_mismatch',
            'T',
            'R',
            null,
            null,
            null,
            [],
            [],
            '4e-self',
            'ledger_mismatch'
        );
        $this->expectException(InvalidArgumentException::class);
        $rem->approve($p['proposal_ref'], $this->adminUserId, 'nope', true);
    }

    public function testRemediationExecutionRemainsDisabled(): void
    {
        $this->expectException(InvalidArgumentException::class);
        service('financialRemediationService')->execute('REM-NONE', $this->adminUserId);
    }

    public function testNotificationDedupAndSmtpUnavailable(): void
    {
        $cfg = config('FinanceNotifications');
        $cfg->emailEnabled = true;
        $cfg->recipients = 'ops@example.com';
        // SMTP not configured → isDeliveryConfigured may still be false if fromEmail empty.
        $emailCfg = config('Email');
        $emailCfg->protocol = 'smtp';
        $emailCfg->SMTPHost = '';
        $emailCfg->fromEmail = '';

        $svc = new FinancialNotificationService($cfg);
        $this->assertFalse($svc->isDeliveryConfigured());

        $alert = service('financialAlertService')->raise(
            FinancialAlertService::TYPE_RPC_UNAVAILABLE,
            'critical',
            'RPC down',
            'timeout https://secret.example/token=abc',
            '4e-rpc-down'
        );
        // Enqueue no-ops when not configured
        $this->assertNull($svc->enqueueForAlert($alert));

        // Configure fully then dedupe
        $emailCfg->SMTPHost = 'smtp.example.test';
        $emailCfg->fromEmail = 'noreply@example.test';
        $svc2 = new FinancialNotificationService($cfg);
        $this->assertTrue($svc2->isDeliveryConfigured());

        $r1 = $svc2->enqueueForAlert($alert);
        $r2 = $svc2->enqueueForAlert($alert);
        $this->assertNotNull($r1);
        $this->assertSame($r1['outbox_ref'], $r2['outbox_ref']);
        $this->assertStringContainsString('***', (string) $r1['recipients_redacted']);

        // processPending should fail safely (no real SMTP) and increment attempts
        $result = $svc2->processPending(5);
        $this->assertTrue($result['configured']);
        $this->assertGreaterThanOrEqual(1, $result['processed']);
    }

    public function testOutboxRetryLimits(): void
    {
        $cfg = config('FinanceNotifications');
        $cfg->emailEnabled = true;
        $cfg->recipients = 'ops@example.com';
        $cfg->maxAttempts = 2;
        $cfg->retryBackoffSeconds = 0;
        $cfg->retryMaxBackoffSeconds = 0;
        $emailCfg = config('Email');
        $emailCfg->protocol = 'smtp';
        $emailCfg->SMTPHost = 'smtp.example.test';
        $emailCfg->fromEmail = 'noreply@example.test';

        // Force deterministic SMTP failure (real mail()/smtp may succeed on some hosts).
        $mailer = $this->getMockBuilder(Email::class)->disableOriginalConstructor()->getMock();
        $mailer->method('setTo')->willReturnSelf();
        $mailer->method('setSubject')->willReturnSelf();
        $mailer->method('setMessage')->willReturnSelf();
        $mailer->method('send')->willReturn(false);
        $mailer->method('printDebugger')->willReturn('SMTP connection refused [redacted-url]');
        Services::injectMock('email', $mailer);

        $svc = new FinancialNotificationService($cfg);
        $alert = [
            'alert_ref'  => 'ALT-TEST',
            'alert_type' => 'rpc_unavailable',
            'severity'   => 'critical',
            'title'      => 't',
            'message'    => 'm',
            'dedupe_key' => '4e-retry-limit',
        ];
        $row = $svc->enqueueForAlert($alert);
        $this->assertNotNull($row);

        $first = $svc->processPending(5);
        $this->assertSame(1, $first['processed']);
        $mid = db_connect()->table('notification_outbox')->where('dedupe_key', 'alert-email:4e-retry-limit')->get()->getRowArray();
        $this->assertSame(1, (int) $mid['attempt_count']);
        $this->assertSame('pending', $mid['status']);

        // Ensure backoff window has elapsed for the second attempt.
        db_connect()->table('notification_outbox')->where('id', (int) $mid['id'])->update([
            'next_attempt_at' => date('Y-m-d H:i:s', time() - 1),
        ]);

        $second = $svc->processPending(5);
        $this->assertSame(1, $second['processed']);
        $final = db_connect()->table('notification_outbox')->where('dedupe_key', 'alert-email:4e-retry-limit')->get()->getRowArray();
        $this->assertNotNull($final);
        $this->assertGreaterThanOrEqual(2, (int) $final['attempt_count']);
        $this->assertSame('failed', $final['status']);
    }

    public function testFinanceDashboardAndAllocationVisibilityForSeededAdmin(): void
    {
        $login = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $login->assertRedirect();

        $dash = $this->get('admin/finance');
        $dash->assertOK();
        $dash->assertSee('Finance Overview');

        $purchases = $this->get('admin/purchases');
        $purchases->assertOK();
    }

    public function testCrossMemberPurchaseAccessBlocked(): void
    {
        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, '4e');
        service('coinInventoryService')->addInventory('100', $this->adminUserId, 's', null, '4e-s');

        $a = service('registrationService')->register([
            'member_name' => 'A',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1911111111',
            'email'       => 'a4e@example.com',
            'usdt_wallet' => '0x' . str_repeat('a', 40),
            'password'    => 'Password123!',
        ]);
        $b = service('registrationService')->register([
            'member_name' => 'B',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1922222222',
            'email'       => 'b4e@example.com',
            'usdt_wallet' => '0x' . str_repeat('b', 40),
            'password'    => 'Password123!',
        ]);
        $pa = model(MemberProfileModel::class)->findByUserId((int) $a['user']->id);
        $pb = model(MemberProfileModel::class)->findByUserId((int) $b['user']->id);

        $purchase = service('coinPurchaseService')->createRequest((int) $a['user']->id, '10', null, '4e-cross');

        $this->post('login', ['member_id' => $pb->member_id, 'password' => 'Password123!']);
        $res = $this->get('member/purchases/' . $purchase['request']['purchase_ref']);
        $res->assertRedirect();
    }
}
