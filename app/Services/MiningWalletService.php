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
        $cfg = $this->config ?? config('Platform');

        return (bool) $cfg->miningPostingEnabled
            || service('financialSandboxService')->allows('mining_posting');
    }

    public function p2pEnabled(): bool
    {
        $cfg = $this->config ?? config('Platform');

        return (bool) $cfg->miningP2pEnabled
            || service('financialSandboxService')->allows('mining_p2p');
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
     * Real mining credit. Production kill-switch OFF unless Financial Sandbox window is open.
     *
     * @param array{amount: string, business_date: string, idempotency_key: string, rate_ref?: string, created_by?: int, meta?: array<string, mixed>} $data
     * @return array<string, mixed>
     */
    public function creditDailyMining(int $memberUserId, string $memberId, array $data): array
    {
        if (! $this->postingEnabled()) {
            throw new RuntimeException('Mining posting is disabled (kill-switch). No mining wallet credit performed.');
        }

        $sandbox = service('financialSandboxService');
        if (! (bool) ($this->config ?? config('Platform'))->miningPostingEnabled) {
            $sandbox->assertActive('mining_posting');
            $sandbox->assertSyntheticMember($memberId, 'mining_posting');
        }

        $amount = MoneyMath::normalize((string) ($data['amount'] ?? ''), 8);
        if (! MoneyMath::isPositive($amount, 8)) {
            throw new InvalidArgumentException('Mining credit amount must be positive.');
        }
        $businessDate = (string) ($data['business_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            throw new InvalidArgumentException('business_date (Y-m-d) is required.');
        }
        $idem = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idem === '') {
            throw new InvalidArgumentException('Idempotency key is required.');
        }

        $existing = db_connect()->table('mining_wallet_ledger')
            ->where('idempotency_key', $idem)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            return $existing;
        }

        $asset = $this->assetCode();
        $this->ensureWallet($memberUserId, $memberId, $asset);

        $db = db_connect();
        $db->transBegin();

        try {
            $wallet = $db->query(
                'SELECT * FROM mining_wallets WHERE member_user_id = ? AND asset_code = ? FOR UPDATE',
                [$memberUserId, $asset]
            )->getRowArray();
            if ($wallet === null) {
                throw new RuntimeException('Mining wallet missing.');
            }

            $available = MoneyMath::normalize((string) $wallet['available_balance'], 8);
            $after     = MoneyMath::add($available, $amount, 8);
            $now       = date('Y-m-d H:i:s');
            $entryRef  = 'MWL-' . strtoupper(bin2hex(random_bytes(6)));

            $db->table('mining_wallets')->where('id', (int) $wallet['id'])->update([
                'available_balance' => $after,
                'total_credited'    => MoneyMath::add((string) $wallet['total_credited'], $amount, 8),
                'updated_at'        => $now,
            ]);

            $db->table('mining_wallet_ledger')->insert([
                'entry_ref'       => $entryRef,
                'member_user_id'  => $memberUserId,
                'member_id'       => $memberId,
                'asset_code'      => $asset,
                'entry_type'      => 'daily_mining_credit',
                'direction'       => 'credit',
                'amount'          => $amount,
                'balance_after'   => $after,
                'business_date'   => $businessDate,
                'reference_type'  => 'mining_daily',
                'reference_id'    => $data['rate_ref'] ?? $businessDate,
                'idempotency_key' => $idem,
                'meta_json'       => isset($data['meta'])
                    ? json_encode($data['meta'], JSON_THROW_ON_ERROR)
                    : null,
                'created_by'      => $data['created_by'] ?? null,
                'created_at'      => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Mining credit failed.');
            }
            $db->transCommit();

            $this->audit->log('mining.daily_credit', $memberUserId, 'mining_wallet', $entryRef, [
                'amount'        => $amount,
                'business_date' => $businessDate,
                'sandbox'       => $sandbox->isWindowOpen(),
            ]);

            return db_connect()->table('mining_wallet_ledger')
                ->where('entry_ref', $entryRef)
                ->get()
                ->getRowArray() ?? ['entry_ref' => $entryRef, 'amount' => $amount];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * Legacy entry — delegates to Phase 11C service (execution remains blocked).
     *
     * @return never
     */
    public function transferP2p(int $fromUserId, int $toUserId, string $amount): never
    {
        if (! $this->p2pEnabled()) {
            throw new RuntimeException('Mining P2P transfers are disabled (kill-switch).');
        }
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
