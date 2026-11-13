<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinSettingsModel extends Model
{
    protected $table         = 'coin_settings';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'current_price_usdt',
        'min_purchase_usdt',
        'price_updated_at',
        'price_updated_by',
    ];

    public function singleton(): array
    {
        $row = $this->find(1);
        if ($row === null) {
            throw new \RuntimeException('coin_settings singleton row is missing.');
        }

        return $row;
    }
}
