<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\AutomationBlockedException;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use Config\Platform;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Financial Automation Control Center — control state, readiness evaluation and status read-model.
 *
 * Design rules (do not weaken):
 *  - The three ON/OFF switches live in `platform_settings` (server side). They are NOT the env kill-switches in
 *    Config\Platform; those stay false. The scheduler runner elevates the kill-switches IN-PROCESS only, for the
 *    duration of one authorized job, and always restores them (see FinancialAutomationRunnerService).
 *  - Turning a switch ON never posts anything, never approves a draft and never creates a finance approver. It only
 *    records an authorization after every prerequisite check passes.
 *  - Turning a switch OFF only stops FUTURE posting. No ledger, wallet or earning row is deleted or changed.
 *  - No calculation lives here: posting is delegated to MiningPostingRunnerService / CommissionPostingRunnerService.
 */
class FinancialAutomationService
{
    public const CONTROL_MINING     = 'daily_mining';
    public const CONTROL_COMMISSION = 'level_commission';
    public const CONTROL_SCHEDULER  = 'scheduler';

    public const CONTROLS = [self::CONTROL_MINING, self::CONTROL_COMMISSION, self::CONTROL_SCHEDULER];

    public const JOB_MINING     = 'mining';
    public const JOB_COMMISSION = 'commission';

    /** Typed confirmation for databases that are not positively classified as an isolated sandbox. */
    public const CONFIRM_PROTECTED = ProductionSafetyGate::CONFIRM_PHRASE;

    /** Typed confirmation for isolated sandbox / test databases. */
    public const CONFIRM_SANDBOX = 'ENABLE AUTOMATION';

    private const SETTING = [
        self::CONTROL_MINING     => 'automation_daily_mining',
        self::CONTROL_COMMISSION => 'automation_level_commission',
        self::CONTROL_SCHEDULER  => 'automation_scheduler',
    ];

    private const KEY_AUTH_BY   = 'automation_authorized_by';
    private const KEY_AUTH_AT   = 'automation_authorized_at';
    private const KEY_AUTH_DB   = 'automation_authorized_db';
    private const KEY_HEARTBEAT = 'automation_heartbeat_at';

    public const LABELS = [
        self::CONTROL_MINING     => 'Daily Coin Mining',
        self::CONTROL_COMMISSION => 'Level Commission',
        self::CONTROL_SCHEDULER  => 'Automatic Scheduler',
    ];

    /** @var list<string> */
    private const REQUIRED_TABLES = [
        'platform_settings', 'settings_audit', 'financial_automation_runs', 'financial_policy_versions',
        'mining_rate_configs', 'mining_wallets', 'mining_wallet_ledger', 'mining_post_day_runs', 'mining_post_members',
        'commission_plans', 'commission_plan_levels', 'commission_post_batches', 'commission_post_items',
        'commission_calculation_revisions', 'commission_wallet_accounts', 'commission_wallet_ledger_entries',
        'member_coin_locks', 'coin_purchase_requests', 'ops_job_locks', 'audit_logs',
    ];

    public function __construct(
        private readonly ?Platform $config = null,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    // ------------------------------------------------------------------ control state

    /**
     * @return array<string, array{key: string, label: string, enabled: bool, active: bool}>
     */
    public function controls(): array
    {
        $out = [];
        foreach (self::CONTROLS as $control) {
            $enabled = $this->rawEnabled($control);
            $out[$control] = [
                'key'     => $control,
                'label'   => self::LABELS[$control],
                'enabled' => $enabled,
                'active'  => $enabled && $this->authorizationValid(),
            ];
        }

        return $out;
    }

    /** Switch is stored ON *and* its authorization belongs to this database and a real admin. */
    public function isActive(string $control): bool
    {
        $this->assertControl($control);

        return $this->rawEnabled($control) && $this->authorizationValid();
    }

    /** @return array{by: ?int, at: ?string, database: ?string} */
    public function authorization(): array
    {
        $by = $this->setting(self::KEY_AUTH_BY);

        return [
            'by'       => $by !== null && ctype_digit($by) ? (int) $by : null,
            'at'       => $this->setting(self::KEY_AUTH_AT),
            'database' => $this->setting(self::KEY_AUTH_DB),
        ];
    }

    public function confirmPhrase(): string
    {
        return ProductionSafetyGate::requiresProductionProtection($this->databaseName())
            ? self::CONFIRM_PROTECTED
            : self::CONFIRM_SANDBOX;
    }

    public function databaseName(): string
    {
        return (string) db_connect()->getDatabase();
    }

    public function heartbeat(): void
    {
        $this->putSetting(self::KEY_HEARTBEAT, (string) time(), null);
    }

    public function heartbeatAt(): ?int
    {
        $v = $this->setting(self::KEY_HEARTBEAT);

        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }

    // ------------------------------------------------------------------ switching

    /**
     * @return array{changed: bool, control: string, enabled: bool, message: string}
     */
    public function setControl(string $control, bool $on, int $actorUserId, ?string $password = null, ?string $confirm = null, ?string $reason = null): array
    {
        $this->assertControl($control);
        $this->assertActor($actorUserId);

        if ($on) {
            $this->assertReauthenticated($actorUserId, (string) $password, (string) $confirm);
            $readiness = $this->readiness($control);
            if (! $readiness['ready']) {
                $this->auditChange('automation.activation_blocked', $actorUserId, [$control], ['blockers' => $readiness['blockers']], $reason);

                throw new AutomationBlockedException($readiness['blockers']);
            }
        }

        $changed = $this->rawEnabled($control) !== $on;
        $db      = db_connect();
        $db->transBegin();

        try {
            $this->putSetting(self::SETTING[$control], $on ? '1' : '0', $actorUserId, true);
            if ($on) {
                $this->recordAuthorization($actorUserId);
            }
            if ($db->transStatus() === false) {
                throw new RuntimeException('Unable to persist automation switch.');
            }
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        if ($changed) {
            $this->auditChange($on ? 'automation.enabled' : 'automation.disabled', $actorUserId, [$control], [
                'reason'              => $reason,
                'financial_rows_kept' => true,
            ], $reason);
        }

        return [
            'changed' => $changed,
            'control' => $control,
            'enabled' => $on,
            'message' => self::LABELS[$control] . ($on ? ' is ON.' : ' is OFF.') . ($changed ? '' : ' (no change)'),
        ];
    }

    /**
     * Start/Stop All. Start is all-or-nothing: if ANY control is not ready, NOTHING is switched on.
     *
     * @return array{changed: list<string>, enabled: bool, message: string}
     */
    public function setAll(bool $on, int $actorUserId, ?string $password = null, ?string $confirm = null, ?string $reason = null): array
    {
        $this->assertActor($actorUserId);

        if ($on) {
            $this->assertReauthenticated($actorUserId, (string) $password, (string) $confirm);
            $blockers = [];
            foreach (self::CONTROLS as $control) {
                $r = $this->readiness($control);
                foreach ($r['blockers'] as $b) {
                    $blockers[] = self::LABELS[$control] . ': ' . $b;
                }
            }
            if ($blockers !== []) {
                $this->auditChange('automation.activation_blocked', $actorUserId, self::CONTROLS, ['blockers' => $blockers], $reason);

                throw new AutomationBlockedException($blockers);
            }
        }

        $db = db_connect();
        $db->transBegin();
        $changedControls = [];

        try {
            foreach (self::CONTROLS as $control) {
                if ($this->rawEnabled($control) !== $on) {
                    $changedControls[] = $control;
                }
                $this->putSetting(self::SETTING[$control], $on ? '1' : '0', $actorUserId, true);
            }
            if ($on) {
                $this->recordAuthorization($actorUserId);
            }
            if ($db->transStatus() === false) {
                throw new RuntimeException('Unable to persist automation switches.');
            }
            $db->transCommit();
        } catch (Throwable $e) {
            $db->transRollback();

            throw $e;
        }

        if ($changedControls !== []) {
            $this->auditChange($on ? 'automation.start_all' : 'automation.stop_all', $actorUserId, $changedControls, [
                'reason'              => $reason,
                'financial_rows_kept' => true,
            ], $reason);
        }

        return [
            'changed' => $changedControls,
            'enabled' => $on,
            'message' => $on ? 'All automation is ON.' : 'All automation is OFF. Existing earnings and ledger entries are untouched.',
        ];
    }

    // ------------------------------------------------------------------ readiness

    /**
     * @return array{control: string, label: string, ready: bool, checks: list<array{key: string, label: string, ok: bool, detail: string}>, blockers: list<string>}
     */
    public function readiness(string $control, ?DateTimeImmutable $now = null): array
    {
        $this->assertControl($control);
        $checks   = [];
        $checks[] = $this->checkDatabase();
        $checks[] = $this->checkCron();

        if ($control === self::CONTROL_MINING) {
            $date     = $this->dueBusinessDate($now);
            $mining   = $this->checkMiningPolicy();
            $checks[] = $mining;
            $checks[] = $this->checkMiningRate($date);
            $checks[] = $this->checkMandatoryLocks();
        } elseif ($control === self::CONTROL_COMMISSION) {
            $checks[] = $this->checkCommissionPolicy();
            $checks[] = $this->checkCommissionPlan($now);
            $checks[] = $this->checkMandatoryLocks();
        }

        $blockers = [];
        foreach ($checks as $c) {
            if (! $c['ok']) {
                $blockers[] = $c['label'] . ' — ' . $c['detail'];
            }
        }

        return [
            'control'  => $control,
            'label'    => self::LABELS[$control],
            'ready'    => $blockers === [],
            'checks'   => $checks,
            'blockers' => $blockers,
        ];
    }

    /** Business date the next scheduled run will post (yesterday in the business timezone). */
    public function dueBusinessDate(?DateTimeImmutable $now = null): string
    {
        return $this->businessNow($now)->modify('-1 day')->format('Y-m-d');
    }

    public function businessNow(?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $tz = new DateTimeZone($this->cfg()->miningBusinessTimezone ?: 'Asia/Kolkata');

        return ($now ?? new DateTimeImmutable('now', $tz))->setTimezone($tz);
    }

    // ------------------------------------------------------------------ status read-model

    /**
     * @return array<string, mixed>
     */
    public function status(bool $withReconciliation = false, bool $withCommissionPreview = false): array
    {
        $controls  = $this->controls();
        $readiness = [];
        $blockers  = [];
        foreach (self::CONTROLS as $control) {
            $readiness[$control] = $this->readiness($control);
            if (! $controls[$control]['enabled']) {
                foreach ($readiness[$control]['blockers'] as $b) {
                    $blockers[$control . ':' . md5($b)] = self::LABELS[$control] . ': ' . $b;
                }
            }
        }

        $failed = $this->failedJobs();
        foreach ($failed as $f) {
            $blockers['failed:' . $f['run_ref']] = 'Failed job ' . $f['job'] . ' for ' . $f['business_date'] . ' — ' . (string) $f['message'];
        }

        $allOn  = $controls[self::CONTROL_MINING]['active'] && $controls[self::CONTROL_COMMISSION]['active'] && $controls[self::CONTROL_SCHEDULER]['active'];
        $anyOn  = false;
        foreach ($controls as $c) {
            $anyOn = $anyOn || $c['enabled'];
        }

        $out = [
            'controls'      => $controls,
            'readiness'     => $readiness,
            'all_on'        => $allOn,
            'any_on'        => $anyOn,
            'all_ready'     => $readiness[self::CONTROL_MINING]['ready'] && $readiness[self::CONTROL_COMMISSION]['ready'] && $readiness[self::CONTROL_SCHEDULER]['ready'],
            'authorization' => $this->authorization(),
            'confirm_phrase' => $this->confirmPhrase(),
            'database'      => $this->databaseName(),
            'rules'         => $this->businessRules(),
            'restricted'    => $this->restrictedFeatures(),
            'metrics'       => $this->metrics(),
            'last_success'  => $this->lastSuccessfulRuns(),
            'next_run'      => $this->nextRun(),
            'failed_jobs'   => $failed,
            'blockers'      => array_values($blockers),
            'recent_runs'   => $this->recentRuns(10),
            'heartbeat_at'  => $this->heartbeatAt(),
            'due_date'      => $this->dueBusinessDate(),
        ];

        if ($withReconciliation) {
            $out['reconciliation'] = $this->reconciliation();
        }
        if ($withCommissionPreview) {
            $out['commission_preview'] = $this->commissionPreview();
        }

        return $out;
    }

    /**
     * Business rules as configured (display only; calculation stays in the existing engines).
     *
     * @return array{mining: array<string, mixed>, commission: array<string, mixed>}
     */
    public function businessRules(): array
    {
        $cfg  = $this->cfg();
        $plan = null;
        try {
            if (db_connect()->tableExists('commission_plans')) {
                $plan = service('commissionPlanService')->findApplicablePlan($this->businessNow()->format('Y-m-d'));
            }
        } catch (Throwable) {
            $plan = null;
        }
        $levels = (array) ($plan['levels'] ?? []);
        $pct    = static fn (int $l, string $fallback): string => rtrim(rtrim(MoneyMath::normalize((string) ($levels[$l] ?? $fallback), 8), '0'), '.') . '%';

        return [
            'mining' => [
                'monthly_rate'   => '3% monthly',
                'divisor'        => $cfg->miningMonthDayDivisorMode === 'calendar_days' ? 'Actual calendar days of the month' : (string) $cfg->miningMonthDayDivisorMode,
                'compounding'    => 'No compounding',
                'approval_day'   => $cfg->miningApprovalDayPolicy === 'exclude_approval_day' ? 'Approval day excluded (starts next business day)' : (string) $cfg->miningApprovalDayPolicy,
                'reward_asset'   => (string) $cfg->miningEarningAsset,
                'timezone'       => (string) $cfg->miningBusinessTimezone,
                'matches_policy' => $cfg->miningMonthDayDivisorMode === 'calendar_days'
                    && $cfg->miningApprovalDayPolicy === 'exclude_approval_day'
                    && $cfg->miningEarningAsset === 'COIN',
            ],
            'commission' => [
                'level_1'      => $pct(1, '10'),
                'level_2'      => $pct(2, '5'),
                'level_3'      => $pct(3, '3'),
                'base'         => 'Approved USDT purchase amount',
                'plan_ref'     => $plan['plan_ref'] ?? null,
                'plan_approved' => $plan !== null,
                'note'         => 'Subject to formal policy approval.',
            ],
        ];
    }

    /**
     * Features this control center never touches. Informational: they stay OFF unless separately authorized.
     *
     * @return list<array{label: string, on: bool}>
     */
    public function restrictedFeatures(): array
    {
        $cfg = $this->cfg();

        return [
            ['label' => 'Mining P2P transfers', 'on' => (bool) $cfg->miningP2pEnabled],
            ['label' => 'Commission withdrawals', 'on' => (bool) $cfg->commissionWithdrawalEnabled],
            ['label' => 'Coin selling', 'on' => (bool) $cfg->coinSellingEnabled],
            ['label' => 'Blockchain payouts', 'on' => (bool) $cfg->commissionBlockchainPayoutEnabled],
            ['label' => 'Commission auto-post on purchase approval', 'on' => (bool) $cfg->commissionAutoPostOnApprovalEnabled],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $db  = db_connect();
        $out = [
            'mining_credit_count' => 0, 'mining_credit_total' => '0.00000000',
            'commission_post_count' => 0, 'commission_post_total' => '0.00000000',
        ];
        if ($db->tableExists('mining_wallet_ledger')) {
            $r = $db->table('mining_wallet_ledger')->select('COUNT(*) AS c, COALESCE(SUM(amount),0) AS t', false)
                ->where('entry_type', 'daily_mining_credit')->where('direction', 'credit')->get()->getRowArray();
            $out['mining_credit_count'] = (int) ($r['c'] ?? 0);
            $out['mining_credit_total'] = MoneyMath::normalize((string) ($r['t'] ?? '0'), 8);
        }
        if ($db->tableExists('commission_wallet_ledger_entries')) {
            $r = $db->table('commission_wallet_ledger_entries')->select('COUNT(*) AS c, COALESCE(SUM(amount),0) AS t', false)
                ->where('entry_type', CommissionWalletService::TYPE_COMMISSION_CREDIT)->get()->getRowArray();
            $out['commission_post_count'] = (int) ($r['c'] ?? 0);
            $out['commission_post_total'] = MoneyMath::normalize((string) ($r['t'] ?? '0'), 8);
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>|null>
     */
    public function lastSuccessfulRuns(): array
    {
        $out = [self::JOB_MINING => null, self::JOB_COMMISSION => null];
        if (! db_connect()->tableExists('financial_automation_runs')) {
            return $out;
        }
        foreach (array_keys($out) as $job) {
            $out[$job] = db_connect()->table('financial_automation_runs')
                ->where('job', $job)->where('status', 'success')->orderBy('id', 'DESC')->get()->getRowArray() ?: null;
        }

        return $out;
    }

    /**
     * Failed/blocked attempts in the last 7 days with no later success for the same job and business date.
     *
     * @return list<array<string, mixed>>
     */
    public function failedJobs(): array
    {
        $db = db_connect();
        if (! $db->tableExists('financial_automation_runs')) {
            return [];
        }
        $since = date('Y-m-d H:i:s', time() - 7 * 86400);
        $stale = date('Y-m-d H:i:s', time() - 2 * 3600);
        $rows  = $db->table('financial_automation_runs')
            ->groupStart()
                ->whereIn('status', ['failed', 'blocked'])
                ->orGroupStart()->where('status', 'running')->where('started_at <', $stale)->groupEnd()
            ->groupEnd()
            ->where('started_at >=', $since)
            ->orderBy('id', 'DESC')
            ->get()->getResultArray();

        $out = [];
        $seen = [];
        foreach ($rows as $row) {
            $k = $row['job'] . '|' . $row['business_date'];
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $ok = $db->table('financial_automation_runs')
                ->where('job', $row['job'])->where('business_date', $row['business_date'])->where('status', 'success')
                ->countAllResults();
            if ($ok === 0) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 10): array
    {
        if (! db_connect()->tableExists('financial_automation_runs')) {
            return [];
        }

        return db_connect()->table('financial_automation_runs')->orderBy('id', 'DESC')->limit(max(1, min(50, $limit)))->get()->getResultArray();
    }

    /**
     * @return array{label: string, at: ?string, business_date: ?string}
     */
    public function nextRun(?DateTimeImmutable $now = null): array
    {
        if (! $this->isActive(self::CONTROL_SCHEDULER)) {
            return ['label' => 'Not scheduled — Automatic Scheduler is OFF', 'at' => null, 'business_date' => null];
        }
        $now  = $this->businessNow($now);
        [$h, $m] = $this->runTimeParts();
        $todayRun = $now->setTime($h, $m);
        $date     = $this->dueBusinessDate($now);

        $pending = false;
        foreach ([self::CONTROL_MINING => self::JOB_MINING, self::CONTROL_COMMISSION => self::JOB_COMMISSION] as $control => $job) {
            if ($this->isActive($control) && ! $this->jobSucceeded($job, $date)) {
                $pending = true;
            }
        }

        if ($now < $todayRun) {
            $next = $todayRun;
            $label = 'Next scheduled run';
        } elseif ($pending) {
            return ['label' => 'Due now — runs on the next cron tick (every ~10 min)', 'at' => $now->format('Y-m-d H:i T'), 'business_date' => $date];
        } else {
            $next = $todayRun->modify('+1 day');
            $label = 'Next scheduled run';
        }

        return ['label' => $label, 'at' => $next->format('Y-m-d H:i T'), 'business_date' => $next->modify('-1 day')->format('Y-m-d')];
    }

    public function jobSucceeded(string $job, string $businessDate): bool
    {
        return db_connect()->table('financial_automation_runs')
            ->where('job', $job)->where('business_date', $businessDate)->where('status', 'success')->countAllResults() > 0;
    }

    /** @return array{0: int, 1: int} */
    public function runTimeParts(): array
    {
        $raw = (string) $this->cfg()->automationDailyRunTime;
        if (! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $raw, $m)) {
            return [1, 30];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * Read-only financial reconciliation of what automation produced. Uses existing reconciliation where it exists.
     *
     * @return array{ok: bool, issues: list<string>, mining: array<string, mixed>, commission: array<string, mixed>}
     */
    public function reconciliation(): array
    {
        $db     = db_connect();
        $issues = [];
        $mining = ['ledger_credits' => '0.00000000', 'wallet_credited' => '0.00000000', 'wallet_balance' => '0.00000000', 'ledger_net' => '0.00000000', 'day_runs_total' => '0.00000000'];
        $comm   = ['wallet_issue_count' => 0];

        if ($db->tableExists('mining_wallet_ledger') && $db->tableExists('mining_wallets')) {
            $credits = (string) ($db->table('mining_wallet_ledger')->select('COALESCE(SUM(amount),0) AS t', false)->where('direction', 'credit')->get()->getRow('t') ?? '0');
            $debits  = (string) ($db->table('mining_wallet_ledger')->select('COALESCE(SUM(amount),0) AS t', false)->where('direction', 'debit')->get()->getRow('t') ?? '0');
            $wallets = $db->table('mining_wallets')->select('COALESCE(SUM(available_balance),0) AS b, COALESCE(SUM(total_credited),0) AS c', false)->get()->getRowArray();
            $mining['ledger_credits']  = MoneyMath::normalize($credits, 8);
            $mining['ledger_net']      = MoneyMath::sub(MoneyMath::normalize($credits, 8), MoneyMath::normalize($debits, 8), 8);
            $mining['wallet_balance']  = MoneyMath::normalize((string) ($wallets['b'] ?? '0'), 8);
            $mining['wallet_credited'] = MoneyMath::normalize((string) ($wallets['c'] ?? '0'), 8);
            if (MoneyMath::compare($mining['ledger_net'], $mining['wallet_balance'], 8) !== 0) {
                $issues[] = 'Mining ledger net (' . $mining['ledger_net'] . ') differs from wallet balances (' . $mining['wallet_balance'] . ').';
            }
            if (MoneyMath::compare($mining['ledger_credits'], $mining['wallet_credited'], 8) !== 0) {
                $issues[] = 'Mining ledger credits (' . $mining['ledger_credits'] . ') differ from wallet total_credited (' . $mining['wallet_credited'] . ').';
            }
            $dupes = $db->query('SELECT member_user_id, business_date, COUNT(*) c FROM mining_wallet_ledger WHERE entry_type = ? GROUP BY member_user_id, business_date HAVING c > 1', ['daily_mining_credit'])->getResultArray();
            if ($dupes !== []) {
                $issues[] = 'Duplicate mining credits for the same member and business date: ' . count($dupes) . '.';
            }
        }
        if ($db->tableExists('mining_post_day_runs') && $db->tableExists('mining_wallet_ledger')) {
            $dayTotal = (string) ($db->table('mining_post_day_runs')->select('COALESCE(SUM(total_credited),0) AS t', false)->where('state', 'COMPLETED')->get()->getRow('t') ?? '0');
            $mining['day_runs_total'] = MoneyMath::normalize($dayTotal, 8);
            $dates = array_column($db->table('mining_post_day_runs')->select('business_date')->where('state', 'COMPLETED')->get()->getResultArray(), 'business_date');
            $ledgerForDays = '0';
            if ($dates !== []) {
                $ledgerForDays = (string) ($db->table('mining_wallet_ledger')->select('COALESCE(SUM(amount),0) AS t', false)
                    ->where('entry_type', 'daily_mining_credit')->whereIn('business_date', $dates)->get()->getRow('t') ?? '0');
            }
            if (MoneyMath::compare(MoneyMath::normalize($ledgerForDays, 8), $mining['day_runs_total'], 8) !== 0) {
                $issues[] = 'Completed mining day runs (' . $mining['day_runs_total'] . ') differ from ledger credits for those dates (' . MoneyMath::normalize($ledgerForDays, 8) . ').';
            }
        }
        if ($db->tableExists('commission_wallet_ledger_entries') && $db->tableExists('commission_wallet_accounts')) {
            try {
                $rec = (new CommissionWalletReconciliationService())->run();
                $comm['wallet_issue_count'] = (int) ($rec['issue_count'] ?? 0);
                if ($comm['wallet_issue_count'] > 0) {
                    $issues[] = 'Commission wallet reconciliation reports ' . $comm['wallet_issue_count'] . ' issue(s).';
                }
            } catch (Throwable $e) {
                $issues[] = 'Commission reconciliation could not run: ' . $e->getMessage();
            }
        }

        return ['ok' => $issues === [], 'issues' => $issues, 'mining' => $mining, 'commission' => $comm];
    }

    /**
     * Read-only preview of what the first commission run would post (existing engine, dry-run).
     *
     * @return array<string, mixed>
     */
    public function commissionPreview(): array
    {
        try {
            $p = service('commissionPostingRunnerService')->preview(null, null, true);
            unset($p['items']);

            return $p + ['error' => null];
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    // ------------------------------------------------------------------ checks

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkDatabase(): array
    {
        $db = db_connect();
        try {
            $db->query('SELECT 1');
        } catch (Throwable $e) {
            return $this->check('database', 'Database ready', false, 'Database connection failed: ' . $e->getMessage());
        }
        $missing = [];
        foreach (self::REQUIRED_TABLES as $t) {
            if (! $db->tableExists($t)) {
                $missing[] = $t;
            }
        }
        if ($missing !== []) {
            return $this->check('database', 'Database ready', false, 'Missing tables (run pending migrations): ' . implode(', ', $missing));
        }

        return $this->check('database', 'Database ready', true, 'Connected to "' . $db->getDatabase() . '"; all required tables exist.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkCron(): array
    {
        $at = $this->heartbeatAt();
        $max = max(60, (int) $this->cfg()->automationHeartbeatMaxAgeSeconds);
        if ($at === null) {
            return $this->check('cron', 'Server cron working', false, 'No cron heartbeat yet. Add the server cron line for `php spark automation:run` (every 10 minutes) and wait for it to run.');
        }
        $age = time() - $at;
        if ($age > $max) {
            return $this->check('cron', 'Server cron working', false, 'Last cron heartbeat was ' . $this->ago($age) . ' ago (limit ' . $this->ago($max) . '). Check the server cron.');
        }

        return $this->check('cron', 'Server cron working', true, 'Last cron heartbeat ' . $this->ago($age) . ' ago.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkMiningPolicy(): array
    {
        $label = 'Mining policy approved & active';
        $cfg   = $this->cfg();
        $row   = $this->latestPolicy(FinancialPolicyService::DOMAIN_MINING);
        if ($row === null) {
            return $this->check('mining_policy', $label, false, 'No approved/active Mining policy in Financial Policies (Advanced). Draft and dual-control approve it first.');
        }
        $why = $this->governance()->approvalProblem(FinancialGovernanceService::SUBJECT_POLICY, $row);
        if ($why !== null) {
            return $this->check('mining_policy', $label, false, $row['policy_ref'] . ': ' . $why);
        }
        $why = $this->effectiveProblem($row);
        if ($why !== null) {
            return $this->check('mining_policy', $label, false, $row['policy_ref'] . ': ' . $why);
        }

        // One validator for saving, review, approval and readiness: App\Libraries\MiningPolicyPayload.
        $bad = \App\Libraries\MiningPolicyPayload::problems($this->payload($row), $cfg);
        if ($bad !== []) {
            return $this->check('mining_policy', $label, false, $row['policy_ref'] . ': ' . implode('; ', $bad) . '.');
        }

        return $this->check('mining_policy', $label, true, $row['policy_ref'] . ' (' . $row['status'] . '), ' . $this->governance()->approvalLabel(FinancialGovernanceService::SUBJECT_POLICY, $row) . '.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkMiningRate(string $businessDate): array
    {
        $label = 'Approved mining rate';
        if (! db_connect()->tableExists('mining_rate_configs')) {
            return $this->check('mining_rate', $label, false, 'mining_rate_configs table is missing.');
        }
        $rate = service('miningRateService')->approvedForDate($businessDate);
        if ($rate === null) {
            return $this->check('mining_rate', $label, false, 'No approved mining rate for ' . substr($businessDate, 0, 7) . ' (next business date ' . $businessDate . ').');
        }
        $why = $this->governance()->approvalProblem(FinancialGovernanceService::SUBJECT_RATE, $rate);
        if ($why !== null) {
            return $this->check('mining_rate', $label, false, $rate['rate_ref'] . ': ' . $why);
        }
        if (($rate['rate_basis'] ?? '') !== 'monthly' || MoneyMath::compare(MoneyMath::normalize((string) $rate['rate_percent'], 8), '3', 8) !== 0) {
            return $this->check('mining_rate', $label, false, $rate['rate_ref'] . ' is ' . $rate['rate_percent'] . '% ' . $rate['rate_basis'] . ' — the approved business rule is 3% monthly.');
        }

        return $this->check('mining_rate', $label, true, $rate['rate_ref'] . ' — 3% monthly for ' . $rate['effective_month'] . ', ' . $this->governance()->approvalLabel(FinancialGovernanceService::SUBJECT_RATE, $rate) . '.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkCommissionPolicy(): array
    {
        $label = 'Commission policy approved & active';
        $row   = $this->latestPolicy(FinancialPolicyService::DOMAIN_COMMISSION);
        if ($row === null) {
            return $this->check('commission_policy', $label, false, 'No approved/active Commission policy in Financial Policies (Advanced). Draft and dual-control approve it first.');
        }
        foreach ([$this->governance()->approvalProblem(FinancialGovernanceService::SUBJECT_POLICY, $row), $this->effectiveProblem($row)] as $why) {
            if ($why !== null) {
                return $this->check('commission_policy', $label, false, $row['policy_ref'] . ': ' . $why);
            }
        }

        return $this->check('commission_policy', $label, true, $row['policy_ref'] . ' (' . $row['status'] . '), ' . $this->governance()->approvalLabel(FinancialGovernanceService::SUBJECT_POLICY, $row) . '.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkCommissionPlan(?DateTimeImmutable $now): array
    {
        $label = 'Approved commission plan (L1 10% / L2 5% / L3 3%)';
        if (! db_connect()->tableExists('commission_plans')) {
            return $this->check('commission_plan', $label, false, 'commission_plans table is missing.');
        }
        $plan = service('commissionPlanService')->findApplicablePlan($this->businessNow($now)->format('Y-m-d'));
        if ($plan === null) {
            return $this->check('commission_plan', $label, false, 'No approved commission plan is effective today.');
        }
        $why = $this->governance()->approvalProblem(FinancialGovernanceService::SUBJECT_PLAN, $plan);
        if ($why !== null) {
            return $this->check('commission_plan', $label, false, $plan['plan_ref'] . ': ' . $why);
        }
        $levels = (array) $plan['levels'];
        foreach ([1 => '10', 2 => '5', 3 => '3'] as $level => $expected) {
            if (! isset($levels[$level]) || MoneyMath::compare((string) $levels[$level], $expected, 8) !== 0) {
                return $this->check('commission_plan', $label, false, $plan['plan_ref'] . ' level ' . $level . ' is ' . ($levels[$level] ?? 'missing') . '% — expected ' . $expected . '%.');
            }
        }
        if (count($levels) !== 3) {
            return $this->check('commission_plan', $label, false, $plan['plan_ref'] . ' must define exactly three levels.');
        }
        if (strtoupper((string) $plan['commission_currency']) !== 'USDT') {
            return $this->check('commission_plan', $label, false, $plan['plan_ref'] . ' pays in ' . $plan['commission_currency'] . ', expected USDT.');
        }

        return $this->check('commission_plan', $label, true, $plan['plan_ref'] . ' — 10% / 5% / 3% of the approved USDT purchase amount, ' . $this->governance()->approvalLabel(FinancialGovernanceService::SUBJECT_PLAN, $plan) . '.');
    }

    /** @return array{key: string, label: string, ok: bool, detail: string} */
    private function checkMandatoryLocks(): array
    {
        $label = 'Mandatory purchase locks in place';
        $cfg   = $this->cfg();
        if (! $cfg->mandatoryPurchaseLockEnabled) {
            return $this->check('mandatory_locks', $label, false, 'Mandatory purchase locking is disabled in the platform configuration.');
        }
        $db = db_connect();
        if (! $db->tableExists('coin_purchase_requests') || ! $db->tableExists('member_coin_locks')) {
            return $this->check('mandatory_locks', $label, false, 'Purchase/lock tables are missing.');
        }
        $missing = (int) ($db->query(
            'SELECT COUNT(*) AS c FROM coin_purchase_requests p
               LEFT JOIN member_coin_locks l ON l.purchase_ref = p.purchase_ref AND l.lock_kind = ?
              WHERE p.status = ? AND l.id IS NULL',
            [CoinLockingService::KIND_MANDATORY_PURCHASE, 'approved']
        )->getRow('c') ?? 0);
        if ($missing > 0) {
            return $this->check('mandatory_locks', $label, false, $missing . ' approved purchase(s) have no mandatory lock. Remediate them (Financial Policies → Purchase/Lock) before activating.');
        }

        return $this->check('mandatory_locks', $label, true, 'Every approved purchase has its mandatory lock.');
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @return array{key: string, label: string, ok: bool, detail: string}
     */
    private function check(string $key, string $label, bool $ok, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'ok' => $ok, 'detail' => $detail];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestPolicy(string $domain): ?array
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            return null;
        }

        return db_connect()->table('financial_policy_versions')
            ->where('domain', $domain)
            ->whereIn('status', [FinancialPolicyService::STATUS_APPROVED, FinancialPolicyService::STATUS_ACTIVE])
            ->orderBy('id', 'DESC')
            ->get()->getRowArray() ?: null;
    }

    private function governance(): FinancialGovernanceService
    {
        return service('financialGovernanceService');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function effectiveProblem(array $row): ?string
    {
        $from = (string) ($row['effective_from'] ?? '');
        if ($from !== '' && $from > $this->businessNow()->format('Y-m-d')) {
            return 'not effective until ' . $from . '.';
        }
        $to = (string) ($row['effective_to'] ?? '');
        if ($to !== '' && $to < $this->businessNow()->format('Y-m-d')) {
            return 'expired on ' . $to . '.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function payload(array $row): array
    {
        $p = json_decode((string) ($row['payload_json'] ?? ''), true);

        return is_array($p) ? $p : [];
    }

    private function rawEnabled(string $control): bool
    {
        return $this->setting(self::SETTING[$control]) === '1';
    }

    private function authorizationValid(): bool
    {
        $a = $this->authorization();
        if ($a['by'] === null || $a['by'] <= 0 || $a['database'] === null || $a['database'] !== $this->databaseName()) {
            return false;
        }
        $user = db_connect()->table('users')->select('id, active')->where('id', $a['by'])->get()->getRowArray();

        return $user !== null && (int) ($user['active'] ?? 1) === 1;
    }

    private function recordAuthorization(int $actorUserId): void
    {
        $this->putSetting(self::KEY_AUTH_BY, (string) $actorUserId, $actorUserId, true);
        $this->putSetting(self::KEY_AUTH_AT, date('Y-m-d H:i:s'), $actorUserId, true);
        $this->putSetting(self::KEY_AUTH_DB, $this->databaseName(), $actorUserId, true);
    }

    private function setting(string $key): ?string
    {
        $db = db_connect();
        if (! $db->tableExists('platform_settings')) {
            return null;
        }
        $row = $db->table('platform_settings')->where('setting_key', $key)->get()->getRowArray();

        return $row === null ? null : (string) ($row['setting_value'] ?? '');
    }

    private function putSetting(string $key, string $value, ?int $by, bool $audit = false): void
    {
        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $existing = $db->table('platform_settings')->where('setting_key', $key)->get()->getRowArray();
        $old      = $existing === null ? '' : (string) ($existing['setting_value'] ?? '');

        if ($existing === null) {
            $db->table('platform_settings')->insert([
                'setting_key' => $key, 'setting_value' => $value, 'updated_by' => $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } elseif ($old !== $value) {
            $db->table('platform_settings')->where('setting_key', $key)->update([
                'setting_value' => $value, 'updated_by' => $by, 'updated_at' => $now,
            ]);
        } else {
            return;
        }

        if ($audit && $by !== null && $by > 0) {
            $request = service('request');
            $db->table('settings_audit')->insert([
                'setting_key'    => $key,
                'previous_value' => $old,
                'new_value'      => $value,
                'changed_by'     => $by,
                'changed_at'     => $now,
                'ip_address'     => is_object($request) && method_exists($request, 'getIPAddress') ? $request->getIPAddress() : null,
            ]);
        }
    }

    /**
     * @param list<string>         $controls
     * @param array<string, mixed> $meta
     */
    private function auditChange(string $action, int $actorUserId, array $controls, array $meta, ?string $reason): void
    {
        try {
            ($this->audit ?? new AuditLogger())->log($action, $actorUserId, 'financial_automation', implode(',', $controls), $meta + [
                'controls' => $controls,
                'database' => $this->databaseName(),
            ]);
        } catch (Throwable $e) {
            log_message('error', '[automation] audit log failed: {m}', ['m' => $e->getMessage()]);
        }
    }

    private function assertControl(string $control): void
    {
        if (! in_array($control, self::CONTROLS, true)) {
            throw new InvalidArgumentException('Unknown automation control.');
        }
    }

    private function assertActor(int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('An authenticated administrator is required.');
        }
        if (service('impersonationService')->activeContext() !== null || (! is_cli() && str_starts_with(uri_string(), 'support-view'))) {
            throw new RuntimeException('Financial automation cannot be changed from Support View.');
        }
    }

    private function assertReauthenticated(int $actorUserId, string $password, string $confirm): void
    {
        $expected = $this->confirmPhrase();
        if (trim($confirm) !== $expected) {
            throw new InvalidArgumentException('Type the confirmation phrase exactly: ' . $expected);
        }

        $password = trim($password);
        if ($password === '') {
            throw new InvalidArgumentException('Admin password reauthentication is required.');
        }
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $actorUserId) {
            throw new InvalidArgumentException('Authenticated admin mismatch.');
        }
        $hash = (string) ($user->password_hash ?? '');
        if ($hash === '' || ! service('passwords')->verify($password, $hash)) {
            $identity = db_connect()->table('auth_identities')
                ->where('user_id', $actorUserId)->where('type', 'email_password')->get()->getRowArray();
            $secret = (string) ($identity['secret2'] ?? $identity['secret'] ?? '');
            if ($secret === '' || ! service('passwords')->verify($password, $secret)) {
                throw new InvalidArgumentException('Password reauthentication failed.');
            }
        }
    }

    private function ago(int $seconds): string
    {
        if ($seconds < 90) {
            return $seconds . 's';
        }
        if ($seconds < 5400) {
            return (int) round($seconds / 60) . ' min';
        }

        return round($seconds / 3600, 1) . ' h';
    }

    private function cfg(): Platform
    {
        return $this->config ?? config('Platform');
    }
}
