<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UsdtDepositEventModel extends Model
{
    protected $table            = 'usdt_deposit_events';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'deposit_id',
        'deposit_ref',
        'actor_user_id',
        'event_type',
        'previous_status',
        'new_status',
        'metadata',
        'created_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function forDeposit(int $depositId, int $limit = 50): array
    {
        return $this->where('deposit_id', $depositId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}
