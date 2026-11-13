<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;

/**
 * Phase 4C: read-only payment reconciliation.
 * Detects discrepancies; never mutates ledgers or auto-repairs.
 */
class PaymentReconciliationService
{
    public function __construct(
        private readonly ?Platform $platform = null
    ) {
    }

    /**
     * @return array{
     *   generated_at: string,
     *   summary: array<string, int>,
     *   findings: list<array<string, mixed>>
     * }
     */
    public function run(): array
    {
        $findings = [];
        $findings = array_merge($findings, $this->duplicateConsumptions());
        $findings = array_merge($findings, $this->orphanAllocations());
        $findings = array_merge($findings, $this->approvedWithoutVerifiedPayment());
        $findings = array_merge($findings, $this->amountMismatches());
        $findings = array_merge($findings, $this->missingOwnershipCredits());
        $findings = array_merge($findings, $this->inventoryOwnershipDiscrepancies());
        $findings = array_merge($findings, $this->integrityFlaggedDeposits());

        $summary = [
            'total_findings' => count($findings),
            'critical'       => 0,
            'warning'        => 0,
            'info'           => 0,
        ];
        foreach ($findings as $f) {
            $sev = (string) ($f['severity'] ?? 'info');
            if (isset($summary[$sev])) {
                $summary[$sev]++;
            }
        }

        return [
            'generated_at' => date('Y-m-d H:i:s'),
            'summary'      => $summary,
            'findings'     => $findings,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function duplicateConsumptions(): array
    {
        $db  = db_connect();
        $out = [];

        $dupDeposits = $db->query(
            'SELECT deposit_ref, COUNT(*) AS cnt
             FROM usdt_payment_allocations
             WHERE status = ?
             GROUP BY deposit_ref
             HAVING COUNT(*) > 1',
            ['consumed']
        )->getResultArray();

        foreach ($dupDeposits as $row) {
            $out[] = [
                'code'     => 'duplicate_consumption_deposit',
                'severity'=> 'critical',
                'message'  => 'Deposit appears in multiple consumed allocations.',
                'deposit_ref' => $row['deposit_ref'],
                'count'    => (int) $row['cnt'],
            ];
        }

        $dupPurchases = $db->query(
            'SELECT purchase_ref, COUNT(*) AS cnt
             FROM usdt_payment_allocations
             WHERE status = ?
             GROUP BY purchase_ref
             HAVING COUNT(*) > 1',
            ['consumed']
        )->getResultArray();

        foreach ($dupPurchases as $row) {
            $out[] = [
                'code'         => 'duplicate_consumption_purchase',
                'severity'    => 'critical',
                'message'      => 'Purchase appears in multiple consumed allocations.',
                'purchase_ref' => $row['purchase_ref'],
                'count'        => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orphanAllocations(): array
    {
        $db  = db_connect();
        $out = [];

        $rows = $db->query(
            'SELECT a.allocation_ref, a.deposit_ref, a.purchase_ref, a.status
             FROM usdt_payment_allocations a
             LEFT JOIN usdt_deposit_requests d ON d.deposit_ref = a.deposit_ref
             LEFT JOIN coin_purchase_requests p ON p.purchase_ref = a.purchase_ref
             WHERE d.id IS NULL OR p.id IS NULL'
        )->getResultArray();

        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'orphan_allocation',
                'severity'      => 'critical',
                'message'        => 'Allocation references missing deposit and/or purchase.',
                'allocation_ref' => $row['allocation_ref'],
                'deposit_ref'    => $row['deposit_ref'],
                'purchase_ref'   => $row['purchase_ref'],
                'status'         => $row['status'],
            ];
        }

        // Linked allocation but deposit not marked linked/consumed
        $mismatch = $db->query(
            'SELECT a.allocation_ref, a.deposit_ref, a.status AS alloc_status, d.payment_consumption_status
             FROM usdt_payment_allocations a
             INNER JOIN usdt_deposit_requests d ON d.deposit_ref = a.deposit_ref
             WHERE a.status = ? AND d.payment_consumption_status = ?',
            ['linked', 'available']
        )->getResultArray();

        foreach ($mismatch as $row) {
            $out[] = [
                'code'           => 'allocation_deposit_state_mismatch',
                'severity'      => 'warning',
                'message'        => 'Allocation is linked but deposit consumption status is still available.',
                'allocation_ref' => $row['allocation_ref'],
                'deposit_ref'    => $row['deposit_ref'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function approvedWithoutVerifiedPayment(): array
    {
        $platform = $this->platform ?? config('Platform');
        if (! ($platform->requireVerifiedDepositForApproval ?? true)) {
            return [[
                'code'     => 'payment_gate_disabled',
                'severity'=> 'warning',
                'message'  => 'requireVerifiedDepositForApproval is false; approved purchases may lack verified payment by design.',
            ]];
        }

        $rows = db_connect()->query(
            'SELECT purchase_ref, payment_status, payment_deposit_ref, status, total_usdt
             FROM coin_purchase_requests
             WHERE status = ?
               AND (payment_status IS NULL OR payment_status != ? OR payment_deposit_ref IS NULL OR payment_deposit_ref = ?)',
            ['approved', 'verified', '']
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'         => 'approved_without_verified_payment',
                'severity'    => 'critical',
                'message'      => 'Approved purchase lacks verified payment coverage while gate is enabled.',
                'purchase_ref' => $row['purchase_ref'],
                'payment_status' => $row['payment_status'],
                'payment_deposit_ref' => $row['payment_deposit_ref'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function amountMismatches(): array
    {
        $scale = ($this->platform ?? config('Platform'))->usdtScale ?? 8;
        $rows  = db_connect()->query(
            'SELECT a.allocation_ref, a.amount_usdt AS alloc_amount,
                    d.deposit_ref, d.verified_amount_usdt, d.expected_amount_usdt,
                    p.purchase_ref, p.total_usdt
             FROM usdt_payment_allocations a
             INNER JOIN usdt_deposit_requests d ON d.deposit_ref = a.deposit_ref
             INNER JOIN coin_purchase_requests p ON p.purchase_ref = a.purchase_ref'
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $alloc = MoneyMath::normalize((string) $row['alloc_amount'], $scale);
            $dep   = MoneyMath::normalize((string) ($row['verified_amount_usdt'] ?? $row['expected_amount_usdt']), $scale);
            $pur   = MoneyMath::normalize((string) $row['total_usdt'], $scale);

            if (MoneyMath::compare($alloc, $dep, $scale) !== 0
                || MoneyMath::compare($alloc, $pur, $scale) !== 0
            ) {
                $out[] = [
                    'code'           => 'payment_amount_mismatch',
                    'severity'      => 'critical',
                    'message'        => 'Allocation amount does not exactly match verified deposit and/or purchase total.',
                    'allocation_ref' => $row['allocation_ref'],
                    'deposit_ref'    => $row['deposit_ref'],
                    'purchase_ref'   => $row['purchase_ref'],
                    'alloc_amount'   => $alloc,
                    'deposit_amount' => $dep,
                    'purchase_amount'=> $pur,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingOwnershipCredits(): array
    {
        $rows = db_connect()->query(
            'SELECT p.purchase_ref, p.ownership_transaction_ref, p.coin_quantity, p.member_user_id
             FROM coin_purchase_requests p
             LEFT JOIN member_coin_ownership_transactions t
               ON t.transaction_ref = p.ownership_transaction_ref
             WHERE p.status = ?
               AND (
                    p.ownership_transaction_ref IS NULL
                    OR p.ownership_transaction_ref = ?
                    OR t.id IS NULL
               )',
            ['approved', '']
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'         => 'missing_ownership_credit',
                'severity'    => 'critical',
                'message'      => 'Approved purchase has no matching ownership ledger credit.',
                'purchase_ref' => $row['purchase_ref'],
                'ownership_transaction_ref' => $row['ownership_transaction_ref'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function inventoryOwnershipDiscrepancies(): array
    {
        $db  = db_connect();
        $out = [];

        $inv = $db->table('coin_inventory')->where('id', 1)->get()->getRowArray();
        if ($inv === null) {
            return [[
                'code'     => 'inventory_missing',
                'severity'=> 'critical',
                'message'  => 'coin_inventory row id=1 is missing.',
            ]];
        }

        $credited = $db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS total
             FROM member_coin_ownership_transactions
             WHERE type = ? AND direction = ?',
            ['PURCHASE_CREDIT', 'credit']
        )->getRowArray();

        $scale = ($this->platform ?? config('Platform'))->coinQuantityScale ?? 8;
        $allocated = MoneyMath::normalize((string) ($inv['total_allocated'] ?? '0'), $scale);
        $ownedSum  = MoneyMath::normalize((string) ($credited['total'] ?? '0'), $scale);

        if (MoneyMath::compare($allocated, $ownedSum, $scale) !== 0) {
            $out[] = [
                'code'               => 'inventory_ownership_discrepancy',
                'severity'          => 'warning',
                'message'            => 'Inventory total_allocated does not match summed PURCHASE_CREDIT ownership credits. Investigate manually — no auto-repair.',
                'inventory_allocated'=> $allocated,
                'ownership_credits'  => $ownedSum,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function integrityFlaggedDeposits(): array
    {
        $rows = db_connect()->table('usdt_deposit_requests')
            ->select('deposit_ref, integrity_flag, integrity_notes, linked_purchase_ref, payment_consumption_status')
            ->whereIn('integrity_flag', ['reorg_suspected', 'needs_investigation'])
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'         => 'deposit_integrity_flag',
                'severity'    => 'critical',
                'message'      => 'Verified deposit flagged for administrator investigation (no automatic ledger reversal).',
                'deposit_ref'  => $row['deposit_ref'],
                'integrity_flag' => $row['integrity_flag'],
                'notes'        => $row['integrity_notes'],
                'linked_purchase_ref' => $row['linked_purchase_ref'],
                'payment_consumption_status' => $row['payment_consumption_status'],
            ];
        }

        return $out;
    }
}
