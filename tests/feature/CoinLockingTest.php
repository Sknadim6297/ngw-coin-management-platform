<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\CoinLockingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 5 coin locking & lifecycle.
 *
 * @internal
 */
final class CoinLockingTest extends CIUnitTestCase
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

        $platform                 = config('Platform');
        $platform->adminEmail     = 'admin@test.local';
        $platform->adminPassword  = 'AdminPass123!';
        $platform->adminName      = 'Test Admin';
        $platform->rootSponsorId  = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Lock test listing');
        service('coinInventoryService')->addInventory('5000', $this->adminUserId, 'Stock', null, 'lock-stock-' . bin2hex(random_bytes(4)));

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

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('l' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Locker ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'locker' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
            'user_id'   => (int) $result['user']->id,
        ];
    }

    private function creditCoins(array $member, string $qty = '100'): void
    {
        $req = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'buy-' . $member['user_id'] . '-' . $qty . '-' . bin2hex(random_bytes(2)));
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'test credit');
    }

    private function enabledPlan(string $code = 'LOCK30', int $days = 30, string $min = '1', ?string $max = '1000'): array
    {
        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => $code . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
            'name'          => 'Test ' . $code,
            'description'   => 'Phase 5 test plan',
            'min_quantity'  => $min,
            'max_quantity'  => $max,
            'duration_days' => $days,
        ], $this->adminUserId);

        return service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);
    }

    private function assertInvariant(int $userId): void
    {
        $s = service('memberCoinOwnershipService')->summaryForUser($userId);
        $sum = MoneyMath::add($s['available'], $s['locked'], 8);
        $this->assertSame($s['balance'], $sum);
    }

    public function testMemberLocksAvailableCoins(): void
    {
        $member = $this->registerMember('lockok');
        $this->creditCoins($member, '50');
        $plan = $this->enabledPlan('OK');

        $result = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '20', 'idem-lock-ok');
        $this->assertFalse($result['duplicate']);
        $this->assertSame('active', $result['lock']['status']);
        $this->assertSame('20.00000000', $result['lock']['quantity']);

        $s = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('50.00000000', $s['balance']);
        $this->assertSame('30.00000000', $s['available']);
        $this->assertSame('20.00000000', $s['locked']);
        $this->assertInvariant($member['user_id']);
    }

    public function testGuestCannotLockCoins(): void
    {
        $result = $this->post('member/locking', [
            'plan_id'         => 1,
            'quantity'        => '10',
            'confirm'         => '1',
            'idempotency_key' => 'guest-lock',
        ]);
        $result->assertRedirect();
    }

    public function testCannotLockMoreThanAvailable(): void
    {
        $member = $this->registerMember('overlock');
        $this->creditCoins($member, '10');
        $plan = $this->enabledPlan('OVER');

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '11');
    }

    public function testNegativeAndZeroQuantitiesRejected(): void
    {
        $member = $this->registerMember('badqty');
        $this->creditCoins($member, '10');
        $plan = $this->enabledPlan('BAD');

        try {
            service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '0');
            $this->fail('Zero should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('greater than zero', $e->getMessage());
        }

        try {
            service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '-5');
            $this->fail('Negative should be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertTrue(true);
        }

        $this->assertInvariant($member['user_id']);
    }

    public function testDuplicateLockRequestPrevented(): void
    {
        $member = $this->registerMember('duplock');
        $this->creditCoins($member, '40');
        $plan = $this->enabledPlan('DUP');

        $a = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '10', 'same-lock-key');
        $b = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '10', 'same-lock-key');

        $this->assertFalse($a['duplicate']);
        $this->assertTrue($b['duplicate']);
        $this->assertSame($a['lock']['lock_ref'], $b['lock']['lock_ref']);

        $s = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('10.00000000', $s['locked']);
        $this->assertSame('30.00000000', $s['available']);
    }

    public function testConcurrentLockingCannotOverspend(): void
    {
        $member = $this->registerMember('race');
        $this->creditCoins($member, '25');
        $plan = $this->enabledPlan('RACE');

        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '20', 'race-1');

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '10', 'race-2');
    }

    public function testActiveLockCannotUnlockBeforeMaturity(): void
    {
        $member = $this->registerMember('early');
        $this->creditCoins($member, '30');
        $plan = $this->enabledPlan('EARLY');
        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '10', 'early-1');

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->unlockCoins($member['user_id'], $lock['lock']['lock_ref']);
    }

    public function testMaturedLockCanUnlock(): void
    {
        $member = $this->registerMember('mature');
        $this->creditCoins($member, '30');
        $plan = $this->enabledPlan('MAT', 1);
        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '12', 'mat-1');
        $ref  = $lock['lock']['lock_ref'];

        db_connect()->table('member_coin_locks')->where('lock_ref', $ref)->update([
            'matures_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->assertTrue(service('coinLockingService')->markMatured($ref));

        $beforeAcquired = service('memberCoinOwnershipService')->summaryForUser($member['user_id'])['total_acquired'];
        $unlocked = service('coinLockingService')->unlockCoins($member['user_id'], $ref);

        $this->assertSame('unlocked', $unlocked['lock']['status']);
        $s = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('30.00000000', $s['balance']);
        $this->assertSame('30.00000000', $s['available']);
        $this->assertSame('0.00000000', $s['locked']);
        $this->assertSame($beforeAcquired, $s['total_acquired']);
        $this->assertInvariant($member['user_id']);
    }

    public function testDoubleUnlockBlocked(): void
    {
        $member = $this->registerMember('dblun');
        $this->creditCoins($member, '20');
        $plan = $this->enabledPlan('DBL');
        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '5', 'dbl-1');
        $ref  = $lock['lock']['lock_ref'];

        db_connect()->table('member_coin_locks')->where('lock_ref', $ref)->update([
            'matures_at' => date('Y-m-d H:i:s', time() - 10),
        ]);
        service('coinLockingService')->markMatured($ref);

        $a = service('coinLockingService')->unlockCoins($member['user_id'], $ref);
        $b = service('coinLockingService')->unlockCoins($member['user_id'], $ref);
        $this->assertFalse($a['duplicate']);
        $this->assertTrue($b['duplicate']);
        $s = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('0.00000000', $s['locked']);
        $this->assertSame('20.00000000', $s['available']);
    }

    public function testCrossMemberAccessBlocked(): void
    {
        $a = $this->registerMember('xa');
        $b = $this->registerMember('xb');
        $this->creditCoins($a, '20');
        $plan = $this->enabledPlan('XMEM');
        $lock = service('coinLockingService')->lockCoins($a['user_id'], (int) $plan['id'], '5', 'xmem-1');

        $this->assertNull(service('coinLockingService')->findOwnedLock($b['user_id'], $lock['lock']['lock_ref']));

        service('memberAuthService')->attempt($b['member_id'], 'Password123!');
        $resp = $this->get('member/locking/' . $lock['lock']['lock_ref']);
        $resp->assertRedirect();

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->unlockCoins($b['user_id'], $lock['lock']['lock_ref']);
    }

    public function testDisablingPlanDoesNotModifyExistingLocks(): void
    {
        $member = $this->registerMember('disable');
        $this->creditCoins($member, '40');
        $plan = $this->enabledPlan('DIS');
        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '15', 'dis-1');

        service('coinLockingService')->setPlanEnabled((int) $plan['id'], false, $this->adminUserId);

        $row = service('coinLockingService')->findLockByRef($lock['lock']['lock_ref']);
        $this->assertSame('active', $row['status']);
        $this->assertSame('15.00000000', $row['quantity']);
        $this->assertSame('15.00000000', service('memberCoinOwnershipService')->summaryForUser($member['user_id'])['locked']);

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '1', 'dis-new');
    }

    public function testMaturityCommandIdempotentAndNoRewards(): void
    {
        $member = $this->registerMember('matcmd');
        $this->creditCoins($member, '40');
        $plan = $this->enabledPlan('CMD', 1);
        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '8', 'cmd-1');
        $ref  = $lock['lock']['lock_ref'];

        db_connect()->table('member_coin_locks')->where('lock_ref', $ref)->update([
            'matures_at' => date('Y-m-d H:i:s', time() - 120),
        ]);

        $before = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $r1 = service('coinLockingService')->checkMaturity(50, 'test-owner-1');
        $r2 = service('coinLockingService')->checkMaturity(50, 'test-owner-2');

        $this->assertTrue($r1['ran']);
        $this->assertSame(1, $r1['matured']);
        $this->assertTrue($r2['ran']);
        $this->assertSame(0, $r2['matured']); // already matured

        $after = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame($before['balance'], $after['balance']);
        $this->assertSame($before['available'], $after['available']);
        $this->assertSame($before['locked'], $after['locked']);
        $this->assertSame($before['total_acquired'], $after['total_acquired']);
        $this->assertSame('matured', service('coinLockingService')->findLockByRef($ref)['status']);

        // No LOCK/UNLOCK reward-style credits: only PURCHASE_CREDIT and LOCK types so far
        $types = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $member['user_id'])
            ->select('type')
            ->get()
            ->getResultArray();
        $typeList = array_column($types, 'type');
        $this->assertNotContains('ADMIN_ADJUSTMENT', $typeList);
        $this->assertContains('LOCK', $typeList);
        $this->assertNotContains('UNLOCK', $typeList);
    }

    public function testTotalOwnedEqualsAvailablePlusLocked(): void
    {
        $member = $this->registerMember('inv');
        $this->creditCoins($member, '60');
        $plan = $this->enabledPlan('INV');
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '22', 'inv-1');
        $this->assertInvariant($member['user_id']);
    }

    public function testLedgerRollbackOnTransactionFailure(): void
    {
        $member = $this->registerMember('rb');
        $this->creditCoins($member, '20');
        $before = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);

        $db = db_connect();
        $db->transBegin();
        try {
            service('memberCoinOwnershipService')->applyLock(
                $member['user_id'],
                $member['member_id'],
                '5',
                'LCK-ROLLBACK-TEST',
                $member['user_id'],
                'rb-own-1',
                false
            );
            throw new RuntimeException('forced failure');
        } catch (RuntimeException $e) {
            $db->transRollback();
            $this->assertSame('forced failure', $e->getMessage());
        }

        $after = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame($before['balance'], $after['balance']);
        $this->assertSame($before['available'], $after['available']);
        $this->assertSame($before['locked'], $after['locked']);
        $this->assertNull(
            db_connect()->table('member_coin_ownership_transactions')
                ->where('idempotency_key', 'rb-own-1')
                ->get()
                ->getRowArray()
        );
    }

    public function testAdminPagesAndMemberDashboard(): void
    {
        $member = $this->registerMember('ui');
        $this->creditCoins($member, '15');
        $plan = $this->enabledPlan('UI');
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '3', 'ui-1');

        service('memberAuthService')->attempt($member['member_id'], 'Password123!');
        $dash = $this->get('member/dashboard');
        $dash->assertOK();
        $dash->assertSee('My Total Coins');
        $dash->assertSee('15');
        $dash->assertSee('My Available Coins');
        $dash->assertSee('My Locked Coins');

        $lockPage = $this->get('member/locking');
        $lockPage->assertOK();
        $lockPage->assertSee('Coin Locking');

        if (auth()->loggedIn()) {
            auth()->logout();
        }

        $login = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $login->assertRedirect();

        $admin = $this->get('admin/locking');
        $admin->assertOK();
        $admin->assertSee('Coin Locking');
        $plans = $this->get('admin/locking/plans');
        $plans->assertOK();
    }

    public function testPlansDisabledByDefault(): void
    {
        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => 'OFF' . strtoupper(bin2hex(random_bytes(2))),
            'name'          => 'Disabled Plan',
            'min_quantity'  => '1',
            'duration_days' => 7,
        ], $this->adminUserId);

        $this->assertSame(0, (int) $plan['is_enabled']);
        $member = $this->registerMember('offplan');
        $this->creditCoins($member, '10');

        $this->expectException(InvalidArgumentException::class);
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '1');
    }
}
