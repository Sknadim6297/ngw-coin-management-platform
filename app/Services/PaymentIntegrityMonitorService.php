<?php

declare(strict_types=1);

namespace App\Services;

use Config\Blockchain;

/**
 * Phase 4D scheduled integrity monitor.
 * Read-only regarding financial ledgers — raises alerts only.
 */
class PaymentIntegrityMonitorService
{
    public function __construct(
        private readonly PaymentReconciliationService $reconciliation = new PaymentReconciliationService(),
        private readonly PaymentHealthService $health = new PaymentHealthService(),
        private readonly FinancialAlertService $alerts = new FinancialAlertService(),
        private readonly OpsJobLockService $locks = new OpsJobLockService(),
        private readonly ?Blockchain $blockchain = null
    ) {
    }

    /**
     * @return array{
     *   ran: bool,
     *   skipped: bool,
     *   reason: ?string,
     *   checked_deposits: int,
     *   alerts_raised: int,
     *   findings: int,
     *   redacted_log: list<string>
     * }
     */
    public function run(int $batchLimit = 50, ?string $owner = null): array
    {
        $owner = $owner ?? ('cli:' . gethostname() . ':' . getmypid());
        $log   = [];
        $alertsRaised = 0;

        if (! $this->locks->acquire('payment_integrity_monitor', $owner, 900)) {
            return [
                'ran' => false,
                'skipped' => true,
                'reason' => 'Another integrity monitor run holds the lock.',
                'checked_deposits' => 0,
                'alerts_raised' => 0,
                'findings' => 0,
                'redacted_log' => ['lock_busy'],
            ];
        }

        try {
            $batchLimit = max(1, min(500, $batchLimit));
            $log[] = 'monitor_start batch=' . $batchLimit;

            // RPC / health probe (no secrets logged)
            $snap = $this->health->probeAndStore(null);
            $log[] = 'rpc_status=' . ($snap['status'] ?? 'unknown');

            if (($snap['status'] ?? '') === 'down' || ($snap['status'] ?? '') === 'unavailable') {
                $this->alerts->raise(
                    FinancialAlertService::TYPE_RPC_UNAVAILABLE,
                    'critical',
                    'RPC unavailable',
                    'Trusted RPC probe failed: ' . ($snap['error_summary'] ?? 'unavailable'),
                    'rpc:unavailable',
                    'blockchain',
                    'global',
                    ['category' => $snap['error_category'] ?? null]
                );
                $alertsRaised++;
            }

            if (($snap['status'] ?? '') === 'wrong_chain') {
                $this->alerts->raise(
                    FinancialAlertService::TYPE_CHAIN_MISMATCH,
                    'critical',
                    'Chain ID mismatch',
                    'RPC reported chain does not match configured network.',
                    'rpc:chain_mismatch',
                    'blockchain',
                    'global',
                    [
                        'expected' => $snap['expected_chain_id'] ?? null,
                        'reported' => $snap['reported_chain_id'] ?? null,
                    ]
                );
                $alertsRaised++;
            }

            // Bounded integrity flag scan (no ledger mutation)
            $flagged = db_connect()->table('usdt_deposit_requests')
                ->select('deposit_ref, integrity_flag')
                ->whereIn('integrity_flag', ['reorg_suspected', 'needs_investigation'])
                ->orderBy('id', 'ASC')
                ->limit($batchLimit)
                ->get()
                ->getResultArray();

            foreach ($flagged as $row) {
                $type = ($row['integrity_flag'] === 'reorg_suspected')
                    ? FinancialAlertService::TYPE_REORG_SUSPECTED
                    : FinancialAlertService::TYPE_INTEGRITY_INVESTIGATION;
                $this->alerts->raise(
                    $type,
                    'critical',
                    'Deposit integrity investigation required',
                    'Deposit ' . $row['deposit_ref'] . ' flagged as ' . $row['integrity_flag'],
                    'deposit_integrity:' . $row['deposit_ref'],
                    'usdt_deposit_request',
                    $row['deposit_ref']
                );
                $alertsRaised++;
            }

            // Repeated verification failures
            $failures = db_connect()->table('usdt_deposit_requests')
                ->select('deposit_ref, verification_attempt_count, last_verification_status')
                ->where('verification_attempt_count >=', 3)
                ->whereIn('status', ['submitted', 'under_review'])
                ->orderBy('verification_attempt_count', 'DESC')
                ->limit($batchLimit)
                ->get()
                ->getResultArray();

            foreach ($failures as $row) {
                $this->alerts->raise(
                    FinancialAlertService::TYPE_REPEATED_VERIFY_FAILURES,
                    'warning',
                    'Repeated verification failures',
                    'Deposit ' . $row['deposit_ref'] . ' attempts=' . $row['verification_attempt_count']
                        . ' last=' . ($row['last_verification_status'] ?? 'n/a'),
                    'verify_fail:' . $row['deposit_ref'],
                    'usdt_deposit_request',
                    $row['deposit_ref']
                );
                $alertsRaised++;
            }

            $report = $this->reconciliation->run();
            foreach ($report['findings'] as $finding) {
                $code = (string) ($finding['code'] ?? 'unknown');
                $map  = [
                    'orphan_allocation'                 => FinancialAlertService::TYPE_ORPHAN_ALLOCATION,
                    'duplicate_consumption_deposit'     => FinancialAlertService::TYPE_DUPLICATE_CONSUMPTION,
                    'duplicate_consumption_purchase'    => FinancialAlertService::TYPE_DUPLICATE_CONSUMPTION,
                    'approved_without_verified_payment' => FinancialAlertService::TYPE_APPROVED_WITHOUT_PAYMENT,
                    'missing_ownership_credit'          => FinancialAlertService::TYPE_APPROVED_WITHOUT_PAYMENT,
                    'inventory_ownership_discrepancy'   => FinancialAlertService::TYPE_LEDGER_MISMATCH,
                    'payment_amount_mismatch'           => FinancialAlertService::TYPE_LEDGER_MISMATCH,
                    'deposit_integrity_flag'            => FinancialAlertService::TYPE_INTEGRITY_INVESTIGATION,
                ];
                if (! isset($map[$code])) {
                    continue;
                }
                $entityId = (string) ($finding['deposit_ref'] ?? $finding['purchase_ref'] ?? $finding['allocation_ref'] ?? $code);
                $this->alerts->raise(
                    $map[$code],
                    (string) ($finding['severity'] ?? 'warning'),
                    'Reconciliation: ' . $code,
                    (string) ($finding['message'] ?? $code),
                    'recon:' . $code . ':' . $entityId,
                    'reconciliation',
                    $entityId,
                    ['code' => $code]
                );
                $alertsRaised++;
            }

            $log[] = 'findings=' . count($report['findings']);
            $log[] = 'alerts_touched=' . $alertsRaised;
            $log[] = 'monitor_complete no_ledger_mutation=1';

            return [
                'ran' => true,
                'skipped' => false,
                'reason' => null,
                'checked_deposits' => count($flagged) + count($failures),
                'alerts_raised' => $alertsRaised,
                'findings' => count($report['findings']),
                'redacted_log' => $log,
            ];
        } finally {
            $this->locks->release('payment_integrity_monitor', $owner);
        }
    }
}
