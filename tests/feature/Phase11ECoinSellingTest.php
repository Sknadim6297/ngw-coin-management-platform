<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\CoinLockingService;
use App\Services\CoinSellingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11E focused coin selling tests (execution remains OFF).
 *
 * @internal
 */
final class Phase11ECoinSellingTest extends CIUnitTestCase
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
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->coinSellingEnabled                = false;
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->coinPlanLockDays                  = 1000;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('2.00', $this->adminUserId, 'p11e price');
        service('coinInventoryService')->addInventory(
            '20000',
            $this->adminUserId,
            'p11e stock',
            null,
            'p11e-stock-' . bin2hex(random_bytes(3))
        );

        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $ref = new \ReflectionClass(service('impersonationService'));
        $prop = $ref->getProperty('activeContext');
        $prop->setAccessible(true);
        $prop->setValue(service('impersonationService'), null);
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        config('Platform')->coinSellingEnabled = false;
        parent::tearDown();
    }

    /**
     * @return array{user_id: int, member_id: string, password: string}
     */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p11e' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p11e-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P11E ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '15' . substr($digits . '00000000', 0, 8),
            'email'       => 'p11e' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $result['user']->activate();
        model(\CodeIgniter\Shield\Models\UserModel::class)->save($result['user']);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
            'password'  => 'Password123!',
        ];
    }

    /**
     * @return array{user_id: int, member_id: string, password: string, purchase_ref: string, lock_ref: string}
     */
    private function buyLocked(string $suffix, string $qty = '10'): array
    {
        $m = $this->register($suffix);
        $req = service('coinPurchaseService')->createRequest(
            $m['user_id'],
            $qty,
            null,
            'p11e-' . $m['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $out = service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $this->adminUserId,
            true,
            'p11e'
        );
        $lock = $out['lock'] ?? db_connect()->table('member_coin_locks')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->get()->getRowArray();

        return $m + [
            'purchase_ref' => (string) $req['request']['purchase_ref'],
            'lock_ref'     => (string) ($lock['lock_ref'] ?? ''),
        ];
    }

    private function forceMature(string $lockRef, int $daysAgo = 1000): void
    {
        $lockedAt  = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('-' . ($daysAgo + 1) . ' days');
        $maturesAt = $lockedAt->modify('+1000 days');
        db_connect()->table('member_coin_locks')->where('lock_ref', $lockRef)->update([
            'locked_at'  => $lockedAt->format('Y-m-d H:i:s'),
            'matures_at' => $maturesAt->format('Y-m-d H:i:s'),
            'status'     => CoinLockingService::STATUS_MATURED,
            'matured_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function testThousandDayLockEligibility(): void
    {
        $m = $this->buyLocked('lock1000', '25');
        $lock = db_connect()->table('member_coin_locks')->where('lock_ref', $m['lock_ref'])->get()->getRowArray();
        $this->assertNotNull($lock);
        $this->assertSame(1000, (int) $lock['duration_days']);
        $this->assertFalse(service('coinSellingService')->isLotMaturedByBusinessDate($lock));
        $summary = service('coinSellingService')->memberLotSummary($m['user_id']);
        $this->assertSame(0, MoneyMath::compare($summary['totals']['eligible_selling_qty'], '0.00000000', 8));
    }

    public function testExactMaturityBoundary(): void
    {
        $m = $this->buyLocked('boundary', '8');
        $this->forceMature($m['lock_ref'], 1000);
        $lock = db_connect()->table('member_coin_locks')->where('lock_ref', $m['lock_ref'])->get()->getRowArray();
        $this->assertTrue(service('coinSellingService')->isLotMaturedByBusinessDate($lock));
        $summary = service('coinSellingService')->memberLotSummary($m['user_id']);
        $this->assertSame(0, MoneyMath::compare($summary['totals']['eligible_selling_qty'], '8.00000000', 8));
    }

    public function testEarlySellingRejected(): void
    {
        $m = $this->buyLocked('early', '5');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('1000-day');
        service('coinSellingService')->preview($m['user_id'], $m['purchase_ref'], '1.00000000');
    }

    public function testCorrectMemberOwnership(): void
    {
        $a = $this->buyLocked('owna', '6');
        $b = $this->buyLocked('ownb', '6');
        $this->forceMature($a['lock_ref'], 1000);
        $this->expectException(InvalidArgumentException::class);
        service('coinSellingService')->preview($b['user_id'], $a['purchase_ref'], '1.00000000');
    }

    public function testOverSellingPrevention(): void
    {
        $m = $this->buyLocked('over', '3');
        $this->forceMature($m['lock_ref'], 1000);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds eligible');
        service('coinSellingService')->preview($m['user_id'], $m['purchase_ref'], '4.00000000');
    }

    public function testDuplicateRequestProtection(): void
    {
        $m = $this->buyLocked('dup', '10');
        $this->forceMature($m['lock_ref'], 1000);
        config('Platform')->coinSellingEnabled = true;
        $key = 'p11e-dup-' . $m['user_id'];
        service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '2.00000000', $key);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency');
        service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '2.00000000', $key);
    }

    public function testConcurrentReservationSafety(): void
    {
        $m = $this->buyLocked('concur', '5');
        $this->forceMature($m['lock_ref'], 1000);
        config('Platform')->coinSellingEnabled = true;
        service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '4.00000000', 'p11e-c1-' . $m['user_id']);
        $this->expectException(InvalidArgumentException::class);
        service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '4.00000000', 'p11e-c2-' . $m['user_id']);
    }

    public function testLedgerIntegrity(): void
    {
        $m = $this->buyLocked('ledger', '7');
        $this->forceMature($m['lock_ref'], 1000);
        config('Platform')->coinSellingEnabled = true;
        $row = service('coinSellingService')->performSubmitWithReserve(
            $m['user_id'],
            $m['purchase_ref'],
            '3.00000000',
            'p11e-led-' . $m['user_id']
        );
        $summary = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
        $this->assertSame(0, MoneyMath::compare($summary['reserved'], '3.00000000', 8));
        $this->assertSame(0, MoneyMath::compare($summary['balance'], '7.00000000', 8));
        $reserve = db_connect()->table('member_coin_ownership_transactions')
            ->where('sell_ref', $row['sell_ref'])
            ->where('type', 'SALE_RESERVE')
            ->countAllResults();
        $this->assertSame(1, $reserve);
    }

    public function testLotSummaryTracksOriginalReservedRejectedCancelledAndSoldQuantities(): void
    {
        $m = $this->buyLocked('lot-accounting', '10');
        $this->forceMature($m['lock_ref'], 1000);
        config('Platform')->coinSellingEnabled = true;

        $rejected = service('coinSellingService')->performSubmitWithReserve(
            $m['user_id'],
            $m['purchase_ref'],
            '3.00000000',
            'p11e-lot-reject-' . $m['user_id']
        );
        $pending = service('coinSellingService')->memberLotSummary($m['user_id'])['lots'][0];
        $this->assertSame('10.00000000', $pending['coin_quantity']);
        $this->assertSame('3.00000000', $pending['reserved_quantity']);
        $this->assertSame('7.00000000', $pending['remaining_on_lot']);

        service('coinSellingService')->performRejectWithRelease($rejected['sell_ref'], $this->adminUserId, 'test rejection');
        $afterReject = service('coinSellingService')->memberLotSummary($m['user_id'])['lots'][0];
        $this->assertSame('10.00000000', $afterReject['coin_quantity']);
        $this->assertSame('0.00000000', $afterReject['reserved_quantity']);
        $this->assertSame('10.00000000', $afterReject['remaining_on_lot']);

        $cancelled = service('coinSellingService')->performSubmitWithReserve(
            $m['user_id'],
            $m['purchase_ref'],
            '1.00000000',
            'p11e-lot-cancel-' . $m['user_id']
        );
        service('coinSellingService')->performCancelWithRelease($cancelled['sell_ref'], $m['user_id']);
        $afterCancel = service('coinSellingService')->memberLotSummary($m['user_id'])['lots'][0];
        $this->assertSame('10.00000000', $afterCancel['coin_quantity']);
        $this->assertSame('10.00000000', $afterCancel['remaining_on_lot']);

        $sold = service('coinSellingService')->performSubmitWithReserve(
            $m['user_id'],
            $m['purchase_ref'],
            '2.00000000',
            'p11e-lot-sold-' . $m['user_id']
        );
        service('coinSellingService')->performApprove($sold['sell_ref'], $this->adminUserId);
        service('coinSellingService')->performRecordSettlement($sold['sell_ref'], $this->adminUserId, [
            'evidence_ref'     => 'EV-LOT-' . $m['user_id'],
            'settlement_asset' => 'USDT',
            'settlement_amount'=> '4.00',
        ]);
        service('coinSellingService')->performCompleteWithVerifiedSettlement($sold['sell_ref'], $this->adminUserId, true);

        $afterSale = service('coinSellingService')->memberLotSummary($m['user_id'])['lots'][0];
        $this->assertSame('10.00000000', $afterSale['coin_quantity']);
        $this->assertSame('2.00000000', $afterSale['sold_quantity']);
        $this->assertSame('8.00000000', $afterSale['remaining_on_lot']);
    }

    public function testSettlementVerificationRequired(): void
    {
        $m = $this->buyLocked('settle', '4');
        $this->forceMature($m['lock_ref'], 1000);
        config('Platform')->coinSellingEnabled = true;
        $row = service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '1.00000000', 'p11e-set-' . $m['user_id']);
        service('coinSellingService')->performApprove($row['sell_ref'], $this->adminUserId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('verified settlement');
        service('coinSellingService')->performCompleteWithVerifiedSettlement($row['sell_ref'], $this->adminUserId, false);
    }

    public function testInvalidStatusTransitionBlocked(): void
    {
        $this->expectException(InvalidArgumentException::class);
        service('coinSellingService')->assertTransition(
            CoinSellingService::STATUS_PENDING_REVIEW,
            CoinSellingService::STATUS_COMPLETED
        );
    }

    public function testSellingDisabledServerSide(): void
    {
        $m = $this->buyLocked('off', '5');
        $this->forceMature($m['lock_ref'], 1000);
        $user = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($m['user_id']);
        auth()->login($user);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Not Activated');
        service('coinSellingService')->submit($m['user_id'], $m['purchase_ref'], '1.00000000', $m['password']);
    }

    public function testAdminImpersonationBlocked(): void
    {
        $m = $this->buyLocked('imp', '5');
        $this->forceMature($m['lock_ref'], 1000);
        service('impersonationService')->setActiveContext([
            'admin_user_id'    => $this->adminUserId,
            'target_user_id'   => $m['user_id'],
            'target_member_id' => $m['member_id'],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('support-view');
        service('coinSellingService')->preview($m['user_id'], $m['purchase_ref'], '1.00000000');
    }

    public function testHistoricalMiningUnchanged(): void
    {
        $m = $this->buyLocked('mine', '12');
        $this->forceMature($m['lock_ref'], 1000);
        $ownedBefore = service('memberCoinOwnershipService')->summaryForUser($m['user_id'])['balance'];
        $this->assertSame(0, MoneyMath::compare($ownedBefore, '12.00000000', 8));

        // Fabricate a historical mining ledger credit for a past business date — sale must not rewrite it.
        $pastDate = '2020-01-15';
        service('miningWalletService')->ensureWallet($m['user_id'], $m['member_id']);
        db_connect()->table('mining_wallet_ledger')->insert([
            'entry_ref'       => 'MWL-HIST-' . strtoupper(bin2hex(random_bytes(4))),
            'member_user_id'  => $m['user_id'],
            'member_id'       => $m['member_id'],
            'asset_code'      => 'COIN',
            'entry_type'      => 'daily_mining_credit',
            'direction'       => 'credit',
            'amount'          => '0.50000000',
            'balance_after'   => '0.50000000',
            'business_date'   => $pastDate,
            'idempotency_key' => 'p11e-hist-mine-' . $m['user_id'],
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        $histBefore = (int) db_connect()->table('mining_wallet_ledger')
            ->where('member_user_id', $m['user_id'])
            ->where('business_date', $pastDate)
            ->where('amount', '0.50000000')
            ->countAllResults();

        config('Platform')->coinSellingEnabled = true;
        $row = service('coinSellingService')->performSubmitWithReserve($m['user_id'], $m['purchase_ref'], '2.00000000', 'p11e-mine-' . $m['user_id']);
        // Reservation does not reduce total owned.
        $afterReserve = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
        $this->assertSame(0, MoneyMath::compare($afterReserve['balance'], '12.00000000', 8));

        service('coinSellingService')->performApprove($row['sell_ref'], $this->adminUserId);
        service('coinSellingService')->performRecordSettlement($row['sell_ref'], $this->adminUserId, [
            'evidence_ref'     => 'EV-TEST-' . $m['user_id'],
            'settlement_asset' => 'USDT',
            'settlement_amount'=> '4.00',
        ]);
        service('coinSellingService')->performCompleteWithVerifiedSettlement($row['sell_ref'], $this->adminUserId, true);

        $after = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
        $this->assertSame(0, MoneyMath::compare($after['balance'], '10.00000000', 8));
        $histAfter = (int) db_connect()->table('mining_wallet_ledger')
            ->where('member_user_id', $m['user_id'])
            ->where('business_date', $pastDate)
            ->where('amount', '0.50000000')
            ->countAllResults();
        $this->assertSame($histBefore, $histAfter);
        $this->assertFalse(config('Platform')->miningPostingEnabled);
    }

    public function testNoRealCoinDebitDuringPreview(): void
    {
        $m = $this->buyLocked('prev', '9');
        $this->forceMature($m['lock_ref'], 1000);
        $before = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
        service('coinSellingService')->preview($m['user_id'], $m['purchase_ref'], '2.00000000');
        $after = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
        $this->assertSame($before['balance'], $after['balance']);
        $this->assertSame($before['available'], $after['available']);
        $this->assertSame($before['locked'], $after['locked']);
        $this->assertSame($before['reserved'], $after['reserved']);
    }

    public function testExistingFinancialKillSwitchesRemainOff(): void
    {
        $cfg = config('Platform');
        $this->assertFalse($cfg->miningPostingEnabled);
        $this->assertFalse($cfg->miningP2pEnabled);
        $this->assertFalse($cfg->commissionPostingEnabled);
        $this->assertFalse($cfg->commissionWithdrawalEnabled);
        $this->assertFalse($cfg->commissionBlockchainPayoutEnabled);
        $this->assertFalse($cfg->coinSellingEnabled);
        $status = service('coinSellingService')->status();
        $this->assertFalse($status['execution_authorized']);
    }

    public function testMemberPageShowsDisabledState(): void
    {
        $m = $this->buyLocked('ui', '5');
        $this->post('login', [
            'member_id' => $m['member_id'],
            'password'  => $m['password'],
        ])->assertRedirect();
        $page = $this->withSession()->get('member/coin-selling');
        $page->assertOK();
        $page->assertSee('Coin Selling Not Activated');
    }
}
