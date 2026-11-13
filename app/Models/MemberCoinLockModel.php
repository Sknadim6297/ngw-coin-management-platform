<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MemberCoinLockModel extends Model
{
    protected $table            = 'member_coin_locks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'lock_ref',
        'member_user_id',
        'member_id',
        'plan_id',
        'plan_code',
        'plan_name',
        'quantity',
        'duration_days',
        'status',
        'locked_at',
        'matures_at',
        'matured_at',
        'unlocked_at',
        'cancelled_at',
        'ownership_lock_txn_ref',
        'ownership_unlock_txn_ref',
        'idempotency_key',
        'unlock_idempotency_key',
    ];

    public function findByRef(string $lockRef): ?array
    {
        return $this->where('lock_ref', $lockRef)->first();
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->where('idempotency_key', $key)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forMember(int $memberUserId, ?string $status = null, int $limit = 50): array
    {
        $builder = $this->where('member_user_id', $memberUserId)->orderBy('id', 'DESC');
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->findAll($limit);
    }
}
