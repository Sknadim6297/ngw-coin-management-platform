<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11C mining-wallet P2P transfer architecture.
 * Transfers only posted mining wallet balances. Execution remains kill-switched OFF.
 */
class MiningP2pTransferService
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_REJECTED  = 'rejected';

    public const RATE_LIMIT_PER_HOUR = 20;

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly MiningWalletService $wallets = new MiningWalletService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    public function enabled(): bool
    {
        return $this->wallets->p2pEnabled();
    }

    public function assetCode(): string
    {
        return $this->wallets->assetCode();
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config ?? config('Platform');

        return [
            'mining_p2p_enabled'     => (bool) $cfg->miningP2pEnabled,
            'mining_posting_enabled' => (bool) $cfg->miningPostingEnabled,
            'asset_code'             => $this->assetCode(),
            'asset_approved'         => false, // DRAFT until business sign-off
            'execution_authorized'   => false,
            'blockers'               => [
                'miningP2pEnabled=false',
                'Mining earning asset denomination still DRAFT (Platform::miningEarningAsset).',
                'Executable P2P not authorized in Phase 11C.',
            ],
        ];
    }

    /**
     * Minimal receiver confirmation (no email/phone).
     *
     * @return array{member_id: string, member_name: string, account_status: string, user_id: int}
     */
    public function lookupReceiver(string $memberId, int $senderUserId): array
    {
        $memberId = strtoupper(trim($memberId));
        if ($memberId === '') {
            throw new InvalidArgumentException('Receiver Member ID is required.');
        }

        $profile = $this->profiles->findByMemberId($memberId);
        if ($profile === null) {
            $this->audit->log('mining.p2p_lookup_denied', $senderUserId, 'member_profile', $memberId, [
                'reason' => 'not_found',
            ]);
            throw new InvalidArgumentException('Receiver member not found.');
        }

        $rootId = trim((string) ($this->config ?? config('Platform'))->rootSponsorId);
        if (! empty($profile->is_root_sponsor) || ($rootId !== '' && $profile->member_id === $rootId)) {
            throw new InvalidArgumentException('Receiver is not eligible for mining P2P.');
        }

        $user = auth()->getProvider()->findById((int) $profile->user_id);
        if ($user === null || (method_exists($user, 'inGroup') && ! $user->inGroup('member'))) {
            throw new InvalidArgumentException('Receiver is not a valid member account.');
        }
        if (method_exists($user, 'inGroup')
            && $user->inGroup('admin', 'finance_viewer', 'finance_operator', 'finance_approver', 'finance_auditor')
        ) {
            throw new InvalidArgumentException('Cannot transfer to an administrator account.');
        }
        if ((int) $profile->user_id === $senderUserId) {
            throw new InvalidArgumentException('Self-transfer is not allowed.');
        }
        if ($profile->isInactive()) {
            throw new InvalidArgumentException('Receiver account is inactive.');
        }

        return [
            'member_id'      => (string) $profile->member_id,
            'member_name'    => (string) $profile->member_name,
            'account_status' => (string) $profile->account_status,
            'user_id'        => (int) $profile->user_id,
        ];
    }

    /**
     * Read-only transfer preview (no writes).
     *
     * @return array<string, mixed>
     */
    public function preview(int $senderUserId, string $receiverMemberId, string $amount): array
    {
        $this->assertNotImpersonation();
        $sender = $this->profiles->findByUserId($senderUserId);
        if ($sender === null) {
            throw new InvalidArgumentException('Sender profile not found.');
        }
        if ($sender->isInactive()) {
            throw new InvalidArgumentException('Sender account is inactive.');
        }

        $receiver = $this->lookupReceiver($receiverMemberId, $senderUserId);
        $asset    = $this->assetCode();
        $qty      = $this->normalizeAmount($amount);
        $wallet   = $this->wallets->balanceFor($senderUserId, $asset);
        $available = MoneyMath::normalize((string) $wallet['available_balance'], 8);

        $reasons = [];
        if (! $this->enabled()) {
            $reasons[] = 'p2p_kill_switch_off';
        }
        if (MoneyMath::compare($qty, $available, 8) === 1) {
            $reasons[] = 'insufficient_posted_balance';
        }

        return [
            'sender_member_id'   => (string) $sender->member_id,
            'receiver'           => $receiver,
            'asset_code'         => $asset,
            'amount'             => $qty,
            'available_balance'  => $available,
            'sufficient'         => MoneyMath::compare($qty, $available, 8) <= 0,
            'p2p_enabled'        => $this->enabled(),
            'can_execute'        => false,
            'blockers'           => $reasons,
            'note'               => 'PREVIEW ONLY — P2P execution is OFF. Simulation earnings are not transferable.',
        ];
    }

    /**
     * Attempt execution. Production remains blocked; Financial Sandbox may call performAtomicTransfer.
     *
     * @return array<string, mixed>
     */
    public function execute(
        int $senderUserId,
        string $receiverMemberId,
        string $amount,
        string $password,
        ?string $idempotencyKey = null
    ): array {
        $this->assertNotImpersonation();
        $this->assertRateLimit($senderUserId);

        $sender = $this->profiles->findByUserId($senderUserId);
        if ($sender === null) {
            throw new InvalidArgumentException('Sender profile not found.');
        }

        $admin = auth()->user();
        if ($admin === null || (int) $admin->id !== $senderUserId) {
            throw new InvalidArgumentException('Authenticated sender mismatch.');
        }
        if (! $this->verifyPassword($admin, $password)) {
            $this->audit->log('mining.p2p_denied', $senderUserId, 'mining_p2p', null, [
                'reason' => 'reauth_failed',
            ]);
            throw new InvalidArgumentException('Password confirmation failed.');
        }

        // Validate fully (receiver, amount, balance) before kill-switch refusal.
        $preview = $this->preview($senderUserId, $receiverMemberId, $amount);
        $idempotencyKey = $this->normalizeIdempotency($idempotencyKey, $senderUserId, $preview);

        if ($existing = $this->findByIdempotency($idempotencyKey)) {
            $this->audit->log('mining.p2p_denied', $senderUserId, 'mining_p2p', (string) $existing['transfer_ref'], [
                'reason' => 'duplicate_idempotency',
            ]);
            throw new InvalidArgumentException('Duplicate transfer request (idempotency).');
        }

        if (! $this->enabled()) {
            $this->recordRejected($preview, $idempotencyKey, $senderUserId, 'p2p_kill_switch_off');
            $this->audit->log('mining.p2p_denied', $senderUserId, 'mining_p2p', null, [
                'reason'             => 'kill_switch_off',
                'receiver_member_id' => $preview['receiver']['member_id'],
                'amount'             => $preview['amount'],
            ]);
            throw new RuntimeException('Mining P2P transfers are disabled (kill-switch).');
        }

        $sandbox = service('financialSandboxService');
        if (! (bool) ($this->config ?? config('Platform'))->miningP2pEnabled) {
            $sandbox->assertActive('mining_p2p');
            $sandbox->assertSyntheticMember((string) $preview['sender_member_id'], 'mining_p2p_sender');
            $sandbox->assertSyntheticMember((string) $preview['receiver']['member_id'], 'mining_p2p_receiver');

            return $this->performAtomicTransfer($preview, $idempotencyKey, $senderUserId);
        }

        $this->audit->log('mining.p2p_denied', $senderUserId, 'mining_p2p', null, [
            'reason' => 'phase_11c_execution_not_authorized',
        ]);
        throw new RuntimeException(
            'Executable mining P2P is not authorized in Phase 11C. Architecture is prepared; activation requires separate approval.'
        );
    }

    /**
     * Future activation path: atomic debit/credit with row locks.
     * Not reachable from public execute() in Phase 11C.
     *
     * @param array<string, mixed> $preview
     * @return array<string, mixed>
     */
    public function performAtomicTransfer(array $preview, string $idempotencyKey, int $actorUserId): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Mining P2P transfers are disabled (kill-switch).');
        }

        if ($this->findByIdempotency($idempotencyKey) !== null) {
            throw new InvalidArgumentException('Duplicate transfer request (idempotency).');
        }

        $asset  = (string) $preview['asset_code'];
        $amount = MoneyMath::normalize((string) $preview['amount'], 8);
        $senderUid = (int) ($this->profiles->findByMemberId((string) $preview['sender_member_id'])->user_id ?? 0);
        $receiverUid = (int) $preview['receiver']['user_id'];
        if ($senderUid <= 0 || $receiverUid <= 0) {
            throw new InvalidArgumentException('Invalid transfer parties.');
        }

        // Lock wallets in ascending user_id order to prevent deadlocks.
        $first  = min($senderUid, $receiverUid);
        $second = max($senderUid, $receiverUid);

        $db = db_connect();
        $db->transBegin();

        try {
            $w1 = $db->query(
                'SELECT * FROM mining_wallets WHERE member_user_id = ? AND asset_code = ? FOR UPDATE',
                [$first, $asset]
            )->getRowArray();
            $w2 = $db->query(
                'SELECT * FROM mining_wallets WHERE member_user_id = ? AND asset_code = ? FOR UPDATE',
                [$second, $asset]
            )->getRowArray();

            $senderWallet   = $senderUid === $first ? $w1 : $w2;
            $receiverWallet = $receiverUid === $first ? $w1 : $w2;

            if ($senderWallet === null) {
                throw new InvalidArgumentException('Sender mining wallet not found.');
            }
            if ($receiverWallet === null) {
                $receiverMid = (string) $preview['receiver']['member_id'];
                $this->wallets->ensureWallet($receiverUid, $receiverMid, $asset);
                $receiverWallet = $db->query(
                    'SELECT * FROM mining_wallets WHERE member_user_id = ? AND asset_code = ? FOR UPDATE',
                    [$receiverUid, $asset]
                )->getRowArray();
            }

            $available = MoneyMath::normalize((string) $senderWallet['available_balance'], 8);
            if (MoneyMath::compare($amount, $available, 8) === 1) {
                throw new InvalidArgumentException('Insufficient posted mining balance.');
            }

            $now = date('Y-m-d H:i:s');
            $transferRef = 'MPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $debitRef  = 'MWL-' . strtoupper(bin2hex(random_bytes(6)));
            $creditRef = 'MWL-' . strtoupper(bin2hex(random_bytes(6)));

            $senderAfter   = MoneyMath::sub($available, $amount, 8);
            $receiverAvail = MoneyMath::normalize((string) ($receiverWallet['available_balance'] ?? '0'), 8);
            $receiverAfter = MoneyMath::add($receiverAvail, $amount, 8);

            $db->table('mining_wallets')->where('id', (int) $senderWallet['id'])->update([
                'available_balance' => $senderAfter,
                'total_debited'     => MoneyMath::add((string) $senderWallet['total_debited'], $amount, 8),
                'updated_at'        => $now,
            ]);
            $db->table('mining_wallets')->where('id', (int) $receiverWallet['id'])->update([
                'available_balance' => $receiverAfter,
                'total_credited'    => MoneyMath::add((string) ($receiverWallet['total_credited'] ?? '0'), $amount, 8),
                'updated_at'        => $now,
            ]);

            $bizDate = service('miningEligibilityService')->businessDate();
            $db->table('mining_wallet_ledger')->insert([
                'entry_ref'        => $debitRef,
                'member_user_id'   => $senderUid,
                'member_id'        => (string) $preview['sender_member_id'],
                'asset_code'       => $asset,
                'entry_type'       => 'p2p_debit',
                'direction'        => 'debit',
                'amount'           => $amount,
                'balance_after'    => $senderAfter,
                'business_date'    => $bizDate,
                'reference_type'   => 'mining_p2p_transfer',
                'reference_id'     => $transferRef,
                'idempotency_key'  => 'p2p-debit:' . $idempotencyKey,
                'meta_json'        => json_encode(['counterparty' => $preview['receiver']['member_id']], JSON_THROW_ON_ERROR),
                'created_by'       => $actorUserId,
                'created_at'       => $now,
            ]);
            $db->table('mining_wallet_ledger')->insert([
                'entry_ref'        => $creditRef,
                'member_user_id'   => $receiverUid,
                'member_id'        => (string) $preview['receiver']['member_id'],
                'asset_code'       => $asset,
                'entry_type'       => 'p2p_credit',
                'direction'        => 'credit',
                'amount'           => $amount,
                'balance_after'    => $receiverAfter,
                'business_date'    => $bizDate,
                'reference_type'   => 'mining_p2p_transfer',
                'reference_id'     => $transferRef,
                'idempotency_key'  => 'p2p-credit:' . $idempotencyKey,
                'meta_json'        => json_encode(['counterparty' => $preview['sender_member_id']], JSON_THROW_ON_ERROR),
                'created_by'       => $actorUserId,
                'created_at'       => $now,
            ]);

            $db->table('mining_p2p_transfers')->insert([
                'transfer_ref'         => $transferRef,
                'sender_user_id'       => $senderUid,
                'sender_member_id'     => (string) $preview['sender_member_id'],
                'receiver_user_id'     => $receiverUid,
                'receiver_member_id'   => (string) $preview['receiver']['member_id'],
                'asset_code'           => $asset,
                'amount'               => $amount,
                'status'               => self::STATUS_COMPLETED,
                'idempotency_key'      => $idempotencyKey,
                'sender_ledger_ref'    => $debitRef,
                'receiver_ledger_ref'  => $creditRef,
                'created_at'           => $now,
                'completed_at'         => $now,
                'created_by'           => $actorUserId,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('P2P transfer failed.');
            }
            $db->transCommit();

            $this->audit->log('mining.p2p_completed', $actorUserId, 'mining_p2p_transfer', $transferRef, [
                'amount'   => $amount,
                'asset'    => $asset,
                'receiver' => $preview['receiver']['member_id'],
            ]);

            return $db->table('mining_p2p_transfers')->where('transfer_ref', $transferRef)->get()->getRowArray() ?? [];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * @return array{available_balance: string, pending_count: int, total_sent: string, total_received: string, asset_code: string, p2p_enabled: bool}
     */
    public function memberWalletSummary(int $memberUserId): array
    {
        $asset  = $this->assetCode();
        $bal    = $this->wallets->balanceFor($memberUserId, $asset);
        $sent   = '0.00000000';
        $recv   = '0.00000000';
        $pending = 0;

        if (db_connect()->tableExists('mining_p2p_transfers')) {
            $s = db_connect()->table('mining_p2p_transfers')
                ->selectSum('amount', 'qty')
                ->where('sender_user_id', $memberUserId)
                ->where('status', self::STATUS_COMPLETED)
                ->get()->getRowArray();
            $r = db_connect()->table('mining_p2p_transfers')
                ->selectSum('amount', 'qty')
                ->where('receiver_user_id', $memberUserId)
                ->where('status', self::STATUS_COMPLETED)
                ->get()->getRowArray();
            $sent = MoneyMath::normalize((string) ($s['qty'] ?? '0'), 8);
            $recv = MoneyMath::normalize((string) ($r['qty'] ?? '0'), 8);
            $pending = (int) db_connect()->table('mining_p2p_transfers')
                ->where('sender_user_id', $memberUserId)
                ->where('status', self::STATUS_PENDING)
                ->countAllResults();
        }

        return [
            'available_balance' => $bal['available_balance'],
            'total_credited'    => $bal['total_credited'],
            'total_debited'     => $bal['total_debited'],
            'pending_count'     => $pending,
            'total_sent'        => $sent,
            'total_received'    => $recv,
            'asset_code'        => $asset,
            'p2p_enabled'       => $this->enabled(),
            'posting_enabled'   => $this->wallets->postingEnabled(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForMember(int $memberUserId, int $limit = 50): array
    {
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return [];
        }

        return db_connect()->table('mining_p2p_transfers')
            ->groupStart()
                ->where('sender_user_id', $memberUserId)
                ->orWhere('receiver_user_id', $memberUserId)
            ->groupEnd()
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * @param array{date_from?: string, date_to?: string, member_id?: string, status?: string, transfer_ref?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function adminSearch(array $filters, int $limit = 100): array
    {
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return [];
        }
        $b = db_connect()->table('mining_p2p_transfers')->orderBy('id', 'DESC');
        if (! empty($filters['status'])) {
            $b->where('status', $filters['status']);
        }
        if (! empty($filters['transfer_ref'])) {
            $b->where('transfer_ref', $filters['transfer_ref']);
        }
        if (! empty($filters['member_id'])) {
            $mid = strtoupper(trim((string) $filters['member_id']));
            $b->groupStart()
                ->where('sender_member_id', $mid)
                ->orWhere('receiver_member_id', $mid)
            ->groupEnd();
        }
        if (! empty($filters['date_from'])) {
            $b->where('created_at >=', $filters['date_from'] . ' 00:00:00');
        }
        if (! empty($filters['date_to'])) {
            $b->where('created_at <=', $filters['date_to'] . ' 23:59:59');
        }

        return $b->limit(max(1, min(500, $limit)))->get()->getResultArray();
    }

    /**
     * @return array{summary: array<string, int|string>, mismatches: list<array<string, mixed>>, note: string}
     */
    public function reconcile(int $limit = 200): array
    {
        $mismatches = [];
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return [
                'summary'    => ['mismatches' => 0],
                'mismatches' => [],
                'note'       => 'No P2P transfer table yet.',
            ];
        }

        $rows = db_connect()->table('mining_p2p_transfers')
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $completed = 0;
        $failed    = 0;
        $volume    = MoneyMath::normalize('0', 8);

        foreach ($rows as $row) {
            if (($row['status'] ?? '') === self::STATUS_COMPLETED) {
                $completed++;
                $volume = MoneyMath::add($volume, (string) $row['amount'], 8);

                $debit = db_connect()->table('mining_wallet_ledger')
                    ->where('reference_id', $row['transfer_ref'])
                    ->where('entry_type', 'p2p_debit')
                    ->countAllResults();
                $credit = db_connect()->table('mining_wallet_ledger')
                    ->where('reference_id', $row['transfer_ref'])
                    ->where('entry_type', 'p2p_credit')
                    ->countAllResults();
                if ($debit !== 1 || $credit !== 1) {
                    $mismatches[] = [
                        'type'         => 'completed_missing_ledger_pair',
                        'transfer_ref' => $row['transfer_ref'],
                        'debits'       => $debit,
                        'credits'      => $credit,
                    ];
                }
            } elseif (($row['status'] ?? '') === self::STATUS_FAILED) {
                $failed++;
            }
        }

        $dupes = db_connect()->query(
            'SELECT transfer_ref, COUNT(*) AS c FROM mining_p2p_transfers GROUP BY transfer_ref HAVING c > 1'
        )->getResultArray();
        foreach ($dupes as $d) {
            $mismatches[] = ['type' => 'duplicate_transfer_ref', 'transfer_ref' => $d['transfer_ref']];
        }

        if (! $this->enabled()) {
            $completedWithLedger = db_connect()->table('mining_wallet_ledger')
                ->whereIn('entry_type', ['p2p_debit', 'p2p_credit'])
                ->countAllResults();
            if ($completedWithLedger > 0) {
                $mismatches[] = [
                    'type'  => 'unexpected_p2p_ledger_while_disabled',
                    'count' => $completedWithLedger,
                ];
            }
        }

        return [
            'summary' => [
                'rows'       => count($rows),
                'completed'  => $completed,
                'failed'     => $failed,
                'volume'     => $volume,
                'mismatches' => count($mismatches),
            ],
            'mismatches' => $mismatches,
            'note'       => 'Reconcile is read-only. Mining P2P execution remains OFF in Phase 11C.',
        ];
    }

    private function normalizeAmount(string $amount): string
    {
        $qty = MoneyMath::normalize(trim($amount), 8);
        if (! MoneyMath::isPositive($qty, 8)) {
            throw new InvalidArgumentException('Transfer amount must be greater than zero.');
        }

        return $qty;
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function normalizeIdempotency(?string $key, int $senderUserId, array $preview): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            $key = 'p2p:' . $senderUserId . ':' . $preview['receiver']['member_id'] . ':' . $preview['amount'] . ':' . date('YmdHis');
        }
        if (strlen($key) > 96) {
            $key = substr(hash('sha256', $key), 0, 96);
        }

        return $key;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdempotency(string $key): ?array
    {
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return null;
        }

        return db_connect()->table('mining_p2p_transfers')
            ->where('idempotency_key', $key)
            ->get()
            ->getRowArray();
    }

    /**
     * @param array<string, mixed> $preview
     */
    private function recordRejected(array $preview, string $idempotencyKey, int $senderUserId, string $reason): void
    {
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return;
        }
        if ($this->findByIdempotency($idempotencyKey) !== null) {
            return;
        }

        db_connect()->table('mining_p2p_transfers')->insert([
            'transfer_ref'       => 'MPT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4))),
            'sender_user_id'     => $senderUserId,
            'sender_member_id'   => (string) $preview['sender_member_id'],
            'receiver_user_id'   => (int) $preview['receiver']['user_id'],
            'receiver_member_id' => (string) $preview['receiver']['member_id'],
            'asset_code'         => (string) $preview['asset_code'],
            'amount'             => (string) $preview['amount'],
            'status'             => self::STATUS_REJECTED,
            'idempotency_key'    => $idempotencyKey,
            'failure_reason'     => substr($reason, 0, 255),
            'created_at'         => date('Y-m-d H:i:s'),
            'created_by'         => $senderUserId,
        ]);
    }

    private function assertNotImpersonation(): void
    {
        if (service('impersonationService')->activeContext() !== null) {
            throw new RuntimeException('P2P transfers are not allowed from Admin support-view impersonation.');
        }
        // Web-only URI guard — ignore stale request paths under CLI/PHPUnit.
        if (! is_cli()) {
            $uri = uri_string();
            if (str_starts_with($uri, 'support-view')) {
                throw new RuntimeException('P2P transfers are not allowed from Admin support-view.');
            }
        }
    }

    private function assertRateLimit(int $senderUserId): void
    {
        if (! db_connect()->tableExists('mining_p2p_transfers')) {
            return;
        }
        $since = date('Y-m-d H:i:s', time() - 3600);
        $count = db_connect()->table('mining_p2p_transfers')
            ->where('sender_user_id', $senderUserId)
            ->where('created_at >=', $since)
            ->countAllResults();
        if ($count >= self::RATE_LIMIT_PER_HOUR) {
            throw new InvalidArgumentException('P2P rate limit exceeded. Try again later.');
        }
    }

    private function verifyPassword(object $user, string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }
        try {
            $hash = (string) ($user->password_hash ?? '');
            if ($hash === '') {
                return false;
            }

            return service('passwords')->verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }
}
