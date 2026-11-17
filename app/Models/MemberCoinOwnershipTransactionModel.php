<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MemberCoinOwnershipTransactionModel extends Model
{
    protected $table            = 'member_coin_ownership_transactions';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'transaction_ref',
        'member_user_id',
        'member_id',
        'type',
        'direction',
        'quantity',
        'previous_balance',
        'new_balance',
        'previous_available',
        'new_available',
        'previous_locked',
        'new_locked',
        'previous_reserved',
        'new_reserved',
        'purchase_ref',
        'lock_ref',
        'sell_ref',
        'inventory_transaction_ref',
        'actor_user_id',
        'idempotency_key',
        'metadata',
    ];

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    public function findByPurchaseRef(string $purchaseRef): ?array
    {
        return $this->where('purchase_ref', $purchaseRef)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forMember(int $memberUserId, int $perPage = 20): array
    {
        return $this->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->paginate($perPage);
    }
}
