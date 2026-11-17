<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11A separate mining wallet. Real credits blocked while miningPostingEnabled=false.
 * P2P and external withdrawals are always blocked in this phase.
 */
class MiningWalletService
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    public function postingEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->miningPostingEnabled;
    }

    public function p2pEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->miningP2pEnabled;
    }

    public function assetCode(): string
    {
        return (string) (($this->config ?? config('Platform'))->miningEarningAsset ?: 'COIN');
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureWallet(int $memberUserId, string $memberId, ?string $asset = null): array
    {
        $asset = $asset ?? $this->assetCode();
        $row = db_connect()->table('mining_wallets')
            ->where('member_user_id', $memberUserId)
            ->where('asset_code', $asset)
            ->get()
            ->getRowArray();
        if ($row !== null) {
            return $row;
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('mining_wallets')->insert([
            'member_user_id'    => $memberUserId,
            'member_id'         => $memberId,
            'asset_code'        => $asset,
            'available_balance' => '0.00000000',
            'total_credited'    => '0.00000000',
            'total_debited'     => '0.00000000',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);

        return db_connect()->table('mining_wallets')
            ->where('member_user_id', $memberUserId)
            ->where('asset_code', $asset)
            ->get()
            ->getRowArray() ?? [
                'member_user_id'    => $memberUserId,
                'member_id'         => $memberId,
                'asset_code'        => $asset,
                'available_balance' => '0.00000000',
            ];
    }

    /**
     * @return array{available_balance: string, total_credited: string, total_debited: string, asset_code: string, posting_enabled: bool}
     */
    public function balanceFor(int $memberUserId, ?string $asset = null): array
    {
        $asset = $asset ?? $this->assetCode();
        $row = db_connect()->table('mining_wallets')
            ->where('member_user_id', $memberUserId)
            ->where('asset_code', $asset)
            ->get()
            ->getRowArray();

        return [
            'available_balance' => MoneyMath::normalize((string) ($row['available_balance'] ?? '0'), 8),
            'total_credited'    => MoneyMath::normalize((string) ($row['total_credited'] ?? '0'), 8),
            'total_debited'     => MoneyMath::normalize((string) ($row['total_debited'] ?? '0'), 8),
            'asset_code'        => $asset,
            'posting_enabled'   => $this->postingEnabled(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ledgerFor(int $memberUserId, int $limit = 50): array
    {
        return db_connect()->table('mining_wallet_ledger')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * Real credit path — always blocked while kill-switch OFF.
     */
    public function creditDailyMining(int $memberUserId, string $memberId, array $data): never
    {
        if (! $this->postingEnabled()) {
            throw new RuntimeException('Mining posting is disabled (kill-switch). No mining wallet credit performed.');
        }

        throw new RuntimeException('Executable mining posting is not authorized in Phase 11A.');
    }

    /**
     * Legacy entry — delegates to Phase 11C service (execution remains blocked).
     *
     * @return never
     */
    public function transferP2p(int $fromUserId, int $toUserId, string $amount): never
    {
        $receiver = model(\App\Models\MemberProfileModel::class)->findByUserId($toUserId);
        if ($receiver === null) {
            throw new InvalidArgumentException('Receiver not found.');
        }
        service('miningP2pTransferService')->execute(
            $fromUserId,
            (string) $receiver->member_id,
            $amount,
            '', // password required by member UI path; empty fails reauth
            null
        );
    }

    public function withdrawExternal(int $memberUserId, string $amount): never
    {
        throw new RuntimeException('Mining wallet funds are not externally withdrawable.');
    }

    /**
     * Posted amount for a member/business date (0 while posting OFF / empty ledger).
     */
    public function postedAmountForDate(int $memberUserId, string $businessDate): string
    {
        $row = db_connect()->table('mining_wallet_ledger')
            ->selectSum('amount', 'qty')
            ->where('member_user_id', $memberUserId)
            ->where('business_date', $businessDate)
            ->where('direction', 'credit')
            ->where('entry_type', 'daily_mining_credit')
            ->get()
            ->getRowArray();

        return MoneyMath::normalize((string) ($row['qty'] ?? '0'), 8);
    }
}
