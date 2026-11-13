<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateCoinInventoryTransactions extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'transaction_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'ADMIN_INVENTORY_ADD',
                    'ADMIN_INVENTORY_CORRECTION',
                    'MEMBER_PURCHASE_ALLOCATION',
                    'PURCHASE_REVERSAL',
                ],
            ],
            'direction' => [
                'type'       => 'ENUM',
                'constraint' => ['credit', 'debit'],
            ],
            'quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
            ],
            'previous_balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
            ],
            'new_balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
            ],
            'actor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'reference' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'idempotency_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'metadata' => [
                'type' => 'TEXT',
                'null' => true,
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
        $this->forge->addUniqueKey('transaction_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('type');
        $this->forge->addKey('actor_user_id');
        $this->forge->addKey('created_at');
        $this->forge->createTable('coin_inventory_transactions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_inventory_transactions', true);
    }
}
