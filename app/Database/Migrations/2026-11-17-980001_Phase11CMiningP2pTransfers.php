<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 11C: mining wallet P2P transfer records (execution kill-switched OFF).
 */
class Phase11CMiningP2pTransfers extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('mining_p2p_transfers')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'transfer_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'sender_user_id' => ['type' => 'INT', 'unsigned' => true],
            'sender_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'receiver_user_id' => ['type' => 'INT', 'unsigned' => true],
            'receiver_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'asset_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'COIN'],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 96],
            'sender_ledger_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'receiver_ledger_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'failure_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'meta_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'completed_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('transfer_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey(['sender_user_id', 'created_at']);
        $this->forge->addKey(['receiver_user_id', 'created_at']);
        $this->forge->addKey(['status', 'created_at']);
        $this->forge->addForeignKey('sender_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('receiver_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('mining_p2p_transfers', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('mining_p2p_transfers', true);
    }
}
