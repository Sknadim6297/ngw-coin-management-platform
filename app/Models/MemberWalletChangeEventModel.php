<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

/**
 * Immutable audit trail for USDT wallet destination changes.
 * Do not update rows after insert.
 */
class MemberWalletChangeEventModel extends Model
{
    protected $table         = 'member_wallet_change_events';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'user_id',
        'member_id',
        'previous_wallet',
        'new_wallet',
        'cooldown_until',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function recentForUser(int $userId, int $limit = 20): array
    {
        return $this->where('user_id', $userId)
            ->orderBy('id', 'DESC')
            ->findAll($limit);
    }
}
