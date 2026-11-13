<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class MemberCoinBalanceModel extends Model
{
    protected $table            = 'member_coin_balances';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'member_user_id',
        'member_id',
        'balance',
        'available_balance',
        'locked_balance',
        'total_acquired',
    ];

    public function findByUserId(int $userId): ?array
    {
        return $this->where('member_user_id', $userId)->first();
    }
}
