<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 1 — Admin-controlled commission withdrawal settings + member KYC.
 * Does not invent fee/minimum amounts; settings start pending until Admin configures them.
 */
class Phase1AdminWithdrawalSettingsAndKyc extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('commission_withdrawal_settings')) {
            $this->forge->addField([
                'id' => ['type' => 'TINYINT', 'unsigned' => true, 'auto_increment' => true],
                'minimum_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'fee_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'policy_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'pending_business_decision'],
                'requests_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'unsigned' => true, 'default' => 0],
                'notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('commission_withdrawal_settings', true);

            $now = date('Y-m-d H:i:s');
            $this->db->table('commission_withdrawal_settings')->insert([
                'id'               => 1,
                'minimum_usdt'     => null,
                'fee_usdt'         => null,
                'policy_status'    => 'pending_business_decision',
                'requests_enabled' => 0,
                'notes'            => null,
                'updated_by'       => null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        }

        if (! $this->db->tableExists('commission_withdrawal_settings_audit')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
                'action' => ['type' => 'VARCHAR', 'constraint' => 40],
                'old_json' => ['type' => 'TEXT', 'null' => true],
                'new_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('created_at');
            $this->forge->createTable('commission_withdrawal_settings_audit', true);
        }

        if (! $this->db->tableExists('member_kyc')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'none'],
                'full_name' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'document_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'document_number' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'member_notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'admin_notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'submitted_at' => ['type' => 'DATETIME', 'null' => true],
                'reviewed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('member_user_id');
            $this->forge->addKey('status');
            $this->forge->addKey('member_id');
            $this->forge->createTable('member_kyc', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('member_kyc', true);
        $this->forge->dropTable('commission_withdrawal_settings_audit', true);
        $this->forge->dropTable('commission_withdrawal_settings', true);
    }
}
