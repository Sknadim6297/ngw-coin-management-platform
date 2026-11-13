<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UsdtPaymentAllocationModel extends Model
{
    protected $table            = 'usdt_payment_allocations';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'allocation_ref',
        'deposit_ref',
        'deposit_active_key',
        'purchase_ref',
        'purchase_active_key',
        'member_user_id',
        'amount_usdt',
        'status',
        'linked_by',
        'consumed_by',
        'released_by',
        'linked_at',
        'consumed_at',
        'released_at',
        'release_reason',
    ];

    public function findByPurchaseRef(string $purchaseRef): ?array
    {
        return $this->where('purchase_ref', $purchaseRef)->first();
    }

    public function findByDepositRef(string $depositRef): ?array
    {
        return $this->where('deposit_ref', $depositRef)->first();
    }
}
