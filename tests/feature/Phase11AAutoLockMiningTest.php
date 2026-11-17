<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use RuntimeException;

/**
 * Phase 11A focused tests: mandatory auto-lock, total-owned mining, wallet isolation, kill-switches.
 *
 * @internal
 */
final class Phase11AAutoLockMiningTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;

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
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->miningBusinessTimezone            = 'Asia/Kolkata';
        $platform->miningMonthDayDivisorMode         = 'calendar_days';
        $platform->miningApprovalDayPolicy           = 'full_day';
        $platform->miningEarningAsset                = 'COIN';
        $platform->coinPlanLockDays                  = 1000;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p11a price');
        service('coinInventoryService')->addInventory(
            '10000',
            $this->adminUserId,
            'p11a stock',
            null,
            'p11a-stock-' . bin2hex(random_bytes(3))
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
        parent::tearDown();
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p11aapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'p11a.approver@test.local',
            'password' => 'ApproverPass123!',
        ]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p11a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p11a-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P11A ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'p11a' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
        ];
    }

    /**
     * @return array{purchase_ref: string, lock: ?array<string, mixed>}
     */
    private function buyAndApprove(array $member, string $qty): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p11a-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $out = service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $this->adminUserId,
            true,
            'p11a'
        );

        return [
            'purchase_ref' => (string) $req['request']['purchase_ref'],
            'lock'         => $out['lock'] ?? null,
        ];
    }

    private function approveMonthlyRate(string $percent = '3.00000000', ?string $month = null): array
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => $month ?? date('Y-m'),
            'rate_percent'    => $percent,
            'rate_basis'      => 'monthly',
            'notes'           => 'p11a',
        ], $this->adminUserId);

        return service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);
    }

    public function testPurchaseApprovalCreatesMandatoryLockOfExactly1000Days(): void
    {
        $member = $this->register('lock1');
        $buy    = $this->buyAndApprove($member, '25');
        $this->assertNotNull($buy['lock']);
        $this->assertSame(1000, (int) $buy['lock']['duration_days']);
        $this->assertSame('mandatory_purchase', $buy['lock']['lock_kind']);
        $this->assertSame($buy['purchase_ref'], $buy['lock']['purchase_ref']);

        $lockedAt  = new \DateTimeImmutable((string) $buy['lock']['locked_at']);
        $maturesAt = new \DateTimeImmutable((string) $buy['lock']['matures_at']);
        $this->assertSame(1000, (int) $lockedAt->diff($maturesAt)->days);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $summary['balance'], '25.00000000', 8));
        $this->assertSame(0, MoneyMath::compare((string) $summary['locked'], '25.00000000', 8));
        $this->assertSame(0, MoneyMath::compare((string) $summary['available'], '0.00000000', 8));
    }

    public function testDuplicateMandatoryLockIsIdempotent(): void
    {
        $member = $this->register('dupl');
        $buy    = $this->buyAndApprove($member, '12');
        $again  = service('coinLockingService')->lockMandatoryForPurchase(
            $member['user_id'],
            $member['member_id'],
            '12',
            $buy['purchase_ref'],
            $this->adminUserId
        );
        $this->assertTrue(! empty($again['duplicate']));

        $count = db_connect()->table('member_coin_locks')
            ->where('purchase_ref', $buy['purchase_ref'])
            ->countAllResults();
        $this->assertSame(1, $count);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $summary['locked'], '12.00000000', 8));
    }

    public function testFailedMandatoryLockRollsBackApproval(): void
    {
        $platform = config('Platform');
        $platform->coinPlanLockDays = 0;

        $member = $this->register('failock');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '8',
            null,
            'p11a-fail-' . bin2hex(random_bytes(2))
        );

        $threw = false;
        try {
            service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p11a');
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw);

        $platform->coinPlanLockDays = 1000;

        $row = db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->get()
            ->getRowArray();
        $this->assertSame('pending', $row['status'] ?? null);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $summary['balance'], '0.00000000', 8));
    }

    public function testMiningUsesTotalOwnedAndLockedRemainsEligible(): void
    {
        $member = $this->register('ownbase');
        $this->buyAndApprove($member, '40');
        $rate = $this->approveMonthlyRate('3.00000000');
        $date = service('miningEligibilityService')->businessDate();
        $eval = service('miningEligibilityService')->evaluate($member['user_id'], $date, $rate);

        $this->assertTrue($eval['eligible']);
        $this->assertSame('40.00000000', $eval['eligible_coins']);
        $this->assertSame('40.00000000', $eval['locked']);
        $this->assertContains('eligible_total_owned', $eval['reason_codes']);
    }

    public function testMiningStartsOnApprovalBusinessDateAndRejectsRejectedPurchases(): void
    {
        $member = $this->register('startd');
        $buy    = $this->buyAndApprove($member, '15');
        $rate   = $this->approveMonthlyRate();
        $start  = service('miningEligibilityService')->miningStartBusinessDate($member['user_id']);
        $this->assertNotNull($start);

        $before = (new \DateTimeImmutable($start))->modify('-1 day')->format('Y-m-d');
        $evalBefore = service('miningEligibilityService')->evaluate($member['user_id'], $before, $rate);
        $this->assertFalse($evalBefore['eligible']);
        $this->assertContains('before_mining_start', $evalBefore['reason_codes']);

        $evalOn = service('miningEligibilityService')->evaluate($member['user_id'], $start, $rate);
        $this->assertTrue($evalOn['eligible']);

        $rej = $this->register('rej');
        $req = service('coinPurchaseService')->createRequest(
            $rej['user_id'],
            '10',
            null,
            'p11a-rej-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->rejectRequest($req['request']['purchase_ref'], $this->adminUserId, 'nope');
        $evalRej = service('miningEligibilityService')->evaluate($rej['user_id'], $start, $rate);
        $this->assertFalse($evalRej['eligible']);
        $this->assertContains('no_approved_purchase', $evalRej['reason_codes']);
        unset($buy);
    }

    public function testMonthlyRateDivisorAndEffectiveDate(): void
    {
        $nov = $this->approveMonthlyRate('3.00000000', '2026-11');
        $this->assertSame('approved', $nov['status']);

        $days = (int) (new \DateTimeImmutable('2026-11-01'))->format('t');
        $this->assertSame($days, service('miningRateService')->monthDayDivisor('2026-11-10', '2026-11'));

        config('Platform')->miningMonthDayDivisorMode = 'fixed_30';
        $this->assertSame(30, service('miningRateService')->monthDayDivisor('2026-11-10', '2026-11'));
        config('Platform')->miningMonthDayDivisorMode = 'calendar_days';

        $this->assertNull(service('miningRateService')->approvedForDate('2026-10-15'));
        $this->assertNotNull(service('miningRateService')->approvedForDate('2026-11-15'));
    }

    public function testDryRunProducesNoWalletCreditsAndIsRepeatable(): void
    {
        $member = $this->register('dry');
        $this->buyAndApprove($member, '20');
        $this->approveMonthlyRate('1.00000000');
        $date = service('miningEligibilityService')->businessDate();

        $ledgerBefore = db_connect()->table('mining_wallet_ledger')->countAllResults();
        $walletBefore = db_connect()->table('mining_wallets')->countAllResults();

        $a = service('miningDailyEngineService')->dryRun($date, 50, true);
        $b = service('miningDailyEngineService')->dryRun($date, 50, true);
        $this->assertSame('DAILY MINING IS NOT POSTING REAL INCOME.', $a['posting_status']);
        $this->assertNotEmpty($a['run_ref']);
        $this->assertNotEmpty($b['run_ref']);
        $this->assertNotSame($a['run_ref'], $b['run_ref']);

        $this->assertSame($ledgerBefore, db_connect()->table('mining_wallet_ledger')->countAllResults());
        $this->assertSame($walletBefore, db_connect()->table('mining_wallets')->countAllResults());

        $bal = service('miningWalletService')->balanceFor($member['user_id']);
        $this->assertSame('0.00000000', $bal['available_balance']);
    }

    public function testMiningPostingP2pAndWithdrawBlocked(): void
    {
        $this->assertFalse(service('miningWalletService')->postingEnabled());
        $this->assertFalse(service('miningWalletService')->p2pEnabled());

        $blocked = 0;
        try {
            service('miningWalletService')->creditDailyMining(1, 'ROOT0001', ['amount' => '1']);
        } catch (RuntimeException) {
            $blocked++;
        }
        try {
            service('miningWalletService')->transferP2p(1, 2, '1');
        } catch (RuntimeException) {
            $blocked++;
        }
        try {
            service('miningWalletService')->withdrawExternal(1, '1');
        } catch (RuntimeException) {
            $blocked++;
        }
        try {
            service('miningDailyEngineService')->postDay(date('Y-m-d'));
        } catch (RuntimeException) {
            $blocked++;
        }
        $this->assertSame(4, $blocked);
    }

    public function testEarlyUnlockOfMandatoryLockBlocked(): void
    {
        $member = $this->register('early');
        $buy    = $this->buyAndApprove($member, '9');
        $this->expectException(\InvalidArgumentException::class);
        service('coinLockingService')->unlockCoins($member['user_id'], (string) $buy['lock']['lock_ref']);
    }

    public function testMemberCannotAccessAnotherMiningWalletViaUi(): void
    {
        $a = $this->register('wa');
        $b = $this->register('wb');
        $this->buyAndApprove($a, '11');
        $this->approveMonthlyRate();

        $this->post('login', [
            'member_id' => $b['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $page = $this->withSession()->get('member/mining');
        $page->assertOK();
        $page->assertDontSee($a['member_id']);
        $page->assertSee('Actual Credits Not Activated');
    }

    public function testReconcileDetectsUnexpectedCreditsWhilePostingOff(): void
    {
        $member = $this->register('recon');
        $this->buyAndApprove($member, '5');
        $this->approveMonthlyRate();
        $date = service('miningEligibilityService')->businessDate();

        // Insert a fabricated ledger credit to prove reconcile flags it (read-only detection).
        service('miningWalletService')->ensureWallet($member['user_id'], $member['member_id']);
        db_connect()->table('mining_wallet_ledger')->insert([
            'entry_ref'       => 'MWL-TEST-' . strtoupper(bin2hex(random_bytes(4))),
            'member_user_id'  => $member['user_id'],
            'member_id'       => $member['member_id'],
            'asset_code'      => 'COIN',
            'entry_type'      => 'daily_mining_credit',
            'direction'       => 'credit',
            'amount'          => '1.00000000',
            'balance_after'   => '1.00000000',
            'business_date'   => $date,
            'idempotency_key' => 'test-unexpected-' . $member['user_id'] . '-' . $date,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);

        $report = service('miningDailyEngineService')->reconcile($date, 100);
        $types  = array_column($report['mismatches'], 'type');
        $this->assertContains('unexpected_credit_while_posting_off', $types);
    }

    public function testHistoricalBackfillPreviewIsReadOnly(): void
    {
        $platform = config('Platform');
        $platform->mandatoryPurchaseLockEnabled = false;
        $member = $this->register('legacy');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '7',
            null,
            'p11a-leg-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'legacy');
        $platform->mandatoryPurchaseLockEnabled = true;

        $beforeLocks = db_connect()->table('member_coin_locks')->countAllResults();
        $missing = service('coinLockingService')->previewMissingMandatoryLocks(50);
        $this->assertNotEmpty($missing);
        $this->assertSame($beforeLocks, db_connect()->table('member_coin_locks')->countAllResults());
    }
}
