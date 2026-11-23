<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Commission wallet (USDT), separate from coin ownership / mining.
 *
 * Phase 11D: reserve / release / settle architecture prepared.
 * Mutations refuse while kill-switches are OFF; public posting remains blocked.
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
     * Member withdrawable summary (posted ledger only — never simulation/mining/coins).
     *
     * @return array<string, mixed>
     */
    public function withdrawableSummary(int $memberUserId): array
    {
        $scale   = $this->scale();
        $account = $this->getAccount($memberUserId);
        $credits = db_connect()->table('commission_wallet_ledger_entries')
            ->selectSum('amount', 'qty')
            ->where('member_user_id', $memberUserId)
            ->where('entry_type', self::TYPE_COMMISSION_CREDIT)
            ->get()
            ->getRowArray();
        $settled = db_connect()->table('commission_wallet_ledger_entries')
            ->selectSum('amount', 'qty')
            ->where('member_user_id', $memberUserId)
            ->where('entry_type', self::TYPE_WITHDRAWAL_DEBIT)
            ->get()
            ->getRowArray();

        return [
            'currency'              => self::CURRENCY,
            'total_posted_credits'  => MoneyMath::normalize((string) ($credits['qty'] ?? '0'), $scale),
            'available_balance'     => MoneyMath::normalize((string) ($account['available_balance'] ?? '0'), $scale),
            'reserved_balance'      => MoneyMath::normalize((string) ($account['reserved_balance'] ?? '0'), $scale),
            'total_balance'         => MoneyMath::normalize((string) ($account['total_balance'] ?? '0'), $scale),
            'total_withdrawn'       => MoneyMath::normalize((string) ($settled['qty'] ?? '0'), $scale),
            'withdrawal_enabled'    => $this->withdrawalEnabled(),
            'posting_enabled'       => $this->postingEnabled(),
            'blockchain_enabled'    => $this->blockchainPayoutEnabled(),
        ];
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
        $cfg = $this->config ?? config('Platform');

        return (bool) $cfg->commissionPostingEnabled
            || service('financialSandboxService')->allows('commission_posting');
    }

    /**
     * Deployer emergency unlock only (Platform / .env / sandbox).
     * Admin request activation is a separate operational gate on the public withdrawal API.
     */
    public function emergencyUnlockActive(): bool
    {
        if (service('financialSandboxService')->allows('commission_withdrawal')) {
            return true;
        }

        return (bool) ($this->config ?? config('Platform'))->commissionWithdrawalEnabled;
    }

    public function withdrawalEnabled(): bool
    {
        // Emergency unlock (deployer / .env) AND Admin operational enable (DB settings).
        // Admin UI cannot override a false emergency unlock.
        if (! $this->emergencyUnlockActive()) {
            return false;
        }

        try {
            return service('commissionWithdrawalSettingsService')->requestsEnabled();
        } catch (\Throwable) {
            return false;
        }
    }

    public function blockchainPayoutEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->commissionBlockchainPayoutEnabled;
    }

    /**
     * @param array{entry_type: string, amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, notes?: string, created_by?: int} $data
     * @return array<string, mixed>
     */
    public function creditCommission(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->postingEnabled()) {
            throw new RuntimeException('Commission posting is disabled (kill-switch). No wallet credit performed.');
        }

        $sandbox = service('financialSandboxService');
        if (! (bool) ($this->config ?? config('Platform'))->commissionPostingEnabled) {
            $sandbox->assertActive('commission_posting');
            $sandbox->assertSyntheticMember($memberId, 'commission_posting');
        }

        $payload = $data;
        $payload['entry_type'] = self::TYPE_COMMISSION_CREDIT;

        return $this->applyLedger($memberUserId, $memberId, self::TYPE_COMMISSION_CREDIT, $payload);
    }

    /**
     * @param array{amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, created_by?: int, notes?: string} $data
     * @return array<string, mixed>
     */
    public function reserveForWithdrawal(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->emergencyUnlockActive()) {
            throw new RuntimeException('Withdrawals are disabled (kill-switch). No reservation performed.');
        }

        return $this->applyLedger($memberUserId, $memberId, self::TYPE_WITHDRAWAL_RESERVE, $data);
    }

    /**
     * @param array{amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, created_by?: int, notes?: string} $data
     * @return array<string, mixed>
     */
    public function releaseForWithdrawal(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->emergencyUnlockActive()) {
            throw new RuntimeException('Withdrawals are disabled (kill-switch). No reservation release performed.');
        }

        return $this->applyLedger($memberUserId, $memberId, self::TYPE_WITHDRAWAL_RELEASE, $data);
    }

    /**
     * Settle reserved funds after verified PAID payout (consumes reservation once).
     *
     * @param array{amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, created_by?: int, notes?: string} $data
     * @return array<string, mixed>
     */
    public function settleWithdrawal(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->emergencyUnlockActive()) {
            throw new RuntimeException('Withdrawals are disabled (kill-switch). No settlement performed.');
        }

        return $this->applyLedger($memberUserId, $memberId, self::TYPE_WITHDRAWAL_DEBIT, $data);
    }

    /**
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

    /**
     * Atomic ledger apply with row lock. Caller must enforce kill-switch policy.
     *
     * @param array{amount: string, reference_type?: string, reference_id?: string, idempotency_key: string, created_by?: int, notes?: string} $data
     * @return array<string, mixed>
     */
    public function applyLedger(int $memberUserId, string $memberId, string $entryType, array $data): array
    {
        $scale  = $this->scale();
        $amount = MoneyMath::normalize((string) ($data['amount'] ?? ''), $scale);
        if (! MoneyMath::isPositive($amount, $scale)) {
            throw new InvalidArgumentException('Amount must be positive.');
        }

        $idem = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idem === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        $existing = db_connect()->table('commission_wallet_ledger_entries')
            ->where('idempotency_key', $idem)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            return $existing;
        }

        $this->ensureAccount($memberUserId, $memberId);

        $db = db_connect();
        $db->transBegin();

        try {
            $account = $db->query(
                'SELECT * FROM commission_wallet_accounts WHERE member_user_id = ? AND currency = ? FOR UPDATE',
                [$memberUserId, self::CURRENCY]
            )->getRowArray();
            if ($account === null) {
                throw new RuntimeException('Commission wallet account missing.');
            }

            $available = MoneyMath::normalize((string) $account['available_balance'], $scale);
            $reserved  = MoneyMath::normalize((string) $account['reserved_balance'], $scale);
            $total     = MoneyMath::normalize((string) $account['total_balance'], $scale);

            $availDelta = MoneyMath::normalize('0', $scale);
            $resDelta   = MoneyMath::normalize('0', $scale);
            $direction  = 'debit';

            switch ($entryType) {
                case self::TYPE_COMMISSION_CREDIT:
                    $availDelta = $amount;
                    $direction  = 'credit';
                    $available  = MoneyMath::add($available, $amount, $scale);
                    $total      = MoneyMath::add($total, $amount, $scale);
                    break;

                case self::TYPE_WITHDRAWAL_RESERVE:
                    if (MoneyMath::compare($amount, $available, $scale) === 1) {
                        throw new InvalidArgumentException('Insufficient available posted commission balance.');
                    }
                    $availDelta = MoneyMath::mul($amount, '-1', $scale);
                    $resDelta   = $amount;
                    $available  = MoneyMath::sub($available, $amount, $scale);
                    $reserved   = MoneyMath::add($reserved, $amount, $scale);
                    break;

                case self::TYPE_WITHDRAWAL_RELEASE:
                    if (MoneyMath::compare($amount, $reserved, $scale) === 1) {
                        throw new InvalidArgumentException('Release amount exceeds reserved balance.');
                    }
                    $availDelta = $amount;
                    $resDelta   = MoneyMath::mul($amount, '-1', $scale);
                    $direction  = 'credit';
                    $available  = MoneyMath::add($available, $amount, $scale);
                    $reserved   = MoneyMath::sub($reserved, $amount, $scale);
                    break;

                case self::TYPE_WITHDRAWAL_DEBIT:
                    if (MoneyMath::compare($amount, $reserved, $scale) === 1) {
                        throw new InvalidArgumentException('Settlement amount exceeds reserved balance.');
                    }
                    $resDelta = MoneyMath::mul($amount, '-1', $scale);
                    $reserved = MoneyMath::sub($reserved, $amount, $scale);
                    $total    = MoneyMath::sub($total, $amount, $scale);
                    break;

                default:
                    throw new InvalidArgumentException('Unsupported ledger entry type.');
            }

            if (MoneyMath::compare($available, '0', $scale) === -1
                || MoneyMath::compare($reserved, '0', $scale) === -1
                || MoneyMath::compare($total, '0', $scale) === -1
            ) {
                throw new RuntimeException('Ledger mutation would create a negative balance.');
            }

            $expectedTotal = MoneyMath::add($available, $reserved, $scale);
            if (MoneyMath::compare($expectedTotal, $total, $scale) !== 0) {
                throw new RuntimeException('Ledger invariant total != available + reserved.');
            }

            $now      = date('Y-m-d H:i:s');
            $entryRef = 'CWL-' . strtoupper(bin2hex(random_bytes(6)));

            $db->table('commission_wallet_ledger_entries')->insert([
                'entry_ref'                 => $entryRef,
                'member_user_id'            => $memberUserId,
                'member_id'                 => $memberId,
                'currency'                  => self::CURRENCY,
                'entry_type'                => $entryType,
                'direction'                 => $direction,
                'amount'                    => $amount,
                'available_delta'           => $availDelta,
                'reserved_delta'            => $resDelta,
                'balance_after_available'   => $available,
                'balance_after_reserved'    => $reserved,
                'balance_after_total'       => $total,
                'reference_type'            => $data['reference_type'] ?? null,
                'reference_id'              => $data['reference_id'] ?? null,
                'idempotency_key'           => $idem,
                'notes'                     => $data['notes'] ?? null,
                'created_by'                => $data['created_by'] ?? null,
                'created_at'                => $now,
            ]);

            $db->table('commission_wallet_accounts')->where('id', (int) $account['id'])->update([
                'available_balance' => $available,
                'reserved_balance'  => $reserved,
                'total_balance'     => $total,
                'updated_at'        => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Commission wallet ledger write failed.');
            }
            $db->transCommit();

            $this->audit->log('commission.wallet_ledger_' . $entryType, (int) ($data['created_by'] ?? $memberUserId), 'commission_wallet', $entryRef, [
                'amount'       => $amount,
                'reference_id' => $data['reference_id'] ?? null,
            ]);

            return $db->table('commission_wallet_ledger_entries')->where('entry_ref', $entryRef)->get()->getRowArray() ?? [];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale;
    }
}
