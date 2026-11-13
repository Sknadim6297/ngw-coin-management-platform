<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only member coin ownership ledger.
 */
class CreateMemberCoinOwnershipTransactions extends Migration
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
            'member_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'type' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'PURCHASE_CREDIT',
                    'ADMIN_ADJUSTMENT',
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
            'purchase_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'inventory_transaction_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'actor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
        $this->forge->addKey('member_user_id');
        $this->forge->addKey('member_id');
        $this->forge->addKey('purchase_ref');
        $this->forge->addKey('type');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('member_coin_ownership_transactions', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('member_coin_ownership_transactions', true);
    }
}
