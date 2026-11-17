<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;

/**
 * Phase 7D read-only commission wallet reconciliation. Never auto-repairs.
 */
class CommissionWalletReconciliationService
{
    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{issue_count: int, issues: list<array<string, mixed>>}
     */
    public function run(): array
    {
        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $issues = [];
        $db     = db_connect();

        // 1) Ledger vs account projection mismatches
        $accounts = $db->table('commission_wallet_accounts')->get()->getResultArray();
        foreach ($accounts as $acc) {
            $uid = (int) $acc['member_user_id'];
            $sumAvail = MoneyMath::normalize('0', $scale);
            $sumRes   = MoneyMath::normalize('0', $scale);
            $entries  = $db->table('commission_wallet_ledger_entries')
                ->where('member_user_id', $uid)
                ->where('currency', $acc['currency'])
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
            foreach ($entries as $e) {
                $sumAvail = MoneyMath::add($sumAvail, (string) $e['available_delta'], $scale);
                $sumRes   = MoneyMath::add($sumRes, (string) $e['reserved_delta'], $scale);
            }
            $total = MoneyMath::add($sumAvail, $sumRes, $scale);
            if (MoneyMath::compare($sumAvail, (string) $acc['available_balance'], $scale) !== 0
                || MoneyMath::compare($sumRes, (string) $acc['reserved_balance'], $scale) !== 0
                || MoneyMath::compare($total, (string) $acc['total_balance'], $scale) !== 0) {
                $issues[] = [
                    'code'    => 'ledger_account_mismatch',
                    'member'  => $acc['member_id'],
                    'detail'  => 'Cached balances do not match ledger deltas.',
                ];
            }

            // 2) Negative balances
            if (MoneyMath::compare((string) $acc['available_balance'], '0', $scale) === -1
                || MoneyMath::compare((string) $acc['reserved_balance'], '0', $scale) === -1) {
                $issues[] = [
                    'code'   => 'negative_balance',
                    'member' => $acc['member_id'],
                    'detail' => 'Negative available or reserved balance detected.',
                ];
            }

            // Over-reserved: reserved > total or available negative after reserve
            if (MoneyMath::compare((string) $acc['reserved_balance'], (string) $acc['total_balance'], $scale) === 1) {
                $issues[] = [
                    'code'   => 'over_reserved',
                    'member' => $acc['member_id'],
                    'detail' => 'Reserved exceeds total balance.',
                ];
            }
        }

        // 3) Duplicate idempotency keys (unique index should prevent; detect orphans)
        $dupes = $db->query(
            'SELECT idempotency_key, COUNT(*) AS c FROM commission_wallet_ledger_entries GROUP BY idempotency_key HAVING c > 1'
        )->getResultArray();
        foreach ($dupes as $d) {
            $issues[] = [
                'code'   => 'duplicate_idempotency_key',
                'member' => null,
                'detail' => (string) $d['idempotency_key'],
            ];
        }

        // 4) Invalid currency
        $badCur = $db->table('commission_wallet_ledger_entries')
            ->where('currency !=', 'USDT')
            ->countAllResults();
        if ($badCur > 0) {
            $issues[] = [
                'code'   => 'invalid_currency',
                'member' => null,
                'detail' => $badCur . ' non-USDT ledger entries',
            ];
        }

        // 5) Missing commission source references on credits
        $missingRef = $db->table('commission_wallet_ledger_entries')
            ->where('entry_type', CommissionWalletService::TYPE_COMMISSION_CREDIT)
            ->groupStart()
                ->where('reference_id', null)
                ->orWhere('reference_id', '')
            ->groupEnd()
            ->countAllResults();
        if ($missingRef > 0) {
            $issues[] = [
                'code'   => 'missing_commission_source',
                'member' => null,
                'detail' => $missingRef . ' credit entries without reference_id',
            ];
        }

        // 6–7) Invalid / over withdrawal reservations
        $reservedWd = $db->table('commission_withdrawal_requests')
            ->where('is_reserved', 1)
            ->whereNotIn('status', ['submitted', 'under_review', 'approved'])
            ->countAllResults();
        if ($reservedWd > 0) {
            $issues[] = [
                'code'   => 'invalid_withdrawal_reservation',
                'member' => null,
                'detail' => $reservedWd . ' reserved withdrawals in unexpected status',
            ];
        }

        // 8) Unsupported transaction types
        $allowed = [
            CommissionWalletService::TYPE_COMMISSION_CREDIT,
            CommissionWalletService::TYPE_WITHDRAWAL_RESERVE,
            CommissionWalletService::TYPE_WITHDRAWAL_RELEASE,
            CommissionWalletService::TYPE_WITHDRAWAL_DEBIT,
        ];
        $badTypes = $db->table('commission_wallet_ledger_entries')
            ->whereNotIn('entry_type', $allowed)
            ->countAllResults();
        if ($badTypes > 0) {
            $issues[] = [
                'code'   => 'unsupported_transaction_type',
                'member' => null,
                'detail' => $badTypes . ' unsupported entry types',
            ];
        }

        // 9) Cross-member references: entry member_id vs account
        foreach ($db->table('commission_wallet_ledger_entries')->limit(5000)->get()->getResultArray() as $e) {
            $acc = $db->table('commission_wallet_accounts')
                ->where('member_user_id', (int) $e['member_user_id'])
                ->get()
                ->getRowArray();
            if ($acc !== null && (string) $acc['member_id'] !== (string) $e['member_id']) {
                $issues[] = [
                    'code'   => 'cross_member_reference',
                    'member' => $e['member_id'],
                    'detail' => 'Ledger member_id mismatches account member_id',
                ];
            }
        }

        // 10) Missing audit for paid withdrawals (none should be paid in 7D)
        $paid = $db->table('commission_withdrawal_requests')->where('is_paid', 1)->countAllResults();
        if ($paid > 0) {
            $issues[] = [
                'code'   => 'unexpected_paid_withdrawal',
                'member' => null,
                'detail' => $paid . ' withdrawals marked paid (should be zero in Phase 7D)',
            ];
        }

        return [
            'issue_count' => count($issues),
            'issues'      => $issues,
        ];
    }
}
