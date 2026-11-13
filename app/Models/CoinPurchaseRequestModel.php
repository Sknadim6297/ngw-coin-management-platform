<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinPurchaseRequestModel extends Model
{
    protected $table            = 'coin_purchase_requests';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'purchase_ref',
        'member_user_id',
        'member_id',
        'coin_quantity',
        'price_usdt_snapshot',
        'total_usdt',
        'status',
        'member_note',
        'rejection_reason',
        'reviewed_by',
        'requested_at',
        'reviewed_at',
        'idempotency_key',
        'inventory_transaction_ref',
        'ownership_transaction_ref',
        'payment_deposit_ref',
        'payment_status',
    ];

    public function findByRef(string $purchaseRef): ?array
    {
        return $this->where('purchase_ref', $purchaseRef)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function countByStatus(string $status): int
    {
        return (int) $this->where('status', $status)->countAllResults();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forMember(
        int $memberUserId,
        ?string $status = null,
        int $perPage = 20
    ): array {
        $builder = $this->where('member_user_id', $memberUserId);

        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->orderBy('id', 'DESC')->paginate($perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function adminList(
        ?string $status = null,
        ?string $search = null,
        int $perPage = 20
    ): array {
        if ($status !== null && $status !== '') {
            $this->where('status', $status);
        }

        if ($search !== null && trim($search) !== '') {
            $term = trim($search);
            $this->groupStart()
                ->like('purchase_ref', $term)
                ->orLike('member_id', $term)
                ->groupEnd();
        }

        return $this->orderBy('id', 'DESC')->paginate($perPage);
    }
}
