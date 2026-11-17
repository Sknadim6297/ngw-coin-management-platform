<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use App\Models\UsdtDepositRequestModel;

/**
 * Unified admin Payment Approvals listing (purchase-centric + orphan deposits).
 * Does not alter financial state — read/join only.
 */
class PaymentApprovalsService
{
    public function __construct(
        private readonly CoinPurchaseRequestModel $purchases = new CoinPurchaseRequestModel(),
        private readonly UsdtDepositRequestModel $deposits = new UsdtDepositRequestModel(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel()
    ) {
    }

    /**
     * @return array{
     *   rows: list<array<string, mixed>>,
     *   pager: \CodeIgniter\Pager\Pager|null,
     *   counts: array<string, int>,
     *   mode: string
     * }
     */
    public function listForAdmin(string $tab, ?string $search, int $perPage = 20): array
    {
        $tab = in_array($tab, ['all', 'pending', 'completed', 'rejected', 'unlinked'], true) ? $tab : 'all';
        $search = $search !== null ? trim($search) : '';

        $counts = [
            'all'       => $this->countPurchases(null),
            'pending'   => $this->countPurchases('pending'),
            'completed' => $this->countPurchases('approved'),
            'rejected'  => $this->countPurchases('rejected'),
            'unlinked'  => $this->countUnlinkedDeposits(),
        ];

        if ($tab === 'unlinked') {
            $rows = $this->listUnlinkedDeposits($search !== '' ? $search : null, $perPage);

            return [
                'rows'  => $rows,
                'pager' => $this->deposits->pager,
                'counts'=> $counts,
                'mode'  => 'unlinked',
            ];
        }

        $status = match ($tab) {
            'pending'   => 'pending',
            'completed' => 'approved',
            'rejected'  => 'rejected',
            default     => null,
        };

        $purchaseRows = $this->purchases->adminList($status, $search !== '' ? $search : null, $perPage);
        $enriched = $this->enrichPurchaseRows($purchaseRows);

        return [
            'rows'   => $enriched,
            'pager'  => $this->purchases->pager,
            'counts' => $counts,
            'mode'   => 'purchase',
        ];
    }

    /**
     * @param list<array<string, mixed>> $purchaseRows
     * @return list<array<string, mixed>>
     */
    private function enrichPurchaseRows(array $purchaseRows): array
    {
        if ($purchaseRows === []) {
            return [];
        }

        $memberIds = [];
        $depositRefs = [];
        foreach ($purchaseRows as $row) {
            $memberIds[] = (int) ($row['member_user_id'] ?? 0);
            if (! empty($row['payment_deposit_ref'])) {
                $depositRefs[] = (string) $row['payment_deposit_ref'];
            }
        }

        $profiles = [];
        $memberIds = array_values(array_unique(array_filter($memberIds)));
        if ($memberIds !== []) {
            foreach ($this->profiles->whereIn('user_id', $memberIds)->findAll() as $profile) {
                $profiles[(int) $profile->user_id] = $profile;
            }
        }

        $deposits = [];
        $depositRefs = array_values(array_unique($depositRefs));
        if ($depositRefs !== []) {
            foreach ($this->deposits->whereIn('deposit_ref', $depositRefs)->findAll() as $dep) {
                $deposits[(string) $dep['deposit_ref']] = $dep;
            }
        }

        // Also attach matching pending deposit by amount when not yet linked.
        $out = [];
        foreach ($purchaseRows as $row) {
            $uid = (int) ($row['member_user_id'] ?? 0);
            $profile = $profiles[$uid] ?? null;
            $dep = null;
            if (! empty($row['payment_deposit_ref']) && isset($deposits[(string) $row['payment_deposit_ref']])) {
                $dep = $deposits[(string) $row['payment_deposit_ref']];
            } elseif (($row['status'] ?? '') === 'pending') {
                $dep = service('usdtDepositService')->findMatchingPendingPaymentForPurchase($row);
            }

            $out[] = [
                'kind'              => 'purchase',
                'member_name'       => $profile->member_name ?? '—',
                'member_id'         => (string) ($row['member_id'] ?? ($profile->member_id ?? '—')),
                'purchase_ref'      => (string) ($row['purchase_ref'] ?? ''),
                'deposit_ref'       => $dep['deposit_ref'] ?? ($row['payment_deposit_ref'] ?? null),
                'usdt_amount'       => (string) ($row['total_usdt'] ?? ''),
                'coin_quantity'     => (string) ($row['coin_quantity'] ?? ''),
                'tx_hash'           => $dep['tx_hash'] ?? null,
                'payment_status'    => $dep['status'] ?? ($row['payment_status'] ?? 'unpaid'),
                'purchase_status'   => (string) ($row['status'] ?? ''),
                'submitted_at'      => $dep['submitted_at'] ?? ($row['requested_at'] ?? null),
                'review_url'        => site_url('admin/payment-approvals/' . rawurlencode((string) $row['purchase_ref'])),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listUnlinkedDeposits(?string $search, int $perPage): array
    {
        $builder = $this->deposits
            ->groupStart()
                ->where('linked_purchase_ref', null)
                ->orWhere('linked_purchase_ref', '')
            ->groupEnd()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->orderBy('id', 'DESC');

        if ($search !== null && $search !== '') {
            $builder->groupStart()
                ->like('deposit_ref', $search)
                ->orLike('member_id', $search)
                ->orLike('tx_hash', $search)
            ->groupEnd();
        }

        $rows = $builder->paginate($perPage);
        $out  = [];
        foreach ($rows as $dep) {
            $profile = $this->profiles->findByUserId((int) $dep['member_user_id']);
            $out[] = [
                'kind'            => 'deposit',
                'member_name'     => $profile->member_name ?? '—',
                'member_id'       => (string) ($dep['member_id'] ?? ''),
                'purchase_ref'    => null,
                'deposit_ref'     => (string) ($dep['deposit_ref'] ?? ''),
                'usdt_amount'     => (string) ($dep['expected_amount_usdt'] ?? ''),
                'coin_quantity'   => '—',
                'tx_hash'         => $dep['tx_hash'] ?? null,
                'payment_status'  => (string) ($dep['status'] ?? ''),
                'purchase_status' => '—',
                'submitted_at'    => $dep['submitted_at'] ?? null,
                'review_url'      => site_url('admin/payment-approvals/deposit/' . rawurlencode((string) $dep['deposit_ref'])),
            ];
        }

        return $out;
    }

    private function countPurchases(?string $status): int
    {
        $builder = db_connect()->table('coin_purchase_requests');
        if ($status !== null) {
            $builder->where('status', $status);
        }

        return (int) $builder->countAllResults();
    }

    private function countUnlinkedDeposits(): int
    {
        return (int) db_connect()->table('usdt_deposit_requests')
            ->groupStart()
                ->where('linked_purchase_ref', null)
                ->orWhere('linked_purchase_ref', '')
            ->groupEnd()
            ->whereNotIn('status', ['cancelled', 'draft'])
            ->countAllResults();
    }
}
