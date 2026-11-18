<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Events\Events;
use Config\Platform;

/**
 * Phase 13B — alert hooks for `mining:post`.
 *
 * LOCAL ONLY: alerts go to writable/logs/mining-post-alerts.log, the CI4 log and the `mining_post_alert`
 * event. No e-mail / webhook / chat integration is configured or contacted here; wiring one is a separate,
 * explicitly authorized step (listen to the event).
 */
class MiningPostAlertService
{
    /** Scheduler exit codes that are expected outcomes and therefore never alert on their own. */
    private const QUIET_EXITS = [0, 10, 20];

    public function __construct(
        private readonly ?Platform $config = null,
        private readonly ?string $file = null
    ) {
    }

    /**
     * @param array<string, mixed> $result Runner result (may be empty when the command refused/failed early)
     * @return list<array<string, mixed>>
     */
    public function evaluate(array $result, string $schedulerStatus, int $exitCode, ?string $error = null): array
    {
        $cfg    = $this->config ?? config('Platform');
        $alerts = [];
        $base   = [
            'business_date'    => $result['business_date'] ?? null,
            'database'         => $result['database'] ?? null,
            'scheduler_status' => $schedulerStatus,
            'exit_code'        => $exitCode,
            'run_ref'          => $result['run_ref'] ?? null,
        ];

        if (! in_array($exitCode, self::QUIET_EXITS, true)) {
            $alerts[] = $base + ['code' => 'nonzero_exit', 'detail' => $error ?? ($result['message'] ?? '')];
        }
        $text = strtolower((string) ($error ?? '') . ' ' . (string) ($result['error'] ?? ''));
        if (str_contains($text, 'no approved mining rate')) {
            $alerts[] = $base + ['code' => 'missing_rate', 'detail' => 'No approved mining rate for the business month.'];
        }
        if (($result['status'] ?? '') === 'partial') {
            $alerts[] = $base + ['code' => 'partial_run', 'detail' => (string) ($result['reason'] ?? 'unresolved members remain')];
        }
        if (($result['reconcile_status'] ?? '') === 'MISMATCH' || ! empty($result['mismatches'])) {
            $alerts[] = $base + ['code' => 'reconciliation_failed', 'detail' => 'mismatches=' . count((array) ($result['mismatches'] ?? []))];
        }
        // Phase 13C monitoring codes.
        if (($result['status'] ?? '') === 'skipped_locked' || $schedulerStatus === 'SKIPPED' && str_contains($text, 'holds')) {
            $alerts[] = $base + ['code' => 'lock_contention', 'detail' => 'Another mining:post invocation holds the per-date lock (duplicate or overlapping job).'];
        }
        if ((int) ($result['blocked'] ?? 0) > 0) {
            $alerts[] = $base + ['code' => 'blocked_historical_data', 'detail' => 'members blocked (missing/ambiguous historical ownership evidence)=' . (int) $result['blocked']];
        }
        if (($result['interrupted'] ?? null) === 'timeout' || str_contains((string) ($result['reason'] ?? ''), 'interrupted: timeout')) {
            $alerts[] = $base + ['code' => 'job_timeout', 'detail' => 'Run stopped at the max-runtime budget; day left PARTIAL (resume by re-running).'];
        }
        if (preg_match('/connection|gone away|refused|2002|2006|2013/i', $text) === 1 && $exitCode === 50) {
            $alerts[] = $base + ['code' => 'database_connection_failure', 'detail' => 'Database connection failure; nothing was written.'];
        }
        $retries = (int) ($result['retry_passes'] ?? 0);
        if ($retries >= max(1, (int) $cfg->miningPostAlertRetryThreshold)) {
            $alerts[] = $base + ['code' => 'excessive_retry', 'detail' => 'retry_passes=' . $retries];
        }
        $seconds = ((int) ($result['duration_ms'] ?? 0)) / 1000;
        if ($seconds >= max(1, (int) $cfg->miningPostAlertRuntimeSeconds)) {
            $alerts[] = $base + ['code' => 'long_runtime', 'detail' => 'seconds=' . (int) $seconds];
        }

        foreach ($alerts as $alert) {
            $this->emit($alert);
        }

        return $alerts;
    }

    /**
     * Scheduled-run watchdog: has the business date's day run completed? Read-only.
     *
     * @return array{business_date: string, ok: bool, code: ?string, state: ?string, detail: string}
     */
    public function checkScheduledRun(string $businessDate): array
    {
        $db = db_connect();
        if (! $db->tableExists('mining_post_day_runs')) {
            return ['business_date' => $businessDate, 'ok' => false, 'code' => 'missing_scheduled_run', 'state' => null, 'detail' => 'mining_post_day_runs table not present (migration not applied).'];
        }
        $row = $db->table('mining_post_day_runs')->where('business_date', $businessDate)->get()->getRowArray();
        if ($row === null) {
            return $this->alertAndReturn($businessDate, false, 'missing_scheduled_run', null, 'No mining day run exists for ' . $businessDate . '.');
        }
        $state = (string) $row['state'];
        if ($state === 'COMPLETED') {
            return ['business_date' => $businessDate, 'ok' => true, 'code' => null, 'state' => $state, 'detail' => 'Day run completed and reconciled.'];
        }
        $ttl = max(60, (int) ($this->config ?? config('Platform'))->miningPostLockTtlSeconds);
        if ($state === 'RUNNING' && strtotime((string) $row['updated_at'] . ' UTC') < time() - $ttl) {
            return $this->alertAndReturn($businessDate, false, 'stale_running_day_run', $state, 'RUNNING without a checkpoint for more than ' . $ttl . 's.');
        }

        return $this->alertAndReturn($businessDate, false, 'day_run_not_completed', $state, 'State ' . $state . ': ' . (string) ($row['last_error'] ?? ''));
    }

    /** @return array{business_date: string, ok: bool, code: ?string, state: ?string, detail: string} */
    private function alertAndReturn(string $date, bool $ok, string $code, ?string $state, string $detail): array
    {
        $this->emit(['code' => $code, 'business_date' => $date, 'detail' => $detail, 'state' => $state]);

        return ['business_date' => $date, 'ok' => $ok, 'code' => $code, 'state' => $state, 'detail' => $detail];
    }

    /** @param array<string, mixed> $alert */
    private function emit(array $alert): void
    {
        $line = json_encode(['ts' => date('c')] + $alert, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        log_message('error', '[mining:post:alert] ' . $line);
        $file = $this->file ?? WRITEPATH . 'logs/mining-post-alerts.log';
        $dir  = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        Events::trigger('mining_post_alert', $alert);
    }
}
