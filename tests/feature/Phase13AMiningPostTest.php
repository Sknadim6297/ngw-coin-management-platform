<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\MiningDailyEngineService;
use App\Services\MiningPostingRunnerService;
use App\Services\MiningWalletService;
use App\Services\OpsJobLockService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 13A focused tests for `mining:post` (MiningPostingRunnerService) on the PHPUnit DB.
 *
 * The PHPUnit database is the only non-sandbox DB the runner will accept (ENVIRONMENT=testing).
 * Posting there goes through the real production authorization path (Platform kill-switches).
 *
 * @internal
 */
final class Phase13AMiningPostTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;
    private string $logFile;

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
        $platform->miningPostingEnabled              = true;   // authorized path under test
        $platform->miningAutoPostEnabled             = true;
        $platform->miningP2pEnabled                  = false;
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->miningBusinessTimezone            = 'Asia/Kolkata';
        $platform->miningMonthDayDivisorMode         = 'calendar_days';
        $platform->miningApprovalDayPolicy           = 'full_day';
        $platform->miningEarningAsset                = 'COIN';
        $platform->coinPlanLockDays                  = 1000;
        $platform->miningPostMaxAttempts             = 3;
        $platform->miningPostRetryDelaySeconds       = 0;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p13a price');
        service('coinInventoryService')->addInventory('10000', $this->adminUserId, 'p13a stock', null, 'p13a-stock-' . bin2hex(random_bytes(3)));

        $this->logFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mining-post-test-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        config('Platform')->miningPostingEnabled  = false;
        config('Platform')->miningAutoPostEnabled = false;
        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ tests

    public function testPostsExpectedCreditsForMultipleMembersWithLockedCoinsAndIsIdempotent(): void
    {
        $runner = $this->runner();
        $date   = $runner->resolveDate('today');
        $this->approveRate($date);

        $a = $this->member('a');            $this->buy($a, '100');            // all locked (mandatory lock)
        $b = $this->member('b');            $this->buy($b, '250.5');          // all locked
        config('Platform')->mandatoryPurchaseLockEnabled = false;
        $c = $this->member('c');            $this->buy($c, '40');             // all available
        $d = $this->member('d');            $this->buy($d, '30');             // available part of mixed
        config('Platform')->mandatoryPurchaseLockEnabled = true;
        $this->buy($d, '20');                                                  // locked part of mixed
        $e = $this->member('e');                                               // no purchase → ineligible

        $this->assertSame(0, MoneyMath::compare((string) service('memberCoinOwnershipService')->summaryForUser($a['user_id'])['locked'], '100', 8), 'A fully locked');
        $dSum = service('memberCoinOwnershipService')->summaryForUser($d['user_id']);
        $this->assertSame(0, MoneyMath::compare((string) $dSum['available'], '30', 8));
        $this->assertSame(0, MoneyMath::compare((string) $dSum['locked'], '20', 8));

        // Rate % == days-in-month → daily factor is exactly 0.01, so expected = owned × 0.01 with no rounding.
        $expected = ['a' => '1.00000000', 'b' => '2.50500000', 'c' => '0.40000000', 'd' => '0.50000000', 'e' => '0.00000000'];
        $members  = ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d, 'e' => $e];

        $first = $runner->run($this->opts($date));

        $this->assertSame('completed', $first['status']);
        $this->assertSame(4, $first['credited']);
        $this->assertSame(0, $first['duplicates']);
        $this->assertSame([], $first['errors']);
        $this->assertSame([], $first['mismatches']);
        $this->assertSame(0, MoneyMath::compare($first['expected_total'], '4.40500000', 8));
        $this->assertSame(0, MoneyMath::compare($first['posted_total'], '4.40500000', 8));

        foreach ($members as $key => $m) {
            $this->assertSame(
                0,
                MoneyMath::compare(service('miningWalletService')->postedAmountForDate($m['user_id'], $date), $expected[$key], 8),
                "member {$key} credit for {$date}"
            );
            $this->assertSame(
                0,
                MoneyMath::compare(service('miningWalletService')->balanceFor($m['user_id'])['available_balance'], $expected[$key], 8),
                "member {$key} wallet balance"
            );
        }

        $rowsAfterFirst = (int) db_connect()->table('mining_wallet_ledger')->countAllResults();
        $this->assertSame(4, $rowsAfterFirst);

        // Same business date again (scheduled + manual): zero new credits, nothing changes.
        $second = $runner->run($this->opts($date));
        $third  = $runner->run($this->opts($date, ['scheduled' => false]));

        foreach ([$second, $third] as $again) {
            $this->assertSame('completed', $again['status']);
            $this->assertSame(0, $again['credited'], 'repeat run must not credit');
            $this->assertSame(4, $again['duplicates']);
            $this->assertSame([], $again['mismatches']);
        }
        $this->assertSame($rowsAfterFirst, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());
        $this->assertSame(0, MoneyMath::compare(service('miningWalletService')->balanceFor($b['user_id'])['available_balance'], '2.505', 8));

        $dupes = db_connect()->query(
            "SELECT member_user_id, business_date, COUNT(*) c FROM mining_wallet_ledger GROUP BY member_user_id, business_date HAVING c > 1"
        )->getResultArray();
        $this->assertSame([], $dupes);

        // Ownership principal is never touched by mining.
        $this->assertSame(0, MoneyMath::compare((string) service('memberCoinOwnershipService')->summaryForUser($a['user_id'])['balance'], '100', 8));

        // Run history + log file.
        $runs = db_connect()->table('mining_daily_runs')->where('run_type', 'post')->orderBy('id')->get()->getResultArray();
        $this->assertCount(3, $runs);
        $this->assertSame(['completed', 'completed', 'completed'], array_column($runs, 'status'));
        $this->assertStringContainsString('"event":"run-finished"', (string) file_get_contents($this->logFile));
    }

    public function testKillSwitchesDateAndRateGatesCreditNothing(): void
    {
        $runner = $this->runner();
        $date   = $runner->resolveDate('today');
        $m      = $this->member('ks');
        $this->buy($m, '100');
        $this->approveRate($date);
        $platform = config('Platform');

        // 1) posting kill-switch OFF → hard refusal.
        $platform->miningPostingEnabled = false;
        $this->expectRefusal(fn () => $runner->run($this->opts($date)), 'kill-switch');

        // 2) scheduled while auto-post OFF → skipped (exit-0 semantics), nothing posted.
        $platform->miningPostingEnabled  = true;
        $platform->miningAutoPostEnabled = false;
        $r = $runner->run($this->opts($date));
        $this->assertSame('skipped_disabled', $r['status']);

        // 3) manual (non-scheduled) run is still allowed when only auto-post is OFF, but a dry-run never writes.
        $platform->miningAutoPostEnabled = true;
        $dry = $runner->run(['date' => $date, 'execute' => false]);
        $this->assertSame('dry_run', $dry['status']);
        $this->assertSame(0, MoneyMath::compare($dry['expected_total'], '1.00000000', 8));

        // 4) future date and missing rate.
        $this->expectRefusal(fn () => $runner->run($this->opts('2099-01-01')), 'future', InvalidArgumentException::class);
        $this->expectRefusal(fn () => $runner->run($this->opts('2020-01-15')), 'No approved mining rate');

        // 5) invalid date strings.
        $this->expectRefusal(fn () => $runner->resolveDate('2026-02-31'), 'valid YYYY-MM-DD', InvalidArgumentException::class);

        // 6) the engine itself still refuses when kill-switch is off (defence in depth).
        $platform->miningPostingEnabled = false;
        $this->expectRefusal(fn () => (new MiningDailyEngineService())->postDay($date, 50, $this->adminUserId), 'kill-switch');

        $this->assertSame(0, (int) db_connect()->table('mining_wallet_ledger')->countAllResults(), 'no ledger rows may exist');
        $this->assertSame(0, (int) db_connect()->table('mining_wallets')->countAllResults(), 'no wallets may be created');
        $this->assertSame(0, (int) db_connect()->table('mining_daily_runs')->where('run_type', 'post')->countAllResults());
    }

    public function testLiveAndUnknownDatabasesAreRefusedThroughEveryGate(): void
    {
        $runner = $this->runner();
        $date   = $runner->resolveDate('today');
        $this->assertSame('live', $runner->classify('default')['mode']);
        $this->assertSame('sandbox', $runner->classify('phase13a')['mode']);
        $this->assertSame('sandbox', $runner->classify('phase12b')['mode']);
        $this->assertSame('testing', $runner->classify('tests')['mode']);
        $this->expectRefusal(fn () => $runner->classify('phase12d'), 'not the live DB, not an allowlisted');
        $this->expectRefusal(fn () => $runner->classify('no_such_group'), 'Unknown database group');

        $platform = config('Platform');
        $live     = $runner->classify('default');
        $phrase   = 'I_AUTHORIZE_PRODUCTION_CHANGE';

        // authorize() touches no database, so the live gate chain can be exercised safely.
        $platform->miningPostingEnabled = false;
        $this->expectRefusal(fn () => $runner->authorize($live, false, true, $phrase), 'kill-switch');

        $platform->miningPostingEnabled = true;
        $this->expectRefusal(fn () => $runner->authorize($live, false, false, $phrase), 'Refusing mining posting on live database');
        $this->expectRefusal(fn () => $runner->authorize($live, false, true, null), 'requires --confirm');
        $this->expectRefusal(fn () => $runner->authorize($live, false, true, 'WRONG'), 'requires --confirm');
        $this->expectRefusal(fn () => $runner->authorize($live, false, true, $phrase), 'business policies not approved');

        // Scheduler against live while auto-post is OFF → skipped (the default production state), never an error.
        $platform->miningAutoPostEnabled = false;
        $this->assertStringContainsString('miningAutoPostEnabled=false', (string) $runner->authorize($live, true, true, $phrase));

        // Only when EVERY gate is satisfied does authorize() pass for live...
        $platform->miningAutoPostEnabled = true;
        foreach ([
            'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus',
            'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus', 'miningRoundingPolicyStatus',
        ] as $field) {
            $platform->{$field} = 'approved';
        }
        $this->assertNull($runner->authorize($live, true, true, $phrase));

        // ...and even then run() refuses unless the ACTIVE connection is the classified database (isolation guard).
        $this->expectRefusal(
            fn () => $runner->run(['group' => 'default', 'date' => $date, 'execute' => true, 'scheduled' => true, 'allow_production' => true, 'confirm' => $phrase]),
            'Isolation check failed'
        );

        // Sandbox DBs need the explicit APPLY_STAGING phrase; a scheduler without it is skipped, a manual run is refused.
        $sandbox = $runner->classify('phase13a');
        $this->assertNull($runner->authorize($sandbox, true, false, 'APPLY_STAGING'));
        $this->assertNotNull($runner->authorize($sandbox, true, false, null));
        $this->expectRefusal(fn () => $runner->authorize($sandbox, false, false, 'nope'), 'APPLY_STAGING');

        $this->assertSame(0, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());
    }

    public function testTransientFailuresAreRetriedAndPermanentFailuresAreNot(): void
    {
        $date = (new MiningPostingRunnerService(null, null, null, null, $this->logFile))->resolveDate('today');
        $this->approveRate($date);
        $m = $this->member('rt');
        $this->buy($m, '100');

        // Transient: deadlock twice, then success on attempt 3.
        $flaky  = $this->flakyEngine(2, 'Deadlock found when trying to get lock; try restarting transaction');
        $sleeps = [];
        $runner = new MiningPostingRunnerService($flaky, null, null, static function (int $s) use (&$sleeps): void { $sleeps[] = $s; }, $this->logFile);
        config('Platform')->miningPostRetryDelaySeconds = 2;

        $ok = $runner->run($this->opts($date));
        $this->assertSame('completed', $ok['status']);
        $this->assertSame(3, $ok['attempts']);
        $this->assertSame(1, $ok['credited']);
        $this->assertSame([2, 4], $sleeps, 'linear back-off between attempts');
        $this->assertSame(1, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());

        // Exhausted: always deadlocks → failed, nothing extra credited (different date, new member state).
        $date2 = (new \DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d');
        if (substr($date2, 0, 7) !== substr($date, 0, 7)) {
            $this->approveRate($date2);
        }
        $always = $this->flakyEngine(99, 'Lock wait timeout exceeded; try restarting transaction');
        $sleeps = [];
        $runner = new MiningPostingRunnerService($always, null, null, static function (int $s) use (&$sleeps): void { $sleeps[] = $s; }, $this->logFile);
        $failed = $runner->run($this->opts($date2));
        $this->assertSame('failed', $failed['status']);
        $this->assertCount(2, $sleeps, '3 attempts → 2 pauses');
        $this->assertSame('failed', db_connect()->table('mining_daily_runs')->where('run_ref', $failed['run_ref'])->get()->getRowArray()['status']);
        $this->assertSame(1, (int) db_connect()->table('mining_wallet_ledger')->countAllResults(), 'failed run credited nothing');

        // Permanent error: not retried.
        $broken = $this->flakyEngine(99, 'Some permanent business rule violation');
        $sleeps = [];
        $runner = new MiningPostingRunnerService($broken, null, null, static function (int $s) use (&$sleeps): void { $sleeps[] = $s; }, $this->logFile);
        $perm = $runner->run($this->opts($date2));
        $this->assertSame('failed', $perm['status']);
        $this->assertSame([], $sleeps, 'non-transient failure must not be retried');
        $this->assertSame(1, $broken->calls);

        // Locks released after failures: a healthy run can start immediately.
        $healthy = (new MiningPostingRunnerService(null, null, null, null, $this->logFile))->run($this->opts($date2));
        $this->assertSame('completed', $healthy['status']);
    }

    public function testConcurrentRunHoldingTheJobLockIsSkippedAndStaleRunIsAbandoned(): void
    {
        $runner = $this->runner();
        $date   = $runner->resolveDate('today');
        $this->approveRate($date);
        $m = $this->member('lk');
        $this->buy($m, '100');

        $locks = new OpsJobLockService();
        $this->assertTrue($locks->acquire(MiningPostingRunnerService::LOCK_PREFIX . $date, 'another-scheduler', 600));

        $skipped = $runner->run($this->opts($date));
        $this->assertSame('skipped_locked', $skipped['status']);
        $this->assertSame(0, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());

        $locks->release(MiningPostingRunnerService::LOCK_PREFIX . $date, 'another-scheduler');

        // A crashed predecessor left a "running" row → abandoned, then a real run proceeds.
        db_connect()->table('mining_daily_runs')->insert([
            'run_ref' => 'MPR-STALE', 'business_date' => $date, 'run_type' => 'post', 'status' => 'running', 'started_at' => date('Y-m-d H:i:s'),
        ]);
        $done = $runner->run($this->opts($date));
        $this->assertSame('completed', $done['status']);
        $this->assertSame('abandoned', db_connect()->table('mining_daily_runs')->where('run_ref', 'MPR-STALE')->get()->getRowArray()['status']);
        $this->assertSame(1, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());
        $this->assertSame('mining_post:' . $date, MiningPostingRunnerService::LOCK_PREFIX . $date);
        $this->assertNull(db_connect()->table('ops_job_locks')->where('lock_name', 'mining_post:' . $date)->get()->getRowArray()['locked_by'], 'lock released');
    }

    public function testOneMemberFailureDoesNotBlockOthersWhenContinueOnError(): void
    {
        $runner = $this->runner();
        $date   = $runner->resolveDate('today');
        $this->approveRate($date);
        $bad  = $this->member('bad');
        $good = $this->member('good');
        $this->buy($bad, '100');
        $this->buy($good, '100');

        $wallets = new class ($bad['member_id']) extends MiningWalletService {
            public function __construct(private readonly string $failMemberId)
            {
                parent::__construct();
            }

            public function creditDailyMining(int $memberUserId, string $memberId, array $data): array
            {
                if ($memberId === $this->failMemberId) {
                    throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
                }

                return parent::creditDailyMining($memberUserId, $memberId, $data);
            }
        };
        $engine = new MiningDailyEngineService(wallets: $wallets);

        // Fail-fast default (unchanged behaviour).
        $this->expectRefusal(fn () => $engine->postDay($date, 50, $this->adminUserId), 'Deadlock');

        // Scheduler mode: error is isolated and reported, the healthy member is credited exactly once.
        $res = $engine->postDay($date, 50, $this->adminUserId, true);
        $this->assertCount(1, $res['errors']);
        $this->assertSame($bad['member_id'], $res['errors'][0]['member_id']);
        $this->assertSame(1, $res['credited'] + $res['duplicates'], 'only the healthy member was attempted successfully');
        // Rate % == days-in-month → factor exactly 0.01 → 100 coins earn 1.00000000.
        $this->assertSame(0, MoneyMath::compare(service('miningWalletService')->postedAmountForDate($good['user_id'], $date), '1.00000000', 8));
        $this->assertSame(1, (int) db_connect()->table('mining_wallet_ledger')->where('member_user_id', $good['user_id'])->countAllResults());
        $this->assertSame(0, (int) db_connect()->table('mining_wallet_ledger')->where('member_user_id', $bad['user_id'])->countAllResults());
    }

    // ------------------------------------------------------------------ helpers

    private function runner(): MiningPostingRunnerService
    {
        return new MiningPostingRunnerService(null, null, null, static function (int $s): void {
        }, $this->logFile);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function opts(string $date, array $extra = []): array
    {
        return $extra + [
            'date'      => $date,
            'group'     => 'tests',
            'execute'   => true,
            'scheduled' => true,
        ];
    }

    private function days(string $date): int
    {
        return (int) (new \DateTimeImmutable(substr($date, 0, 7) . '-01'))->format('t');
    }

    private function ratePercent(string $date): string
    {
        return number_format($this->days($date), 8, '.', '');
    }

    /**
     * Rate % equals days in month so daily factor == 0.01 exactly.
     */
    private function approveRate(string $date): void
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => substr($date, 0, 7),
            'rate_percent'    => $this->ratePercent($date),
            'rate_basis'      => 'monthly',
            'notes'           => 'p13a test rate',
        ], $this->adminUserId);
        service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);
    }

    private function flakyEngine(int $failures, string $message): MiningDailyEngineService
    {
        return new class ($failures, $message) extends MiningDailyEngineService {
            public int $calls = 0;

            public function __construct(private readonly int $failures, private readonly string $message)
            {
                parent::__construct();
            }

            public function postDay(string $businessDate, int $limit = 200, ?int $actorUserId = null, bool $continueOnError = false): array
            {
                $this->calls++;
                if ($this->calls <= $this->failures) {
                    throw new RuntimeException($this->message);
                }

                return parent::postDay($businessDate, $limit, $actorUserId, $continueOnError);
            }
        };
    }

    private function expectRefusal(callable $fn, string $needle, string $class = RuntimeException::class): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e, 'unexpected exception type: ' . $e::class . ' / ' . $e->getMessage());
            $this->assertStringContainsString($needle, $e->getMessage());

            return;
        }
        $this->fail('Expected refusal containing "' . $needle . '" but call succeeded.');
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p13aapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'p13a.approver@test.local', 'password' => 'ApproverPass123!']);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function member(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p13a' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p13a-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P13A ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p13a' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }

    /**
     * @param array{user_id: int, member_id: string} $member
     */
    private function buy(array $member, string $qty): void
    {
        $req = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'p13a-' . $member['user_id'] . '-' . bin2hex(random_bytes(2)));
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p13a');
    }
}
