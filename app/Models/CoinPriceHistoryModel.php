<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinPriceHistoryModel extends Model
{
    protected $table         = 'coin_price_history';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'previous_price_usdt',
        'new_price_usdt',
        'actor_user_id',
        'reason',
        'effective_at',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return $this->orderBy('id', 'DESC')->findAll($limit);
    }
}
