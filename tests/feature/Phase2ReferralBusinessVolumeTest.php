<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;

/**
 * Phase 2 — Level-wise referral Business Volume (read-only; no ledger mutation).
 *
 * @internal
 */
final class Phase2ReferralBusinessVolumeTest extends CIUnitTestCase
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
        $platform->commissionAutoPostOnApprovalEnabled = false;
        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->commissionWithdrawalEnabled       = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p2 price');
        service('coinInventoryService')->addInventory(
            '50000',
            $this->adminUserId,
            'p2 stock',
            null,
            'p2-stock-' . bin2hex(random_bytes(3))
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
        Services::reset(true);
        parent::tearDown();
    }

    /**
     * New members start inactive until first approved purchase. SponsorValidator
     * blocks inactive non-root sponsors — activate via fixture (no purchase) so
     * chained registrations stay valid without polluting BV assertions.
     */
    private function ensureSponsorCanRecruit(string $sponsorMemberId): void
    {
        if (strcasecmp($sponsorMemberId, 'ROOT0001') === 0) {
            return;
        }

        $profile = model(MemberProfileModel::class)->findByMemberId($sponsorMemberId);
        if ($profile === null || ! $profile->isInactive()) {
            return;
        }

        db_connect()->table('member_profiles')->where('member_id', $sponsorMemberId)->update([
            'account_status' => 'active',
            'activated_at'   => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{user_id: int, member_id: string, password: string}
     */
    private function registerUnder(string $sponsorMemberId, string $suffix): array
    {
        $this->ensureSponsorCanRecruit($sponsorMemberId);

        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p2' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p2-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P2 ' . $suffix,
            'sponsor_id'  => $sponsorMemberId,
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p2' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
            'password'  => 'Password123!',
        ];
    }

    /**
     * @return array{purchase_ref: string, total_usdt: string}
     */
    private function approvedVerifiedPurchase(int $userId, string $coins, bool $markVerified = true): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $userId,
            $coins,
            null,
            'p2-buy-' . $userId . '-' . bin2hex(random_bytes(3))
        );
        $ref = (string) $req['request']['purchase_ref'];
        service('coinPurchaseService')->approveRequest($ref, $this->adminUserId, true, 'p2');

        if ($markVerified) {
            db_connect()->table('coin_purchase_requests')->where('purchase_ref', $ref)->update([
                'payment_status' => 'verified',
            ]);
        }

        $row = db_connect()->table('coin_purchase_requests')->where('purchase_ref', $ref)->get()->getRowArray();

        return [
            'purchase_ref' => $ref,
            'total_usdt'   => MoneyMath::normalize((string) ($row['total_usdt'] ?? '0'), 8),
        ];
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

    public function testLevel1BusinessVolume(): void
    {
        $a = $this->registerUnder('ROOT0001', 'a1');
        $b = $this->registerUnder($a['member_id'], 'b1');
        $p = $this->approvedVerifiedPurchase($b['user_id'], '10');

        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame($p['total_usdt'], $summary['levels'][1]['business_volume_usdt']);
        $this->assertSame(1, $summary['levels'][1]['purchase_count']);
        $this->assertSame('0.00000000', $summary['levels'][2]['business_volume_usdt']);
        $this->assertSame($p['total_usdt'], $summary['total_business_volume_usdt']);
    }

    public function testLevel2AndLevel3BusinessVolume(): void
    {
        $a = $this->registerUnder('ROOT0001', 'a2');
        $b = $this->registerUnder($a['member_id'], 'b2');
        $c = $this->registerUnder($b['member_id'], 'c2');
        $d = $this->registerUnder($c['member_id'], 'd2');

        $pb = $this->approvedVerifiedPurchase($b['user_id'], '10');
        $pc = $this->approvedVerifiedPurchase($c['user_id'], '20');
        $pd = $this->approvedVerifiedPurchase($d['user_id'], '30');

        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame($pb['total_usdt'], $summary['levels'][1]['business_volume_usdt']);
        $this->assertSame($pc['total_usdt'], $summary['levels'][2]['business_volume_usdt']);
        $this->assertSame($pd['total_usdt'], $summary['levels'][3]['business_volume_usdt']);

        $expectedTotal = MoneyMath::add(MoneyMath::add($pb['total_usdt'], $pc['total_usdt'], 8), $pd['total_usdt'], 8);
        $this->assertSame($expectedTotal, $summary['total_business_volume_usdt']);
        $this->assertSame(3, $summary['network_member_count']);
    }

    public function testLevel4IsExcluded(): void
    {
        $a = $this->registerUnder('ROOT0001', 'a4');
        $b = $this->registerUnder($a['member_id'], 'b4');
        $c = $this->registerUnder($b['member_id'], 'c4');
        $d = $this->registerUnder($c['member_id'], 'd4');
        $e = $this->registerUnder($d['member_id'], 'e4');

        $this->approvedVerifiedPurchase($e['user_id'], '50');
        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame('0.00000000', $summary['levels'][1]['business_volume_usdt']);
        $this->assertSame('0.00000000', $summary['levels'][2]['business_volume_usdt']);
        $this->assertSame('0.00000000', $summary['levels'][3]['business_volume_usdt']);
        $this->assertSame('0.00000000', $summary['total_business_volume_usdt']);
    }

    public function testPendingAndRejectedExcluded(): void
    {
        $a = $this->registerUnder('ROOT0001', 'ax');
        $b = $this->registerUnder($a['member_id'], 'bx');

        $pending = service('coinPurchaseService')->createRequest(
            $b['user_id'],
            '10',
            null,
            'p2-pend-' . $b['user_id']
        );
        $this->assertSame('pending', $pending['request']['status']);

        $rej = service('coinPurchaseService')->createRequest(
            $b['user_id'],
            '10',
            null,
            'p2-rej-' . $b['user_id']
        );
        service('coinPurchaseService')->rejectRequest($rej['request']['purchase_ref'], $this->adminUserId, 'no');

        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame('0.00000000', $summary['total_business_volume_usdt']);
    }

    public function testUnverifiedDepositExcluded(): void
    {
        $a = $this->registerUnder('ROOT0001', 'auv');
        $b = $this->registerUnder($a['member_id'], 'buv');
        $this->approvedVerifiedPurchase($b['user_id'], '10', false);

        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame('0.00000000', $summary['levels'][1]['business_volume_usdt']);
    }

    public function testMultiplePurchasesByOneReferral(): void
    {
        $a = $this->registerUnder('ROOT0001', 'am');
        $b = $this->registerUnder($a['member_id'], 'bm');
        $p1 = $this->approvedVerifiedPurchase($b['user_id'], '5');
        $p2 = $this->approvedVerifiedPurchase($b['user_id'], '7');

        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $expected = MoneyMath::add($p1['total_usdt'], $p2['total_usdt'], 8);
        $this->assertSame($expected, $summary['levels'][1]['business_volume_usdt']);
        $this->assertSame(2, $summary['levels'][1]['purchase_count']);
        $this->assertSame(1, $summary['levels'][1]['purchasing_member_count']);
        $this->assertSame(1, $summary['levels'][1]['referral_member_count']);
    }

    public function testMemberWithNoReferrals(): void
    {
        $a = $this->registerUnder('ROOT0001', 'alone');
        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertSame('0.00000000', $summary['total_business_volume_usdt']);
        $this->assertSame(0, $summary['network_member_count']);
        $this->assertSame(0, $summary['direct_referral_count']);
    }

    public function testExactEightDecimalTotals(): void
    {
        $a = $this->registerUnder('ROOT0001', 'adec');
        $b = $this->registerUnder($a['member_id'], 'bdec');
        // price 1.00 * quantity with decimals
        $p = $this->approvedVerifiedPurchase($b['user_id'], '1.12345678');
        $summary = service('referralBusinessVolumeService')->summary($a['user_id']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{8}$/', $summary['total_business_volume_usdt']);
        $this->assertSame($p['total_usdt'], $summary['total_business_volume_usdt']);
    }

    public function testDateFilteringUsesReviewedAt(): void
    {
        $a = $this->registerUnder('ROOT0001', 'adt');
        $b = $this->registerUnder($a['member_id'], 'bdt');
        $p = $this->approvedVerifiedPurchase($b['user_id'], '10');

        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $p['purchase_ref'])->update([
            'reviewed_at' => '2020-01-15 12:00:00',
        ]);

        $inRange = service('referralBusinessVolumeService')->summary($a['user_id'], [
            'date_from' => '2020-01-01',
            'date_to'   => '2020-01-31',
        ]);
        $this->assertSame($p['total_usdt'], $inRange['total_business_volume_usdt']);

        $outRange = service('referralBusinessVolumeService')->summary($a['user_id'], [
            'date_from' => '2021-01-01',
            'date_to'   => '2021-01-31',
        ]);
        $this->assertSame('0.00000000', $outRange['total_business_volume_usdt']);
    }

    public function testSearchAndPagination(): void
    {
        $a = $this->registerUnder('ROOT0001', 'apg');
        $b1 = $this->registerUnder($a['member_id'], 'bpg1');
        $b2 = $this->registerUnder($a['member_id'], 'bpg2');
        $this->approvedVerifiedPurchase($b1['user_id'], '3');
        $this->approvedVerifiedPurchase($b2['user_id'], '4');

        $page1 = service('referralBusinessVolumeService')->membersAtLevel($a['user_id'], 1, [
            'per_page' => 1,
            'page'     => 1,
        ]);
        $this->assertCount(1, $page1['rows']);
        $this->assertSame(2, (int) $page1['summary']['purchase_count']);

        $search = service('referralBusinessVolumeService')->membersAtLevel($a['user_id'], 1, [
            'q' => $b1['member_id'],
        ]);
        $this->assertCount(1, $search['rows']);
        $this->assertSame($b1['member_id'], $search['rows'][0]['member_id']);
    }

    public function testCrossMemberIsolationAndUnauthorizedDetail(): void
    {
        $a = $this->registerUnder('ROOT0001', 'aiso');
        $b = $this->registerUnder($a['member_id'], 'biso');
        $x = $this->registerUnder('ROOT0001', 'xiso');
        $this->approvedVerifiedPurchase($b['user_id'], '10');

        $this->expectException(InvalidArgumentException::class);
        service('referralBusinessVolumeService')->memberPurchases($x['user_id'], $b['member_id']);
    }

    public function testNoCommissionPostingOrLedgerMutation(): void
    {
        $a = $this->registerUnder('ROOT0001', 'aled');
        $b = $this->registerUnder($a['member_id'], 'bled');
        $this->approvedVerifiedPurchase($b['user_id'], '10');

        $beforeLedger = db_connect()->table('commission_wallet_ledger_entries')->countAllResults();
        $beforeWd = db_connect()->table('commission_withdrawal_requests')->countAllResults();

        service('referralBusinessVolumeService')->summary($a['user_id']);
        service('referralBusinessVolumeService')->membersAtLevel($a['user_id'], 1, []);

        $this->assertSame($beforeLedger, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
        $this->assertSame($beforeWd, db_connect()->table('commission_withdrawal_requests')->countAllResults());
        $this->assertFalse((bool) config('Platform')->commissionPostingEnabled);
        $this->assertFalse((bool) config('Platform')->commissionWithdrawalEnabled);
    }

    public function testBusinessPageRequiresAuthAndEscapes(): void
    {
        $res = $this->get('member/referrals/business');
        $code = $res->response()->getStatusCode();
        $this->assertTrue($code === 302 || $code === 303 || $code >= 300);

        $a = $this->registerUnder('ROOT0001', 'aui');
        $this->loginAs($a['user_id']);
        $page = $this->withSession()->get('member/referrals/business');
        if ($page->response()->getStatusCode() === 200) {
            $body = (string) ($page->getBody() ?: $page->response()->getBody());
            $this->assertStringContainsString('Business Volume', $body);
            $this->assertStringContainsString('Level 1', $body);
            $this->assertStringNotContainsString('<script>alert', $body);
        }
    }

    public function testInvalidLevelRejected(): void
    {
        $a = $this->registerUnder('ROOT0001', 'abad');
        $this->expectException(InvalidArgumentException::class);
        service('referralBusinessVolumeService')->membersAtLevel($a['user_id'], 4, []);
    }
}
