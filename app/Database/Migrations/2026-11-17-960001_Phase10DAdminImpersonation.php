<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 10D: short-lived impersonation grants + isolated support-view sessions.
 * Does not alter Shield sessions or financial tables.
 */
class Phase10DAdminImpersonation extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('admin_impersonation_grants')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'grant_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'admin_user_id' => ['type' => 'INT', 'unsigned' => true],
                'target_user_id' => ['type' => 'INT', 'unsigned' => true],
                'target_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
                'expires_at' => ['type' => 'DATETIME'],
                'consumed_at' => ['type' => 'DATETIME', 'null' => true],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('grant_ref');
            $this->forge->addUniqueKey('token_hash');
            $this->forge->addKey(['admin_user_id', 'created_at']);
            $this->forge->addKey(['status', 'expires_at']);
            $this->forge->addForeignKey('admin_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('target_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('admin_impersonation_grants', true);
        }

        if (! $this->db->tableExists('admin_impersonation_sessions')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'session_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'grant_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'admin_user_id' => ['type' => 'INT', 'unsigned' => true],
                'target_user_id' => ['type' => 'INT', 'unsigned' => true],
                'target_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'active'],
                'created_at' => ['type' => 'DATETIME'],
                'last_activity_at' => ['type' => 'DATETIME'],
                'expires_at' => ['type' => 'DATETIME'],
                'ended_at' => ['type' => 'DATETIME', 'null' => true],
                'end_reason' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'ip_address' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
                'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('session_ref');
            $this->forge->addUniqueKey('token_hash');
            $this->forge->addKey(['admin_user_id', 'status']);
            $this->forge->addKey(['status', 'expires_at']);
            $this->forge->addForeignKey('admin_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('target_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('admin_impersonation_sessions', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('admin_impersonation_sessions', true);
        $this->forge->dropTable('admin_impersonation_grants', true);
    }
}
