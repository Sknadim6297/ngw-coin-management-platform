<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AutomationBlockedException;
use App\Libraries\MoneyMath;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Platform;
use DateTimeImmutable;
use Throwable;

/**
 * Scheduler entry point of the Financial Automation Control Center (`php spark automation:run`).
 *
 * Responsibilities (and nothing more):
 *   1. write the cron heartbeat (always, even when everything is OFF);
 *   2. when the Automatic Scheduler switch is ON and the daily run time has passed, run the due jobs once per
 *      business date through the EXISTING runners (MiningPostingRunnerService / CommissionPostingRunnerService);
 *   3. log every attempt (financial_automation_runs), audit it and raise an alert on failure.
 *
 * Safety model:
 *   - Env kill-switches in Config\Platform stay false. For the duration of ONE authorized job this class raises the
 *     matching in-process flags and always restores the previous values in `finally`. Nothing is written to .env.
 *   - A job runs only when its own switch is ON, its authorization belongs to THIS database, and every readiness
 *     check passes again at run time. Anything else is logged as `blocked` and alerted — nothing is posted.
 *   - Idempotency: one `success` row per (job, business date) ends the job for that day; the underlying runners are
 *     independently idempotent (unique day run / ledger idempotency keys), and an OpsJobLock prevents overlap.
 *   - Posting for a date happens in the runner's own transactions; this class never writes wallet/ledger rows.
 */
class FinancialAutomationRunnerService
{
    public const LOCK_NAME = 'financial_automation_run';

    /** Policy status flags the existing runners demand as "approved" on a live database (DB-verified before use). */
    private const MINING_POLICY_FLAGS = [
        'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus', 'miningApprovalDayPolicyStatus',
        'miningLockedCoinEligibilityStatus', 'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus',
        'miningRoundingPolicyStatus',
    ];

    private const COMMISSION_POLICY_FLAGS = [
        'commissionBasePolicyStatus', 'commissionRoundingPolicyStatus', 'commissionRootEligibilityPolicyStatus',
    ];

    public function __construct(
        private readonly ?FinancialAutomationService $control = null,
        private readonly ?OpsJobLockService $locks = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?Platform $config = null,
    ) {
    }

    /**
     * @return array{status: string, business_date: ?string, jobs: array<string, array<string, mixed>>, message: string}
     */
    public function run(?DateTimeImmutable $now = null, ?string $group = null): array
    {
        $ctl = $this->ctl();
        $ctl->heartbeat();

        if (! $ctl->isActive(FinancialAutomationService::CONTROL_SCHEDULER)) {
            return ['status' => 'scheduler_off', 'business_date' => null, 'jobs' => [], 'message' => 'Automatic Scheduler is OFF — heartbeat recorded, nothing posted.'];
        }

        $bizNow = $ctl->businessNow($now);
        [$h, $m] = $ctl->runTimeParts();
        if ($bizNow < $bizNow->setTime($h, $m)) {
            return ['status' => 'not_due', 'business_date' => null, 'jobs' => [], 'message' => sprintf('Daily run time %02d:%02d %s not reached yet.', $h, $m, $bizNow->format('T'))];
        }

        $date  = $ctl->dueBusinessDate($bizNow);
        $owner = (gethostname() ?: 'host') . '-' . getmypid();
        $locks = $this->locks ?? new OpsJobLockService();
        if (! $locks->acquire(self::LOCK_NAME, $owner, 3 * 3600)) {
            return ['status' => 'skipped_locked', 'business_date' => $date, 'jobs' => [], 'message' => 'Another automation run holds the lock.'];
        }

        $jobs = [];

        try {
            foreach ([
                FinancialAutomationService::CONTROL_MINING     => FinancialAutomationService::JOB_MINING,
                FinancialAutomationService::CONTROL_COMMISSION => FinancialAutomationService::JOB_COMMISSION,
            ] as $control => $job) {
                if (! $ctl->isActive($control)) {
                    $jobs[$job] = ['status' => 'off', 'message' => 'Switch is OFF.'];

                    continue;
                }
                $jobs[$job] = $this->runJob($job, $control, $date, $bizNow, $group);
            }
        } finally {
            $locks->release(self::LOCK_NAME, $owner);
        }

        $bad = array_filter($jobs, static fn (array $j): bool => in_array($j['status'], ['failed', 'blocked'], true));

        return [
            'status'        => $bad === [] ? 'ok' : 'attention',
            'business_date' => $date,
            'jobs'          => $jobs,
            'message'       => $bad === [] ? 'Automation tick finished.' : 'One or more jobs failed or are blocked — see alerts.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runJob(string $job, string $control, string $date, DateTimeImmutable $bizNow, ?string $group): array
    {
        $ctl = $this->ctl();
        $cfg = $this->cfg();
        $db  = db_connect();

        if ($ctl->jobSucceeded($job, $date)) {
            return ['status' => 'already_done', 'message' => 'Already completed for ' . $date . '.'];
        }

        $attempts = (int) $db->table('financial_automation_runs')
            ->where('job', $job)->where('business_date', $date)->whereIn('status', ['failed', 'blocked', 'running'])->countAllResults();
        $max = max(1, (int) $cfg->automationMaxAttemptsPerDay);
        if ($attempts >= $max) {
            return ['status' => 'attempts_exhausted', 'message' => $attempts . ' attempts already failed for ' . $date . '; an operator must investigate.'];
        }
        $last = $db->table('financial_automation_runs')
            ->where('job', $job)->where('business_date', $date)->whereIn('status', ['failed', 'blocked'])->orderBy('id', 'DESC')->get()->getRowArray();
        if ($last !== null && $last['finished_at'] !== null
            && strtotime((string) $last['finished_at']) > time() - max(60, (int) $cfg->automationRetryBackoffSeconds)) {
            return ['status' => 'backoff', 'message' => 'Waiting before retrying the failed attempt.'];
        }

        $runRef = 'AUT-' . strtoupper(bin2hex(random_bytes(6)));
        $now    = date('Y-m-d H:i:s');
        $db->table('financial_automation_runs')->insert([
            'run_ref'       => $runRef,
            'job'           => $job,
            'business_date' => $date,
            'attempt'       => $attempts + 1,
            'status'        => 'running',
            'started_at'    => $now,
            'created_at'    => $now,
        ]);

        $status  = 'failed';
        $message = '';
        $count   = 0;
        $amount  = '0.00000000';
        $summary = [];

        try {
            $readiness = $ctl->readiness($control, $bizNow);
            if (! $readiness['ready']) {
                throw new AutomationBlockedException($readiness['blockers']);
            }

            $result = $job === FinancialAutomationService::JOB_MINING
                ? $this->withRuntimeAuthorization($job, fn (): array => $this->execMining($group, $date))
                : $this->withRuntimeAuthorization($job, fn (): array => $this->execCommission($group));

            [$status, $message, $count, $amount, $summary] = $this->interpret($job, $result);
        } catch (AutomationBlockedException $e) {
            $status  = 'blocked';
            $message = mb_substr($e->getMessage(), 0, 500);
        } catch (DatabaseException $e) {
            $status  = 'failed';
            $message = mb_substr('Database error: ' . $e->getMessage(), 0, 500);
        } catch (Throwable $e) {
            // Gate refusals (kill-switch, approval, isolation) are BLOCKED; unexpected errors are FAILED.
            $status  = $e instanceof \RuntimeException && ! $this->isTransient($e) ? 'blocked' : 'failed';
            $message = mb_substr($e->getMessage(), 0, 500);
        }

        $db->table('financial_automation_runs')->where('run_ref', $runRef)->update([
            'status'        => $status,
            'message'       => $message,
            'credits_count' => $count,
            'amount'        => $amount,
            'summary_json'  => json_encode($summary, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'finished_at'   => date('Y-m-d H:i:s'),
        ]);

        try {
            ($this->audit ?? new AuditLogger())->log('automation.job_' . $status, null, 'financial_automation', $runRef, [
                'job' => $job, 'business_date' => $date, 'credits' => $count, 'amount' => $amount, 'message' => $message,
                'database' => $ctl->databaseName(),
            ]);
        } catch (Throwable $e) {
            log_message('error', '[automation] audit failed: {m}', ['m' => $e->getMessage()]);
        }

        if (in_array($status, ['failed', 'blocked'], true)) {
            $this->alert($job, $date, $runRef, $status, $message);
        }

        return ['status' => $status, 'run_ref' => $runRef, 'message' => $message, 'credits' => $count, 'amount' => $amount];
    }

    /**
     * Run $fn with the in-process kill-switches and policy flags this job needs, then restore everything.
     * The flags are raised only after FinancialAutomationService::readiness() proved the DB-backed approvals.
     *
     * @param callable(): array<string, mixed> $fn
     * @return array<string, mixed>
     */
    private function withRuntimeAuthorization(string $job, callable $fn): array
    {
        $cfg = $this->cfg();
        $set = $job === FinancialAutomationService::JOB_MINING
            ? ['miningPostingEnabled' => true, 'miningAutoPostEnabled' => true]
            : ['commissionPostingEnabled' => true];
        foreach ($job === FinancialAutomationService::JOB_MINING ? self::MINING_POLICY_FLAGS : self::COMMISSION_POLICY_FLAGS as $flag) {
            $set[$flag] = 'approved';
        }

        $previous = [];
        foreach ($set as $prop => $value) {
            $previous[$prop] = $cfg->{$prop};
        }

        try {
            foreach ($set as $prop => $value) {
                $cfg->{$prop} = $value;
            }

            return $fn();
        } finally {
            foreach ($previous as $prop => $value) {
                $cfg->{$prop} = $value;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function execMining(?string $group, string $date): array
    {
        $runner = service('miningPostingRunnerService');
        $group  = $this->group($group);
        $ctx    = $runner->classify($group);

        return $runner->run([
            'date'             => $date, // explicit: never re-derived from the clock (midnight-crossing retries)
            'group'            => $group,
            'execute'          => true,
            'scheduled'        => true,
            'allow_production' => true,
            'confirm'          => $this->confirmFor($ctx['mode']),
            'actor'            => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function execCommission(?string $group): array
    {
        $runner = service('commissionPostingRunnerService');
        $group  = $this->group($group);
        $ctx    = $runner->classify($group);

        $out = $runner->run([
            'group'            => $group,
            'execute'          => true,
            'allow_production' => true,
            'confirm'          => $this->confirmFor($ctx['mode']),
            'include_root'     => true,
            // Recovery only: once instant posting was ever authorized, the scheduler never posts purchases approved
            // before that moment (no retroactive posting; older rows need the explicit reconciliation command).
            'approved_since'   => $this->ctl()->instantSince(),
            'batch_size'       => 50,
            'max_runtime'      => 1800,
            'actor'            => 0,
        ]);
        unset($out['items']);

        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @return array{0: string, 1: string, 2: int, 3: string, 4: array<string, mixed>}
     */
    private function interpret(string $job, array $r): array
    {
        $status = (string) ($r['status'] ?? '');
        $msg    = (string) ($r['message'] ?? $status);

        if ($job === FinancialAutomationService::JOB_MINING) {
            $count  = (int) ($r['credited_total'] ?? $r['credited'] ?? 0);
            $amount = MoneyMath::normalize((string) ($r['total_credited'] ?? $r['posted_total'] ?? '0'), 8);
            $sum    = array_intersect_key($r, array_flip(['status', 'run_ref', 'rate_ref', 'policy_version', 'member_count', 'eligible_count', 'credited', 'credited_total', 'duplicates', 'skipped', 'blocked', 'failed', 'pending', 'total_credited', 'reconcile_status', 'reason']));
            if ($status === 'completed' && ($r['reconcile_status'] ?? 'OK') !== 'MISMATCH' && empty($r['mismatches'])) {
                return ['success', $msg, $count, $amount, $sum];
            }
            if (str_starts_with($status, 'skipped_')) {
                return ['skipped', $msg, 0, '0.00000000', $sum];
            }

            return ['failed', $msg !== '' ? $msg : 'Mining run did not complete.', $count, $amount, $sum];
        }

        $count  = (int) ($r['posted_total'] ?? 0);
        $amount = MoneyMath::normalize((string) ($r['total_posted_usdt'] ?? '0'), 8);
        $sum    = array_intersect_key($r, array_flip(['status', 'batch_ref', 'posted_now', 'posted_total', 'skipped', 'failed', 'pending', 'duplicates_total', 'total_posted_usdt', 'reconcile_status', 'reason']));
        if ($status === 'completed') {
            return ['success', $msg !== '' ? $msg : 'Commission batch completed.', $count, $amount, $sum];
        }
        if ($status === 'skipped_locked') {
            return ['skipped', $msg, 0, '0.00000000', $sum];
        }

        return ['failed', (string) ($r['reason'] ?? $msg ?: 'Commission run did not complete.'), $count, $amount, $sum];
    }

    private function alert(string $job, string $date, string $runRef, string $status, string $message): void
    {
        try {
            service('financialAlertService')->raise(
                'automation_job_' . $status,
                $status === 'failed' ? 'critical' : 'warning',
                'Financial automation ' . $status . ': ' . $job . ' ' . $date,
                $message,
                'automation:' . $job . ':' . $date,
                'financial_automation',
                $runRef,
                ['job' => $job, 'business_date' => $date, 'status' => $status]
            );
        } catch (Throwable $e) {
            log_message('error', '[automation] alert failed: {m}', ['m' => $e->getMessage()]);
        }
        log_message('error', '[automation] {job} {date} {status}: {msg}', ['job' => $job, 'date' => $date, 'status' => $status, 'msg' => $message]);
    }

    private function confirmFor(string $mode): ?string
    {
        return match ($mode) {
            'live'    => ProductionSafetyGate::CONFIRM_PHRASE,
            'sandbox' => 'APPLY_STAGING',
            default   => null,
        };
    }

    private function group(?string $group): string
    {
        return $group !== null && $group !== '' ? $group : (string) config('Database')->defaultGroup;
    }

    private function isTransient(Throwable $e): bool
    {
        $m = strtolower($e->getMessage());
        foreach (['deadlock', 'lock wait timeout', 'server has gone away', 'lost connection', 'too many connections', 'connection refused', 'unable to connect'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function ctl(): FinancialAutomationService
    {
        return $this->control ?? service('financialAutomationService');
    }

    private function cfg(): Platform
    {
        return $this->config ?? config('Platform');
    }
}
