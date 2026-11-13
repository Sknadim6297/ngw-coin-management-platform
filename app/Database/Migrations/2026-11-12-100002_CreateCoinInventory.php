<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Single-row authoritative cached inventory balance.
 * Updated atomically with the append-only ledger.
 */
class CreateCoinInventory extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
            ],
            'available_balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
            ],
            'total_added' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
            ],
            'total_allocated' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->createTable('coin_inventory', true);

        $now = date('Y-m-d H:i:s');
        $this->db->table('coin_inventory')->insert([
            'id'                => 1,
            'available_balance' => '0.00000000',
            'total_added'       => '0.00000000',
            'total_allocated'   => '0.00000000',
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_inventory', true);
    }
}
