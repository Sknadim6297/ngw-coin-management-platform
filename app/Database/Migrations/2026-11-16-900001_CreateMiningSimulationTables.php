<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 7B: mining rate configuration + simulation only (never wallet credits).
 */
class CreateMiningSimulationTables extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'effective_month' => ['type' => 'CHAR', 'constraint' => 7], // YYYY-MM
            'rate_percent' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'rate_basis' => ['type' => 'ENUM', 'constraint' => ['daily', 'monthly', 'annual'], 'default' => 'monthly'],
            'precision_scale' => ['type' => 'TINYINT', 'unsigned' => true, 'default' => 8],
            'eligibility_policy' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'available_active_only'],
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
        $this->forge->addUniqueKey('rate_ref');
        $this->forge->addKey(['status', 'effective_month']);
        $this->forge->createTable('mining_rate_configs', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('rate_ref');
        $this->forge->createTable('mining_rate_approval_events', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'calculation_date' => ['type' => 'DATE'],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'status' => ['type' => 'ENUM', 'constraint' => ['completed', 'failed', 'partial'], 'default' => 'completed'],
            'is_dry_run' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'is_payable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_posted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'member_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'eligible_member_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'total_eligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'total_preview_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
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
        $this->forge->createTable('mining_simulation_runs', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'item_ref' => ['type' => 'VARCHAR', 'constraint' => 48],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'calculation_date' => ['type' => 'DATE'],
            'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'applied_percent' => ['type' => 'DECIMAL', 'constraint' => '18,8', 'default' => '0'],
            'rate_basis' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
            'total_owned' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'available_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'locked_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'eligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'ineligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'preview_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'eligible' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'reason_codes_json' => ['type' => 'TEXT', 'null' => true],
            'status' => ['type' => 'ENUM', 'constraint' => ['simulated', 'skipped', 'error'], 'default' => 'simulated'],
            'is_payable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_posted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('item_ref');
        $this->forge->addUniqueKey(['run_ref', 'member_user_id']);
        $this->forge->addKey(['member_user_id', 'calculation_date']);
        $this->forge->addKey('calculation_date');
        $this->forge->createTable('mining_simulation_items', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'calculation_date' => ['type' => 'DATE'],
            'eligible' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'eligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'reason_codes_json' => ['type' => 'TEXT', 'null' => true],
            'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'source' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'simulation'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['member_user_id', 'calculation_date']);
        $this->forge->createTable('mining_eligibility_audit_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('mining_eligibility_audit_events', true);
        $this->forge->dropTable('mining_simulation_items', true);
        $this->forge->dropTable('mining_simulation_runs', true);
        $this->forge->dropTable('mining_rate_approval_events', true);
        $this->forge->dropTable('mining_rate_configs', true);
    }
}
