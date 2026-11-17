<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 7C: 3-level purchase commission plans + simulation (never posts payouts).
 */
class CreateCommissionSimulationTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'plan_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'effective_from' => ['type' => 'DATE'],
            'effective_to' => ['type' => 'DATE', 'null' => true],
            'level_count' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 3],
            'commission_currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'eligible_purchase_policy' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'approved_verified_usdt'],
            'sponsor_eligibility_policy' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'active_sponsors_only'],
            'status' => ['type' => 'ENUM', 'constraint' => ['draft', 'approved', 'disabled'], 'default' => 'draft'],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'disabled_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'disabled_at' => ['type' => 'DATETIME', 'null' => true],
            'notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('plan_ref');
        $this->forge->addKey(['status', 'effective_from']);
        $this->forge->createTable('commission_plans', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'level' => ['type' => 'TINYINT', 'unsigned' => true],
            'percent' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['plan_ref', 'level']);
        $this->forge->createTable('commission_plan_levels', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('plan_ref');
        $this->forge->createTable('commission_plan_approval_events', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'calculation_date' => ['type' => 'DATE'],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['completed', 'failed', 'partial'], 'default' => 'completed'],
            'is_dry_run' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'is_payable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_posted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'purchase_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'eligible_purchase_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'ineligible_purchase_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'item_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'total_preview_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'level1_preview' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'level2_preview' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'level3_preview' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'error_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'error_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('run_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey('calculation_date');
        $this->forge->createTable('commission_simulation_runs', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'item_ref' => ['type' => 'VARCHAR', 'constraint' => 64],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 120],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchaser_user_id' => ['type' => 'INT', 'unsigned' => true],
            'purchaser_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'beneficiary_user_id' => ['type' => 'INT', 'unsigned' => true],
            'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'level' => ['type' => 'TINYINT', 'unsigned' => true],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchase_amount' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'percent' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'preview_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'calculation_date' => ['type' => 'DATE'],
            'eligible' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'reason_codes_json' => ['type' => 'TEXT', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['simulated', 'skipped', 'error'], 'default' => 'simulated'],
            'is_payable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_posted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('item_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey(['beneficiary_user_id', 'calculation_date']);
        $this->forge->addKey('purchase_ref');
        $this->forge->addKey(['run_ref', 'level']);
        $this->forge->createTable('commission_simulation_items', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'level' => ['type' => 'TINYINT', 'unsigned' => true, 'null' => true],
            'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'eligible' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'reason_codes_json' => ['type' => 'TEXT', 'null' => true],
            'source' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'simulation'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('purchase_ref');
        $this->forge->createTable('commission_eligibility_audit_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('commission_eligibility_audit_events', true);
        $this->forge->dropTable('commission_simulation_items', true);
        $this->forge->dropTable('commission_simulation_runs', true);
        $this->forge->dropTable('commission_plan_approval_events', true);
        $this->forge->dropTable('commission_plan_levels', true);
        $this->forge->dropTable('commission_plans', true);
    }
}
