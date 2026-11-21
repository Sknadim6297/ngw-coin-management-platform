<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * EVM-specific network metadata for Phase 4B verification.
 */
class ExtendUsdtNetworksForEvm extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('usdt_payment_networks', [
            'chain_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'network_key',
            ],
            'verifier_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'default'    => 'none',
                'after'      => 'chain_id',
            ],
            'explorer_tx_url_template' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'instructions',
            ],
            'rpc_url_env_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
                'after'      => 'explorer_tx_url_template',
            ],
        ]);
    }

    public function down(): void
    {
        foreach ([
            'chain_id',
            'verifier_type',
            'explorer_tx_url_template',
            'rpc_url_env_key',
        ] as $col) {
            if ($this->db->tableExists('usdt_payment_networks') && $this->db->fieldExists($col, 'usdt_payment_networks')) {
                try {
                    $this->forge->dropColumn('usdt_payment_networks', $col);
                } catch (\Throwable) {
                }
            }
        }
    }
}
