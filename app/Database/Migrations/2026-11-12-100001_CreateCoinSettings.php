<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoinSettings extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
            ],
            'current_price_usdt' => [
                'type'      => 'DECIMAL',
                'constraint' => '18,8',
                'null'      => true,
            ],
            'min_purchase_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
                'default'    => '5.00000000',
            ],
            'price_updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'price_updated_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
        $this->forge->createTable('coin_settings', true);

        $now = date('Y-m-d H:i:s');
        $this->db->table('coin_settings')->insert([
            'id'                => 1,
            'current_price_usdt'=> null,
            'min_purchase_usdt' => '5.00000000',
            'price_updated_at'  => null,
            'price_updated_by'  => null,
            'created_at'        => $now,
            'updated_at'        => $now,
        ]);
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_settings', true);
    }
}
