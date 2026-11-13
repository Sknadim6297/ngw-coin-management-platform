<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoinPriceHistory extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'previous_price_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
                'null'       => true,
            ],
            'new_price_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
            ],
            'actor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
            ],
            'effective_at' => [
                'type' => 'DATETIME',
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
        $this->forge->addKey('actor_user_id');
        $this->forge->addKey('effective_at');
        $this->forge->createTable('coin_price_history', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_price_history', true);
    }
}
