<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\UsdtDepositEventModel;
use App\Models\UsdtDepositRequestModel;
use App\Models\UsdtPaymentAllocationModel;

/**
 * Phase 4C: admin payment audit center queries (read-only).
 */
class PaymentAuditService
{
    /**
     * @return array{rows: list<array<string, mixed>>, pager: mixed}
     */
    public function search(
        ?string $status = null,
        ?string $verificationStatus = null,
        ?string $consumption = null,
        ?string $integrity = null,
        ?string $q = null,
        int $perPage = 20
    ): array {
        $model = model(UsdtDepositRequestModel::class);

        if ($status !== null && $status !== '') {
            $model->where('status', $status);
        }
        if ($verificationStatus !== null && $verificationStatus !== '') {
            $model->where('last_verification_status', $verificationStatus);
        }
        if ($consumption !== null && $consumption !== '') {
            $model->where('payment_consumption_status', $consumption);
        }
        if ($integrity !== null && $integrity !== '') {
            $model->where('integrity_flag', $integrity);
        }
        if ($q !== null && trim($q) !== '') {
            $term = trim($q);
            $model->groupStart()
                ->like('deposit_ref', $term)
                ->orLike('tx_hash', $term)
                ->orLike('member_id', $term)
                ->orLike('linked_purchase_ref', $term)
                ->groupEnd();
        }

        $rows = $model->orderBy('id', 'DESC')->paginate($perPage);

        // Attach allocation snapshot without N+1 explosion for small pages.
        $refs = array_column($rows, 'deposit_ref');
        $allocMap = [];
        if ($refs !== []) {
            $allocs = model(UsdtPaymentAllocationModel::class)
                ->whereIn('deposit_ref', $refs)
                ->findAll();
            foreach ($allocs as $a) {
                $allocMap[$a['deposit_ref']] = $a;
            }
        }

        foreach ($rows as &$row) {
            $a = $allocMap[$row['deposit_ref']] ?? null;
            $row['allocation_ref']    = $a['allocation_ref'] ?? null;
            $row['allocation_status'] = $a['status'] ?? null;
            $row['reconciliation_status'] = $this->quickReconStatus($row, $a);
        }
        unset($row);

        return [
            'rows'  => $rows,
            'pager' => $model->pager,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $depositRef): ?array
    {
        $deposit = model(UsdtDepositRequestModel::class)->findByRef($depositRef);
        if ($deposit === null) {
            return null;
        }

        $allocation = model(UsdtPaymentAllocationModel::class)
            ->where('deposit_ref', $depositRef)
            ->first();

        $events = model(UsdtDepositEventModel::class)
            ->where('deposit_ref', $depositRef)
            ->orderBy('id', 'ASC')
            ->findAll();

        $purchase = null;
        $purchaseRef = $deposit['linked_purchase_ref']
            ?? ($allocation['purchase_ref'] ?? null);
        if ($purchaseRef) {
            $purchase = db_connect()->table('coin_purchase_requests')
                ->where('purchase_ref', $purchaseRef)
                ->get()
                ->getRowArray();
        }

        return [
            'deposit'               => $deposit,
            'allocation'            => $allocation,
            'purchase'              => $purchase,
            'events'                => $events,
            'reconciliation_status' => $this->quickReconStatus($deposit, $allocation, $purchase),
            'error_category'        => $deposit['last_verification_status'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed>      $deposit
     * @param array<string, mixed>|null $allocation
     * @param array<string, mixed>|null $purchase
     */
    private function quickReconStatus(array $deposit, ?array $allocation, ?array $purchase = null): string
    {
        if (! empty($deposit['integrity_flag']) && $deposit['integrity_flag'] !== 'ok') {
            return (string) $deposit['integrity_flag'];
        }

        if (($deposit['status'] ?? '') === 'verified'
            && ($deposit['payment_consumption_status'] ?? '') === 'consumed'
            && $purchase !== null
            && ($purchase['status'] ?? '') !== 'approved'
        ) {
            return 'consumed_without_approved_purchase';
        }

        if ($allocation === null && ($deposit['payment_consumption_status'] ?? '') === 'linked') {
            return 'missing_allocation';
        }

        return 'ok';
    }
}
