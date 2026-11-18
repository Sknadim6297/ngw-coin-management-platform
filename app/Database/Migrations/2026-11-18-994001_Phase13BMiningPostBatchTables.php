<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 13B — additive tables for resumable, keyset-batched daily mining posting.
 * Touches no wallet, ledger, ownership or purchase table. NOT executed against production by this phase.
 *
 * mining_post_day_runs    one row per business date: frozen policy/rate/cutoff/population, cursor, counters, state
 * mining_post_members     one row per (business date, member): frozen population + per-member outcome
 */
class Phase13BMiningPostBatchTables extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('mining_post_day_runs')) {
            $this->forge->addField([
                'id'               => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'business_date'    => ['type' => 'DATE'],
                'state'            => ['type' => 'ENUM', 'constraint' => ['PENDING', 'RUNNING', 'PARTIAL', 'COMPLETED', 'FAILED'], 'default' => 'PENDING'],
                'policy_version'   => ['type' => 'VARCHAR', 'constraint' => 24],
                'policy_json'      => ['type' => 'TEXT'],
                'rate_ref'         => ['type' => 'VARCHAR', 'constraint' => 40],
                'cutoff_utc'       => ['type' => 'DATETIME'],
                'incomplete_day'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'max_user_id'      => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
                'population_frozen' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'population_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'eligible_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'credited_count'   => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'skipped_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'blocked_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'failed_count'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'pending_count'    => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'duplicate_count'  => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'total_credited'   => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'batch_size'       => ['type' => 'INT', 'unsigned' => true, 'default' => 500],
                'batches_done'     => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'last_user_id'     => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
                'invocations'      => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'reconcile_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'null' => true],
                'last_error'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'started_at'       => ['type' => 'DATETIME', 'null' => true],
                'finished_at'      => ['type' => 'DATETIME', 'null' => true],
                'created_at'       => ['type' => 'DATETIME', 'null' => true],
                'updated_at'       => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('business_date');
            $this->forge->addKey('state');
            $this->forge->createTable('mining_post_day_runs', true);
        }

        if (! $this->db->tableExists('mining_post_members')) {
            $this->forge->addField([
                'id'              => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'business_date'   => ['type' => 'DATE'],
                'member_user_id'  => ['type' => 'BIGINT', 'unsigned' => true],
                'member_id'       => ['type' => 'VARCHAR', 'constraint' => 32],
                'account_status'  => ['type' => 'VARCHAR', 'constraint' => 24],
                'status'          => ['type' => 'ENUM', 'constraint' => ['PENDING', 'CREDITED', 'SKIPPED', 'BLOCKED', 'FAILED'], 'default' => 'PENDING'],
                'reason'          => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
                'eligible_coins'  => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'expected_amount' => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'credited_amount' => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
                'ledger_ref'      => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'evidence_ref'    => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'was_duplicate'   => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'attempts'        => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'last_error'      => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'processed_at'    => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['business_date', 'member_user_id']);
            $this->forge->addKey(['business_date', 'status', 'member_user_id']);
            $this->forge->createTable('mining_post_members', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('mining_post_members', true);
        $this->forge->dropTable('mining_post_day_runs', true);
    }
}
