<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinInventoryTransactionModel extends Model
{
    protected $table         = 'coin_inventory_transactions';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'transaction_ref',
        'type',
        'direction',
        'quantity',
        'previous_balance',
        'new_balance',
        'actor_user_id',
        'reference',
        'reason',
        'idempotency_key',
        'metadata',
    ];

    public function findByIdempotencyKey(string $key): ?array
    {
        $row = $this->where('idempotency_key', $key)->first();

        return $row ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }
}
