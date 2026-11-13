<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class BlockchainHealthSnapshotModel extends Model
{
    protected $table            = 'blockchain_health_snapshots';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'scope',
        'network_id',
        'rpc_host_fingerprint',
        'rpc_host_redacted',
        'expected_chain_id',
        'reported_chain_id',
        'head_block_number',
        'latency_ms',
        'status',
        'error_category',
        'error_summary',
        'checked_by',
        'checked_at',
        'created_at',
    ];

    public function latest(?string $scope = null): ?array
    {
        $builder = $this->builder()->orderBy('checked_at', 'DESC')->limit(1);
        if ($scope !== null) {
            $builder->where('scope', $scope);
        }

        $row = $builder->get()->getRowArray();

        return $row ?: null;
    }
}
