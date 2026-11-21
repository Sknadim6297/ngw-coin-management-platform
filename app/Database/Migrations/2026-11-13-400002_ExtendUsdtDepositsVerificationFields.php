<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExtendUsdtDepositsVerificationFields extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('usdt_deposit_requests', [
            'verified_chain_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'verified_amount_usdt',
            ],
            'verified_token_contract' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'verified_chain_id',
            ],
            'verified_destination' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'verified_token_contract',
            ],
            'verified_block_number' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'verified_destination',
            ],
            'verified_block_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
                'after'      => 'verified_block_number',
            ],
            'verified_confirmations' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'verified_block_hash',
            ],
            'payment_consumption_status' => [
                'type'       => 'ENUM',
                'constraint' => ['available', 'linked', 'consumed'],
                'default'    => 'available',
                'after'      => 'linked_purchase_ref',
            ],
            'payment_consumed_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'payment_consumption_status',
            ],
        ]);

        $this->db->query(
            'CREATE INDEX usdt_deposit_requests_consumption ON usdt_deposit_requests (payment_consumption_status, status)'
        );
    }

    public function down(): void
    {
        foreach ([
            'verified_chain_id',
            'verified_token_contract',
            'verified_destination',
            'verified_block_number',
            'verified_block_hash',
            'verified_confirmations',
            'payment_consumption_status',
            'payment_consumed_at',
        ] as $col) {
            if ($this->db->tableExists('usdt_deposit_requests') && $this->db->fieldExists($col, 'usdt_deposit_requests')) {
                try {
                    $this->forge->dropColumn('usdt_deposit_requests', $col);
                } catch (\Throwable) {
                }
            }
        }
    }
}
