<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UsdtDepositRequestModel extends Model
{
    protected $table            = 'usdt_deposit_requests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'deposit_ref',
        'member_user_id',
        'member_id',
        'network_id',
        'network_key',
        'expected_amount_usdt',
        'destination_wallet_snapshot',
        'tx_hash',
        'tx_hash_normalized',
        'evidence_stored_name',
        'evidence_original_name',
        'evidence_mime',
        'evidence_size',
        'status',
        'verification_method',
        'verification_reference',
        'verified_amount_usdt',
        'verified_chain_id',
        'verified_token_contract',
        'verified_destination',
        'verified_block_number',
        'verified_block_hash',
        'verified_confirmations',
        'last_verification_status',
        'last_verification_message',
        'last_verification_at',
        'verification_attempt_count',
        'next_verification_after',
        'integrity_flag',
        'integrity_checked_at',
        'integrity_notes',
        'verified_at',
        'reviewed_by',
        'review_notes',
        'rejection_reason',
        'submitted_at',
        'idempotency_key',
        'linked_purchase_ref',
        'payment_consumption_status',
        'payment_consumed_at',
    ];

    public function findByRef(string $ref): ?array
    {
        return $this->where('deposit_ref', $ref)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function findByNormalizedTxHash(string $hash): ?array
    {
        return $this->where('tx_hash_normalized', $hash)->first();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->where('status', $status)->countAllResults();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forMember(int $memberUserId, ?string $status = null, int $perPage = 20): array
    {
        if ($status !== null && $status !== '') {
            $this->where('status', $status);
        }

        return $this->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminList(?string $status = null, ?string $search = null, int $perPage = 20): array
    {
        if ($status !== null && $status !== '') {
            $this->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $this->groupStart()
                ->like('deposit_ref', $term)
                ->orLike('member_id', $term)
                ->orLike('tx_hash', $term)
                ->groupEnd();
        }

        return $this->orderBy('id', 'DESC')->paginate($perPage);
    }
}
