<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinInventoryModel extends Model
{
    protected $table         = 'coin_inventory';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = true;
    protected $allowedFields = [
        'available_balance',
        'total_added',
        'total_allocated',
    ];

    public function singleton(): array
    {
        $row = $this->find(1);
        if ($row === null) {
            throw new \RuntimeException('coin_inventory singleton row is missing.');
        }

        return $row;
    }
}
