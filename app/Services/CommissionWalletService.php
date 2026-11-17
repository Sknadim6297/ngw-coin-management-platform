<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7D commission wallet (USDT), separate from coin ownership.
 *
 * Invariants (documented & enforced on mutation paths):
 * 1) Ledger is source of truth; account balances are projections.
 * 2) total = available + reserved (exact BCMath scale).
 * 3) available >= 0 and reserved >= 0.
 * 4) Append-only ledger; unique idempotency_key.
 * 5) No direct balance edits.
 * 6) Simulation / mining previews NEVER credit this wallet.
 * 7) All mutation paths refuse while commissionPostingEnabled /
 *    commissionWithdrawalEnabled kill-switches are false (Phase 7D).
 */
class CommissionWalletService
{
    public const CURRENCY = 'USDT';

    public const TYPE_COMMISSION_CREDIT   = 'commission_credit';
    public const TYPE_WITHDRAWAL_RESERVE  = 'withdrawal_reserve';
    public const TYPE_WITHDRAWAL_RELEASE  = 'withdrawal_release';
    public const TYPE_WITHDRAWAL_DEBIT    = 'withdrawal_debit';

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Ensure account exists (zero balances). Safe / non-financial.
     *
     * @return array<string, mixed>
     */
    public function ensureAccount(int $memberUserId, string $memberId, string $currency = self::CURRENCY): array
    {
        $currency = strtoupper($currency);
        if ($currency !== self::CURRENCY) {
            throw new InvalidArgumentException('Only USDT commission wallets are supported.');
        }

        $db  = db_connect();
        $row = $db->table('commission_wallet_accounts')
            ->where('member_user_id', $memberUserId)
            ->where('currency', $currency)
            ->get()
            ->getRowArray();
        if ($row !== null) {
            return $row;
        }

        $now   = date('Y-m-d H:i:s');
        $scale = $this->scale();
        $zero  = MoneyMath::normalize('0', $scale);
        $db->table('commission_wallet_accounts')->insert([
            'member_user_id'    => $memberUserId,
            'member_id'         => $memberId,
            'currency'          => $currency,
            'available_balance' => $zero,
            'reserved_balance'  => $zero,
            'total_balance'     => $zero,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return $db->table('commission_wallet_accounts')
            ->where('member_user_id', $memberUserId)
            ->where('currency', $currency)
            ->get()
            ->getRowArray() ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccount(int $memberUserId, string $currency = self::CURRENCY): array
    {
        $row = db_connect()->table('commission_wallet_accounts')
            ->where('member_user_id', $memberUserId)
            ->where('currency', strtoupper($currency))
            ->get()
            ->getRowArray();

        if ($row === null) {
            $scale = $this->scale();
            $zero  = MoneyMath::normalize('0', $scale);

            return [
                'member_user_id'    => $memberUserId,
                'member_id'         => null,
                'currency'          => strtoupper($currency),
                'available_balance' => $zero,
                'reserved_balance'  => $zero,
                'total_balance'     => $zero,
                'exists'            => false,
            ];
        }

        $row['exists'] = true;

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentEntries(int $memberUserId, int $limit = 20): array
    {
        return db_connect()->table('commission_wallet_ledger_entries')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
    }

    public function postingEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->commissionPostingEnabled;
    }

    public function withdrawalEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->commissionWithdrawalEnabled;
    }

    public function blockchainPayoutEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->commissionBlockchainPayoutEnabled;
    }

    /**
     * Phase 7D: mutation API exists for architecture/tests of guards only.
     * Always refuses while kill-switch is off. Never used by simulation.
     *
     * @param array{entry_type: string, amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, notes?: string, created_by?: int} $data
     * @return array<string, mixed>
     */
    public function creditCommission(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->postingEnabled()) {
            throw new RuntimeException('Commission posting is disabled (kill-switch). No wallet credit performed.');
        }

        throw new RuntimeException('Executable commission posting is not implemented in Phase 7D.');
    }

    /**
     * @param array{amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, created_by?: int} $data
     * @return array<string, mixed>
     */
    public function reserveForWithdrawal(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->withdrawalEnabled()) {
            throw new RuntimeException('Withdrawals are disabled (kill-switch). No reservation performed.');
        }

        throw new RuntimeException('Executable withdrawal reservation is not implemented in Phase 7D.');
    }

    /**
     * Platform aggregates (actual ledger only — not simulation).
     *
     * @return array<string, mixed>
     */
    public function platformSnapshot(): array
    {
        $scale = $this->scale();
        $acc   = db_connect()->table('commission_wallet_accounts')
            ->selectSum('available_balance', 'available')
            ->selectSum('reserved_balance', 'reserved')
            ->selectSum('total_balance', 'total')
            ->get()
            ->getRowArray();

        $accounts = (int) db_connect()->table('commission_wallet_accounts')->countAllResults();
        $credits  = db_connect()->table('commission_wallet_ledger_entries')
            ->selectSum('amount', 'qty')
            ->where('entry_type', self::TYPE_COMMISSION_CREDIT)
            ->get()
            ->getRowArray();

        return [
            'account_count'       => $accounts,
            'total_available'     => MoneyMath::normalize((string) ($acc['available'] ?? '0'), $scale),
            'total_reserved'      => MoneyMath::normalize((string) ($acc['reserved'] ?? '0'), $scale),
            'total_balance'       => MoneyMath::normalize((string) ($acc['total'] ?? '0'), $scale),
            'total_credits'       => MoneyMath::normalize((string) ($credits['qty'] ?? '0'), $scale),
            'posting_enabled'     => $this->postingEnabled(),
            'withdrawal_enabled'  => $this->withdrawalEnabled(),
            'blockchain_enabled'  => $this->blockchainPayoutEnabled(),
            'posting_status'      => $this->postingEnabled() ? 'ENABLED' : 'DISABLED',
            'withdrawal_status'   => $this->withdrawalEnabled() ? 'ENABLED' : 'Withdrawals Not Activated',
        ];
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale;
    }
}
