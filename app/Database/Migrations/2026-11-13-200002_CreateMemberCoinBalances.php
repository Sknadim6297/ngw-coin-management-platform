<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Cached per-member coin ownership totals.
 * Updated atomically with the append-only ownership ledger.
 */
class CreateMemberCoinBalances extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'member_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
            ],
            'total_acquired' => [
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
        $this->forge->addUniqueKey('member_user_id');
        $this->forge->addUniqueKey('member_id');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('member_coin_balances', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('member_coin_balances', true);
    }
}
