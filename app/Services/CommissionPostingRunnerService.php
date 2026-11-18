<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Closure;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 14 — controlled, idempotent, resumable level commission posting & historical recovery runner.
 *
 * Design:
 *  - Dry-run by default (reads only; zero ledger/wallet/batch writes).
 *  - Preserves immutable NO_APPROVED_PLAN rows in `commission_purchase_calculations` by writing
 *    append-only superseding calculations into `commission_calculation_revisions`.
 *  - Enforces kill-switches (`commissionPostingEnabled`, plus `commissionAutoPostOnApprovalEnabled` for
 *    purchase-approval events), live DB safety gate, commission policy approval flags, and OpsJobLock.
 *  - Supports keyset-paginated batching (`max_batches`, `max_runtime`), crash-safe resume (`PARTIAL` -> `COMPLETED`),
 *    bounded transient retries, idempotency (`comm-post:{purchase_ref}:L{level}`), and SQL reconciliation.
 */
class CommissionPostingRunnerService
{
    public const CONFIRM_STAGING = 'APPLY_STAGING';
    public const LOCK_NAME       = 'commission_post';

    /** @var list<string> */
    private const TRANSIENT_MARKERS = [
        'deadlock', 'lock wait timeout', 'try restarting transaction', 'server has gone away',
        'lost connection', 'too many connections', 'connection refused', 'unable to connect',
    ];

    /** @var list<string> */
    private const POLICY_STATUS_FIELDS = [
        'commissionBasePolicyStatus',
        'commissionRoundingPolicyStatus',
        'commissionRootEligibilityPolicyStatus',
    ];

    public function __construct(
        private readonly ?CommissionPlanService $plans = null,
        private readonly ?PurchaseSponsorSnapshotService $snapshots = null,
        private readonly ?CommissionWalletService $wallets = null,
        private readonly ?OpsJobLockService $locks = null,
        private readonly ?Platform $config = null,
        private readonly ?Closure $sleeper = null,
        private readonly ?string $logFile = null,
    ) {
    }

    /**
     * Classify database group for commission execution.
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

        if (in_array($db, ProductionSafetyGate::liveDatabases(), true) || str_contains(strtolower($db), 'prod')) {
            return ['mode' => 'live', 'database' => $db, 'group' => $group];
        }
        if ($info['is_allowed']) {
            return ['mode' => 'sandbox', 'database' => $db, 'group' => $group];
        }
        if (ENVIRONMENT === 'testing' && $db === (string) ($dbConfig->tests['database'] ?? '')) {
            return ['mode' => 'testing', 'database' => $db, 'group' => $group];
        }

        throw new RuntimeException(
            'Refusing commission posting on database "' . $db . '": not a recognized live DB, not an allowlisted '
            . 'sandbox DB and not the PHPUnit DB.'
        );
    }

    /**
     * Build (and optionally persist) append-only superseding calculation revisions for approved purchases
     * whose initial `commission_purchase_calculations` rows are `NO_APPROVED_PLAN` (or missing).
     * Never updates or deletes historical `commission_purchase_calculations` rows.
     *
     * @return array{
     *   plan_ref: ?string,
     *   root_eligible: bool,
     *   purchases_scanned: int,
     *   eligible_lines: int,
     *   unallocated_lines: int,
     *   ineligible_lines: int,
     *   revisions_created: int,
     *   revisions_existing: int,
     *   expected_total_usdt: string,
     *   lines: list<array<string, mixed>>
     * }
     */
    public function generateSupersedingRevisions(
        ?string $planRef = null,
        bool $persist = true,
        bool $includeRoot = true,
        ?int $actorUserId = null,
        ?string $onlyPurchaseRef = null
    ): array {
        $db    = db_connect();
        $plans = $this->plans ?? new CommissionPlanService();
        $snaps = $this->snapshots ?? new PurchaseSponsorSnapshotService();
        $scale = $this->scale();
        $cfg   = $this->config ?? config('Platform');

        if ($persist && ! $db->tableExists('commission_calculation_revisions')) {
            throw new RuntimeException('Missing table commission_calculation_revisions (apply migration 995001).');
        }

        $explicitPlan = null;
        if ($planRef !== null && trim($planRef) !== '') {
            $explicitPlan = $plans->findByRef(trim($planRef));
            if ($explicitPlan === null) {
                throw new InvalidArgumentException('Commission plan not found: ' . $planRef);
            }
            if (($explicitPlan['status'] ?? '') !== CommissionPlanService::STATUS_APPROVED) {
                throw new InvalidArgumentException('Commission plan ' . $planRef . ' is not approved (status=' . ($explicitPlan['status'] ?? 'unknown') . ').');
            }
        }

        $builder = $db->table('coin_purchase_requests')
            ->where('status', 'approved')
            ->orderBy('id', 'ASC');
        if ($onlyPurchaseRef !== null && trim($onlyPurchaseRef) !== '') {
            $builder->where('purchase_ref', trim($onlyPurchaseRef));
        }
        $purchases = $builder->get()->getResultArray();

        $outLines          = [];
        $eligibleLines     = 0;
        $unallocatedLines  = 0;
        $ineligibleLines   = 0;
        $revisionsCreated  = 0;
        $revisionsExisting = 0;
        $expectedTotal     = MoneyMath::normalize('0', $scale);
        $usedPlanRef       = $explicitPlan['plan_ref'] ?? null;

        $assumptions = [
            'commission_base' => 'approved_purchase_total_usdt',
            'rounding'        => 'bcmath_truncate_toward_zero_at_usdt_scale',
            'root_eligible'   => $includeRoot,
            'append_only'     => true,
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($purchases as $purchase) {
            $pRef         = (string) $purchase['purchase_ref'];
            $businessDate = substr((string) ($purchase['reviewed_at'] ?? $purchase['requested_at'] ?? date('Y-m-d')), 0, 10);
            $plan         = $explicitPlan;
            if ($plan !== null) {
                $from = (string) ($plan['effective_from'] ?? '');
                $to   = $plan['effective_to'] ?? null;
                if ($from !== '' && strcmp($businessDate, $from) < 0) {
                    $plan = null;
                }
                if ($plan !== null && $to !== null && $to !== '' && strcmp($businessDate, (string) $to) > 0) {
                    $plan = null;
                }
            } else {
                $plan = $plans->findApplicablePlan($businessDate);
            }

            if ($plan === null || ($plan['status'] ?? '') !== CommissionPlanService::STATUS_APPROVED) {
                continue;
            }
            $usedPlanRef ??= (string) $plan['plan_ref'];

            // Index immutable base calculation rows by sponsor_level if any
            $origByLevel = [];
            if ($db->tableExists('commission_purchase_calculations')) {
                foreach ($db->table('commission_purchase_calculations')->where('purchase_ref', $pRef)->get()->getResultArray() as $c) {
                    $origByLevel[(int) $c['sponsor_level']] = $c;
                }
            }

            $trust = $snaps->evaluateTrust($pRef);
            $snapByLevel = [];
            foreach ($trust['levels'] as $lvl) {
                $snapByLevel[(int) $lvl['sponsor_level']] = $lvl;
            }

            $base         = MoneyMath::normalize((string) ($purchase['total_usdt'] ?? '0'), $scale);
            $purchaserUid = (int) $purchase['member_user_id'];

            for ($level = 1; $level <= 3; $level++) {
                $orig = $origByLevel[$level] ?? null;
                // Only generate superseding revisions when original was absent or NO_APPROVED_PLAN
                if ($orig !== null && ($orig['status'] ?? '') !== CommissionPurchaseEngineService::STATUS_NO_PLAN) {
                    continue;
                }

                $snap    = $snapByLevel[$level] ?? null;
                $percent = isset($plan['levels'][$level]) ? MoneyMath::normalize((string) $plan['levels'][$level], $scale) : null;
                $reasons = [];

                if ((bool) $cfg->requireVerifiedDepositForApproval && strtolower((string) ($purchase['payment_status'] ?? '')) !== 'verified') {
                    $reasons[] = 'payment_not_verified';
                }

                $status      = 'CALCULATED_SUPERSEDING';
                $eligStatus  = 'eligible';
                $benUid      = null;
                $benMid      = null;
                $expectedAmt = MoneyMath::normalize('0', $scale);

                if (! $trust['trusted']) {
                    $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                    $eligStatus = 'ineligible';
                    $reasons    = array_merge($reasons, $trust['reason_codes'] ?: ['untrusted_sponsor_snapshot']);
                    $ineligibleLines++;
                } elseif ($percent === null) {
                    $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                    $eligStatus = 'ineligible';
                    $reasons[]  = 'missing_plan_level';
                    $ineligibleLines++;
                } elseif ($snap === null || ! empty($snap['is_missing']) || empty($snap['sponsor_user_id'])) {
                    $status     = CommissionPurchaseEngineService::STATUS_UNALLOCATED;
                    $eligStatus = 'ineligible';
                    $reasons[]  = 'missing_sponsor_level_' . $level;
                    $unallocatedLines++;
                } else {
                    $benUid = (int) $snap['sponsor_user_id'];
                    $benMid = (string) $snap['sponsor_member_id'];
                    $prof   = $db->table('member_profiles')
                        ->select('account_status, is_root_sponsor')
                        ->where('user_id', $benUid)
                        ->get()
                        ->getRowArray();

                    if ($benUid === $purchaserUid) {
                        $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                        $eligStatus = 'ineligible';
                        $reasons[]  = 'self_commission_blocked';
                        $ineligibleLines++;
                    } elseif (! $includeRoot && ($benMid === 'ROOT0001' || (int) ($prof['is_root_sponsor'] ?? 0) === 1)) {
                        $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                        $eligStatus = 'ineligible';
                        $reasons[]  = 'root_sponsor_excluded_by_policy';
                        $ineligibleLines++;
                    } elseif (($plan['sponsor_eligibility_policy'] ?? '') === 'active_sponsors_only'
                        && ($prof === null || ($prof['account_status'] ?? '') !== 'active')
                    ) {
                        $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                        $eligStatus = 'ineligible';
                        $reasons[]  = 'sponsor_not_active';
                        $ineligibleLines++;
                    } elseif ($reasons !== []) {
                        $status     = CommissionPurchaseEngineService::STATUS_INELIGIBLE;
                        $eligStatus = 'ineligible';
                        $ineligibleLines++;
                    } else {
                        $expectedAmt = MoneyMath::div(MoneyMath::mul($base, $percent, 12), '100', $scale);
                        $reasons[]   = 'eligible_superseding_revision';
                        $eligibleLines++;
                        $expectedTotal = MoneyMath::add($expectedTotal, $expectedAmt, $scale);
                    }
                }

                $idem = sprintf('rev:%s:L%d:%s:root%d', $pRef, $level, (string) $plan['plan_ref'], $includeRoot ? 1 : 0);
                $row  = [
                    'supersedes_calc_ref'   => $orig['calc_ref'] ?? null,
                    'purchase_ref'          => $pRef,
                    'purchase_id'           => (int) $purchase['id'],
                    'purchaser_user_id'     => $purchaserUid,
                    'purchaser_member_id'   => (string) $purchase['member_id'],
                    'sponsor_level'         => $level,
                    'beneficiary_user_id'   => $benUid,
                    'beneficiary_member_id' => $benMid,
                    'commission_base_usdt'  => $base,
                    'rate_percent'          => $percent,
                    'expected_amount_usdt'  => $expectedAmt,
                    'plan_ref'              => (string) $plan['plan_ref'],
                    'plan_effective_from'   => (string) $plan['effective_from'],
                    'business_date'         => $businessDate,
                    'root_eligible'         => $includeRoot ? 1 : 0,
                    'status'                => $status,
                    'eligibility_status'    => $eligStatus,
                    'reason_codes'          => implode(',', array_values(array_unique($reasons))),
                    'assumptions_json'      => json_encode($assumptions, JSON_THROW_ON_ERROR),
                    'idempotency_key'       => $idem,
                    'created_by'            => $actorUserId,
                    'created_at'            => $now,
                ];

                if ($persist) {
                    $existing = $db->table('commission_calculation_revisions')
                        ->where('idempotency_key', $idem)
                        ->get()
                        ->getRowArray();
                    if ($existing !== null) {
                        $row['revision_ref'] = $existing['revision_ref'];
                        $revisionsExisting++;
                    } else {
                        $row['revision_ref'] = 'CCR-' . strtoupper(bin2hex(random_bytes(6)));
                        $db->table('commission_calculation_revisions')->insert($row);
                        $revisionsCreated++;
                    }
                }

                $outLines[] = $row;
            }
        }

        return [
            'plan_ref'           => $usedPlanRef,
            'root_eligible'      => $includeRoot,
            'purchases_scanned'  => count($purchases),
            'eligible_lines'     => $eligibleLines,
            'unallocated_lines'  => $unallocatedLines,
            'ineligible_lines'   => $ineligibleLines,
            'revisions_created'  => $revisionsCreated,
            'revisions_existing' => $revisionsExisting,
            'expected_total_usdt'=> $expectedTotal,
            'lines'              => $outLines,
        ];
    }

    /**
     * Purchase-approval event posting hook (fail-closed OFF unless BOTH commissionPostingEnabled
     * AND commissionAutoPostOnApprovalEnabled AND all 3 commission policy statuses are approved).
     *
     * @return array<string, mixed>|null
     */
    public function postApprovedPurchaseEvent(string $purchaseRef, int $actorUserId, ?string $group = null): ?array
    {
        $cfg = $this->config ?? config('Platform');
        if (! (bool) $cfg->commissionPostingEnabled || ! (bool) $cfg->commissionAutoPostOnApprovalEnabled) {
            return null;
        }

        $group ??= (string) config('Database')->defaultGroup;
        $ctx   = $this->classify($group);
        if ($ctx['mode'] === 'live') {
            $this->assertPoliciesApproved($cfg);
        }

        return $this->run([
            'group'            => $group,
            'execute'          => true,
            'event_mode'       => true,
            'purchase'         => $purchaseRef,
            'allow_production' => $ctx['mode'] === 'live',
            'confirm'          => $ctx['mode'] === 'sandbox' ? self::CONFIRM_STAGING : ($ctx['mode'] === 'live' ? ProductionSafetyGate::CONFIRM_PHRASE : null),
            'actor'            => $actorUserId,
        ]);
    }

    /**
     * Authorize execution of commission posting.
     *
     * @param array{mode: string, database: string, group: string} $ctx
     */
    public function authorize(array $ctx, bool $eventMode, bool $allowProduction, ?string $confirm): void
    {
        $cfg = $this->config ?? config('Platform');

        if ($ctx['mode'] === 'sandbox') {
            if ($confirm !== self::CONFIRM_STAGING) {
                throw new RuntimeException('Sandbox commission posting requires --confirm ' . self::CONFIRM_STAGING);
            }

            return;
        }

        if (! (bool) $cfg->commissionPostingEnabled) {
            throw new RuntimeException('Commission posting kill-switch is OFF (commissionPostingEnabled=false). No wallet credit performed.');
        }

        if ($eventMode && ! (bool) $cfg->commissionAutoPostOnApprovalEnabled) {
            throw new RuntimeException('Purchase-approval auto-post is disabled (commissionAutoPostOnApprovalEnabled=false).');
        }

        if ($ctx['mode'] === 'live') {
            (new ProductionSafetyGate())->assertMutationAllowed($allowProduction, $confirm, $ctx['group'], 'commission posting');
            $this->assertPoliciesApproved($cfg);
        }
    }

    /**
     * Execute or dry-run commission posting (purchase-event or historical recovery).
     *
     * @param array{
     *   group?: ?string,
     *   confirm?: ?string,
     *   allow_production?: bool,
     *   execute?: bool,
     *   purchase?: ?string,
     *   plan_ref?: ?string,
     *   include_root?: bool,
     *   recovery?: bool,
     *   event_mode?: bool,
     *   batch_size?: int,
     *   max_batches?: ?int,
     *   max_runtime?: ?int,
     *   resume_batch_ref?: ?string,
     *   actor?: ?int
     * } $opts
     * @return array<string, mixed>
     */
    public function run(array $opts): array
    {
        $cfg         = $this->config ?? config('Platform');
        $group       = (string) ($opts['group'] ?? config('Database')->defaultGroup);
        $execute     = (bool) ($opts['execute'] ?? false);
        $allowProd   = (bool) ($opts['allow_production'] ?? false);
        $confirm     = isset($opts['confirm']) ? (string) $opts['confirm'] : null;
        $onlyPurchase= isset($opts['purchase']) && trim((string) $opts['purchase']) !== '' ? trim((string) $opts['purchase']) : null;
        $planRef     = isset($opts['plan_ref']) && trim((string) $opts['plan_ref']) !== '' ? trim((string) $opts['plan_ref']) : null;
        $includeRoot = array_key_exists('include_root', $opts) ? (bool) $opts['include_root'] : true;
        $eventMode   = (bool) ($opts['event_mode'] ?? false);
        $runMode     = $eventMode ? 'purchase_event' : 'recovery';
        $batchSize   = max(1, min(2000, (int) ($opts['batch_size'] ?? 50)));
        $maxBatches  = isset($opts['max_batches']) && (int) $opts['max_batches'] > 0 ? (int) $opts['max_batches'] : null;
        $maxRuntime  = isset($opts['max_runtime']) && (int) $opts['max_runtime'] > 0 ? (int) $opts['max_runtime'] : null;
        $resumeRef   = isset($opts['resume_batch_ref']) && trim((string) $opts['resume_batch_ref']) !== '' ? trim((string) $opts['resume_batch_ref']) : null;
        $actor       = (int) ($opts['actor'] ?? 0);
        $actor       = $actor > 0 ? $actor : null;
        $startedAt   = microtime(true);

        $ctx = $this->classify($group);
        $this->assertActiveConnectionMatches($ctx);

        $base = [
            'database'      => $ctx['database'],
            'group'         => $group,
            'mode'          => $ctx['mode'],
            'run_mode'      => $runMode,
            'execute'       => $execute,
            'purchase'      => $onlyPurchase,
            'plan_ref'      => $planRef,
            'include_root'  => $includeRoot,
            'kill_switches' => service('financialSandboxService')->productionSwitchSnapshot(),
        ];

        // --------------------------------------------------------------- dry-run (read-only)
        if (! $execute) {
            $preview = $this->preview($onlyPurchase, $planRef, $includeRoot);
            $out     = $base + $preview + [
                'status'  => 'dry_run',
                'message' => 'DRY-RUN only — no commission wallet was credited.',
            ];
            $this->log('info', 'dry-run', $out);

            return $out;
        }

        // --------------------------------------------------------------- authorization
        try {
            $this->authorize($ctx, $eventMode, $allowProd, $confirm);
        } catch (Throwable $e) {
            $this->log('error', 'authorization-refused', $base + ['error' => $e->getMessage()]);

            throw $e;
        }

        $sandbox      = service('financialSandboxService');
        $openedWindow = false;
        $locks        = $this->locks ?? new OpsJobLockService();
        $owner        = (gethostname() ?: 'host') . '-' . getmypid();
        $batchRef     = null;

        try {
            if ($ctx['mode'] === 'sandbox') {
                $sandbox->openWindow(300, $group);
                $openedWindow = true;
            }

            $this->assertSchema();

            if (! $locks->acquire(self::LOCK_NAME, $owner, max(120, (int) ($maxRuntime ?? 600) + 120))) {
                $out = $base + ['status' => 'skipped_locked', 'message' => 'Another commission posting run holds ' . self::LOCK_NAME . '.'];
                $this->log('warning', 'skipped_locked', $out);

                return $out;
            }

            try {
                // Ensure append-only superseding revisions exist if original rows were NO_APPROVED_PLAN
                if (! $eventMode) {
                    $this->generateSupersedingRevisions($planRef, true, $includeRoot, $actor, $onlyPurchase);
                }

                $batch    = $this->prepareBatch($runMode, $planRef, $includeRoot, $batchSize, $onlyPurchase, $resumeRef, $actor);
                $batchRef = (string) $batch['batch_ref'];

                $result = $this->processBatch($batch, $maxBatches, $maxRuntime, $startedAt, $actor);

                $out = $base + $result + [
                    'batch_ref'   => $batchRef,
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ];

                $this->log($out['status'] === 'completed' ? 'info' : 'error', 'run-finished', $out);

                return $out;
            } catch (Throwable $e) {
                if ($batchRef !== null) {
                    db_connect()->table('commission_post_batches')->where('batch_ref', $batchRef)->update([
                        'state'      => 'FAILED',
                        'last_error' => substr($e->getMessage(), 0, 500),
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                $this->log('error', 'run-failed', $base + ['batch_ref' => $batchRef, 'error' => $e->getMessage()]);

                throw $e;
            } finally {
                $locks->release(self::LOCK_NAME, $owner);
            }
        } finally {
            if ($openedWindow) {
                $sandbox->closeWindow();
            }
        }
    }

    /**
     * Read-only preview of payable vs already-posted vs missing commission items.
     *
     * @return array<string, mixed>
     */
    public function preview(?string $onlyPurchaseRef = null, ?string $planRef = null, bool $includeRoot = true): array
    {
        $items = $this->collectPayableItems($onlyPurchaseRef, $planRef, $includeRoot);
        $scale = $this->scale();
        $db    = db_connect();

        $expectedTotal = MoneyMath::normalize('0', $scale);
        $postedTotal   = MoneyMath::normalize('0', $scale);
        $missingTotal  = MoneyMath::normalize('0', $scale);
        $pendingCount  = 0;
        $postedCount   = 0;

        foreach ($items as &$it) {
            $amt = MoneyMath::normalize((string) $it['expected_amount_usdt'], $scale);
            $expectedTotal = MoneyMath::add($expectedTotal, $amt, $scale);

            $ledgerRow = $db->tableExists('commission_wallet_ledger_entries')
                ? $db->table('commission_wallet_ledger_entries')
                    ->where('idempotency_key', $it['idempotency_key'])
                    ->get()
                    ->getRowArray()
                : null;

            $alreadyPosted = $ledgerRow !== null
                ? MoneyMath::normalize((string) $ledgerRow['amount'], $scale)
                : MoneyMath::normalize('0', $scale);

            $it['already_posted_usdt'] = $alreadyPosted;
            $postedTotal = MoneyMath::add($postedTotal, $alreadyPosted, $scale);

            if ($ledgerRow !== null) {
                $postedCount++;
            } else {
                $pendingCount++;
                $missingTotal = MoneyMath::add($missingTotal, $amt, $scale);
            }
        }
        unset($it);

        return [
            'eligible_items'      => count($items),
            'already_posted_items'=> $postedCount,
            'pending_items'       => $pendingCount,
            'expected_total_usdt' => $expectedTotal,
            'posted_total_usdt'   => $postedTotal,
            'missing_total_usdt'  => $missingTotal,
            'items'               => $items,
        ];
    }

    /**
     * Collect payable items from `commission_purchase_calculations` (CALCULATED_NOT_POSTED)
     * plus latest superseding `commission_calculation_revisions` (CALCULATED_SUPERSEDING) for
     * purchases whose original row was NO_APPROVED_PLAN, OR in-memory evaluation during dry-run.
     *
     * @return list<array<string, mixed>>
     */
    private function collectPayableItems(?string $onlyPurchaseRef, ?string $planRef, bool $includeRoot): array
    {
        $db    = db_connect();
        $scale = $this->scale();
        $out   = [];
        $seen  = [];

        if ($db->tableExists('commission_purchase_calculations')) {
            $b = $db->table('commission_purchase_calculations')
                ->where('status', CommissionPurchaseEngineService::STATUS_CALCULATED)
                ->orderBy('id', 'ASC');
            if ($onlyPurchaseRef !== null) {
                $b->where('purchase_ref', $onlyPurchaseRef);
            }
            foreach ($b->get()->getResultArray() as $c) {
                if (! $includeRoot && (string) ($c['beneficiary_member_id'] ?? '') === 'ROOT0001') {
                    continue;
                }
                if ($planRef !== null && (string) ($c['plan_ref'] ?? '') !== $planRef) {
                    continue;
                }
                $amt = MoneyMath::normalize((string) $c['expected_amount_usdt'], $scale);
                if (! MoneyMath::isPositive($amt, $scale) || empty($c['beneficiary_user_id'])) {
                    continue;
                }
                $key = $c['purchase_ref'] . ':L' . (int) $c['sponsor_level'];
                $seen[$key] = true;
                $out[] = [
                    'purchase_ref'          => (string) $c['purchase_ref'],
                    'purchase_id'           => (int) ($c['purchase_id'] ?? 0),
                    'sponsor_level'         => (int) $c['sponsor_level'],
                    'source_calc_ref'       => (string) $c['calc_ref'],
                    'source_revision_ref'   => null,
                    'beneficiary_user_id'   => (int) $c['beneficiary_user_id'],
                    'beneficiary_member_id' => (string) $c['beneficiary_member_id'],
                    'expected_amount_usdt'  => $amt,
                    'idempotency_key'       => 'comm-post:' . $c['purchase_ref'] . ':L' . (int) $c['sponsor_level'],
                ];
            }
        }

        if ($db->tableExists('commission_calculation_revisions')) {
            $b = $db->table('commission_calculation_revisions')
                ->where('status', 'CALCULATED_SUPERSEDING')
                ->where('root_eligible', $includeRoot ? 1 : 0)
                ->orderBy('id', 'ASC');
            if ($onlyPurchaseRef !== null) {
                $b->where('purchase_ref', $onlyPurchaseRef);
            }
            if ($planRef !== null) {
                $b->where('plan_ref', $planRef);
            }
            foreach ($b->get()->getResultArray() as $r) {
                $key = $r['purchase_ref'] . ':L' . (int) $r['sponsor_level'];
                if (isset($seen[$key])) {
                    continue;
                }
                $amt = MoneyMath::normalize((string) $r['expected_amount_usdt'], $scale);
                if (! MoneyMath::isPositive($amt, $scale) || empty($r['beneficiary_user_id'])) {
                    continue;
                }
                $seen[$key] = true;
                $out[] = [
                    'purchase_ref'          => (string) $r['purchase_ref'],
                    'purchase_id'           => (int) ($r['purchase_id'] ?? 0),
                    'sponsor_level'         => (int) $r['sponsor_level'],
                    'source_calc_ref'       => $r['supersedes_calc_ref'] ?? null,
                    'source_revision_ref'   => (string) $r['revision_ref'],
                    'beneficiary_user_id'   => (int) $r['beneficiary_user_id'],
                    'beneficiary_member_id' => (string) $r['beneficiary_member_id'],
                    'expected_amount_usdt'  => $amt,
                    'idempotency_key'       => 'comm-post:' . $r['purchase_ref'] . ':L' . (int) $r['sponsor_level'],
                ];
            }
        }

        // Dry-run fallback: if no persisted revisions yet and explicit plan passed (or approved plan exists), evaluate in-memory
        if ($out === [] && $planRef !== null) {
            $sim = $this->generateSupersedingRevisions($planRef, false, $includeRoot, null, $onlyPurchaseRef);
            foreach ($sim['lines'] as $l) {
                if (($l['status'] ?? '') !== 'CALCULATED_SUPERSEDING') {
                    continue;
                }
                $out[] = [
                    'purchase_ref'          => (string) $l['purchase_ref'],
                    'purchase_id'           => (int) $l['purchase_id'],
                    'sponsor_level'         => (int) $l['sponsor_level'],
                    'source_calc_ref'       => $l['supersedes_calc_ref'] ?? null,
                    'source_revision_ref'   => null,
                    'beneficiary_user_id'   => (int) $l['beneficiary_user_id'],
                    'beneficiary_member_id' => (string) $l['beneficiary_member_id'],
                    'expected_amount_usdt'  => (string) $l['expected_amount_usdt'],
                    'idempotency_key'       => 'comm-post:' . $l['purchase_ref'] . ':L' . (int) $l['sponsor_level'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function prepareBatch(
        string $runMode,
        ?string $planRef,
        bool $includeRoot,
        int $batchSize,
        ?string $onlyPurchaseRef,
        ?string $resumeRef,
        ?int $actorUserId
    ): array {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');

        if ($resumeRef !== null) {
            $existing = $db->table('commission_post_batches')->where('batch_ref', $resumeRef)->get()->getRowArray();
            if ($existing === null) {
                throw new InvalidArgumentException('Batch not found for resume: ' . $resumeRef);
            }
            if (($existing['state'] ?? '') === 'COMPLETED') {
                return $existing;
            }
            $db->table('commission_post_batches')->where('batch_ref', $resumeRef)->update([
                'state'       => 'RUNNING',
                'invocations' => (int) $existing['invocations'] + 1,
                'updated_at'  => $now,
            ]);

            return $db->table('commission_post_batches')->where('batch_ref', $resumeRef)->get()->getRowArray();
        }

        // If recovery mode and an open PARTIAL/FAILED batch exists for same plan/root setting, resume it
        if ($runMode === 'recovery' && $onlyPurchaseRef === null) {
            $openB = $db->table('commission_post_batches')
                ->where('run_mode', 'recovery')
                ->whereIn('state', ['PARTIAL', 'RUNNING', 'FAILED'])
                ->where('root_eligible', $includeRoot ? 1 : 0);
            if ($planRef !== null) {
                $openB->where('plan_ref', $planRef);
            }
            $open = $openB->orderBy('id', 'DESC')->get()->getRowArray();
            if ($open !== null) {
                $db->table('commission_post_batches')->where('id', (int) $open['id'])->update([
                    'state'       => 'RUNNING',
                    'invocations' => (int) $open['invocations'] + 1,
                    'updated_at'  => $now,
                ]);

                return $db->table('commission_post_batches')->where('id', (int) $open['id'])->get()->getRowArray();
            }
        }

        $items    = $this->collectPayableItems($onlyPurchaseRef, $planRef, $includeRoot);
        $batchRef = 'CPB-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));

        $db->transBegin();
        try {
            $db->table('commission_post_batches')->insert([
                'batch_ref'        => $batchRef,
                'run_mode'         => $runMode,
                'state'            => 'RUNNING',
                'plan_ref'         => $planRef,
                'policy_version'   => 'CPOL-P14-' . ($includeRoot ? 'ROOT' : 'NOROOT'),
                'root_eligible'    => $includeRoot ? 1 : 0,
                'population_count' => count($items),
                'eligible_count'   => count($items),
                'pending_count'    => count($items),
                'batch_size'       => $batchSize,
                'invocations'      => 1,
                'started_at'       => $now,
                'created_by'       => $actorUserId,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

            foreach ($items as $it) {
                $db->table('commission_post_items')->insert([
                    'batch_ref'             => $batchRef,
                    'purchase_ref'          => $it['purchase_ref'],
                    'purchase_id'           => $it['purchase_id'],
                    'sponsor_level'         => $it['sponsor_level'],
                    'source_calc_ref'       => $it['source_calc_ref'],
                    'source_revision_ref'   => $it['source_revision_ref'],
                    'beneficiary_user_id'   => $it['beneficiary_user_id'],
                    'beneficiary_member_id' => $it['beneficiary_member_id'],
                    'expected_amount_usdt'  => $it['expected_amount_usdt'],
                    'posted_amount_usdt'    => '0.00000000',
                    'status'                => 'PENDING',
                    'idempotency_key'       => $it['idempotency_key'],
                    'created_at'            => $now,
                    'updated_at'            => $now,
                ]);
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Failed to freeze commission post batch.');
            }
            $db->transCommit();
        } catch (Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }

        return $db->table('commission_post_batches')->where('batch_ref', $batchRef)->get()->getRowArray();
    }

    /**
     * @param array<string, mixed> $batch
     * @return array<string, mixed>
     */
    private function processBatch(
        array $batch,
        ?int $maxBatches,
        ?int $maxRuntime,
        float $startedAt,
        ?int $actorUserId
    ): array {
        $db        = db_connect();
        $cfg       = $this->config ?? config('Platform');
        $wallets   = $this->wallets ?? new CommissionWalletService();
        $batchRef  = (string) $batch['batch_ref'];
        $batchSize = max(1, (int) $batch['batch_size']);
        $cursor    = (int) ($batch['last_item_id'] ?? 0);
        $scale     = $this->scale();

        $maxAttempts = max(1, (int) $cfg->miningPostMaxAttempts);
        $retryDelay  = max(0, (int) $cfg->miningPostRetryDelaySeconds);

        $batchesRun  = 0;
        $postedNow   = 0;
        $dupesNow    = 0;
        $skippedNow  = 0;
        $errors      = [];
        $interrupted = null;

        if (($batch['state'] ?? '') !== 'COMPLETED') {
            while (true) {
                if ($maxBatches !== null && $batchesRun >= $maxBatches) {
                    $interrupted = 'max_batches';
                    break;
                }
                if ($maxRuntime !== null && (microtime(true) - $startedAt) >= $maxRuntime) {
                    $interrupted = 'timeout';
                    break;
                }

                $rows = $db->table('commission_post_items')
                    ->where('batch_ref', $batchRef)
                    ->whereIn('status', ['PENDING', 'FAILED'])
                    ->where('id >', $cursor)
                    ->orderBy('id', 'ASC')
                    ->limit($batchSize)
                    ->get()
                    ->getResultArray();

                if ($rows === []) {
                    // Check if any FAILED items exist below cursor
                    $rows = $db->table('commission_post_items')
                        ->where('batch_ref', $batchRef)
                        ->whereIn('status', ['PENDING', 'FAILED'])
                        ->orderBy('id', 'ASC')
                        ->limit($batchSize)
                        ->get()
                        ->getResultArray();
                    if ($rows === []) {
                        break;
                    }
                }

                $batchesRun++;

                foreach ($rows as $item) {
                    $itemId = (int) $item['id'];
                    $cursor = max($cursor, $itemId);
                    $amt    = MoneyMath::normalize((string) $item['expected_amount_usdt'], $scale);
                    $uid    = (int) ($item['beneficiary_user_id'] ?? 0);
                    $mid    = (string) ($item['beneficiary_member_id'] ?? '');
                    $idem   = (string) $item['idempotency_key'];

                    if ($uid <= 0 || $mid === '' || ! MoneyMath::isPositive($amt, $scale)) {
                        $db->table('commission_post_items')->where('id', $itemId)->update([
                            'status'       => 'SKIPPED',
                            'reason'       => 'zero_or_missing_beneficiary',
                            'processed_at' => date('Y-m-d H:i:s'),
                            'updated_at'   => date('Y-m-d H:i:s'),
                        ]);
                        $skippedNow++;
                        continue;
                    }

                    $attempt = 0;
                    $done    = false;
                    while ($attempt < $maxAttempts && ! $done) {
                        $attempt++;
                        try {
                            $before = $db->table('commission_wallet_ledger_entries')
                                ->where('idempotency_key', $idem)
                                ->get()
                                ->getRowArray();

                            $entry = $wallets->creditCommission($uid, $mid, [
                                'amount'          => $amt,
                                'reference_type'  => 'commission_purchase',
                                'reference_id'    => (string) $item['purchase_ref'] . ':L' . (int) $item['sponsor_level'],
                                'idempotency_key' => $idem,
                                'created_by'      => $actorUserId,
                                'notes'           => ($batch['run_mode'] === 'recovery' ? 'Commission recovery batch ' : 'Commission purchase event ') . $batchRef,
                            ]);

                            $wasDup = $before !== null ? 1 : 0;
                            if ($wasDup) {
                                $dupesNow++;
                            } else {
                                $postedNow++;
                            }

                            $db->table('commission_post_items')->where('id', $itemId)->update([
                                'status'           => 'POSTED',
                                'posted_amount_usdt' => MoneyMath::normalize((string) ($entry['amount'] ?? $amt), $scale),
                                'ledger_entry_ref' => $entry['entry_ref'] ?? null,
                                'was_duplicate'    => $wasDup,
                                'attempts'         => (int) $item['attempts'] + $attempt,
                                'last_error'       => null,
                                'processed_at'     => date('Y-m-d H:i:s'),
                                'updated_at'       => date('Y-m-d H:i:s'),
                            ]);

                            // Advance CALCULATED_NOT_POSTED only; NEVER mutate historical NO_APPROVED_PLAN rows!
                            if ($db->tableExists('commission_purchase_calculations')) {
                                $db->table('commission_purchase_calculations')
                                    ->where('purchase_ref', (string) $item['purchase_ref'])
                                    ->where('sponsor_level', (int) $item['sponsor_level'])
                                    ->where('status', CommissionPurchaseEngineService::STATUS_CALCULATED)
                                    ->update([
                                        'posted_amount_usdt' => $amt,
                                        'status'             => 'POSTED',
                                        'updated_at'         => date('Y-m-d H:i:s'),
                                    ]);
                            }

                            $done = true;
                        } catch (Throwable $e) {
                            if ($this->isTransientMessage($e->getMessage()) && $attempt < $maxAttempts) {
                                $this->pause($retryDelay * $attempt);
                                continue;
                            }
                            $db->table('commission_post_items')->where('id', $itemId)->update([
                                'status'     => 'FAILED',
                                'attempts'   => (int) $item['attempts'] + $attempt,
                                'last_error' => substr($e->getMessage(), 0, 255),
                                'updated_at' => date('Y-m-d H:i:s'),
                            ]);
                            $errors[] = [
                                'purchase_ref' => $item['purchase_ref'],
                                'level'        => (int) $item['sponsor_level'],
                                'message'      => $e->getMessage(),
                            ];
                            break;
                        }
                    }
                }

                $db->table('commission_post_batches')->where('batch_ref', $batchRef)->update([
                    'last_item_id' => $cursor,
                    'batches_done' => (int) $batch['batches_done'] + $batchesRun,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
        }

        // Summarize & reconcile
        $counts = $db->query(
            'SELECT status, COUNT(*) AS c, SUM(posted_amount_usdt) AS tot, SUM(was_duplicate) AS dups
               FROM commission_post_items WHERE batch_ref = ? GROUP BY status',
            [$batchRef]
        )->getResultArray();
        $byStatus = [];
        $totPosted = MoneyMath::normalize('0', $scale);
        $totDups   = 0;
        foreach ($counts as $c) {
            $byStatus[(string) $c['status']] = (int) $c['c'];
            $totPosted = MoneyMath::add($totPosted, MoneyMath::normalize((string) ($c['tot'] ?? '0'), $scale), $scale);
            $totDups  += (int) ($c['dups'] ?? 0);
        }

        $pending   = $byStatus['PENDING'] ?? 0;
        $failed    = $byStatus['FAILED'] ?? 0;
        $postedAll = $byStatus['POSTED'] ?? 0;
        $skippedAll = $byStatus['SKIPPED'] ?? 0;

        $walletRec  = (new CommissionWalletReconciliationService())->run();
        $itemMismatches = $this->reconcileBatchItems($batchRef);
        $recOk      = ($walletRec['issue_count'] ?? 0) === 0 && $itemMismatches === [];
        $recStatus  = $recOk ? 'OK' : 'MISMATCH';

        $completed = $interrupted === null && $pending === 0 && $failed === 0 && $recOk;
        $state     = $completed ? 'COMPLETED' : 'PARTIAL';
        $status    = $completed ? 'completed' : 'partial';
        $reason    = $interrupted !== null
            ? ('interrupted: ' . $interrupted)
            : (! $recOk ? 'reconciliation_mismatch' : ($failed > 0 ? 'failed_items=' . $failed : ($pending > 0 ? 'pending_items=' . $pending : null)));

        $now = date('Y-m-d H:i:s');
        $db->table('commission_post_batches')->where('batch_ref', $batchRef)->update([
            'state'             => $state,
            'posted_count'      => $postedAll,
            'skipped_count'     => $skippedAll,
            'failed_count'      => $failed,
            'pending_count'     => $pending,
            'duplicate_count'   => $totDups,
            'total_posted_usdt' => $totPosted,
            'reconcile_status'  => $recStatus,
            'last_error'        => $reason,
            'finished_at'       => $completed ? $now : null,
            'updated_at'        => $now,
        ]);

        return [
            'status'           => $status,
            'state'            => $state,
            'posted_now'       => $postedNow,
            'posted_total'     => $postedAll,
            'skipped'          => $skippedAll,
            'failed'           => $failed,
            'pending'          => $pending,
            'duplicates_now'   => $dupesNow,
            'duplicates_total' => $totDups,
            'total_posted_usdt'=> $totPosted,
            'batches_run'      => $batchesRun,
            'interrupted'      => $interrupted,
            'reason'           => $reason,
            'reconcile_status' => $recStatus,
            'wallet_issues'    => $walletRec['issues'] ?? [],
            'item_mismatches'  => $itemMismatches,
            'errors'           => $errors,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileBatchItems(string $batchRef): array
    {
        $db    = db_connect();
        $scale = $this->scale();
        $out   = [];

        $items = $db->table('commission_post_items')->where('batch_ref', $batchRef)->where('status', 'POSTED')->get()->getResultArray();
        foreach ($items as $it) {
            $row = $db->table('commission_wallet_ledger_entries')
                ->where('idempotency_key', (string) $it['idempotency_key'])
                ->get()
                ->getRowArray();
            if ($row === null) {
                $out[] = ['type' => 'posted_item_missing_ledger_entry', 'purchase_ref' => $it['purchase_ref'], 'level' => (int) $it['sponsor_level']];
                continue;
            }
            if (MoneyMath::compare((string) $it['expected_amount_usdt'], (string) $row['amount'], $scale) !== 0) {
                $out[] = ['type' => 'ledger_amount_mismatch', 'purchase_ref' => $it['purchase_ref'], 'level' => (int) $it['sponsor_level']];
            }
        }

        // Duplicate idempotency keys in ledger check
        $dups = $db->query(
            'SELECT idempotency_key, COUNT(*) AS c FROM commission_wallet_ledger_entries GROUP BY idempotency_key HAVING c > 1'
        )->getResultArray();
        foreach ($dups as $d) {
            $out[] = ['type' => 'duplicate_ledger_idempotency_key', 'key' => $d['idempotency_key']];
        }

        return $out;
    }

    private function assertActiveConnectionMatches(array $ctx): void
    {
        $active = (string) db_connect()->getDatabase();
        if ($active !== $ctx['database']) {
            throw new RuntimeException(
                'Isolation check failed: active connection is "' . $active . '" but group "' . $ctx['group'] . '" is "' . $ctx['database'] . '".'
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
            throw new RuntimeException('Live commission posting blocked: business policies not approved: ' . implode(', ', $pending));
        }
    }

    private function assertSchema(): void
    {
        $db = db_connect();
        foreach ([
            'commission_plans',
            'commission_plan_levels',
            'commission_purchase_calculations',
            'commission_calculation_revisions',
            'commission_post_batches',
            'commission_post_items',
            'commission_wallet_accounts',
            'commission_wallet_ledger_entries',
            'purchase_sponsor_snapshots',
            'ops_job_locks',
        ] as $t) {
            if (! $db->tableExists($t)) {
                throw new RuntimeException('Required table missing on ' . $db->getDatabase() . ': ' . $t);
            }
        }
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

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale ?? 8;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $level, string $event, array $context = []): void
    {
        unset($context['kill_switches'], $context['items']);
        foreach ($context as $k => $v) {
            if (preg_match('/pass(word|wd)?|secret|private|token|credential|api[_-]?key|dsn/i', (string) $k)) {
                $context[$k] = '[redacted]';
            }
        }
        $line = json_encode(['ts' => date('c'), 'level' => $level, 'event' => $event] + $context, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        log_message($level, '[commissions:post] ' . $line);
        $file = $this->logFile ?? WRITEPATH . 'logs/commission-post.log';
        $dir  = dirname($file);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
