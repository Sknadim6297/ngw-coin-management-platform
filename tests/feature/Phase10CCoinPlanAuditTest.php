<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * Phase 10C focused Coin Plan compliance tests (no financial posting activation).
 *
 * @internal
 */
final class Phase10CCoinPlanAuditTest extends CIUnitTestCase
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
        $platform->mandatoryPurchaseLockEnabled      = true;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p10c price');
        service('coinInventoryService')->addInventory('5000', $this->adminUserId, 'p10c stock', null, 'p10c-stock-' . bin2hex(random_bytes(3)));

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

    /**
     * @return array{user: object, profile: object, member_id: string, user_id: int}
     */
    private function register(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p10c' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p10c-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P10C ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '15' . substr($digits . '00000000', 0, 8),
            'email'       => 'p10c' . $suffix . '@example.com',
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

    public function testFreeRegistrationCreatesInactiveMember(): void
    {
        $member = $this->register('free');
        $this->assertSame('inactive', $member['profile']->account_status);
        $this->assertNull($member['profile']->activated_at);
    }

    public function testMinimumPurchaseBoundary(): void
    {
        $this->assertSame('5.00000000', service('coinPriceService')->getMinPurchaseUsdt());

        $this->expectException(InvalidArgumentException::class);
        service('coinPriceService')->quoteByUsdtAmount('4.99');
    }

    public function testExactFiveAndTenUsdtQuotesAccepted(): void
    {
        $q5  = service('coinPriceService')->quoteByUsdtAmount('5.00');
        $q10 = service('coinPriceService')->quoteByUsdtAmount('10.00');
        $this->assertSame(0, MoneyMath::compare((string) $q5['usdt_amount'], '5.00000000', 8));
        $this->assertSame(0, MoneyMath::compare((string) $q10['usdt_amount'], '10.00000000', 8));
    }

    public function testPaymentSubmitDoesNotActivate(): void
    {
        $member = $this->register('payonly');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '10',
            null,
            'p10c-payonly-' . bin2hex(random_bytes(2))
        );
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByUserId($member['user_id'])->account_status);
        $this->assertSame('pending', $req['request']['status']);
    }

    public function testApprovedPurchaseActivatesAndMandatoryAutoLocks(): void
    {
        $member = $this->register('actv');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '10',
            null,
            'p10c-act-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p10c');

        $fresh = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $this->assertSame('active', $fresh->account_status);
        $this->assertNotEmpty($fresh->activated_at);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $summary['available'], '0.00000000', 8));
        $this->assertSame(0, MoneyMath::compare((string) $summary['locked'], '10.00000000', 8));
        $this->assertSame(0, MoneyMath::compare((string) $summary['balance'], '10.00000000', 8));
    }

    public function testCommissionIllustrativeRatesTenFiveThree(): void
    {
        $lines = service('coinPlanAuditService')->illustrativeCommissionBreakdown('100.00000000');
        $this->assertSame('10.00000000', $lines[0]['expected']);
        $this->assertSame('5.00000000', $lines[1]['expected']);
        $this->assertSame('3.00000000', $lines[2]['expected']);
    }

    public function testCommissionPostingAndWithdrawalRemainDisabled(): void
    {
        $this->assertFalse(service('commissionWalletService')->postingEnabled());
        $this->assertFalse(service('commissionWalletService')->withdrawalEnabled());

        $blocked = false;
        try {
            service('commissionWalletService')->creditCommission(1, 'ROOT0001', [
                'amount'          => '1',
                'entry_type'      => 'commission_credit',
                'idempotency_key' => 'p10c-should-fail',
            ]);
        } catch (\RuntimeException $e) {
            $blocked = str_contains(strtolower($e->getMessage()), 'disabled')
                || str_contains(strtolower($e->getMessage()), 'not implemented');
        }
        $this->assertTrue($blocked);
    }

    public function testMiningDryRunDoesNotPostIncome(): void
    {
        $member = $this->register('mine');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '20',
            null,
            'p10c-mine-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p10c');

        $todayIst = service('miningEligibilityService')->businessDate();
        $nextIst  = (new \DateTimeImmutable($todayIst, new \DateTimeZone('Asia/Kolkata')))->modify('+1 day')->format('Y-m-d');
        $month    = substr($nextIst, 0, 7);
        $draft = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => '1.00000000',
            'rate_basis'      => 'daily',
            'notes'           => 'p10c dry-run',
        ], $this->adminUserId);
        $approver = new \CodeIgniter\Shield\Entities\User(['username' => 'p10cminer', 'active' => 1]);
        $users = model(\CodeIgniter\Shield\Models\UserModel::class);
        $users->save($approver);
        $approver = $users->findById($users->getInsertID());
        $approver->createEmailIdentity(['email' => 'p10cminer@example.com', 'password' => 'Password123!']);
        $approver->addGroup('finance_approver');
        service('miningRateService')->approve($draft['rate_ref'], (int) $approver->id);

        // Approval day excluded: 0 expected income on approval date; positive on next Asia/Kolkata business date.
        $todayPreview = service('coinPlanAuditService')->miningDryRunPreview($todayIst, 100);
        foreach ($todayPreview['rows'] as $row) {
            if ($row['member_id'] === $member['member_id']) {
                $this->assertSame('0.00000000', $row['expected_income']);
                break;
            }
        }

        $preview = service('coinPlanAuditService')->miningDryRunPreview($nextIst, 100);
        $this->assertSame('DAILY MINING IS NOT POSTING REAL INCOME.', $preview['posting_status']);

        $found = null;
        foreach ($preview['rows'] as $row) {
            if ($row['member_id'] === $member['member_id']) {
                $found = $row;
                break;
            }
        }
        $this->assertNotNull($found);
        $this->assertSame('0.00000000', $found['posted_income']);
        $this->assertTrue(MoneyMath::compare((string) $found['expected_income'], '0', 8) === 1);
    }

    public function testRuleMatrixStatusesAndFinalNonCompliance(): void
    {
        $matrix = service('coinPlanAuditService')->ruleMatrix();
        $byRule = [];
        foreach ($matrix as $row) {
            $byRule[$row['rule']] = $row['status'];
        }
        $this->assertSame('MATCHED', $byRule['1. Free joining']);
        $this->assertSame('PARTIALLY MATCHED', $byRule['5. 1000-day lock + sell after maturity']);
        $this->assertSame('PARTIALLY MATCHED', $byRule['7. Separate mining wallet + P2P only']);
        $this->assertSame('PARTIALLY MATCHED', $byRule['3. Dynamic daily mining']);

        $flags = service('coinPlanAuditService')->runtimeFlags();
        $this->assertTrue($flags['mining_wallet_exists']);
        $this->assertFalse($flags['p2p_exists']);
        $this->assertFalse($flags['coin_selling_exists']);
        $this->assertTrue($flags['auto_lock_on_purchase']);
        $this->assertFalse($flags['commission_posting_enabled']);
        $this->assertFalse($flags['mining_posting_enabled']);
    }

    public function testVoluntaryLockingStillWorksWhenMandatoryLockDisabledForCredit(): void
    {
        $platform = config('Platform');
        $platform->mandatoryPurchaseLockEnabled = false;

        $member = $this->register('lock1k');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '50',
            null,
            'p10c-lock-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p10c');

        $platform->mandatoryPurchaseLockEnabled = true;

        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => 'LOCK1000P10C' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)),
            'name'          => '1000 Day Plan',
            'duration_days' => 1000,
            'min_quantity'  => '1',
        ], $this->adminUserId);
        $plan = service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);

        $result = service('coinLockingService')->lockCoins(
            $member['user_id'],
            (int) $plan['id'],
            '10',
            'p10c-lock-' . bin2hex(random_bytes(2))
        );
        $this->assertSame(1000, (int) $result['lock']['duration_days']);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $summary['locked'], '10.00000000', 8));
    }

    public function testWithdrawalsRemainBlockedForMember(): void
    {
        $member = $this->register('wdblk');
        $user = model(\CodeIgniter\Shield\Models\UserModel::class)->findById($member['user_id']);
        auth()->login($user);

        $page = $this->withSession()->get('member/withdrawals');
        $page->assertOK();
        $page->assertSee('Not Available');
    }
}
