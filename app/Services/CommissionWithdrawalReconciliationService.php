<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;

/**
 * Phase 11D read-only withdrawal lifecycle reconciliation. Never mutates financial data.
 */
class CommissionWithdrawalReconciliationService
{
    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{summary: array<string, int|string>, mismatches: list<array<string, mixed>>, note: string}
     */
    public function run(int $limit = 200): array
    {
        $scale      = ($this->config ?? config('Platform'))->usdtScale;
        $mismatches = [];
        $db         = db_connect();

        if (! $db->tableExists('commission_withdrawal_requests')) {
            return [
                'summary'    => ['mismatches' => 0],
                'mismatches' => [],
                'note'       => 'Withdrawal tables missing.',
            ];
        }

        $rows = $db->table('commission_withdrawal_requests')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(1000, $limit)))
            ->get()
            ->getResultArray();

        $reservedActive = [
            CommissionWithdrawalService::STATUS_PENDING_ADMIN_REVIEW,
            CommissionWithdrawalService::STATUS_SUBMITTED,
            CommissionWithdrawalService::STATUS_UNDER_REVIEW,
            CommissionWithdrawalService::STATUS_APPROVED,
            CommissionWithdrawalService::STATUS_PAYOUT_IN_PROGRESS,
            CommissionWithdrawalService::STATUS_PAYOUT_SUBMITTED,
        ];

        foreach ($rows as $row) {
            $ref = (string) $row['withdrawal_ref'];

            if ((int) $row['is_reserved'] === 1) {
                $reserve = $db->table('commission_wallet_ledger_entries')
                    ->where('reference_id', $ref)
                    ->where('entry_type', CommissionWalletService::TYPE_WITHDRAWAL_RESERVE)
                    ->countAllResults();
                if ($reserve < 1) {
                    $mismatches[] = ['type' => 'withdrawal_without_reservation', 'transfer_ref' => $ref];
                }
                $dupReserve = $db->table('commission_wallet_ledger_entries')
                    ->where('reference_id', $ref)
                    ->where('entry_type', CommissionWalletService::TYPE_WITHDRAWAL_RESERVE)
                    ->countAllResults();
                if ($dupReserve > 1) {
                    $mismatches[] = ['type' => 'duplicate_reservation', 'transfer_ref' => $ref, 'count' => $dupReserve];
                }
                if (! in_array((string) $row['status'], $reservedActive, true)) {
                    $mismatches[] = ['type' => 'reserved_in_terminal_status', 'transfer_ref' => $ref, 'status' => $row['status']];
                }
            }

            if ((string) $row['status'] === CommissionWithdrawalService::STATUS_PAID) {
                if ((int) $row['is_paid'] !== 1) {
                    $mismatches[] = ['type' => 'paid_status_without_flag', 'transfer_ref' => $ref];
                }
                $settle = $db->table('commission_wallet_ledger_entries')
                    ->where('reference_id', $ref)
                    ->where('entry_type', CommissionWalletService::TYPE_WITHDRAWAL_DEBIT)
                    ->countAllResults();
                if ($settle !== 1) {
                    $mismatches[] = ['type' => 'paid_without_settlement', 'transfer_ref' => $ref, 'settlements' => $settle];
                }
                if ($db->tableExists('commission_withdrawal_payout_evidence')) {
                    $ev = $db->table('commission_withdrawal_payout_evidence')
                        ->where('withdrawal_ref', $ref)
                        ->where('verification_status', 'verified')
                        ->countAllResults();
                    if ($ev < 1) {
                        $mismatches[] = ['type' => 'paid_without_verified_tx', 'transfer_ref' => $ref];
                    }
                }
            }
        }

        // Settlements without withdrawal row
        $settlements = $db->table('commission_wallet_ledger_entries')
            ->where('entry_type', CommissionWalletService::TYPE_WITHDRAWAL_DEBIT)
            ->limit($limit)
            ->get()
            ->getResultArray();
        foreach ($settlements as $s) {
            $ref = (string) ($s['reference_id'] ?? '');
            if ($ref === '') {
                $mismatches[] = ['type' => 'orphaned_settlement_ledger', 'entry_ref' => $s['entry_ref']];
                continue;
            }
            $wd = $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $ref)->countAllResults();
            if ($wd < 1) {
                $mismatches[] = ['type' => 'settlement_without_withdrawal', 'transfer_ref' => $ref];
            }
        }

        if ($db->tableExists('commission_withdrawal_payout_evidence')) {
            $dupTx = $db->query(
                'SELECT tx_hash_normalized, COUNT(*) AS c FROM commission_withdrawal_payout_evidence GROUP BY tx_hash_normalized HAVING c > 1'
            )->getResultArray();
            foreach ($dupTx as $d) {
                $mismatches[] = ['type' => 'duplicate_transaction_hash', 'tx_hash' => $d['tx_hash_normalized']];
            }
        }

        // Negative balances / projection check (sample)
        foreach ($db->table('commission_wallet_accounts')->limit(500)->get()->getResultArray() as $acc) {
            if (MoneyMath::compare((string) $acc['available_balance'], '0', $scale) === -1
                || MoneyMath::compare((string) $acc['reserved_balance'], '0', $scale) === -1
            ) {
                $mismatches[] = ['type' => 'negative_wallet_balance', 'member_id' => $acc['member_id']];
            }
            $expected = MoneyMath::add((string) $acc['available_balance'], (string) $acc['reserved_balance'], $scale);
            if (MoneyMath::compare($expected, (string) $acc['total_balance'], $scale) !== 0) {
                $mismatches[] = ['type' => 'incorrect_available_balance', 'member_id' => $acc['member_id']];
            }
        }

        $walletIssues = service('commissionWalletReconciliationService')->run();
        foreach ($walletIssues['issues'] as $issue) {
            $mismatches[] = [
                'type'   => 'wallet_' . ($issue['code'] ?? 'issue'),
                'member' => $issue['member'] ?? null,
                'detail' => $issue['detail'] ?? null,
            ];
        }

        return [
            'summary' => [
                'rows'       => count($rows),
                'mismatches' => count($mismatches),
            ],
            'mismatches' => $mismatches,
            'note'       => 'Read-only. Commission withdrawals and blockchain payouts remain OFF in Phase 11D.',
        ];
    }
}
