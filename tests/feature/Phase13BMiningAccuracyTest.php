<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\MiningBlockedException;
use App\Libraries\CliArgs;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Models\MemberProfileModel;
use App\Services\MiningEligibleOwnershipSnapshotService;
use App\Services\MiningPostAlertService;
use App\Services\MiningPostingRunnerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Phase 13B focused tests (PHPUnit DB only; no live or sandbox DB is touched).
 *
 * The long-running scenarios (10000 members, child-process scheduler simulations, 12 scheduler cases, CLI audit of
 * every command) run in `php spark mining:phase13b-verify` against the isolated `coin_platform_phase13b` database.
 *
 * @internal
 */
final class Phase13BMiningAccuracyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;
    private string $logFile;
    private string $alertFile;

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
        $platform->miningPostingEnabled              = true;
        $platform->miningAutoPostEnabled             = true;
        $platform->miningP2pEnabled                  = false;
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->miningBusinessTimezone            = 'Asia/Kolkata';
        $platform->miningMonthDayDivisorMode         = 'calendar_days';
        $platform->miningApprovalDayPolicy           = 'full_day';
        $platform->miningRoundingMode                = 'half_up';
        $platform->miningReservedCoinPolicy          = 'include';
        $platform->miningMaturityPolicy              = 'include_all';
        $platform->miningEarningAsset                = 'COIN';
        $platform->coinPlanLockDays                  = 1000;
        $platform->miningPostMaxAttempts             = 3;
        $platform->miningPostRetryDelaySeconds       = 0;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p13b price');
        service('coinInventoryService')->addInventory('10000', $this->adminUserId, 'p13b stock', null, 'p13b-stock-' . bin2hex(random_bytes(3)));

        $this->logFile   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p13b-post-' . bin2hex(random_bytes(4)) . '.log';
        $this->alertFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p13b-alert-' . bin2hex(random_bytes(4)) . '.log';
    }

    protected function tearDown(): void
    {
        config('Platform')->miningPostingEnabled  = false;
        config('Platform')->miningAutoPostEnabled = false;
        foreach ([$this->logFile, $this->alertFile] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        parent::tearDown();
    }

    // ===================================================================== math / precision

    public function testPrecisionIllustrationFromSpecAndNoPrematureTruncation(): void
    {
        // 1000 coins, 3% monthly, 31-day month
        $this->assertSame('0.96774194', MiningMath::dailyAmount('1000', '3', 31, 8, MiningMath::ROUND_HALF_UP));
        $this->assertSame('0.96774193', MiningMath::dailyAmount('1000', '3', 31, 8, MiningMath::ROUND_DOWN));
        $this->assertSame('1.00000000', MiningMath::dailyAmount('1000', '3', 30, 8, MiningMath::ROUND_HALF_UP));

        // Old 13A path: factor cut to 12dp then truncated. Prove the new path differs only by the final rounding,
        // and that a large eligible amount keeps its precision (no early 8dp truncation of the factor).
        $big = MiningMath::dailyAmount('123456789.12345678', '2.5', 31, 8, MiningMath::ROUND_DOWN);
        $ind = $this->independent('123456789.12345678', '2.5', 31, 8, false);
        $this->assertSame($ind, $big);
    }

    public function testMonthDivisorsAcrossFebruaryAprilJanuaryAndDecemberJanuary(): void
    {
        $cases = [
            ['2027-02-10', 28], ['2028-02-29', 29], ['2026-04-30', 30], ['2026-01-31', 31], ['2026-12-31', 31], ['2027-01-01', 31],
        ];
        foreach ($cases as [$date, $days]) {
            $this->assertSame($days, MiningMath::divisor('monthly', $date, null, MiningMath::DIVISOR_CALENDAR), $date);
            $this->assertSame(30, MiningMath::divisor('monthly', $date, null, MiningMath::DIVISOR_FIXED_30), $date . ' fixed');
        }
        $this->assertSame(1, MiningMath::divisor('daily', '2026-10-01', null, MiningMath::DIVISOR_CALENDAR));
        $this->assertSame(365, MiningMath::divisor('annual', '2026-10-01', null, MiningMath::DIVISOR_CALENDAR));

        // Month of a rate is its effective month, not the posting date's month.
        $this->assertSame(31, MiningMath::divisor('monthly', '2026-11-01', '2026-10', MiningMath::DIVISOR_CALENDAR));

        // Whole-month totals: calendar divisor pays exactly the monthly percent; fixed-30 over/under-pays by days/30.
        foreach ([[28, '2027-02'], [29, '2028-02'], [30, '2026-04'], [31, '2026-01']] as [$days, $ym]) {
            $total = '0';
            for ($d = 1; $d <= $days; $d++) {
                $date  = sprintf('%s-%02d', $ym, $d);
                $total = bcadd($total, MiningMath::dailyAmount('1000', '3', MiningMath::divisor('monthly', $date, null, MiningMath::DIVISOR_CALENDAR), 8, MiningMath::ROUND_HALF_UP), 8);
            }
            $this->assertEqualsWithDelta(30.0, (float) $total, 0.0000005 * $days, "calendar total {$ym}");
        }
    }

    public function testRoundingModesBoundaryAndRejectionOfBadInput(): void
    {
        // exact half unit at the 8th decimal: 0.000000005
        $this->assertSame('0.00000001', MiningMath::round('0.000000005', 8, MiningMath::ROUND_HALF_UP));
        $this->assertSame('0.00000000', MiningMath::round('0.000000005', 8, MiningMath::ROUND_DOWN));
        $this->assertSame('0.00000000', MiningMath::round('0.0000000049999', 8, MiningMath::ROUND_HALF_UP));
        $this->assertSame('0.00000000', MiningMath::dailyAmount('0', '3', 31, 8, MiningMath::ROUND_HALF_UP));

        foreach ([
            fn () => MiningMath::dailyAmount('-1', '3', 31, 8, 'down'),
            fn () => MiningMath::dailyAmount('1e3', '3', 31, 8, 'down'),
            fn () => MiningMath::dailyAmount('1', '101', 31, 8, 'down'),
            fn () => MiningMath::dailyAmount('1', '3', 0, 8, 'down'),
            fn () => MiningMath::dailyAmount('1', '3', 31, 8, 'bankers'),
            fn () => MiningMath::divisor('monthly', '2026-10-01', null, 'weird'),
        ] as $bad) {
            $this->assertThrowsInvalid($bad);
        }
    }

    // ===================================================================== policy

    public function testPolicyValidationVersioningAndNoAutoSelection(): void
    {
        $a = MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP');
        $b = MiningCalculationPolicy::fixture('T-FIXED30-HALFUP');
        $this->assertNotSame($a->version(), $b->version());
        $this->assertSame($a->version(), MiningCalculationPolicy::fromArray($a->toArray())->version());
        $this->assertTrue(MiningCalculationPolicy::fixture('T-NEXTDAY-DOWN')->usesStartOfDaySnapshot());
        $this->assertFalse($a->usesStartOfDaySnapshot());

        $this->expectException(InvalidArgumentException::class);
        MiningCalculationPolicy::fromArray(['approval_day' => 'full_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'sometimes', 'reserved' => 'include', 'maturity' => 'include_all']);
    }

    public function testPlatformPolicyDefaultsAreUnapprovedAndProductionSwitchesFalse(): void
    {
        $fresh = new \Config\Platform();
        foreach (['miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled'] as $sw) {
            $this->assertFalse((bool) $fresh->{$sw}, $sw . ' must default to false');
        }
        foreach (['miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus', 'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus', 'miningRoundingPolicyStatus'] as $st) {
            $this->assertNotSame('approved', strtolower((string) $fresh->{$st}), $st . ' must not default to approved');
        }
    }

    // ===================================================================== timezone

    public function testBusinessDateBoundariesUseAsiaKolkataNotUtc(): void
    {
        $svc = new MiningEligibleOwnershipSnapshotService();
        $this->assertSame('2026-10-14 18:30:00', $svc->dayStartUtc('2026-10-15'));
        $full = MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP');
        $next = MiningCalculationPolicy::fixture('T-NEXTDAY-DOWN');

        $this->assertSame('2026-10-15 18:30:00', $svc->cutoff('2026-10-15', $full, new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('UTC')))['cutoff_utc']);
        $this->assertSame('2026-10-14 18:30:00', $svc->cutoff('2026-10-15', $next, new DateTimeImmutable('2026-10-20 00:00:00', new DateTimeZone('UTC')))['cutoff_utc']);

        // 15 Oct 23:59:59 IST (= 18:29:59 UTC) is still 15 Oct; 16 Oct 00:00:00 IST is the cutoff itself.
        $this->assertSame('2026-10-15 18:30:00', $svc->cutoff('2026-10-15', $full, new DateTimeImmutable('2026-10-16 05:00:00', new DateTimeZone('UTC')))['cutoff_utc']);
    }

    public function testIncompleteBusinessDayIsRefusedUnlessExplicitlyAllowed(): void
    {
        $svc = new MiningEligibleOwnershipSnapshotService();
        $pol = MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP');
        $now = new DateTimeImmutable('2026-10-15 10:00:00', new DateTimeZone('UTC'));

        try {
            $svc->cutoff('2026-10-15', $pol, $now);
            $this->fail('partial day must be refused');
        } catch (MiningBlockedException $e) {
            $this->assertStringContainsString('not complete', $e->getMessage());
        }
        $c = $svc->cutoff('2026-10-15', $pol, $now, true);
        $this->assertTrue($c['incomplete_day']);
        $this->assertSame('2026-10-15 10:00:01', $c['cutoff_utc']);
    }

    // ===================================================================== historical ownership (DB)

    public function testAsOfSnapshotExcludesCoinsNotYetApprovedAndIncludesLockedAndAvailable(): void
    {
        $locked = $this->member('lk');
        $this->buy($locked, '100');                                   // locked (mandatory lock)
        config('Platform')->mandatoryPurchaseLockEnabled = false;
        $mixed = $this->member('mx');
        $this->buy($mixed, '40');                                     // available
        config('Platform')->mandatoryPurchaseLockEnabled = true;
        $this->buy($mixed, '60');                                     // locked
        $none = $this->member('no');                                  // never purchased

        $svc    = new MiningEligibleOwnershipSnapshotService();
        $policy = MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP');
        $future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        $past   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-30 days')->format('Y-m-d H:i:s');

        $now = $svc->snapshotBatch([$locked['user_id'], $mixed['user_id'], $none['user_id']], $future, $policy);
        $this->assertSame(MiningEligibleOwnershipSnapshotService::STATUS_ELIGIBLE, $now[$locked['user_id']]['status']);
        $this->assertSame(0, bccomp($now[$locked['user_id']]['eligible'], '100', 8), 'locked coins are eligible');
        $this->assertSame(0, bccomp($now[$mixed['user_id']]['eligible'], '100', 8), 'available + locked are both eligible');
        $this->assertSame(0, bccomp($now[$mixed['user_id']]['locked'], '60', 8));
        $this->assertSame(0, bccomp($now[$mixed['user_id']]['available'], '40', 8));
        $this->assertSame(0, bccomp($now[$none['user_id']]['eligible'], '0', 8), 'no purchase ? zero');

        // Before any approval existed ? zero (coins not yet approved are excluded, and that is not a guess).
        $then = $svc->snapshotBatch([$locked['user_id'], $mixed['user_id']], $past, $policy);
        $this->assertSame(0, bccomp($then[$locked['user_id']]['eligible'], '0', 8));
        $this->assertSame(0, bccomp($then[$mixed['user_id']]['eligible'], '0', 8));
        $this->assertNotSame(MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED, $then[$locked['user_id']]['status']);
    }

    public function testMissingOwnershipEvidenceBlocksInsteadOfGuessing(): void
    {
        $m = $this->member('ev');
        $this->buy($m, '100');
        // Remove the append-only evidence but keep the cached balance ? the cache alone must not be trusted.
        db_connect()->query('DELETE FROM member_coin_ownership_transactions WHERE member_user_id = ?', [$m['user_id']]);

        $future = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d H:i:s');
        $res    = (new MiningEligibleOwnershipSnapshotService())->snapshotBatch([$m['user_id']], $future, MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP'));
        $this->assertSame(MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED, $res[$m['user_id']]['status']);
    }

    // ===================================================================== batching / resume / idempotency (DB)

    public function testResumableBatchingDoesNotDuplicateAndNeverMarksPartialDayComplete(): void
    {
        $date = $this->runner()->resolveDate('today');
        $this->approveRate($date);
        $ids = [];
        foreach (['b1', 'b2', 'b3', 'b4', 'b5'] as $s) {
            $m = $this->member($s);
            $this->buy($m, '100');
            $ids[] = $m['user_id'];
        }

        // One batch of 2 ? PARTIAL, only 2 credited, never COMPLETED.
        $r1 = $this->runner()->run($this->opts($date, ['batch_size' => 2, 'max_batches' => 1]));
        $this->assertSame('PARTIAL', $r1['state']);
        $this->assertSame(2, (int) db_connect()->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults());
        $day = db_connect()->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray();
        $this->assertNull($day['finished_at']);
        $this->assertNotSame('COMPLETED', $day['state']);

        // Resume ? remaining 3 only, then COMPLETED.
        $r2 = $this->runner()->run($this->opts($date, ['batch_size' => 2]));
        $this->assertSame('COMPLETED', $r2['state']);
        $this->assertSame(5, (int) db_connect()->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults());

        // Repeat ? zero new credits, still 5 rows; no duplicate idempotency groups.
        $r3 = $this->runner()->run($this->opts($date, ['batch_size' => 3]));
        $this->assertSame('COMPLETED', $r3['state']);
        $this->assertSame(5, (int) db_connect()->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults());
        $dupes = db_connect()->query('SELECT idempotency_key, COUNT(*) c FROM mining_wallet_ledger GROUP BY idempotency_key HAVING c > 1')->getResultArray();
        $this->assertSame([], $dupes);

        foreach ($ids as $uid) {
            $this->assertSame(1, (int) db_connect()->table('mining_wallet_ledger')->where('member_user_id', $uid)->where('business_date', $date)->countAllResults());
        }
    }

    public function testChangedPolicyOnResumeFailsClosedAndPostsNothingMore(): void
    {
        $date = $this->runner()->resolveDate('today');
        $this->approveRate($date);
        foreach (['p1', 'p2', 'p3'] as $s) {
            $this->buy($this->member($s), '100');
        }
        $this->runner()->run($this->opts($date, ['batch_size' => 1, 'max_batches' => 1]));
        $before = (int) db_connect()->table('mining_wallet_ledger')->countAllResults();
        $this->assertSame(1, $before);

        config('Platform')->miningRoundingMode = 'down';        // different policy version
        try {
            $this->runner()->run($this->opts($date, ['batch_size' => 1]));
            $this->fail('policy change on resume must be refused');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('olicy', $e->getMessage());
        }
        $this->assertSame($before, (int) db_connect()->table('mining_wallet_ledger')->countAllResults());
    }

    // ===================================================================== CLI parser

    public function testCliParserRejectsAmbiguousSyntaxAndAcceptsSpaceSeparated(): void
    {
        $spec = ['values' => ['group' => CliArgs::DB_GROUPS, 'date' => 'date', 'limit' => 'int-positive'], 'flags' => ['execute', 'scheduled']];

        $ok = CliArgs::parse(['--date', '2026-10-15', '--group', 'phase13b', '--execute'], $spec);
        $this->assertSame([], $ok['errors']);
        $this->assertSame('phase13b', $ok['options']['group']);

        foreach ([
            ['--group=phase13b'],                    // equals syntax: silently dropped by CI4 ? would fall back to live DB
            ['--group'],                             // missing value
            ['--group', '--execute'],                // value swallowed by next option
            ['--group', 'coin_platform'],            // not an allowed group
            ['--group', 'phase13b', '--group', 'default'], // duplicate
            ['--execute', 'yes'],                    // flag given a value / stray positional
            ['--bogus'],                             // unknown
            ['-x'],                                  // short option
            ['--limit', '0'],                        // invalid number
            ['--limit', '-5'],                       // negative number (looks like an option)
            ['--date', '15-10-2026'],                // bad date format
        ] as $args) {
            $r = CliArgs::parse($args, $spec);
            $this->assertNotSame([], $r['errors'], 'must reject: ' . implode(' ', $args));
        }
    }

    public function testMalformedDatabaseGroupNeverSelectsLiveDatabaseThroughGuard(): void
    {
        ob_start();
        $result = CliArgs::guard('mining:post', ['values' => ['group' => CliArgs::DB_GROUPS]], ['spark', 'mining:post', '--group=phase13b']);
        ob_end_clean();
        $this->assertNull($result, 'guard returns null (command must stop) instead of defaulting to the live group');
    }

    // ===================================================================== alerts / classification

    public function testAlertHooksFireForFailuresAndStayQuietForExpectedOutcomes(): void
    {
        $svc = new MiningPostAlertService(null, $this->alertFile);

        $this->assertSame([], $svc->evaluate(['status' => 'completed', 'business_date' => '2026-10-15'], 'POSTED', 0));
        $this->assertSame([], $svc->evaluate([], 'SKIPPED', 20));

        $codes = array_column($svc->evaluate(['status' => 'partial', 'business_date' => '2026-10-15', 'reason' => 'x'], 'PARTIAL', 40), 'code');
        $this->assertContains('nonzero_exit', $codes);
        $this->assertContains('partial_run', $codes);

        $codes = array_column($svc->evaluate(['reconcile_status' => 'MISMATCH', 'mismatches' => [1]], 'FAILED', 60), 'code');
        $this->assertContains('reconciliation_failed', $codes);

        $codes = array_column($svc->evaluate([], 'FAILED', 50, 'No approved mining rate for month'), 'code');
        $this->assertContains('missing_rate', $codes);

        $this->assertFileExists($this->alertFile);
        $content = (string) file_get_contents($this->alertFile);
        $this->assertStringNotContainsStringIgnoringCase('password', $content);
    }

    public function testLiveClassificationStillRefusedAndTestsGroupAllowed(): void
    {
        $runner = $this->runner();
        $this->assertSame('live', $runner->classify('default')['mode']);
        $this->assertSame('sandbox', $runner->classify('phase13b')['mode']);
        $this->assertSame('testing', $runner->classify('tests')['mode']);
    }

    // ===================================================================== helpers

    private function independent(string $eligible, string $percent, int $divisor, int $scale, bool $halfUp): string
    {
        // Integer-only arithmetic over 8-dp scaled units (bcmath scale 0) — independent of MiningMath's decimal path.
        $toUnits = static function (string $v): string {
            [$i, $f] = array_pad(explode('.', $v, 2), 2, '');
            $s = ltrim($i . str_pad(substr($f, 0, 8), 8, '0'), '0');

            return $s === '' ? '0' : $s;
        };
        $num = bcmul(bcmul($toUnits($eligible), $toUnits($percent), 0), bcpow('10', (string) $scale, 0), 0);
        $den = bcmul(bcmul(bcpow('10', '16', 0), '100', 0), (string) $divisor, 0);
        $q   = $halfUp ? bcdiv(bcadd(bcmul($num, '2', 0), $den, 0), bcmul($den, '2', 0), 0) : bcdiv($num, $den, 0);
        $q   = str_pad($q, $scale + 1, '0', STR_PAD_LEFT);

        return substr($q, 0, -$scale) . '.' . substr($q, -$scale);
    }

    private function assertThrowsInvalid(callable $fn): void
    {
        try {
            $fn();
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);

            return;
        }
        $this->fail('expected InvalidArgumentException');
    }

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
        return $extra + ['date' => $date, 'group' => 'tests', 'execute' => true, 'scheduled' => true, 'allow_incomplete_day' => true];
    }

    private function approveRate(string $date): void
    {
        $days  = (int) (new DateTimeImmutable(substr($date, 0, 7) . '-01'))->format('t');
        $draft = service('miningRateService')->createDraft([
            'effective_month' => substr($date, 0, 7),
            'rate_percent'    => number_format($days, 8, '.', ''),
            'rate_basis'      => 'monthly',
            'notes'           => 'p13b test rate',
        ], $this->adminUserId);
        service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p13bapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'p13b.approver@test.local', 'password' => 'ApproverPass123!']);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /** @return array{user_id: int, member_id: string} */
    private function member(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p13b' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p13b-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P13B ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p13b' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $profile->member_id];
    }

    /** @param array{user_id: int, member_id: string} $member */
    private function buy(array $member, string $qty): void
    {
        $req = service('coinPurchaseService')->createRequest($member['user_id'], $qty, null, 'p13b-' . $member['user_id'] . '-' . bin2hex(random_bytes(2)));
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p13b');
    }
}
