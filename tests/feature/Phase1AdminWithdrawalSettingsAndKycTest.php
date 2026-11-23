<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\CommissionWithdrawalService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 1 — Admin withdrawal settings gates (execution remains OFF unless test unlocks).
 *
 * @internal
 */
final class Phase1AdminWithdrawalSettingsAndKycTest extends CIUnitTestCase
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
        $platform->memberIdStartNumber               = 1;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->mandatoryPurchaseLockEnabled      = true;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p1 price');
        service('coinInventoryService')->addInventory(
            '20000',
            $this->adminUserId,
            'p1 stock',
            null,
            'p1-stock-' . bin2hex(random_bytes(3))
        );

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $platform = config('Platform');
        $platform->commissionWithdrawalEnabled         = false;
        $platform->commissionBlockchainPayoutEnabled   = false;
        $platform->commissionWithdrawalFeeUsdt         = null;
        $platform->commissionWithdrawalMinimumUsdt     = null;
        $platform->commissionWithdrawalFeePolicyStatus = 'pending_business_decision';
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * @return array{user_id: int, member_id: string, password: string}
     */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p1' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p1-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P1 ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'p1' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
            'password'  => 'Password123!',
        ];
    }

    private function activate(array $member): void
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '10',
            null,
            'p1-act-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $this->adminUserId,
            true,
            'p1'
        );
    }

    private function seedPostedCommission(int $userId, string $memberId, string $amount): void
    {
        service('commissionWalletService')->ensureAccount($userId, $memberId);
        service('commissionWalletService')->applyLedger(
            $userId,
            $memberId,
            \App\Services\CommissionWalletService::TYPE_COMMISSION_CREDIT,
            [
                'amount'          => $amount,
                'reference_type'  => 'test_seed',
                'reference_id'    => 'seed-' . $userId . '-' . bin2hex(random_bytes(2)),
                'idempotency_key' => 'test-credit:' . $userId . ':' . bin2hex(random_bytes(4)),
                'created_by'      => $userId,
            ]
        );
    }

    private function loginAs(int $userId): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $user = model(UserModel::class)->findById($userId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    private function unlockEmergency(): void
    {
        config('Platform')->commissionWithdrawalEnabled       = true;
        config('Platform')->commissionBlockchainPayoutEnabled = false;
    }

    public function testSettingsStartPendingWithoutInventedDefaults(): void
    {
        $current = service('commissionWithdrawalSettingsService')->current();
        $this->assertNull($current['minimum_usdt']);
        $this->assertNull($current['fee_usdt']);
        $this->assertFalse($current['policy_approved']);
        $this->assertFalse($current['requests_enabled']);
        $this->assertFalse(service('commissionWithdrawalService')->feePolicyApproved());
    }

    public function testAdminCanChangeMinimumAndFee(): void
    {
        $this->unlockEmergency();
        $out = service('commissionWithdrawalSettingsService')->update([
            'minimum_usdt'   => '10.00000000',
            'fee_usdt'       => '1.50000000',
            'approve_policy' => true,
        ], $this->adminUserId);

        $this->assertSame('10.00000000', $out['minimum_usdt']);
        $this->assertSame('1.50000000', $out['fee_usdt']);
        $this->assertTrue($out['policy_approved']);
        $this->assertFalse($out['requests_enabled']);

        $audit = service('commissionWithdrawalSettingsService')->auditTrail(5);
        $this->assertNotEmpty($audit);
        $this->assertSame($this->adminUserId, (int) $audit[0]['actor_user_id']);
    }

    public function testCannotActivateRequestsWithoutEmergencyUnlock(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('emergency unlock');
        service('commissionWithdrawalSettingsService')->update([
            'minimum_usdt'     => '5.00000000',
            'fee_usdt'         => '0.00000000',
            'approve_policy'   => true,
            'requests_enabled' => true,
        ], $this->adminUserId);
    }

    public function testCannotActivateWithInvalidPolicy(): void
    {
        $this->unlockEmergency();
        $this->expectException(InvalidArgumentException::class);
        service('commissionWithdrawalSettingsService')->update([
            'minimum_usdt'     => '0.00000000',
            'fee_usdt'         => '0.00000000',
            'approve_policy'   => true,
            'requests_enabled' => true,
        ], $this->adminUserId);
    }

    public function testDisabledSettingsBlockSubmission(): void
    {
        $m = $this->register('disableset');
        $this->activate($m);
        $this->seedPostedCommission($m['user_id'], $m['member_id'], '20.00000000');
        $this->unlockEmergency();
        service('commissionWithdrawalSettingsService')->applyForTests('5.00000000', '0.50000000', false, $this->adminUserId);
        $this->loginAs($m['user_id']);

        $d = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '5.00000000',
            'idempotency_key' => 'p1-dis-' . $m['user_id'],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('request activation is OFF');
        service('commissionWithdrawalService')->submit($d['withdrawal_ref'], $m['user_id'], $m['password']);
    }

    public function testInvalidBep20WalletBlocksDraft(): void
    {
        $m = $this->register('badwallet');
        $this->activate($m);
        $this->seedPostedCommission($m['user_id'], $m['member_id'], '20.00000000');
        $this->unlockEmergency();
        service('commissionWithdrawalSettingsService')->applyForTests('5.00000000', '0.00000000', true, $this->adminUserId);

        db_connect()->table('member_profiles')->where('user_id', $m['user_id'])->update([
            'usdt_wallet' => 'not-a-wallet',
        ]);

        $this->expectException(InvalidArgumentException::class);
        service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '5.00000000',
            'idempotency_key' => 'p1-badw-' . $m['user_id'],
        ]);
    }

    public function testValidRequestWithFeePreviewAndReserve(): void
    {
        $m = $this->register('validwd');
        $this->activate($m);
        $this->seedPostedCommission($m['user_id'], $m['member_id'], '20.00000000');
        $this->unlockEmergency();
        service('commissionWithdrawalSettingsService')->applyForTests('5.00000000', '1.00000000', true, $this->adminUserId);
        $this->loginAs($m['user_id']);

        $preview = service('commissionWithdrawalService')->preview($m['user_id'], '10.00000000');
        $this->assertTrue($preview['can_execute']);
        $this->assertSame('1.00000000', $preview['fee_amount']);
        $this->assertSame('9.00000000', $preview['net_amount']);

        $d = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '10.00000000',
            'idempotency_key' => 'p1-ok-' . $m['user_id'],
        ]);
        $out = service('commissionWithdrawalService')->submit($d['withdrawal_ref'], $m['user_id'], $m['password']);
        $this->assertSame(CommissionWithdrawalService::STATUS_PENDING_ADMIN_REVIEW, $out['status']);
        $row = service('commissionWithdrawalService')->findByRef((string) $out['withdrawal_ref']);
        $this->assertSame('1.00000000', (string) ($row['fee_amount'] ?? ''));
        $this->assertSame('9.00000000', (string) ($row['net_amount'] ?? ''));
        $this->assertNotSame(CommissionWithdrawalService::STATUS_PAID, $out['status']);
    }

    public function testDuplicateIdempotencyReturnsSameDraft(): void
    {
        $m = $this->register('dupkey');
        $this->activate($m);
        $this->seedPostedCommission($m['user_id'], $m['member_id'], '20.00000000');
        $this->unlockEmergency();
        service('commissionWithdrawalSettingsService')->applyForTests('5.00000000', '0.00000000', true, $this->adminUserId);

        $key = 'p1-dup-' . $m['user_id'];
        $a = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '5.00000000',
            'idempotency_key' => $key,
        ]);
        $b = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '5.00000000',
            'idempotency_key' => $key,
        ]);
        $this->assertSame($a['withdrawal_ref'], $b['withdrawal_ref']);
    }

    public function testAdminApproveAndRejectRelease(): void
    {
        $m = $this->register('admflow');
        $this->activate($m);
        $this->seedPostedCommission($m['user_id'], $m['member_id'], '30.00000000');
        $this->unlockEmergency();
        service('commissionWithdrawalSettingsService')->applyForTests('5.00000000', '0.50000000', true, $this->adminUserId);
        $this->loginAs($m['user_id']);

        $d1 = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '6.00000000',
            'idempotency_key' => 'p1-apr-' . $m['user_id'],
        ]);
        service('commissionWithdrawalService')->submit($d1['withdrawal_ref'], $m['user_id'], $m['password']);

        $d2 = service('commissionWithdrawalService')->createDraft($m['user_id'], $m['member_id'], [
            'amount' => '7.00000000',
            'idempotency_key' => 'p1-rej-' . $m['user_id'],
        ]);
        service('commissionWithdrawalService')->submit($d2['withdrawal_ref'], $m['user_id'], $m['password']);

        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $admin = model(UserModel::class)->findById($this->adminUserId);
        $admin->fill(['password' => 'AdminPass123!']);
        model(UserModel::class)->save($admin);
        auth()->login($admin);

        $approved = service('commissionWithdrawalService')->approve($d1['withdrawal_ref'], $this->adminUserId, 'AdminPass123!');
        $this->assertSame(CommissionWithdrawalService::STATUS_APPROVED, $approved['status']);

        $rejected = service('commissionWithdrawalService')->reject(
            $d2['withdrawal_ref'],
            $this->adminUserId,
            'incomplete docs',
            'AdminPass123!'
        );
        $this->assertSame(CommissionWithdrawalService::STATUS_REJECTED, $rejected['status']);

        $summary = service('commissionWalletService')->withdrawableSummary($m['user_id']);
        // 30 - 6 approved reserved + 7 released = 24 available, 6 reserved
        $this->assertSame('24.00000000', $summary['available_balance']);
        $this->assertSame('6.00000000', $summary['reserved_balance']);
    }

    public function testMemberCannotAccessAdminWithdrawalSettings(): void
    {
        $m = $this->register('memiso');
        $this->loginAs($m['user_id']);
        $res = $this->get('admin/withdrawal-settings');
        $code = $res->response()->getStatusCode();
        $this->assertTrue($code === 302 || $code === 303 || $code === 401 || $code === 403 || $code >= 300);
    }
}
