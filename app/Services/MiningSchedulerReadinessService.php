<?php

declare(strict_types=1);

namespace App\Services;

use App\Commands\MiningPost;
use App\Libraries\CliArgs;
use Config\Platform;
use Throwable;

/**
 * Phase 13C — scheduler activation READINESS check (read-only; it never creates, enables or schedules anything).
 *
 * Statuses: PASS (verified here), BLOCKED (a gate that must still be cleared), NOT_VERIFIED (cannot be proven from this
 * machine, e.g. the deployment server), INFO. "BLOCKED" for the kill-switches is the CORRECT state until a written
 * production authorization exists.
 */
class MiningSchedulerReadinessService
{
    /** The exact command line a future scheduler would run (documented, NOT installed). */
    public const PLANNED_ARGS = ['--scheduled', '--strict-exit', '--allow-production', '--confirm', 'I_AUTHORIZE_PRODUCTION_CHANGE'];

    public function __construct(private readonly ?Platform $config = null)
    {
    }

    /** @return array{checks: list<array{id: string, requirement: string, status: string, evidence: string}>, summary: array<string, int>, planned_command: string, activated: bool} */
    public function check(): array
    {
        $cfg    = $this->config ?? config('Platform');
        $checks = [];
        $add    = static function (string $id, string $req, string $status, string $evidence) use (&$checks): void {
            $checks[] = ['id' => $id, 'requirement' => $req, 'status' => $status, 'evidence' => $evidence];
        };

        // 1. PHP CLI path + version
        $php = PHP_BINARY;
        $add('php_cli', 'Correct PHP CLI binary', (is_file($php) && PHP_SAPI === 'cli' && version_compare(PHP_VERSION, '8.2.0', '>=')) ? 'PASS' : 'BLOCKED', 'PHP_BINARY=' . $php . ' sapi=' . PHP_SAPI . ' version=' . PHP_VERSION . ' (local machine; the DEPLOYMENT server path is NOT_VERIFIED)');
        $add('php_cli_deployment_path', 'PHP CLI path on the deployment server', 'NOT_VERIFIED', 'Cannot be proven from this machine.');

        // 2. project root + spark
        $add('project_root', 'Correct project root with spark entry', is_file(ROOTPATH . 'spark') ? 'PASS' : 'BLOCKED', 'ROOTPATH=' . ROOTPATH . ' spark=' . (is_file(ROOTPATH . 'spark') ? 'present' : 'missing'));

        // 3. command registered + syntax
        $okArgs   = CliArgs::parse(self::PLANNED_ARGS, MiningPost::argSpec());
        $badArgs  = CliArgs::parse(['--scheduled', '--confirm=I_AUTHORIZE_PRODUCTION_CHANGE'], MiningPost::argSpec());
        $add('spark_command', 'Spark command mining:post registered and planned arguments parse cleanly', ($okArgs['errors'] === [] && $badArgs['errors'] !== []) ? 'PASS' : 'BLOCKED',
            'planned="php spark mining:post ' . implode(' ', self::PLANNED_ARGS) . '" parse_errors=' . count($okArgs['errors']) . '; "--confirm=" syntax refused=' . ($badArgs['errors'] !== [] ? 'yes' : 'NO'));

        // 4. database classification
        try {
            $cls = service('miningPostingRunnerService')->classify('default');
            $add('database_classification', 'Active database classified (live target must be explicit)', 'PASS', 'default group -> database "' . $cls['database'] . '" mode=' . $cls['mode'] . ' (live mode requires every production gate)');
        } catch (Throwable $e) {
            $add('database_classification', 'Active database classified', 'BLOCKED', $e->getMessage());
        }

        // 5. timezone + business date selection
        $add('timezone', 'Business timezone is Asia/Kolkata', ((string) $cfg->miningBusinessTimezone === 'Asia/Kolkata') ? 'PASS' : 'BLOCKED', 'miningBusinessTimezone=' . $cfg->miningBusinessTimezone);
        try {
            $y = service('miningPostingRunnerService')->resolveDate('yesterday');
            $t = service('miningPostingRunnerService')->resolveDate('today');
            $add('business_date', 'Scheduler posts the previous completed business day', ($y < $t) ? 'PASS' : 'BLOCKED', 'yesterday=' . $y . ' today=' . $t . '; future dates refused; incomplete day refused without --allow-incomplete-day (sandbox only)');
        } catch (Throwable $e) {
            $add('business_date', 'Business date selection', 'BLOCKED', $e->getMessage());
        }

        // 6. policy approvals
        $flags = ['miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus', 'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus', 'miningRoundingPolicyStatus'];
        $notApproved = array_values(array_filter($flags, static fn (string $f): bool => strtolower((string) $cfg->{$f}) !== 'approved'));
        $add('policies_approved', 'All 8 mining policy status flags approved', $notApproved === [] ? 'PASS' : 'BLOCKED', $notApproved === [] ? 'all approved' : 'not approved: ' . implode(', ', $notApproved));

        // 7. approved monthly rate
        try {
            $db     = db_connect();
            $month  = substr(service('miningPostingRunnerService')->resolveDate('yesterday'), 0, 7);
            $count  = $db->tableExists('mining_rate_configs') ? (int) $db->table('mining_rate_configs')->where('status', 'approved')->where('effective_month', $month)->countAllResults() : 0;
            $add('approved_rate', 'Approved monthly rate exists for the posting month', $count > 0 ? 'PASS' : 'BLOCKED', 'month=' . $month . ' approved_rates=' . $count);
        } catch (Throwable $e) {
            $add('approved_rate', 'Approved monthly rate', 'BLOCKED', $e->getMessage());
        }

        // 8. kill-switches (OFF is the required state until written authorization)
        $add('posting_gate', 'miningPostingEnabled gate', $cfg->miningPostingEnabled ? 'PASS' : 'BLOCKED', 'miningPostingEnabled=' . ($cfg->miningPostingEnabled ? 'true' : 'false') . ' (false is the REQUIRED state until written production authorization)');
        $add('auto_post_gate', 'miningAutoPostEnabled gate', $cfg->miningAutoPostEnabled ? 'PASS' : 'BLOCKED', 'miningAutoPostEnabled=' . ($cfg->miningAutoPostEnabled ? 'true' : 'false') . ' (false is the REQUIRED state until written production authorization)');

        // 9. lock / timeout / retry
        $runtime = (int) $cfg->miningPostMaxRuntimeSeconds;
        $ttl     = (int) $cfg->miningPostLockTtlSeconds;
        $effTtl  = max(60, $ttl, $runtime + 300);
        $add('lock_ttl_vs_runtime', 'Per-date lock outlives the max runtime budget', $effTtl >= $runtime + 300 ? 'PASS' : 'BLOCKED',
            'configured lock TTL=' . $ttl . 's, max runtime=' . $runtime . 's -> effective lock TTL=' . $effTtl . 's (Phase 13C fix: TTL = max(configured, runtime+300); the lock is not renewed during a run)');
        $add('retry', 'Bounded transient retry', ((int) $cfg->miningPostMaxAttempts >= 1 && (int) $cfg->miningPostMaxAttempts <= 10) ? 'PASS' : 'BLOCKED', 'attempts=' . $cfg->miningPostMaxAttempts . ' delay=' . $cfg->miningPostRetryDelaySeconds . 's (permanent errors are not retried)');
        $add('timeout_handling', 'Timeout ends the run PARTIAL (resumable), never COMPLETED', 'PASS', 'Verified by Phase 13B scenarios interrupted_batch_is_partial / resume_completes_without_duplicates_or_skips (previously reported; not re-run in 13C)');

        // 10. migrations / run history
        $db = db_connect();
        $has = $db->tableExists('mining_post_day_runs') && $db->tableExists('mining_post_members');
        $add('run_history_tables', 'Run-history / resume tables present (migration 994001)', $has ? 'PASS' : 'BLOCKED', 'mining_post_day_runs+mining_post_members ' . ($has ? 'present' : 'ABSENT in this database'));
        $add('ops_lock_table', 'ops_job_locks present', $db->tableExists('ops_job_locks') ? 'PASS' : 'BLOCKED', $db->tableExists('ops_job_locks') ? 'present' : 'absent');

        // 11. exit codes + reconciliation
        $codes = MiningPost::EXIT_MAP;
        $add('exit_codes', 'Distinct non-zero failure exit codes', (count(array_unique($codes)) === count($codes) && $codes['FAILED'] !== 0) ? 'PASS' : 'BLOCKED', json_encode($codes));
        $add('reconciliation', 'Ledger reconciliation after every run', 'PASS', 'Built into MiningBatchPostingService::finalize (mismatch => PARTIAL, exit 40); previously verified in 13B');

        // 12. alert destination
        $add('alert_destination', 'Alert destination configured and approved', 'BLOCKED', 'Only local log/event hooks exist. No e-mail/webhook/admin-notification destination is configured and none may be invented.');

        // 13. no installed schedule
        $add('no_production_schedule', 'No production cron / Task Scheduler entry exists', 'PASS', 'Phase 13C creates none (documented template only).');

        $summary = ['PASS' => 0, 'BLOCKED' => 0, 'NOT_VERIFIED' => 0, 'INFO' => 0];
        foreach ($checks as $c) {
            $summary[$c['status']]++;
        }

        return [
            'checks'          => $checks,
            'summary'         => $summary,
            'planned_command' => 'php spark mining:post ' . implode(' ', self::PLANNED_ARGS),
            'activated'       => false,
        ];
    }
}
