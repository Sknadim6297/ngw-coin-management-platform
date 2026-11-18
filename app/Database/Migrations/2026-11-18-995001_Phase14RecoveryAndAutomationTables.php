<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 14 — additive tables for:
 *  1) Append-only commission calculation revisions (preserves immutable NO_APPROVED_PLAN rows)
 *  2) Controlled, resumable commission posting batches & per-item checkpoints
 *  3) Dual-control mining recovery proposals & item evidence
 */
class Phase14RecoveryAndAutomationTables extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('commission_calculation_revisions')) {
            $this->forge->addField([
                'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'revision_ref'          => ['type' => 'VARCHAR', 'constraint' => 48],
                'supersedes_calc_ref'   => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'purchase_ref'          => ['type' => 'VARCHAR', 'constraint' => 40],
                'purchase_id'           => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'purchaser_user_id'     => ['type' => 'INT', 'unsigned' => true],
                'purchaser_member_id'   => ['type' => 'VARCHAR', 'constraint' => 32],
                'sponsor_level'         => ['type' => 'TINYINT', 'unsigned' => true],
                'beneficiary_user_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'commission_base_usdt'  => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'rate_percent'          => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'expected_amount_usdt'  => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'plan_ref'              => ['type' => 'VARCHAR', 'constraint' => 40],
                'plan_effective_from'   => ['type' => 'DATE'],
                'business_date'         => ['type' => 'DATE'],
                'root_eligible'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'status'                => ['type' => 'VARCHAR', 'constraint' => 40],
                'eligibility_status'    => ['type' => 'VARCHAR', 'constraint' => 40],
                'reason_codes'          => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'assumptions_json'      => ['type' => 'TEXT', 'null' => true],
                'idempotency_key'       => ['type' => 'VARCHAR', 'constraint' => 128],
                'created_by'            => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'            => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('revision_ref');
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addKey(['purchase_ref', 'sponsor_level']);
            $this->forge->addKey(['plan_ref', 'status']);
            $this->forge->createTable('commission_calculation_revisions', true);
        }

        if (! $this->db->tableExists('commission_post_batches')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'batch_ref'        => ['type' => 'VARCHAR', 'constraint' => 48],
                'run_mode'         => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'recovery'],
                'state'            => ['type' => 'ENUM', 'constraint' => ['PENDING', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED'], 'default' => 'PENDING'],
                'plan_ref'         => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'policy_version'   => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'root_eligible'    => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
                'population_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'eligible_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'posted_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'skipped_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'failed_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'pending_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'duplicate_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'total_posted_usdt'=> ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'batch_size'       => ['type' => 'INT', 'unsigned' => true, 'default' => 500],
                'batches_done'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'last_item_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
                'invocations'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'reconcile_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
                'last_error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'started_at'       => ['type' => 'DATETIME', 'null' => true],
                'finished_at'      => ['type' => 'DATETIME', 'null' => true],
                'created_by'       => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at'       => ['type' => 'DATETIME', 'null' => true],
                'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('batch_ref');
            $this->forge->addKey(['run_mode', 'state']);
            $this->forge->createTable('commission_post_batches', true);
        }

        if (! $this->db->tableExists('commission_post_items')) {
            $this->forge->addField([
                'id'                    => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'batch_ref'             => ['type' => 'VARCHAR', 'constraint' => 48],
                'purchase_ref'          => ['type' => 'VARCHAR', 'constraint' => 40],
                'purchase_id'           => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'sponsor_level'         => ['type' => 'TINYINT', 'unsigned' => true],
                'source_calc_ref'       => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'source_revision_ref'   => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
                'beneficiary_user_id'   => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'expected_amount_usdt'  => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'posted_amount_usdt'    => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'status'                => ['type' => 'ENUM', 'constraint' => ['PENDING', 'POSTED', 'SKIPPED', 'FAILED'], 'default' => 'PENDING'],
                'reason'                => ['type' => 'VARCHAR', 'constraint' => 160, 'null' => true],
                'ledger_entry_ref'      => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
                'idempotency_key'       => ['type' => 'VARCHAR', 'constraint' => 120],
                'was_duplicate'         => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'attempts'              => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'last_error'            => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'processed_at'          => ['type' => 'DATETIME', 'null' => true],
                'created_at'            => ['type' => 'DATETIME', 'null' => true],
                'updated_at'            => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['batch_ref', 'purchase_ref', 'sponsor_level']);
            $this->forge->addKey(['batch_ref', 'status', 'id']);
            $this->forge->createTable('commission_post_items', true);
        }

        if (! $this->db->tableExists('mining_recovery_proposals')) {
            $this->forge->addField([
                'id'                         => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'proposal_ref'               => ['type' => 'VARCHAR', 'constraint' => 48],
                'business_date'              => ['type' => 'DATE'],
                'policy_version'             => ['type' => 'VARCHAR', 'constraint' => 32],
                'rate_ref'                   => ['type' => 'VARCHAR', 'constraint' => 40],
                'supply_source'              => ['type' => 'VARCHAR', 'constraint' => 48],
                'total_eligible_members'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'total_eligible_coins'       => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'total_expected_coins'       => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'existing_posted_coins'      => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'missing_coins'              => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'inventory_available_before' => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'status'                     => ['type' => 'ENUM', 'constraint' => ['draft', 'approved', 'executed', 'rejected'], 'default' => 'draft'],
                'created_by'                 => ['type' => 'INT', 'unsigned' => true],
                'approved_by'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'approved_at'                => ['type' => 'DATETIME', 'null' => true],
                'executed_by'                => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'executed_at'                => ['type' => 'DATETIME', 'null' => true],
                'run_ref'                    => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
                'notes'                      => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'created_at'                 => ['type' => 'DATETIME'],
                'updated_at'                 => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('proposal_ref');
            $this->forge->addKey(['business_date', 'status']);
            $this->forge->createTable('mining_recovery_proposals', true);
        }

        if (! $this->db->tableExists('mining_recovery_proposal_items')) {
            $this->forge->addField([
                'id'                   => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'proposal_ref'         => ['type' => 'VARCHAR', 'constraint' => 48],
                'business_date'        => ['type' => 'DATE'],
                'member_user_id'       => ['type' => 'INT', 'unsigned' => true],
                'member_id'            => ['type' => 'VARCHAR', 'constraint' => 32],
                'eligible_coins'       => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'expected_coins'       => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'already_posted_coins' => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'missing_coins'        => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'evidence_ref'         => ['type' => 'VARCHAR', 'constraint' => 48, 'null' => true],
                'snapshot_status'      => ['type' => 'VARCHAR', 'constraint' => 32],
                'created_at'           => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['proposal_ref', 'business_date', 'member_user_id']);
            $this->forge->createTable('mining_recovery_proposal_items', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('mining_recovery_proposal_items', true);
        $this->forge->dropTable('mining_recovery_proposals', true);
        $this->forge->dropTable('commission_post_items', true);
        $this->forge->dropTable('commission_post_batches', true);
        $this->forge->dropTable('commission_calculation_revisions', true);
    }
}
