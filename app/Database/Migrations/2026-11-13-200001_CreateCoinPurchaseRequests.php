<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Member coin purchase requests (internal workflow only — not payment settlement).
 */
class CreateCoinPurchaseRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'purchase_ref' => [
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
            'coin_quantity' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
            ],
            'price_usdt_snapshot' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
            ],
            'total_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'approved', 'rejected', 'cancelled'],
                'default'    => 'pending',
            ],
            'member_note' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'rejection_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'reviewed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'requested_at' => [
                'type' => 'DATETIME',
            ],
            'reviewed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'idempotency_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'inventory_transaction_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'ownership_transaction_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
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
        $this->forge->addUniqueKey('purchase_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('member_user_id');
        $this->forge->addKey('member_id');
        $this->forge->addKey('status');
        $this->forge->addKey('requested_at');
        $this->forge->addKey(['status', 'requested_at']);
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('coin_purchase_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_purchase_requests', true);
    }
}
