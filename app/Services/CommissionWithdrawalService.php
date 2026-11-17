<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7D withdrawal request architecture.
 * While commissionWithdrawalEnabled=false: view OK, submit blocked, no reserve/debit.
 */
class CommissionWithdrawalService
{
    public const STATUS_DRAFT        = 'draft';
    public const STATUS_SUBMITTED    = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_APPROVED     = 'approved';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_CANCELLED    = 'cancelled';

    public function __construct(
        private readonly CommissionWalletService $wallets = new CommissionWalletService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    public function activationStatus(): string
    {
        return $this->wallets->withdrawalEnabled()
            ? 'ENABLED'
            : 'Withdrawals Not Activated';
    }

    /**
     * Create a draft withdrawal request (no balance reservation).
     *
     * @param array{amount: string, currency?: string, network: string, destination_address: string, idempotency_key?: string} $data
     * @return array<string, mixed>
     */
    public function createDraft(int $memberUserId, string $memberId, array $data): array
    {
        $amount = MoneyMath::normalize((string) ($data['amount'] ?? ''), 8);
        if (! MoneyMath::isPositive($amount, 8)) {
            throw new InvalidArgumentException('Withdrawal amount must be positive.');
        }

        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USDT')));
        if ($currency !== 'USDT') {
            throw new InvalidArgumentException('Only USDT withdrawals are supported.');
        }

        $network = strtoupper(trim((string) ($data['network'] ?? '')));
        $allowed = ($this->config ?? config('Platform'))->commissionWithdrawalNetworks;
        if (! in_array($network, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported or unconfigured withdrawal network.');
        }

        $address = trim((string) ($data['destination_address'] ?? ''));
        $this->assertAddress($network, $address);

        // Simulation cannot be withdrawn — enforce actual available balance only.
        $account = $this->wallets->getAccount($memberUserId, $currency);
        if (MoneyMath::compare($amount, (string) ($account['available_balance'] ?? '0'), 8) === 1) {
            throw new InvalidArgumentException('Amount exceeds available posted commission balance (simulation is not withdrawable).');
        }

        $idem = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idem === '') {
            $idem = 'wd-draft:' . $memberUserId . ':' . bin2hex(random_bytes(8));
        }

        $ref = 'CWD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');

        try {
            db_connect()->table('commission_withdrawal_requests')->insert([
                'withdrawal_ref'      => $ref,
                'member_user_id'      => $memberUserId,
                'member_id'           => $memberId,
                'amount'              => $amount,
                'currency'            => $currency,
                'network'             => $network,
                'destination_address' => $address,
                'status'              => self::STATUS_DRAFT,
                'requested_at'        => null,
                'idempotency_key'     => $idem,
                'is_reserved'         => 0,
                'is_paid'             => 0,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw new InvalidArgumentException('Duplicate withdrawal idempotency key.');
            }
            throw $e;
        }

        $this->recordEvent($ref, 'created', $memberUserId, null, self::STATUS_DRAFT, null);
        $this->audit->log('commission.withdrawal_draft_created', $memberUserId, 'commission_withdrawal', $ref, [
            'amount' => $amount,
        ]);

        return $this->findByRef($ref) ?? ['withdrawal_ref' => $ref];
    }

    /**
     * Submit for review — blocked while withdrawals are not activated.
     *
     * @return array<string, mixed>
     */
    public function submit(string $withdrawalRef, int $memberUserId): array
    {
        if (! $this->wallets->withdrawalEnabled()) {
            throw new RuntimeException('Withdrawals Not Activated. Submission is blocked server-side.');
        }

        // Even if flag flipped unexpectedly, Phase 7D refuses reservation/payout.
        throw new RuntimeException('Executable withdrawal submission/reservation is not implemented in Phase 7D.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        $row = db_connect()->table('commission_withdrawal_requests')
            ->where('withdrawal_ref', $ref)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['events'] = db_connect()->table('commission_withdrawal_events')
            ->where('withdrawal_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOwned(string $ref, int $memberUserId): ?array
    {
        $row = $this->findByRef($ref);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForMember(int $memberUserId, int $limit = 50): array
    {
        $rows = db_connect()->table('commission_withdrawal_requests')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
        foreach ($rows as &$row) {
            $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(array $filters = [], int $limit = 50): array
    {
        $b = db_connect()->table('commission_withdrawal_requests')->orderBy('id', 'DESC');
        if (! empty($filters['status'])) {
            $b->where('status', (string) $filters['status']);
        }
        if (! empty($filters['member_q'])) {
            $q = trim((string) $filters['member_q']);
            $b->groupStart()->like('member_id', $q)->orLike('withdrawal_ref', $q)->groupEnd();
        }
        $rows = $b->limit(max(1, min(200, $limit)))->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);
        }

        return $rows;
    }

    public function countByStatus(?string $status = null): int
    {
        $b = db_connect()->table('commission_withdrawal_requests');
        if ($status !== null && $status !== '') {
            $b->where('status', $status);
        }

        return $b->countAllResults();
    }

    /**
     * Admin dual-control approve architecture — refuses while disabled / Phase 7D.
     *
     * @return array<string, mixed>
     */
    public function approve(string $withdrawalRef, int $approverUserId): array
    {
        if (! $this->wallets->withdrawalEnabled()) {
            throw new RuntimeException('Withdrawals Not Activated. Approval mutations are blocked.');
        }
        if ($this->wallets->blockchainPayoutEnabled()) {
            throw new RuntimeException('Blockchain payout must remain disabled in Phase 7D.');
        }

        throw new RuntimeException('Executable withdrawal approval/payout is not implemented in Phase 7D.');
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $withdrawalRef, int $approverUserId, string $reason): array
    {
        if (! $this->wallets->withdrawalEnabled()) {
            throw new RuntimeException('Withdrawals Not Activated. Rejection mutations that alter reserves are blocked.');
        }

        throw new RuntimeException('Executable withdrawal rejection path is not implemented in Phase 7D.');
    }

    public function maskAddress(string $address): string
    {
        $len = strlen($address);
        if ($len <= 10) {
            return str_repeat('*', $len);
        }

        return substr($address, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($address, -4);
    }

    private function assertAddress(string $network, string $address): void
    {
        if ($address === '') {
            throw new InvalidArgumentException('Destination address is required.');
        }
        if ($network === 'BEP20') {
            $pattern = ($this->config ?? config('Platform'))->bep20Pattern;
            if (! preg_match($pattern, $address)) {
                throw new InvalidArgumentException('Invalid BEP20 destination address.');
            }

            return;
        }

        throw new InvalidArgumentException('No address validator configured for network ' . $network);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $ref,
        string $type,
        ?int $actor,
        ?string $from,
        ?string $to,
        ?array $payload
    ): void {
        db_connect()->table('commission_withdrawal_events')->insert([
            'withdrawal_ref' => $ref,
            'event_type'     => $type,
            'actor_user_id'  => $actor,
            'from_status'    => $from,
            'to_status'      => $to,
            'payload_json'   => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
