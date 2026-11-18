<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 12E — additive financial business policy versioning.
 * Does not alter wallets, ledgers, purchases, or kill-switches.
 */
class Phase12EFinancialPolicyVersions extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('financial_policy_versions')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'policy_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'domain' => ['type' => 'VARCHAR', 'constraint' => 40],
                'version_no' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'title' => ['type' => 'VARCHAR', 'constraint' => 160],
                'status' => [
                    'type'       => 'ENUM',
                    'constraint' => ['draft', 'pending_approval', 'approved', 'active', 'rejected', 'superseded'],
                    'default'    => 'draft',
                ],
                'payload_json' => ['type' => 'LONGTEXT', 'null' => true],
                'baseline_json' => ['type' => 'LONGTEXT', 'null' => true],
                'effective_from' => ['type' => 'DATE', 'null' => true],
                'effective_to' => ['type' => 'DATE', 'null' => true],
                'notes' => ['type' => 'TEXT', 'null' => true],
                'created_by' => ['type' => 'BIGINT', 'unsigned' => true],
                'submitted_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'submitted_at' => ['type' => 'DATETIME', 'null' => true],
                'approved_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'approved_at' => ['type' => 'DATETIME', 'null' => true],
                'rejected_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'rejected_at' => ['type' => 'DATETIME', 'null' => true],
                'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'activated_by' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'activated_at' => ['type' => 'DATETIME', 'null' => true],
                'lock_version' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('policy_ref');
            $this->forge->addKey(['domain', 'status']);
            $this->forge->addKey(['domain', 'version_no']);
            $this->forge->createTable('financial_policy_versions', true);
        }

        if (! $this->db->tableExists('financial_policy_events')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'policy_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
                'actor_user_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'payload_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('policy_ref');
            $this->forge->createTable('financial_policy_events', true);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('financial_policy_events')) {
            $this->forge->dropTable('financial_policy_events', true);
        }
        if ($this->db->tableExists('financial_policy_versions')) {
            $this->forge->dropTable('financial_policy_versions', true);
        }
    }
}
