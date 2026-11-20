<?php

declare(strict_types=1);

namespace App\Services;

use Config\Platform;
use Throwable;

/**
 * Instant commission posting: right after a valid purchase approval/activation, post that purchase's L1/L2/L3
 * commission through the EXISTING CommissionPostingRunnerService (event mode). Nothing is calculated here.
 *
 * Safety model (do not weaken):
 *  - Fail-closed. Does nothing unless the DB-backed "Instant Commission Posting" control is ON, its authorization
 *    belongs to THIS database and a real admin, and the commission policy / approved plan / mandatory-lock checks
 *    pass again at approval time. Env kill-switches in Config\Platform stay false; they are raised in-process for
 *    the single runner call only and always restored (same model as the scheduler).
 *  - No new approval step: purchase verification and approval already happened in CoinPurchaseService::approveRequest
 *    (verified deposit, review, inventory, ownership, activation). This only runs AFTER that transaction committed.
 *  - Never throws into the approval flow and never rolls the approval back. Any failure leaves the calculation rows
 *    CALCULATED_NOT_POSTED, is audited and alerted, and the scheduler recovers it later (no duplicates: ledger keys
 *    `comm-post:{purchase_ref}:L{level}` are unique and the runner only collects unposted rows).
 *  - Only purchases approved at/after the first-authorization cutoff are ever posted (no retroactive posting).
 */
class InstantCommissionService
{
    /** Posting flags the runner demands on a live database; raised in-process only, after readiness passed. */
    private const POLICY_FLAGS = [
        'commissionBasePolicyStatus', 'commissionRoundingPolicyStatus', 'commissionRootEligibilityPolicyStatus',
    ];

    public function __construct(
        private readonly ?FinancialAutomationService $control = null,
        private readonly ?CommissionPostingRunnerService $runner = null,
        private readonly ?AuditLogger $audit = null,
        private readonly ?Platform $config = null,
        private readonly int $lockRetries = 4,
        private readonly int $lockRetryMicros = 300000,
    ) {
    }

    /**
     * @return array<string, mixed>|null null when instant posting is OFF (nothing was attempted)
     */
    public function postApprovedPurchase(string $purchaseRef, int $actorUserId): ?array
    {
        $purchaseRef = trim($purchaseRef);
        $ctl         = $this->ctl();

        try {
            if (! $ctl->isActive(FinancialAutomationService::CONTROL_INSTANT)) {
                return null;
            }
        } catch (Throwable $e) {
            log_message('error', '[instant-commission] control check failed: {m}', ['m' => $e->getMessage()]);

            return null;
        }

        try {
            return $this->attempt($purchaseRef, $actorUserId);
        } catch (Throwable $e) {
            return $this->finish('failed', $purchaseRef, $actorUserId, $e->getMessage(), [], true);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function attempt(string $purchaseRef, int $actorUserId): array
    {
        $ctl      = $this->ctl();
        $cfg      = $this->cfg();
        $purchase = db_connect()->table('coin_purchase_requests')->where('purchase_ref', $purchaseRef)->get()->getRowArray();

        if ($purchase === null || ($purchase['status'] ?? '') !== 'approved') {
            return $this->finish('skipped', $purchaseRef, $actorUserId, 'purchase_not_approved');
        }
        if ((bool) $cfg->requireVerifiedDepositForApproval && strtolower((string) ($purchase['payment_status'] ?? '')) !== 'verified') {
            return $this->finish('skipped', $purchaseRef, $actorUserId, 'payment_not_verified');
        }

        $since = $ctl->instantSince();
        if ($since === null || (string) ($purchase['reviewed_at'] ?? '') < $since) {
            return $this->finish('skipped', $purchaseRef, $actorUserId, 'before_instant_cutoff');
        }

        $blockers = $ctl->instantRuntimeBlockers();
        if ($blockers !== []) {
            return $this->finish('blocked', $purchaseRef, $actorUserId, implode(' | ', $blockers), ['blockers' => $blockers], true);
        }

        // The post-commit calculation may have failed (it must not undo the approval). Re-running it is idempotent:
        // existing calculation rows are immutable and are never rewritten.
        $have = db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $purchaseRef)->countAllResults();
        if ($have < 3) {
            service('commissionPurchaseEngineService')->calculateForPurchase($purchaseRef, true, $actorUserId);
        }

        $runner = $this->runner ?? new CommissionPostingRunnerService(null, null, null, null, $cfg);
        $result = $this->withRuntimeAuthorization($cfg, function () use ($runner, $purchaseRef, $actorUserId): ?array {
            $out = null;
            for ($try = 0; $try <= $this->lockRetries; $try++) {
                $out = $runner->postApprovedPurchaseEvent($purchaseRef, $actorUserId);
                if (($out['status'] ?? '') !== 'skipped_locked') {
                    break;
                }
                usleep($this->lockRetryMicros);
            }

            return $out;
        });

        if ($result === null) {
            return $this->finish('failed', $purchaseRef, $actorUserId, 'posting runner refused to run (kill-switch).', [], true);
        }

        $status = (string) ($result['status'] ?? '');
        if ($status === 'completed') {
            return $this->finish('posted', $purchaseRef, $actorUserId, null, $result);
        }

        return $this->finish(
            $status === 'skipped_locked' ? 'deferred' : 'failed',
            $purchaseRef,
            $actorUserId,
            (string) ($result['reason'] ?? $result['message'] ?? $status),
            $result,
            true
        );
    }

    /**
     * Raise the in-process flags the existing runner needs, run, and ALWAYS restore the previous values.
     *
     * @param callable(): ?array<string, mixed> $fn
     * @return ?array<string, mixed>
     */
    private function withRuntimeAuthorization(Platform $cfg, callable $fn): ?array
    {
        $set = ['commissionPostingEnabled' => true, 'commissionAutoPostOnApprovalEnabled' => true];
        foreach (self::POLICY_FLAGS as $flag) {
            $set[$flag] = 'approved';
        }

        $previous = [];
        foreach ($set as $prop => $_) {
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
     * Audit (+ alert when attention is needed) and build the result handed back to the approval flow.
     *
     * @param array<string, mixed> $runResult
     * @return array<string, mixed>
     */
    private function finish(string $outcome, string $purchaseRef, int $actorUserId, ?string $reason, array $runResult = [], bool $alert = false): array
    {
        $summary = array_intersect_key($runResult, array_flip([
            'status', 'batch_ref', 'posted_now', 'posted_total', 'duplicates_now', 'skipped', 'failed', 'pending',
            'total_posted_usdt', 'reconcile_status', 'reason',
        ]));

        try {
            ($this->audit ?? new AuditLogger())->log('commission.instant_' . $outcome, $actorUserId > 0 ? $actorUserId : null, 'coin_purchase_request', $purchaseRef, [
                'outcome'  => $outcome,
                'reason'   => $reason,
                'database' => (string) db_connect()->getDatabase(),
                'summary'  => $summary,
                'recovery' => $outcome === 'posted' ? null : 'Calculation rows stay unposted; the scheduler recovery run posts them (idempotent).',
            ]);
        } catch (Throwable $e) {
            log_message('error', '[instant-commission] audit failed: {m}', ['m' => $e->getMessage()]);
        }

        if ($alert) {
            try {
                service('financialAlertService')->raise(
                    'instant_commission_' . $outcome,
                    $outcome === 'failed' ? 'critical' : 'warning',
                    'Instant commission ' . $outcome . ': ' . $purchaseRef,
                    (string) $reason,
                    'instant-commission:' . $purchaseRef,
                    'coin_purchase_request',
                    $purchaseRef,
                    ['outcome' => $outcome]
                );
            } catch (Throwable $e) {
                log_message('error', '[instant-commission] alert failed: {m}', ['m' => $e->getMessage()]);
            }
            log_message('error', '[instant-commission] {ref} {outcome}: {msg}', ['ref' => $purchaseRef, 'outcome' => $outcome, 'msg' => (string) $reason]);
        }

        if ($outcome === 'posted') {
            return $runResult + ['instant' => true];
        }

        return array_merge($summary, [
            'runner_status' => $summary['status'] ?? null,
            'status'        => 'instant_' . $outcome,
            'message'       => (string) $reason,
            'instant'       => true,
        ]);
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
