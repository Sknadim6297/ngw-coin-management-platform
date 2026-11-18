<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MiningBlockedException;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Closure;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 13A — guarded runner behind `php spark mining:post`.
 *
 * Wraps MiningDailyEngineService::postDay() with:
 *   - database classification + isolation (live / allowlisted sandbox / PHPUnit DB only)
 *   - kill-switches (miningPostingEnabled, miningAutoPostEnabled for scheduled runs)
 *   - explicit confirmation phrases
 *   - per-business-date job lock (ops_job_locks)
 *   - bounded retry for transient failures (credits are idempotent, so retry is safe)
 *   - persisted run history (mining_daily_runs, run_type = post) + file/CI4 logging
 *   - post-run reconciliation (expected vs posted)
 *
 * It never writes wallet rows itself; every credit goes through MiningWalletService
 * via MiningDailyEngineService::postDay().
 */
class MiningPostingRunnerService
{
    public const CONFIRM_STAGING = 'APPLY_STAGING';

    public const LOCK_PREFIX = 'mining_post:';

    /** dryRun() hard-caps member scans at this value. A larger population must fail closed. */
    public const MAX_MEMBERS = 2000;

    public const RUN_TYPE = 'post';

    /** @var list<string> */
    private const TRANSIENT_MARKERS = [
        'deadlock',
        'lock wait timeout',
        'try restarting transaction',
        'server has gone away',
        'lost connection',
        'too many connections',
        'connection refused',
        'unable to connect',
    ];

    /** @var list<string> Platform policy flags that must read "approved" before LIVE posting. */
    private const POLICY_STATUS_FIELDS = [
        'miningMonthlyPercentStatus',
        'miningDayDivisorStatus',
        'miningDenominationStatus',
        'miningApprovalDayPolicyStatus',
        'miningLockedCoinEligibilityStatus',
        'miningPostMaturityEligibilityStatus',
        'miningPostSaleEligibilityStatus',
        'miningRoundingPolicyStatus',
    ];

    public function __construct(
        private readonly ?MiningDailyEngineService $engine = null,
        private readonly ?OpsJobLockService $locks = null,
        private readonly ?Platform $config = null,
        private readonly ?Closure $sleeper = null,
        private readonly ?string $logFile = null,
    ) {
    }

    /**
     * Resolve the business date. Accepts today | yesterday | YYYY-MM-DD (never in the future).
     */
    public function resolveDate(?string $input): string
    {
        $cfg = $this->config ?? config('Platform');
        $tz  = new \DateTimeZone((string) ($cfg->miningBusinessTimezone ?: 'Asia/Kolkata'));
        $now = new \DateTimeImmutable('now', $tz);

        $in = strtolower(trim((string) $input));
        if ($in === '' || $in === 'yesterday') {
            return $now->modify('-1 day')->format('Y-m-d');
        }
        if ($in === 'today') {
            return $now->format('Y-m-d');
        }

        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $in, $tz);
        if ($dt === false || $dt->format('Y-m-d') !== $in) {
            throw new InvalidArgumentException('--date must be today, yesterday or a valid YYYY-MM-DD.');
        }
        if ($in > $now->format('Y-m-d')) {
            throw new InvalidArgumentException('Refusing to post mining for a future business date (' . $in . ').');
        }

        return $in;
    }

    /**
     * Classify a database group. Unknown / non-allowlisted databases are refused.
     *
     * @return array{mode: string, database: string, group: string}
     */
    public function classify(string $group): array
    {
        $dbConfig = config('Database');
        if ($group === '' || ! property_exists($dbConfig, $group) || ! is_array($dbConfig->{$group})) {
            throw new RuntimeException('Unknown database group "' . $group . '".');
        }

        $info = service('financialSandboxService')->inspectConnection($group);
        $db   = $info['database'];
        if ($db === '') {
            throw new RuntimeException('Database group "' . $group . '" has no database name.');
        }

        if (ProductionSafetyGate::isLiveDatabase($db)) {
            return ['mode' => 'live', 'database' => $db, 'group' => $group];
        }
        if ($info['is_allowed']) {
            return ['mode' => 'sandbox', 'database' => $db, 'group' => $group];
        }
        if (ENVIRONMENT === 'testing' && $db === (string) ($dbConfig->tests['database'] ?? '')) {
            return ['mode' => 'testing', 'database' => $db, 'group' => $group];
        }

        throw new RuntimeException(
            'Refusing mining posting on database "' . $db . '": not the live DB, not an allowlisted '
            . 'sandbox DB (' . implode(', ', FinancialSandboxService::ALLOWED_DATABASES) . ') and not the PHPUnit DB.'
        );
    }

    /**
     * @param array{
     *   date?: ?string, limit?: int, group?: ?string, confirm?: ?string,
     *   allow_production?: bool, scheduled?: bool, execute?: bool, actor?: ?int
     * } $opts
     * @return array<string, mixed>
     */
    public function run(array $opts): array
    {
        $cfg       = $this->config ?? config('Platform');
        $group     = (string) ($opts['group'] ?? config('Database')->defaultGroup);
        $execute   = (bool) ($opts['execute'] ?? false);
        $scheduled = (bool) ($opts['scheduled'] ?? false);
        $allowProd = (bool) ($opts['allow_production'] ?? false);
        $confirm   = isset($opts['confirm']) ? (string) $opts['confirm'] : null;
        $actor     = (int) ($opts['actor'] ?? 0);
        $actor     = $actor > 0 ? $actor : null;
        $explicitLimit = isset($opts['limit']) && (int) $opts['limit'] > 0 ? (int) $opts['limit'] : null;
        $limit     = max(1, min(self::MAX_MEMBERS, $explicitLimit ?? self::MAX_MEMBERS)); // legacy engine path only
        $legacy    = $this->engine !== null;
        $batchSize = max(1, min(5000, (int) ($opts['batch_size'] ?? $cfg->miningPostBatchSize)));
        $maxBatches = isset($opts['max_batches']) && (int) $opts['max_batches'] > 0 ? (int) $opts['max_batches'] : null;
        $maxRuntime = isset($opts['max_runtime']) && (int) $opts['max_runtime'] > 0 ? (int) $opts['max_runtime'] : null;
        $allowIncompleteFlag = (bool) ($opts['allow_incomplete_day'] ?? false);
        $date      = $this->resolveDate($opts['date'] ?? null);
        $startedAt = microtime(true);

        $ctx = $this->classify($group);
        $this->assertActiveConnectionMatches($ctx);

        $result = [
            'business_date'  => $date,
            'database'       => $ctx['database'],
            'group'          => $ctx['group'],
            'mode'           => $ctx['mode'],
            'scheduled'      => $scheduled,
            'execute'        => $execute,
            'kill_switches'  => service('financialSandboxService')->productionSwitchSnapshot(),
        ];

        // ---------------------------------------------------------------- dry-run (read-only)
        if (! $execute) {
            if ($legacy) {
                $preview = $this->engine()->dryRun($date, $limit, false, $actor);
                $this->log('info', 'dry-run', $result + ['totals' => $preview['totals']]);

                return $result + [
                    'status'         => 'dry_run',
                    'rate_ref'       => $preview['rate']['rate_ref'] ?? null,
                    'member_count'   => $preview['totals']['member_count'],
                    'eligible_count' => $preview['totals']['eligible_count'],
                    'expected_total' => $preview['totals']['total_expected_amount'],
                    'posted_total'   => $preview['totals']['total_posted_amount'],
                    'message'        => 'DRY-RUN only — no wallet was credited.',
                ];
            }

            return $this->dryRunAsOf($result, $date, $batchSize, $startedAt);
        }

        // ---------------------------------------------------------------- authorization gates
        $sandbox      = service('financialSandboxService');
        $openedWindow = false;

        try {
            $skipReason = $this->authorize($ctx, $scheduled, $allowProd, $confirm);
        } catch (Throwable $e) {
            $this->log('error', 'authorization-refused', $result + ['error' => $e->getMessage()]);

            throw $e;
        }
        if ($skipReason !== null) {
            return $this->skipped($result, 'skipped_disabled', $skipReason);
        }

        $owner = (gethostname() ?: 'host') . '-' . getmypid();
        $lock  = self::LOCK_PREFIX . $date;
        $locks = $this->locks ?? new OpsJobLockService();
        $runRef = null;

        try {
            if ($ctx['mode'] === 'sandbox') {
                $sandbox->openWindow(300, $group);
                $openedWindow = true;
            }

            $this->assertSchema(! $legacy);

            $rate = (new MiningRateService())->approvedForDate($date);
            if ($rate === null) {
                throw new MiningBlockedException('No approved mining rate for ' . substr($date, 0, 7) . '. Nothing posted.');
            }

            $policy = null;
            if (! $legacy) {
                try {
                    $policy = MiningCalculationPolicy::fromPlatform($cfg);
                } catch (InvalidArgumentException $e) {
                    throw new MiningBlockedException('Invalid mining calculation policy: ' . $e->getMessage());
                }
                $result['policy_version'] = $policy->version();
            }

            $memberCount = 0;
            if ($legacy) {
                $memberCount = (int) (new MemberProfileModel())->excludeRootSponsors()->countAllResults();
                if ($memberCount > $limit) {
                    throw new RuntimeException(
                        'Member population (' . $memberCount . ') exceeds the per-run cap (' . $limit
                        . '). Refusing a partial run; paginate the engine before enabling.'
                    );
                }
            }

            if (! $legacy && $explicitLimit !== null) {
                $population = (new MiningBatchPostingService(null, null, null, $cfg, $this->sleeper))->populationCount();
                if ($population > $explicitLimit) {
                    throw new MiningBlockedException(sprintf('Member population (%d) exceeds the explicit --limit (%d). Refusing a partial run.', $population, $explicitLimit));
                }
            }

            // Phase 13C: the lock is not renewed while a run is in progress, so its TTL must outlive the longest allowed
            // invocation (runtime budget + 5 min margin); otherwise a second invocation could take over a live run's lock.
            $runtimeBudget = max(1, (int) ($opts['max_runtime'] ?? $cfg->miningPostMaxRuntimeSeconds));
            $lockTtl       = max(60, (int) $cfg->miningPostLockTtlSeconds, $runtimeBudget + 300);
            if (! $locks->acquire($lock, $owner, $lockTtl)) {
                return $this->skipped($result, 'skipped_locked', 'Another mining:post run holds ' . $lock . '.');
            }

            try {
                $this->abandonStaleRuns($date);
                $runRef = $this->openRun($date, $rate, $ctx, $scheduled, $actor, $policy);
                $this->log('info', 'run-start', $result + ['run_ref' => $runRef, 'rate_ref' => $rate['rate_ref']]);

                if (! $legacy) {
                    return $this->executeBatched(
                        $result, $rate, $policy, $runRef, $date, $ctx, $explicitLimit, $batchSize, $maxBatches, $maxRuntime,
                        $actor, $allowIncompleteFlag, $startedAt
                    );
                }

                $outcome = $this->postWithRetry($date, $limit, $actor);
                $final   = $this->verify($date, $limit);

                $status = 'completed';
                if ($outcome['errors'] !== []) {
                    $status = 'completed_with_errors';
                } elseif ($final['mismatches'] !== [] || ! $final['totals_match']) {
                    $status = 'completed_with_mismatch';
                }

                $out = $result + [
                    'status'         => $status,
                    'run_ref'        => $runRef,
                    'rate_ref'       => $rate['rate_ref'],
                    'attempts'       => $outcome['attempts'],
                    'member_count'   => $memberCount,
                    'eligible_count' => $final['eligible_count'],
                    'credited'       => $outcome['credited'],
                    'skipped'        => $outcome['skipped'],
                    'duplicates'     => $outcome['duplicates'],
                    'errors'         => $outcome['errors'],
                    'expected_total' => $final['expected_total'],
                    'posted_total'   => $final['posted_total'],
                    'mismatches'     => $final['mismatches'],
                    'message'        => $status === 'completed'
                        ? 'Mining posted and reconciled.'
                        : 'Run finished with issues — review errors / mismatches.',
                ];

                $this->closeRun($runRef, $status, $out);
                $this->audit('mining.post_run', $runRef, [
                    'business_date' => $date,
                    'status'        => $status,
                    'credited'      => $outcome['credited'],
                    'duplicates'    => $outcome['duplicates'],
                    'database'      => $ctx['database'],
                ]);
                $this->log($status === 'completed' ? 'info' : 'error', 'run-finished', $out);

                return $out;
            } catch (MiningBlockedException $e) {
                if ($runRef !== null) {
                    $this->closeRun($runRef, 'blocked', $result + ['error' => $e->getMessage()]);
                }

                throw $e;
            } catch (Throwable $e) {
                if ($runRef !== null) {
                    $this->closeRun($runRef, 'failed', $result + ['error' => $e->getMessage()]);
                }
                $this->log('error', 'run-failed', $result + ['run_ref' => $runRef, 'error' => $e->getMessage()]);

                return $result + [
                    'status'  => 'failed',
                    'run_ref' => $runRef,
                    'error'   => $e->getMessage(),
                    'message' => 'Run failed: ' . $e->getMessage(),
                ];
            } finally {
                $locks->release($lock, $owner);
            }
        } catch (Throwable $e) {
            // Pre-run refusals (no rate, population cap, schema, sandbox window) — nothing was posted.
            $this->log('error', 'run-refused', $result + ['error' => $e->getMessage()]);

            throw $e;
        } finally {
            if ($openedWindow) {
                $sandbox->closeWindow();
            }
        }
    }

    /**
     * Authorization gates for a posting (non dry-run) request. Touches no database.
     *
     * @param array{mode: string, database: string, group: string} $ctx
     * @return string|null Skip reason for a scheduled run that is simply not authorized (exit-0 semantics);
     *                     null when posting may proceed. Throws RuntimeException when refused.
     */
    public function authorize(array $ctx, bool $scheduled, bool $allowProduction, ?string $confirm): ?string
    {
        $cfg = $this->config ?? config('Platform');

        if ($ctx['mode'] === 'sandbox') {
            if ($confirm !== self::CONFIRM_STAGING) {
                if ($scheduled) {
                    return 'Scheduled posting not authorized (missing --confirm ' . self::CONFIRM_STAGING . ').';
                }

                throw new RuntimeException('Sandbox posting requires --confirm ' . self::CONFIRM_STAGING);
            }

            return null;
        }

        // live + testing (PHPUnit DB): the real kill-switches apply.
        if ($scheduled && ! (bool) $cfg->miningAutoPostEnabled) {
            return 'miningAutoPostEnabled=false — automatic mining posting is disabled.';
        }
        if (! (bool) $cfg->miningPostingEnabled) {
            throw new RuntimeException('Mining posting kill-switch is OFF (miningPostingEnabled=false). No credit performed.');
        }
        if ($ctx['mode'] === 'live') {
            (new ProductionSafetyGate())->assertMutationAllowed($allowProduction, $confirm, $ctx['group'], 'mining posting');
            $this->assertPoliciesApproved($cfg);
        }

        return null;
    }

    public function isTransient(Throwable $e): bool
    {
        if ($e instanceof InvalidArgumentException) {
            return false;
        }

        return $this->isTransientMessage($e->getMessage());
    }

    private function isTransientMessage(string $message): bool
    {
        $m = strtolower($message);
        foreach (self::TRANSIENT_MARKERS as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Structured scheduler-exit log line (written by the Spark command after the run).
     *
     * @param array<string, mixed> $result
     */
    public function logCommandExit(array $result, string $schedulerStatus, int $exitCode, ?string $error = null): void
    {
        $this->log($exitCode === 0 || in_array($exitCode, [10, 20], true) ? 'info' : 'error', 'command-exit', [
            'run_id'           => $result['run_ref'] ?? null,
            'day_run_id'       => $result['day_run_id'] ?? null,
            'business_date'    => $result['business_date'] ?? null,
            'database_class'   => $result['mode'] ?? null,
            'database'         => $result['database'] ?? null,
            'run_mode'         => ! empty($result['scheduled']) ? 'scheduled' : 'manual',
            'execute'          => $result['execute'] ?? null,
            'policy_version'   => $result['policy_version'] ?? null,
            'members'          => $result['member_count'] ?? null,
            'batches'          => $result['batches_done'] ?? null,
            'credited'         => $result['credited'] ?? null,
            'skipped'          => $result['skipped'] ?? null,
            'failed'           => $result['failed'] ?? null,
            'duplicates'       => $result['duplicates'] ?? null,
            'total_credited'   => $result['total_credited'] ?? null,
            'duration_ms'      => $result['duration_ms'] ?? null,
            'reconcile_status' => $result['reconcile_status'] ?? null,
            'scheduler_status' => $schedulerStatus,
            'exit_code'        => $exitCode,
            'error'            => $error,
        ]);
    }

    /**
     * Read-only as-of preview (no ledger / wallet / run-table writes).
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dryRunAsOf(array $result, string $date, int $batchSize, float $startedAt): array
    {
        $cfg  = $this->config ?? config('Platform');
        $rate = (new MiningRateService())->approvedForDate($date);
        if ($rate === null) {
            $out = $result + [
                'status' => 'dry_run', 'rate_ref' => null, 'member_count' => 0, 'eligible_count' => 0,
                'expected_total' => '0.00000000', 'posted_total' => '0.00000000',
                'message' => 'DRY-RUN only — no approved mining rate for ' . substr($date, 0, 7) . '; a real run would be BLOCKED.',
            ];
            $this->log('info', 'dry-run', $out);

            return $out;
        }

        try {
            $policy = MiningCalculationPolicy::fromPlatform($cfg);
        } catch (InvalidArgumentException $e) {
            throw new MiningBlockedException('Invalid mining calculation policy: ' . $e->getMessage());
        }

        $batch   = new MiningBatchPostingService(null, null, null, $cfg, $this->sleeper);
        $preview = $batch->preview($date, $rate, $policy, $batchSize, true);
        $out     = $result + [
            'status'         => 'dry_run',
            'rate_ref'       => $rate['rate_ref'],
            'policy_version' => $policy->version(),
            'cutoff_utc'     => $preview['cutoff_utc'],
            'member_count'   => $preview['member_count'],
            'eligible_count' => $preview['eligible_count'],
            'blocked_count'  => $preview['blocked_count'],
            'expected_total' => $preview['expected_total'],
            'posted_total'   => $preview['posted_total'],
            'day_run_state'  => $preview['day_run_state'],
            'duration_ms'    => (int) round((microtime(true) - $startedAt) * 1000),
            'message'        => 'DRY-RUN only — no wallet was credited.',
        ];
        $this->log('info', 'dry-run', $out);

        return $out;
    }

    /**
     * Phase 13B posting path: frozen population, keyset batches, durable checkpoints.
     *
     * @param array<string, mixed> $result
     * @param array<string, mixed> $rate
     * @param array{mode: string, database: string, group: string} $ctx
     * @return array<string, mixed>
     */
    private function executeBatched(
        array $result,
        array $rate,
        MiningCalculationPolicy $policy,
        string $runRef,
        string $date,
        array $ctx,
        ?int $explicitLimit,
        int $batchSize,
        ?int $maxBatches,
        ?int $maxRuntime,
        ?int $actor,
        bool $allowIncompleteFlag,
        float $startedAt
    ): array {
        $cfg = $this->config ?? config('Platform');
        // Live DB never snapshots a partial business day; the PHPUnit DB may; sandbox needs the explicit flag.
        $allowIncomplete = $ctx['mode'] === 'testing' || ($ctx['mode'] === 'sandbox' && $allowIncompleteFlag);

        $batch   = new MiningBatchPostingService(null, null, null, $cfg, $this->sleeper);
        $dayRun  = $batch->prepare($date, $rate, $policy, $batchSize, $allowIncomplete, $explicitLimit);
        $this->log('info', 'day-run-prepared', $result + [
            'run_ref' => $runRef, 'day_run_id' => (int) $dayRun['id'], 'population' => (int) $dayRun['population_count'],
            'cutoff_utc' => $dayRun['cutoff_utc'], 'incomplete_day' => (bool) $dayRun['incomplete_day'],
        ]);

        try {
            $s = $batch->process($dayRun, $rate, [
                'batch_size' => $batchSize, 'max_batches' => $maxBatches, 'max_runtime' => $maxRuntime, 'actor' => $actor,
            ]);
        } catch (Throwable $e) {
            db_connect()->table('mining_post_day_runs')->where('id', (int) $dayRun['id'])->update([
                'state' => MiningBatchPostingService::STATE_FAILED, 'last_error' => substr($e->getMessage(), 0, 500), 'updated_at' => date('Y-m-d H:i:s'),
            ]);

            throw $e;
        }

        $status = $s['state'] === MiningBatchPostingService::STATE_COMPLETED ? 'completed' : 'partial';
        $out    = $result + [
            'status'           => $status,
            'state'            => $s['state'],
            'run_ref'          => $runRef,
            'day_run_id'       => (int) $dayRun['id'],
            'rate_ref'         => $rate['rate_ref'],
            'policy_version'   => $s['policy_version'],
            'cutoff_utc'       => $s['cutoff_utc'],
            'incomplete_day'   => $s['incomplete_day'],
            'attempts'         => $s['passes'],
            'retry_passes'     => $s['retry_passes'],
            'member_count'     => $s['population'],
            'eligible_count'   => $s['eligible_count'],
            'credited'         => $s['credited_now'],
            'credited_total'   => $s['credited'],
            'skipped'          => $s['skipped'],
            'blocked'          => $s['blocked'],
            'failed'           => $s['failed'],
            'pending'          => $s['pending'],
            'duplicates'       => max(0, $s['credited'] - $s['credited_now']),
            'duplicates_adopted' => $s['duplicates'],
            'errors'           => $s['errors'],
            'total_credited'   => $s['total_credited'],
            'expected_total'   => $s['expected_total'],
            'posted_total'     => $s['posted_total'],
            'mismatches'       => $s['mismatches'],
            'reconcile_status' => $s['reconcile_status'],
            'batch_size'       => $batchSize,
            'batches_run'      => $s['batches_run'],
            'batches_done'     => $s['batches_done'],
            'cursor'           => $s['cursor'],
            'interrupted'      => $s['interrupted'],
            'reason'           => $s['reason'],
            'queries'          => $s['queries'],
            'duration_ms'      => (int) round((microtime(true) - $startedAt) * 1000),
            'peak_memory_mb'   => round(memory_get_peak_usage(true) / 1048576, 1),
            'message'          => $status === 'completed'
                ? 'Mining posted and reconciled.'
                : 'Run PARTIAL — resume with the same command; unresolved members: ' . ($s['reason'] ?? 'see counts'),
        ];

        $this->closeRun($runRef, $status, $out);
        $this->audit('mining.post_run', $runRef, [
            'business_date' => $date, 'status' => $status, 'state' => $s['state'], 'credited' => $s['credited_now'],
            'policy_version' => $s['policy_version'], 'database' => $ctx['database'],
        ]);
        $this->log($status === 'completed' ? 'info' : 'error', 'run-finished', $out);

        return $out;
    }

    /**
     * @return array{attempts: int, credited: int, skipped: int, duplicates: int, errors: list<array<string, mixed>>}
     */
    private function postWithRetry(string $date, int $limit, ?int $actor): array
    {
        $cfg      = $this->config ?? config('Platform');
        $max      = max(1, (int) $cfg->miningPostMaxAttempts);
        $delay    = max(0, (int) $cfg->miningPostRetryDelaySeconds);
        $attempts = 0;
        $credited = 0;
        $skipped  = 0;
        $dupes    = 0;
        $errors   = [];

        while ($attempts < $max) {
            $attempts++;
            try {
                $r = $this->engine()->postDay($date, $limit, $actor, true);
            } catch (Throwable $e) {
                if ($this->isTransient($e) && $attempts < $max) {
                    $this->log('warning', 'retry-after-exception', [
                        'business_date' => $date, 'attempt' => $attempts, 'error' => $e->getMessage(),
                    ]);
                    $this->pause($delay * $attempts);

                    continue;
                }

                throw $e;
            }

            // Credits are idempotent: only the first successful pass can credit; later passes report duplicates.
            $credited += (int) $r['credited'];
            $skipped   = (int) $r['skipped'];
            $dupes     = (int) $r['duplicates'];
            $errors    = $r['errors'] ?? [];

            if ($errors === []) {
                break;
            }

            $retryable = array_filter($errors, fn (array $err): bool => $this->isTransientMessage((string) ($err['message'] ?? '')));
            if ($retryable === [] || $attempts >= $max) {
                break;
            }

            $this->log('warning', 'retry-after-member-errors', [
                'business_date' => $date, 'attempt' => $attempts, 'error_count' => count($errors),
            ]);
            $this->pause($delay * $attempts);
        }

        return [
            'attempts'   => $attempts,
            'credited'   => $credited,
            'skipped'    => $skipped,
            'duplicates' => $dupes,
            'errors'     => $errors,
        ];
    }

    /**
     * @return array{eligible_count: int, expected_total: string, posted_total: string, totals_match: bool, mismatches: list<array<string, mixed>>}
     */
    private function verify(string $date, int $limit): array
    {
        $engine  = $this->engine();
        $preview = $engine->dryRun($date, $limit, false);
        $recon   = $engine->reconcile($date, $limit);

        $expected = (string) $preview['totals']['total_expected_amount'];
        $posted   = (string) $preview['totals']['total_posted_amount'];

        return [
            'eligible_count' => (int) $preview['totals']['eligible_count'],
            'expected_total' => $expected,
            'posted_total'   => $posted,
            'totals_match'   => MoneyMath::compare($expected, $posted, 8) === 0,
            'mismatches'     => $recon['mismatches'],
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function skipped(array $result, string $status, string $message): array
    {
        $out = $result + ['status' => $status, 'message' => $message];
        $this->log('warning', $status, $out);

        return $out;
    }

    private function assertActiveConnectionMatches(array $ctx): void
    {
        $active = (string) db_connect()->getDatabase();
        if ($active !== $ctx['database']) {
            throw new RuntimeException(
                'Isolation check failed: active connection is "' . $active . '" but target group "'
                . $ctx['group'] . '" is "' . $ctx['database'] . '". Switch the default group first.'
            );
        }
    }

    private function assertPoliciesApproved(Platform $cfg): void
    {
        $pending = [];
        foreach (self::POLICY_STATUS_FIELDS as $field) {
            if (strtolower((string) ($cfg->{$field} ?? '')) !== 'approved') {
                $pending[] = $field . '=' . (string) ($cfg->{$field} ?? 'unset');
            }
        }
        if ($pending !== []) {
            throw new RuntimeException('Live mining posting blocked: business policies not approved: ' . implode(', ', $pending));
        }
    }

    private function assertSchema(bool $batched = false): void
    {
        $db     = db_connect();
        $tables = ['mining_wallets', 'mining_wallet_ledger', 'mining_daily_runs', 'mining_rate_configs', 'ops_job_locks'];
        if ($batched) {
            $tables = array_merge($tables, ['mining_post_day_runs', 'mining_post_members', 'member_coin_ownership_transactions', 'member_coin_balances', 'coin_purchase_requests']);
        }
        foreach ($tables as $table) {
            if (! $db->tableExists($table)) {
                throw new RuntimeException('Required table missing on ' . $db->getDatabase() . ': ' . $table);
            }
        }
    }

    private function abandonStaleRuns(string $date): void
    {
        // We hold the job lock, so any run still "running" for this date is a crashed predecessor.
        db_connect()->table('mining_daily_runs')
            ->where('run_type', self::RUN_TYPE)
            ->where('business_date', $date)
            ->where('status', 'running')
            ->update(['status' => 'abandoned', 'finished_at' => date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed> $rate
     * @param array{mode: string, database: string, group: string} $ctx
     */
    private function openRun(string $date, array $rate, array $ctx, bool $scheduled, ?int $actor, ?MiningCalculationPolicy $policy = null): string
    {
        $runRef = 'MPR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        db_connect()->table('mining_daily_runs')->insert([
            'run_ref'          => $runRef,
            'business_date'    => $date,
            'run_type'         => self::RUN_TYPE,
            'status'           => 'running',
            'rate_ref'         => $rate['rate_ref'] ?? null,
            'assumptions_json' => json_encode([
                'mode'      => $ctx['mode'],
                'database'  => $ctx['database'],
                'scheduled' => $scheduled,
                'host'      => gethostname() ?: null,
                'pid'       => getmypid(),
                'policy_version' => $policy?->version(),
                'policy'         => $policy?->toArray(),
            ], JSON_UNESCAPED_SLASHES),
            'started_at'       => date('Y-m-d H:i:s'),
            'created_by'       => $actor,
        ]);

        return $runRef;
    }

    /**
     * @param array<string, mixed> $out
     */
    private function closeRun(string $runRef, string $status, array $out): void
    {
        $summary = null;
        if (! empty($out['errors'])) {
            $summary = 'errors=' . count($out['errors']) . ' first=' . substr((string) ($out['errors'][0]['message'] ?? ''), 0, 200);
        } elseif (! empty($out['mismatches'])) {
            $summary = 'mismatches=' . count($out['mismatches']);
        } elseif (! empty($out['error'])) {
            $summary = substr((string) $out['error'], 0, 500);
        } elseif (! empty($out['reason'])) {
            $summary = substr((string) $out['reason'], 0, 500);
        }

        db_connect()->table('mining_daily_runs')->where('run_ref', $runRef)->update([
            'status'                => substr($status, 0, 24),
            'member_count'          => (int) ($out['member_count'] ?? 0),
            'eligible_count'        => (int) ($out['eligible_count'] ?? 0),
            'total_expected_amount' => MoneyMath::normalize((string) ($out['expected_total'] ?? '0'), 8),
            'total_posted_amount'   => MoneyMath::normalize((string) ($out['posted_total'] ?? '0'), 8),
            'error_summary'         => $summary !== null ? substr($summary, 0, 500) : null,
            'finished_at'           => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function audit(string $action, string $ref, array $payload): void
    {
        try {
            (new AuditLogger())->log($action, 0, 'mining_daily_run', $ref, $payload);
        } catch (Throwable $e) {
            $this->log('error', 'audit-failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Never write credentials / secrets into logs.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        foreach ($context as $k => $v) {
            if (preg_match('/pass(word|wd)?|secret|private|token|credential|api[_-]?key|dsn/i', (string) $k)) {
                $context[$k] = '[redacted]';
            } elseif (is_array($v)) {
                $context[$k] = $this->redact($v);
            } elseif (is_string($v) && preg_match('/(password|pwd|secret)=\S+/i', $v)) {
                $context[$k] = preg_replace('/((?:password|pwd|secret)=)\S+/i', '$1[redacted]', $v);
            }
        }

        return $context;
    }

    private function engine(): MiningDailyEngineService
    {
        // Built lazily so its models bind to the DB group active *after* the command switched it.
        return $this->engine ?? new MiningDailyEngineService();
    }

    private function pause(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }
        sleep($seconds);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $event, array $context = []): void
    {
        unset($context['kill_switches']);
        $context = $this->redact($context);
        $line = json_encode(
            ['ts' => date('c'), 'level' => $level, 'event' => $event] + $context,
            JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        log_message($level, '[mining:post] ' . $line);

        $file = $this->logFile ?? WRITEPATH . 'logs/mining-post.log';
        $dir  = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
