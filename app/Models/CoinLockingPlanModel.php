<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class CoinLockingPlanModel extends Model
{
    protected $table            = 'coin_locking_plans';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'plan_code',
        'name',
        'description',
        'min_quantity',
        'max_quantity',
        'duration_days',
        'is_enabled',
        'allow_early_unlock',
        'created_by',
        'updated_by',
    ];

    public function findByCode(string $code): ?array
    {
        return $this->where('plan_code', $code)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listEnabled(): array
    {
        return $this->where('is_enabled', 1)->orderBy('duration_days', 'ASC')->findAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->orderBy('id', 'DESC')->findAll();
    }
}
