<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MiningBlockedException;
use App\Libraries\MiningCalculationPolicy;
use Closure;
use CodeIgniter\Events\Events;
use Config\Platform;
use RuntimeException;
use Throwable;

/**
 * Phase 13B — resumable, keyset-paginated daily mining posting.
 *
 * Design:
 *  1. prepare(): one `mining_post_day_runs` row per business date freezes the policy version, the rate,
 *     the snapshot cutoff and the member POPULATION (rows in `mining_post_members`, keyset-inserted).
 *     A resumed run whose policy or rate differs fails closed (MiningBlockedException).
 *  2. process(): walks PENDING / FAILED / BLOCKED members in `member_user_id` order using
 *     `WHERE member_user_id > :cursor ... LIMIT :batch` (no OFFSET, bounded memory). Every member's
 *     outcome is committed to its own row, so the durable checkpoint is exact: a crash, kill, timeout or DB
 *     loss re-processes only members that are not CREDITED/SKIPPED. Credits keep the unique idempotency key
 *     `mining-daily:{user_id}:{business_date}`; an existing ledger row is adopted (duplicate skip), never re-credited.
 *  3. finalize(): SQL reconciliation (ledger vs run rows, duplicates, wallet totals) plus an as-of recompute
 *     of every active member. Only an all-resolved, zero-mismatch run is marked COMPLETED.
 *
 * Writes only through MiningWalletService::creditDailyMining (kill-switch + sandbox guards intact) and the
 * two Phase 13B bookkeeping tables.
 */
class MiningBatchPostingService
{
    public const STATE_PENDING   = 'PENDING';
    public const STATE_RUNNING   = 'RUNNING';
    public const STATE_PARTIAL   = 'PARTIAL';
    public const STATE_COMPLETED = 'COMPLETED';
    public const STATE_FAILED    = 'FAILED';

    /** @var list<string> */
    private const TRANSIENT_MARKERS = [
        'deadlock', 'lock wait timeout', 'try restarting transaction', 'server has gone away',
        'lost connection', 'too many connections', 'connection refused', 'unable to connect',
    ];

    private const FREEZE_CHUNK = 1000;

    private static int $queryCount = 0;

    private static bool $queryHooked = false;

    private ?MiningWalletService $wallets;

    private MiningEligibleOwnershipSnapshotService $snapshots;

    private MiningRateService $rates;

    public function __construct(
        ?MiningEligibleOwnershipSnapshotService $snapshots = null,
        ?MiningWalletService $wallets = null,
        ?MiningRateService $rates = null,
        private readonly ?Platform $config = null,
        private readonly ?Closure $sleeper = null,
        private readonly ?Closure $clock = null,
    ) {
        $this->snapshots = $snapshots ?? new MiningEligibleOwnershipSnapshotService($config);
        $this->wallets   = $wallets;
        $this->rates     = $rates ?? new MiningRateService();

        if (! self::$queryHooked) {
            self::$queryHooked = true;
            Events::on('DBQuery', static function (): void {
                self::$queryCount++;
            });
        }
    }

    public static function isTransientMessage(string $message): bool
    {
        $m = strtolower($message);
        foreach (self::TRANSIENT_MARKERS as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    public static function queryCount(): int
    {
        return self::$queryCount;
    }

    public static function idempotencyKey(int $memberUserId, string $businessDate): string
    {
        return 'mining-daily:' . $memberUserId . ':' . $businessDate;
    }

    /**
     * Create (or load and validate) the day run and make sure its population is frozen.
     *
     * @param array<string, mixed> $rate
     * @return array<string, mixed> day-run row
     */
    public function prepare(string $date, array $rate, MiningCalculationPolicy $policy, int $batchSize, bool $allowIncompleteDay, ?int $memberCap = null): array
    {
        $db  = db_connect();
        $row = $db->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray();

        if ($row !== null) {
            if ((string) $row['policy_version'] !== $policy->version()) {
                throw new MiningBlockedException(sprintf(
                    'Policy changed since the %s run started (%s -> %s). Refusing to mix calculations; resolve explicitly.',
                    $date,
                    $row['policy_version'],
                    $policy->version()
                ));
            }
            if ((string) $row['rate_ref'] !== (string) $rate['rate_ref']) {
                throw new MiningBlockedException(sprintf(
                    'Approved rate changed since the %s run started (%s -> %s). Refusing to mix calculations.',
                    $date,
                    $row['rate_ref'],
                    $rate['rate_ref']
                ));
            }
        } else {
            $cut = $this->snapshots->cutoff($date, $policy, $this->now(), $allowIncompleteDay);

            $maxUser = (int) ($db->query(
                'SELECT COALESCE(MAX(user_id), 0) AS m FROM member_profiles WHERE ' . $this->populationWhere()['sql'],
                $this->populationWhere()['bind']
            )->getRowArray()['m'] ?? 0);

            $now = date('Y-m-d H:i:s');
            try {
                $db->table('mining_post_day_runs')->insert([
                    'business_date'  => $date,
                    'state'          => self::STATE_PENDING,
                    'policy_version' => $policy->version(),
                    'policy_json'    => $policy->json(),
                    'rate_ref'       => (string) $rate['rate_ref'],
                    'cutoff_utc'     => $cut['cutoff_utc'],
                    'incomplete_day' => $cut['incomplete_day'] ? 1 : 0,
                    'max_user_id'    => $maxUser,
                    'batch_size'     => $batchSize,
                    'created_at'     => $now,
                    'updated_at'     => $now,
                ]);
            } catch (Throwable $e) {
                if (! str_contains(strtolower($e->getMessage()), 'duplicate')) {
                    throw $e;
                }
            }
            $row = $db->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray();
        }

        if ($row === null) {
            throw new RuntimeException('Unable to create or load the mining day run for ' . $date . '.');
        }

        if ((int) $row['population_frozen'] !== 1) {
            $this->freezePopulation($row, $memberCap);
            $row = $db->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray();
        }

        return $row;
    }

    /**
     * Read-only per-member preview using the same as-of logic (nothing is written).
     *
     * @param array<string, mixed> $rate
     * @return array<string, mixed>
     */
    public function preview(string $date, array $rate, MiningCalculationPolicy $policy, int $batchSize, bool $allowIncompleteDay): array
    {
        $db      = db_connect();
        $existing = $db->tableExists('mining_post_day_runs')
            ? $db->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray()
            : null;
        $cutInfo = $existing !== null
            ? ['cutoff_utc' => (string) $existing['cutoff_utc'], 'incomplete_day' => (bool) $existing['incomplete_day']]
            : $this->snapshots->cutoff($date, $policy, $this->now(), $allowIncompleteDay);
        $cutoff  = $cutInfo['cutoff_utc'];

        $where   = $this->populationWhere();
        $cursor  = 0;
        $members = 0;
        $eligible = 0;
        $blocked = 0;
        $expectedTotal = '0.00000000';
        $eligibleCoins = '0.00000000';

        while (true) {
            $chunk = $db->query(
                'SELECT user_id, account_status FROM member_profiles WHERE ' . $where['sql'] . ' AND user_id > ? ORDER BY user_id ASC LIMIT ' . max(1, $batchSize),
                array_merge($where['bind'], [$cursor])
            )->getResultArray();
            if ($chunk === []) {
                break;
            }
            $cursor = (int) $chunk[count($chunk) - 1]['user_id'];
            $active = [];
            foreach ($chunk as $c) {
                $members++;
                if ((string) $c['account_status'] === 'active') {
                    $active[] = (int) $c['user_id'];
                }
            }
            foreach ($this->snapshots->snapshotBatch($active, $cutoff, $policy) as $snap) {
                if ($snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED) {
                    $blocked++;

                    continue;
                }
                if ($snap['status'] !== MiningEligibleOwnershipSnapshotService::STATUS_ELIGIBLE) {
                    continue;
                }
                $amount = $this->rates->previewAmount($snap['eligible'], $rate, $date, $policy->divisorMode, $policy->rounding);
                if (bccomp($amount, '0', 8) > 0) {
                    $eligible++;
                    $expectedTotal = bcadd($expectedTotal, $amount, 8);
                    $eligibleCoins = bcadd($eligibleCoins, $snap['eligible'], 8);
                }
            }
        }

        $posted = $db->query(
            "SELECT COALESCE(SUM(amount), 0) AS s FROM mining_wallet_ledger WHERE business_date = ? AND entry_type = 'daily_mining_credit' AND direction = 'credit'",
            [$date]
        )->getRowArray();

        return [
            'cutoff_utc'     => $cutoff,
            'incomplete_day' => $cutInfo['incomplete_day'],
            'member_count'   => $members,
            'eligible_count' => $eligible,
            'blocked_count'  => $blocked,
            'eligible_coins' => $eligibleCoins,
            'expected_total' => $expectedTotal,
            'posted_total'   => bcadd((string) ($posted['s'] ?? '0'), '0', 8),
            'day_run_state'  => $existing['state'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $dayRun
     * @param array<string, mixed> $rate
     * @param array{batch_size?: int, max_batches?: ?int, max_runtime?: ?int, actor?: ?int} $opts
     * @return array<string, mixed>
     */
    public function process(array $dayRun, array $rate, array $opts = []): array
    {
        $cfg        = $this->config ?? config('Platform');
        $db         = db_connect();
        $date       = (string) $dayRun['business_date'];
        $policy     = MiningCalculationPolicy::fromArray(json_decode((string) $dayRun['policy_json'], true, 512, JSON_THROW_ON_ERROR));
        $batchSize  = max(1, (int) ($opts['batch_size'] ?? $dayRun['batch_size']));
        $maxBatches = isset($opts['max_batches']) ? max(1, (int) $opts['max_batches']) : null;
        $runtime    = max(1, (int) ($opts['max_runtime'] ?? $cfg->miningPostMaxRuntimeSeconds));
        $actor      = $opts['actor'] ?? null;
        $maxAttempts = max(1, (int) $cfg->miningPostMaxAttempts);
        $delay       = max(0, (int) $cfg->miningPostRetryDelaySeconds);
        $deadline    = microtime(true) + $runtime;
        $queriesAtStart = self::$queryCount;

        $stats = [
            'credited_now' => 0, 'duplicates_now' => 0, 'batches_run' => 0, 'passes' => 0,
            'interrupted' => null, 'errors' => [], 'retry_passes' => 0,
        ];

        $now = date('Y-m-d H:i:s');
        $db->query(
            'UPDATE mining_post_day_runs SET state = ?, invocations = invocations + 1, batch_size = ?, started_at = COALESCE(started_at, ?), finished_at = NULL, updated_at = ? WHERE id = ?',
            [self::STATE_RUNNING, $batchSize, $now, $now, (int) $dayRun['id']]
        );

        for ($pass = 1; $pass <= $maxAttempts; $pass++) {
            $stats['passes'] = $pass;
            $statuses        = $pass === 1 ? "'PENDING','FAILED','BLOCKED'" : "'FAILED'";
            $cursor          = 0;

            while (true) {
                if ($maxBatches !== null && $stats['batches_run'] >= $maxBatches) {
                    $stats['interrupted'] = 'max_batches';
                    break 2;
                }
                if (microtime(true) >= $deadline) {
                    $stats['interrupted'] = 'timeout';
                    break 2;
                }

                $rows = $db->query(
                    'SELECT * FROM mining_post_members WHERE business_date = ? AND status IN (' . $statuses . ') AND member_user_id > ? ORDER BY member_user_id ASC LIMIT ' . $batchSize,
                    [$date, $cursor]
                )->getResultArray();
                if ($rows === []) {
                    break;
                }
                $cursor = (int) $rows[count($rows) - 1]['member_user_id'];

                if ($pass > 1) {
                    $rows = array_values(array_filter(
                        $rows,
                        static fn (array $r): bool => self::isTransientMessage((string) ($r['last_error'] ?? ''))
                    ));
                }
                if ($rows !== []) {
                    $this->processBatch($rows, $dayRun, $rate, $policy, $actor, $stats);
                }

                $stats['batches_run']++;
                $this->checkpoint((int) $dayRun['id'], $date, $cursor, $stats['batches_run']);
            }

            $retryable = (int) ($db->query(
                "SELECT COUNT(*) AS c FROM mining_post_members WHERE business_date = ? AND status = 'FAILED' AND ("
                . implode(' OR ', array_fill(0, count(self::TRANSIENT_MARKERS), 'LOWER(last_error) LIKE ?')) . ')',
                array_merge([$date], array_map(static fn (string $m): string => '%' . $m . '%', self::TRANSIENT_MARKERS))
            )->getRowArray()['c'] ?? 0);
            if ($retryable === 0 || $pass >= $maxAttempts) {
                break;
            }
            $stats['retry_passes']++;
            $this->pause($delay * $pass);
        }

        $fin = $this->finalize((int) $dayRun['id'], $date, $rate, $policy, $stats['interrupted']);

        return $fin + [
            'credited_now'   => $stats['credited_now'],
            'duplicates_now' => $stats['duplicates_now'],
            'batches_run'    => $stats['batches_run'],
            'passes'         => $stats['passes'],
            'retry_passes'   => $stats['retry_passes'],
            'interrupted'    => $stats['interrupted'],
            'errors'         => array_slice($stats['errors'], 0, 50),
            'queries'        => self::$queryCount - $queriesAtStart,
        ];
    }

    /**
     * SQL reconciliation + as-of recompute. Never mutates ledgers.
     *
     * @param array<string, mixed> $rate
     * @return array{mismatches: list<array<string, mixed>>, ok: bool}
     */
    public function verify(string $date, array $rate, MiningCalculationPolicy $policy, string $cutoffUtc, bool $recompute = true): array
    {
        $db = db_connect();
        $mismatches = [];

        $ledger = $db->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS s FROM mining_wallet_ledger
              WHERE business_date = ? AND entry_type = 'daily_mining_credit' AND direction = 'credit'",
            [$date]
        )->getRowArray();
        $rows = $db->query(
            "SELECT COUNT(*) AS c, COALESCE(SUM(credited_amount),0) AS s FROM mining_post_members WHERE business_date = ? AND status = 'CREDITED'",
            [$date]
        )->getRowArray();
        if ((int) $ledger['c'] !== (int) $rows['c'] || bccomp((string) $ledger['s'], (string) $rows['s'], 8) !== 0) {
            $mismatches[] = ['type' => 'ledger_vs_run_totals', 'ledger_count' => (int) $ledger['c'], 'run_count' => (int) $rows['c'], 'ledger_total' => (string) $ledger['s'], 'run_total' => (string) $rows['s']];
        }

        foreach ($db->query(
            "SELECT l.member_id, l.amount AS ledger_amount, m.status, m.credited_amount
               FROM mining_wallet_ledger l
               LEFT JOIN mining_post_members m ON m.business_date = l.business_date AND m.member_user_id = l.member_user_id
              WHERE l.business_date = ? AND l.entry_type = 'daily_mining_credit' AND l.direction = 'credit'
                AND (m.id IS NULL OR m.status <> 'CREDITED' OR m.credited_amount <> l.amount)
              LIMIT 20",
            [$date]
        )->getResultArray() as $r) {
            $mismatches[] = ['type' => 'ledger_credit_not_matched_by_run_row', 'member_id' => $r['member_id'], 'ledger_amount' => $r['ledger_amount'], 'run_status' => $r['status'], 'run_amount' => $r['credited_amount']];
        }

        foreach ($db->query(
            "SELECT m.member_user_id, m.credited_amount FROM mining_post_members m
               LEFT JOIN mining_wallet_ledger l ON l.business_date = m.business_date AND l.member_user_id = m.member_user_id
                    AND l.entry_type = 'daily_mining_credit' AND l.direction = 'credit'
              WHERE m.business_date = ? AND m.status = 'CREDITED' AND l.id IS NULL LIMIT 20",
            [$date]
        )->getResultArray() as $r) {
            $mismatches[] = ['type' => 'credited_row_without_ledger', 'member_user_id' => (int) $r['member_user_id'], 'amount' => $r['credited_amount']];
        }

        foreach ($db->query(
            "SELECT member_user_id, COUNT(*) AS c FROM mining_wallet_ledger
              WHERE business_date = ? AND entry_type = 'daily_mining_credit' GROUP BY member_user_id HAVING COUNT(*) > 1 LIMIT 20",
            [$date]
        )->getResultArray() as $r) {
            $mismatches[] = ['type' => 'duplicate_ledger_credit', 'member_user_id' => (int) $r['member_user_id'], 'count' => (int) $r['c']];
        }

        $walletBad = (int) ($db->query(
            "SELECT COUNT(*) AS c FROM mining_wallets w
               JOIN (SELECT member_user_id, SUM(amount) AS s FROM mining_wallet_ledger WHERE direction = 'credit' GROUP BY member_user_id) cr
                 ON cr.member_user_id = w.member_user_id
              WHERE w.total_credited <> cr.s OR w.available_balance <> w.total_credited - w.total_debited"
        )->getRowArray()['c'] ?? 0);
        if ($walletBad > 0) {
            $mismatches[] = ['type' => 'wallet_balance_not_equal_ledger_credits', 'wallets' => $walletBad];
        }

        if ($recompute) {
            $cursor = 0;
            $found  = 0;
            while (true) {
                $chunk = $db->query(
                    "SELECT member_user_id, member_id, status, credited_amount FROM mining_post_members
                      WHERE business_date = ? AND account_status = 'active' AND status IN ('CREDITED','SKIPPED') AND member_user_id > ?
                      ORDER BY member_user_id ASC LIMIT 500",
                    [$date, $cursor]
                )->getResultArray();
                if ($chunk === []) {
                    break;
                }
                $cursor = (int) $chunk[count($chunk) - 1]['member_user_id'];
                $snaps  = $this->snapshots->snapshotBatch(array_map(static fn (array $r): int => (int) $r['member_user_id'], $chunk), $cutoffUtc, $policy);
                foreach ($chunk as $r) {
                    $snap = $snaps[(int) $r['member_user_id']];
                    $expected = '0.00000000';
                    if ($snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_ELIGIBLE) {
                        $expected = $this->rates->previewAmount($snap['eligible'], $rate, $date, $policy->divisorMode, $policy->rounding);
                    }
                    $recorded = (string) $r['credited_amount'];
                    if ($snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED || bccomp($expected, $recorded, 8) !== 0) {
                        if (++$found <= 20) {
                            $mismatches[] = [
                                'type'      => 'historical_ownership_mismatch',
                                'member_id' => $r['member_id'],
                                'recorded'  => $recorded,
                                'recomputed' => $expected,
                                'snapshot'  => $snap['status'],
                            ];
                        }
                    }
                }
            }
        }

        return ['mismatches' => $mismatches, 'ok' => $mismatches === []];
    }

    // ------------------------------------------------------------------ internals

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $dayRun
     * @param array<string, mixed> $rate
     * @param array<string, mixed> $stats
     */
    private function processBatch(array $rows, array $dayRun, array $rate, MiningCalculationPolicy $policy, ?int $actor, array &$stats): void
    {
        $db     = db_connect();
        $date   = (string) $dayRun['business_date'];
        $cutoff = (string) $dayRun['cutoff_utc'];

        $activeIds = [];
        $keys      = [];
        foreach ($rows as $r) {
            if ((string) $r['account_status'] === 'active') {
                $activeIds[] = (int) $r['member_user_id'];
                $keys[]      = self::idempotencyKey((int) $r['member_user_id'], $date);
            }
        }

        $snaps = [];
        if ($activeIds !== []) {
            try {
                $snaps = $this->snapshots->snapshotBatch($activeIds, $cutoff, $policy);
            } catch (Throwable $e) {
                $this->failRows($rows, $e->getMessage(), $stats);

                return;
            }
        }

        $existing = [];
        if ($keys !== []) {
            foreach ($db->query('SELECT idempotency_key, entry_ref, amount FROM mining_wallet_ledger WHERE idempotency_key IN ?', [$keys])->getResultArray() as $l) {
                $existing[(string) $l['idempotency_key']] = $l;
            }
        }

        foreach ($rows as $r) {
            $uid   = (int) $r['member_user_id'];
            $upd   = ['attempts' => (int) $r['attempts'] + 1, 'processed_at' => date('Y-m-d H:i:s'), 'last_error' => null, 'was_duplicate' => 0];

            if ((string) $r['account_status'] !== 'active') {
                $upd += ['status' => 'SKIPPED', 'reason' => 'member_inactive', 'eligible_coins' => '0.00000000', 'expected_amount' => '0.00000000'];
                $this->updateMember((int) $r['id'], $upd);

                continue;
            }

            $snap = $snaps[$uid];
            if ($snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED) {
                $upd += [
                    'status' => 'BLOCKED', 'reason' => substr('BLOCKED_HISTORICAL_DATA: ' . implode('; ', array_slice($snap['reasons'], 0, 3)), 0, 120),
                    'eligible_coins' => '0.00000000', 'expected_amount' => '0.00000000', 'evidence_ref' => $snap['evidence_ref'],
                ];
                $this->updateMember((int) $r['id'], $upd);

                continue;
            }
            if ($snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_ZERO) {
                $upd += ['status' => 'SKIPPED', 'reason' => 'no_eligible_ownership_as_of_snapshot', 'eligible_coins' => '0.00000000', 'expected_amount' => '0.00000000', 'evidence_ref' => $snap['evidence_ref']];
                $this->updateMember((int) $r['id'], $upd);

                continue;
            }

            $amount = $this->rates->previewAmount($snap['eligible'], $rate, $date, $policy->divisorMode, $policy->rounding);
            $base   = ['eligible_coins' => $snap['eligible'], 'expected_amount' => $amount, 'evidence_ref' => $snap['evidence_ref']];
            if (bccomp($amount, '0', 8) <= 0) {
                $this->updateMember((int) $r['id'], $upd + $base + ['status' => 'SKIPPED', 'reason' => 'amount_rounds_to_zero']);

                continue;
            }

            $key = self::idempotencyKey($uid, $date);
            if (isset($existing[$key])) {
                if (bccomp((string) $existing[$key]['amount'], $amount, 8) !== 0) {
                    $this->updateMember((int) $r['id'], $upd + $base + [
                        'status' => 'BLOCKED', 'reason' => 'existing_credit_differs_from_recomputed_amount',
                        'credited_amount' => (string) $existing[$key]['amount'], 'ledger_ref' => (string) $existing[$key]['entry_ref'],
                    ]);

                    continue;
                }
                $this->updateMember((int) $r['id'], [...$upd, ...$base, 'status' => 'CREDITED', 'reason' => 'duplicate_skip_existing_ledger_credit',
                    'credited_amount' => $amount, 'ledger_ref' => (string) $existing[$key]['entry_ref'], 'was_duplicate' => 1]);
                $stats['duplicates_now']++;

                continue;
            }

            try {
                $entry = $this->wallets()->creditDailyMining($uid, (string) $r['member_id'], [
                    'amount'          => $amount,
                    'business_date'   => $date,
                    'idempotency_key' => $key,
                    'rate_ref'        => (string) $rate['rate_ref'],
                    'created_by'      => $actor,
                    'meta'            => [
                        'phase'          => '13B',
                        'policy_version' => $policy->version(),
                        'eligible_coins' => $snap['eligible'],
                        'cutoff_utc'     => $cutoff,
                        'evidence_ref'   => $snap['evidence_ref'],
                        'divisor_mode'   => $policy->divisorMode,
                        'rounding'       => $policy->rounding,
                    ],
                ]);
                $this->updateMember((int) $r['id'], $upd + $base + ['status' => 'CREDITED', 'reason' => null, 'credited_amount' => $amount, 'ledger_ref' => (string) ($entry['entry_ref'] ?? '')]);
                $stats['credited_now']++;
            } catch (Throwable $e) {
                $this->updateMember((int) $r['id'], array_merge($upd, $base, ['status' => 'FAILED', 'reason' => 'credit_failed', 'last_error' => substr($e->getMessage(), 0, 255)]));
                $stats['errors'][] = ['member_id' => (string) $r['member_id'], 'message' => $e->getMessage()];
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, mixed> $stats
     */
    private function failRows(array $rows, string $message, array &$stats): void
    {
        foreach ($rows as $r) {
            $this->updateMember((int) $r['id'], [
                'status' => 'FAILED', 'reason' => 'snapshot_failed', 'attempts' => (int) $r['attempts'] + 1,
                'last_error' => substr($message, 0, 255), 'processed_at' => date('Y-m-d H:i:s'),
            ]);
            $stats['errors'][] = ['member_id' => (string) $r['member_id'], 'message' => $message];
        }
    }

    /** @param array<string, mixed> $data */
    private function updateMember(int $id, array $data): void
    {
        db_connect()->table('mining_post_members')->where('id', $id)->update($data);
    }

    private function checkpoint(int $dayRunId, string $date, int $cursor, int $batches): void
    {
        $c = $this->aggregate($date);
        db_connect()->table('mining_post_day_runs')->where('id', $dayRunId)->update($c['columns'] + [
            'last_user_id' => $cursor,
            'batches_done' => $batches,
            'updated_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array{columns: array<string, mixed>, counts: array<string, int>}
     */
    private function aggregate(string $date): array
    {
        $out = ['PENDING' => 0, 'CREDITED' => 0, 'SKIPPED' => 0, 'BLOCKED' => 0, 'FAILED' => 0];
        $total = '0.00000000';
        $dupes = 0;
        $eligible = 0;
        foreach (db_connect()->query(
            'SELECT status, COUNT(*) AS c, COALESCE(SUM(credited_amount),0) AS s, COALESCE(SUM(was_duplicate),0) AS d, COALESCE(SUM(eligible_coins > 0),0) AS e
               FROM mining_post_members WHERE business_date = ? GROUP BY status',
            [$date]
        )->getResultArray() as $r) {
            $out[(string) $r['status']] = (int) $r['c'];
            $total    = bcadd($total, (string) $r['s'], 8);
            $dupes   += (int) $r['d'];
            $eligible += (int) $r['e'];
        }

        return [
            'counts'  => $out,
            'columns' => [
                'credited_count'  => $out['CREDITED'],
                'skipped_count'   => $out['SKIPPED'],
                'blocked_count'   => $out['BLOCKED'],
                'failed_count'    => $out['FAILED'],
                'pending_count'   => $out['PENDING'],
                'duplicate_count' => $dupes,
                'eligible_count'  => $eligible,
                'total_credited'  => $total,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $rate
     * @return array<string, mixed>
     */
    private function finalize(int $dayRunId, string $date, array $rate, MiningCalculationPolicy $policy, ?string $interrupted): array
    {
        $db  = db_connect();
        $run = $db->table('mining_post_day_runs')->where('id', $dayRunId)->get()->getRowArray();
        $agg = $this->aggregate($date);
        $c   = $agg['counts'];

        $unresolved = $c['PENDING'] + $c['FAILED'] + $c['BLOCKED'];
        $verify     = $this->verify($date, $rate, $policy, (string) $run['cutoff_utc'], true);

        $state  = self::STATE_COMPLETED;
        $reason = null;
        if ($interrupted !== null) {
            $state  = self::STATE_PARTIAL;
            $reason = 'interrupted: ' . $interrupted;
        } elseif ($unresolved > 0) {
            $state  = self::STATE_PARTIAL;
            $reason = sprintf('unresolved members: pending=%d failed=%d blocked=%d', $c['PENDING'], $c['FAILED'], $c['BLOCKED']);
        }
        if (! $verify['ok']) {
            $state  = self::STATE_PARTIAL;
            $reason = trim(($reason ?? '') . ' reconciliation mismatch (' . count($verify['mismatches']) . ')');
        }

        $reconcile = $verify['ok'] ? ($unresolved > 0 || $interrupted !== null ? 'OK_PARTIAL' : 'OK') : 'MISMATCH';
        $now       = date('Y-m-d H:i:s');
        $db->table('mining_post_day_runs')->where('id', $dayRunId)->update($agg['columns'] + [
            'state'            => $state,
            'reconcile_status' => $reconcile,
            'last_error'       => $reason !== null ? substr($reason, 0, 500) : null,
            'finished_at'      => $state === self::STATE_COMPLETED ? $now : null,
            'updated_at'       => $now,
        ]);

        $posted = $db->query(
            "SELECT COALESCE(SUM(amount),0) AS s FROM mining_wallet_ledger WHERE business_date = ? AND entry_type = 'daily_mining_credit' AND direction = 'credit'",
            [$date]
        )->getRowArray();
        $expected = $db->query('SELECT COALESCE(SUM(expected_amount),0) AS s FROM mining_post_members WHERE business_date = ?', [$date])->getRowArray();

        return [
            'state'            => $state,
            'reason'           => $reason,
            'reconcile_status' => $reconcile,
            'mismatches'       => $verify['mismatches'],
            'population'       => (int) $run['population_count'],
            'counts'           => $c,
            'eligible_count'   => (int) $agg['columns']['eligible_count'],
            'credited'         => $c['CREDITED'],
            'skipped'          => $c['SKIPPED'],
            'blocked'          => $c['BLOCKED'],
            'failed'           => $c['FAILED'],
            'pending'          => $c['PENDING'],
            'duplicates'       => (int) $agg['columns']['duplicate_count'],
            'total_credited'   => (string) $agg['columns']['total_credited'],
            'expected_total'   => bcadd((string) $expected['s'], '0', 8),
            'posted_total'     => bcadd((string) $posted['s'], '0', 8),
            'cursor'           => (int) ($db->table('mining_post_day_runs')->where('id', $dayRunId)->get()->getRowArray()['last_user_id'] ?? 0),
            'batches_done'     => (int) ($db->table('mining_post_day_runs')->where('id', $dayRunId)->get()->getRowArray()['batches_done'] ?? 0),
            'incomplete_day'   => (bool) $run['incomplete_day'],
            'cutoff_utc'       => (string) $run['cutoff_utc'],
            'policy_version'   => (string) $run['policy_version'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function freezePopulation(array $row, ?int $memberCap): void
    {
        $db    = db_connect();
        $date  = (string) $row['business_date'];
        $where = $this->populationWhere();
        $max   = (int) $row['max_user_id'];

        if ($memberCap !== null) {
            $count = (int) ($db->query(
                'SELECT COUNT(*) AS c FROM member_profiles WHERE ' . $where['sql'] . ' AND user_id <= ?',
                array_merge($where['bind'], [$max])
            )->getRowArray()['c'] ?? 0);
            if ($count > $memberCap) {
                throw new MiningBlockedException(sprintf('Member population (%d) exceeds the explicit --limit (%d). Refusing a partial run.', $count, $memberCap));
            }
        }

        while (true) {
            $last = (int) ($db->query('SELECT COALESCE(MAX(member_user_id), 0) AS m FROM mining_post_members WHERE business_date = ?', [$date])->getRowArray()['m'] ?? 0);
            $chunk = $db->query(
                'SELECT user_id, member_id, account_status FROM member_profiles WHERE ' . $where['sql'] . ' AND user_id > ? AND user_id <= ? ORDER BY user_id ASC LIMIT ' . self::FREEZE_CHUNK,
                array_merge($where['bind'], [$last, $max])
            )->getResultArray();
            if ($chunk === []) {
                break;
            }
            $batch = [];
            foreach ($chunk as $m) {
                $batch[] = [
                    'business_date'  => $date,
                    'member_user_id' => (int) $m['user_id'],
                    'member_id'      => (string) $m['member_id'],
                    'account_status' => (string) $m['account_status'],
                    'status'         => 'PENDING',
                ];
            }
            $db->table('mining_post_members')->ignore(true)->insertBatch($batch);
        }

        $count = (int) ($db->query('SELECT COUNT(*) AS c FROM mining_post_members WHERE business_date = ?', [$date])->getRowArray()['c'] ?? 0);
        $now   = date('Y-m-d H:i:s');
        $db->table('mining_post_day_runs')->where('id', (int) $row['id'])->update([
            'population_frozen' => 1,
            'population_count'  => $count,
            'pending_count'     => $count,
            'updated_at'        => $now,
        ]);
    }

    /** Current number of members that a new day run would freeze (read-only). */
    public function populationCount(): int
    {
        $where = $this->populationWhere();

        return (int) (db_connect()->query('SELECT COUNT(*) AS c FROM member_profiles WHERE ' . $where['sql'], $where['bind'])->getRowArray()['c'] ?? 0);
    }

    /** @return array{sql: string, bind: list<string>} */
    private function populationWhere(): array
    {
        $sql  = 'is_root_sponsor = 0';
        $bind = [];
        $root = trim((string) (($this->config ?? config('Platform'))->rootSponsorId ?? ''));
        if ($root !== '') {
            $sql   .= ' AND member_id <> ?';
            $bind[] = $root;
        }

        return ['sql' => $sql, 'bind' => $bind];
    }

    private function wallets(): MiningWalletService
    {
        return $this->wallets ??= new MiningWalletService();
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
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
}
