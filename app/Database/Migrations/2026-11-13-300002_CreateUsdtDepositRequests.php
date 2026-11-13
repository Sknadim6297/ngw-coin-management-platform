<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Member USDT deposit requests and payment evidence (not settlement).
 */
class CreateUsdtDepositRequests extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'deposit_ref' => [
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
            'network_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'network_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'expected_amount_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
            ],
            'destination_wallet_snapshot' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
            ],
            'tx_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'tx_hash_normalized' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'evidence_stored_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
            'evidence_original_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'evidence_mime' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'evidence_size' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'draft',
                    'submitted',
                    'under_review',
                    'verified',
                    'rejected',
                    'cancelled',
                ],
                'default'    => 'draft',
            ],
            'verification_method' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'null'       => true,
            ],
            'verification_reference' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'verified_amount_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
                'null'       => true,
            ],
            'verified_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'reviewed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'review_notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'rejection_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'submitted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'idempotency_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'linked_purchase_ref' => [
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
        $this->forge->addUniqueKey('deposit_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addUniqueKey('tx_hash_normalized');
        $this->forge->addKey('member_user_id');
        $this->forge->addKey('member_id');
        $this->forge->addKey('network_id');
        $this->forge->addKey('status');
        $this->forge->addKey('submitted_at');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('network_id', 'usdt_payment_networks', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('usdt_deposit_requests', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('usdt_deposit_requests', true);
    }
}
