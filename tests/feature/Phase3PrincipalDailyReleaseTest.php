<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\PrincipalDailyReleaseService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Phase 3 — Daily principal release schedule (posting remains OFF).
 *
 * @internal
 */
final class Phase3PrincipalDailyReleaseTest extends CIUnitTestCase
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

        $p = config('Platform');
        $p->adminEmail = 'admin@test.local';
        $p->adminPassword = 'AdminPass123!';
        $p->adminName = 'Test Admin';
        $p->rootSponsorId = 'ROOT0001';
        $p->memberIdPrefix = 'CPM';
        $p->memberIdStartNumber = 1;
        $p->requireVerifiedDepositForApproval = false;
        $p->mandatoryPurchaseLockEnabled = true;
        $p->principalDailyReleasePostingEnabled = false;
        $p->principalDailyReleaseRate = '0.001';
        $p->principalDailyReleaseMaxDays = 1000;
        $p->commissionPostingEnabled = false;
        $p->miningPostingEnabled = false;
        $p->miningP2pEnabled = false;
        $p->coinSellingEnabled = false;
        $p->commissionWithdrawalEnabled = false;
        $p->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p3');
        service('coinInventoryService')->addInventory('50000', $this->adminUserId, 'p3', null, 'p3-' . bin2hex(random_bytes(2)));

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        config('Platform')->principalDailyReleasePostingEnabled = false;
        Services::reset(true);
        parent::tearDown();
    }

    /** @return array{user_id: int, member_id: string} */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . '00000000');
        $hex = str_pad(substr(hash('sha256', 'p3' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P3 ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits, 0, 8),
            'email'       => 'p3' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $result['member_id']];
    }

    private function purchaseWithLock(int $userId, string $coins): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $userId,
            $coins,
            null,
            'p3-buy-' . $userId . '-' . bin2hex(random_bytes(2))
        );
        $ref = (string) $req['request']['purchase_ref'];
        service('coinPurchaseService')->approveRequest($ref, $this->adminUserId, true, 'p3');
        $lock = db_connect()->table('member_coin_locks')->where('purchase_ref', $ref)->get()->getRowArray();
        $this->assertNotNull($lock);

        return ['purchase_ref' => $ref, 'lock' => $lock, 'quantity' => MoneyMath::normalize((string) $lock['quantity'], 8)];
    }

    public function testDailyPointOnePercentOfOriginal(): void
    {
        $svc = service('principalDailyReleaseService');
        $qty = $svc->dayReleaseQuantity('1000.00000000', 1, 1000, '0.001');
        $this->assertSame('1.00000000', $qty);
        $qty2 = $svc->dayReleaseQuantity('1000.00000000', 50, 1000, '0.001');
        $this->assertSame('1.00000000', $qty2);
    }

    public function testUsesOriginalNotRemaining(): void
    {
        $svc = service('principalDailyReleaseService');
        // Even on day 500, amount is still original × 0.001, not remaining × 0.001
        $this->assertSame('1.00000000', $svc->dayReleaseQuantity('1000.00000000', 500, 1000, '0.001'));
    }

    public function testThousandDayCapAndRemainder(): void
    {
        $svc = service('principalDailyReleaseService');
        $original = '100.00000000';
        $sum = '0.00000000';
        for ($d = 1; $d <= 1000; $d++) {
            $sum = MoneyMath::add($sum, $svc->dayReleaseQuantity($original, $d, 1000, '0.001'), 8);
        }
        $this->assertSame($original, $sum);
        $this->assertSame('0.00000000', $svc->dayReleaseQuantity($original, 1001, 1000, '0.001'));
    }

    public function testMultiplePurchasesIndependent(): void
    {
        $m = $this->register('multi');
        $p1 = $this->purchaseWithLock($m['user_id'], '100');
        $p2 = $this->purchaseWithLock($m['user_id'], '50');

        $summary = service('principalDailyReleaseService')->summaryForMember($m['user_id']);
        $expectedOriginal = MoneyMath::add($p1['quantity'], $p2['quantity'], 8);
        $this->assertSame($expectedOriginal, $summary['original_principal']);
        $this->assertFalse($summary['posting_enabled']);
        $this->assertSame('schedule_preview_only', $summary['mode']);
    }

    public function testPrecisionEightDecimals(): void
    {
        $svc = service('principalDailyReleaseService');
        $q = $svc->dayReleaseQuantity('1.00000000', 1, 1000, '0.001');
        $this->assertSame('0.00100000', $q);
        $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $q);
    }

    public function testMemberIsolation(): void
    {
        $a = $this->register('isoA');
        $b = $this->register('isoB');
        $this->purchaseWithLock($a['user_id'], '100');
        $sumA = service('principalDailyReleaseService')->summaryForMember($a['user_id']);
        $sumB = service('principalDailyReleaseService')->summaryForMember($b['user_id']);
        $this->assertTrue(MoneyMath::compare($sumA['original_principal'], '0', 8) === 1);
        $this->assertSame('0.00000000', $sumB['original_principal']);
    }

    public function testNoLedgerMutationAndFlagsUnchanged(): void
    {
        $m = $this->register('led');
        $this->purchaseWithLock($m['user_id'], '10');

        $ownBefore = db_connect()->table('member_coin_ownership_transactions')->countAllResults();
        $mineBefore = db_connect()->tableExists('mining_wallet_ledger')
            ? db_connect()->table('mining_wallet_ledger')->countAllResults()
            : 0;
        $commBefore = db_connect()->tableExists('commission_wallet_ledger_entries')
            ? db_connect()->table('commission_wallet_ledger_entries')->countAllResults()
            : 0;
        $wdBefore = db_connect()->tableExists('commission_withdrawal_requests')
            ? db_connect()->table('commission_withdrawal_requests')->countAllResults()
            : 0;

        service('principalDailyReleaseService')->summaryForMember($m['user_id']);
        service('principalDailyReleaseService')->historyForMember($m['user_id'], [
            'date_from' => date('Y-m-d'),
            'date_to'   => date('Y-m-d'),
        ]);

        $this->assertSame($ownBefore, db_connect()->table('member_coin_ownership_transactions')->countAllResults());
        if (db_connect()->tableExists('mining_wallet_ledger')) {
            $this->assertSame($mineBefore, db_connect()->table('mining_wallet_ledger')->countAllResults());
        }
        if (db_connect()->tableExists('commission_wallet_ledger_entries')) {
            $this->assertSame($commBefore, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        }
        if (db_connect()->tableExists('commission_withdrawal_requests')) {
            $this->assertSame($wdBefore, db_connect()->table('commission_withdrawal_requests')->countAllResults());
        }
        $this->assertFalse((bool) config('Platform')->principalDailyReleasePostingEnabled);
        $this->assertFalse((bool) config('Platform')->miningPostingEnabled);
        $this->assertFalse((bool) config('Platform')->commissionWithdrawalEnabled);
    }

    public function testHistoryDateFilterAndStatusPreview(): void
    {
        $m = $this->register('hist');
        $this->purchaseWithLock($m['user_id'], '100');
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
        $hist = service('principalDailyReleaseService')->historyForMember($m['user_id'], [
            'date_from' => $today,
            'date_to'   => $today,
            'per_page'  => 10,
        ]);
        $this->assertSame('schedule_preview', $hist['source']);
        $this->assertGreaterThanOrEqual(1, $hist['total']);
        $this->assertSame(PrincipalDailyReleaseService::STATUS_SCHEDULED_NOT_POSTED, $hist['rows'][0]['status']);
    }

    public function testPageRequiresAuth(): void
    {
        $res = $this->get('member/principal-release');
        $code = $res->response()->getStatusCode();
        $this->assertTrue($code === 302 || $code === 303 || $code >= 300);
    }

    public function testBusinessVolumeUnaffected(): void
    {
        $m = $this->register('bv');
        $this->purchaseWithLock($m['user_id'], '10');
        $before = service('referralBusinessVolumeService')->summary($m['user_id']);
        service('principalDailyReleaseService')->summaryForMember($m['user_id']);
        $after = service('referralBusinessVolumeService')->summary($m['user_id']);
        $this->assertSame($before['total_business_volume_usdt'], $after['total_business_volume_usdt']);
    }
}
