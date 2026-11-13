<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4D: allocation event ledger, financial alerts, remediation proposals.
 */
class CreateAllocationEventsAndFinanceOps extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'allocation_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'deposit_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'previous_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'new_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'metadata' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('allocation_ref');
        $this->forge->addKey('deposit_ref');
        $this->forge->addKey('purchase_ref');
        $this->forge->addKey('event_type');
        $this->forge->createTable('usdt_payment_allocation_events', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'alert_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'alert_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'severity' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'warning'],
            'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 190],
            'status' => ['type' => 'ENUM', 'constraint' => ['open', 'acknowledged'], 'default' => 'open'],
            'title' => ['type' => 'VARCHAR', 'constraint' => 180],
            'message' => ['type' => 'VARCHAR', 'constraint' => 500],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'entity_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'metadata' => ['type' => 'TEXT', 'null' => true],
            'occurrence_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'first_seen_at' => ['type' => 'DATETIME'],
            'last_seen_at' => ['type' => 'DATETIME'],
            'acknowledged_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'acknowledged_at' => ['type' => 'DATETIME', 'null' => true],
            'ack_note' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('alert_ref');
        $this->forge->addUniqueKey('dedupe_key');
        $this->forge->addKey('status');
        $this->forge->addKey('alert_type');
        $this->forge->addKey('severity');
        $this->forge->createTable('financial_alerts', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['proposed', 'approved', 'rejected', 'execution_unavailable'],
                'default'    => 'proposed',
            ],
            'remediation_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'title' => ['type' => 'VARCHAR', 'constraint' => 180],
            'reason' => ['type' => 'TEXT'],
            'evidence_notes' => ['type' => 'TEXT', 'null' => true],
            'entity_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'entity_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'before_state_json' => ['type' => 'TEXT', 'null' => true],
            'planned_after_state_json' => ['type' => 'TEXT', 'null' => true],
            'proposed_by' => ['type' => 'INT', 'unsigned' => true],
            'reviewed_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'review_note' => ['type' => 'TEXT', 'null' => true],
            'proposed_at' => ['type' => 'DATETIME'],
            'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'execution_allowed' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'execution_result' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('proposal_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('status');
        $this->forge->addKey('proposed_by');
        $this->forge->createTable('financial_remediation_proposals', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'metadata' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('proposal_ref');
        $this->forge->createTable('financial_remediation_events', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'lock_name' => ['type' => 'VARCHAR', 'constraint' => 64],
            'locked_by' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'locked_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('lock_name');
        $this->forge->createTable('ops_job_locks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('ops_job_locks', true);
        $this->forge->dropTable('financial_remediation_events', true);
        $this->forge->dropTable('financial_remediation_proposals', true);
        $this->forge->dropTable('financial_alerts', true);
        $this->forge->dropTable('usdt_payment_allocation_events', true);
    }
}
