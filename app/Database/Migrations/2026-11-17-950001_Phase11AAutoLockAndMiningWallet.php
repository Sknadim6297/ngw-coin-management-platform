<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 11A: mandatory purchase locks + mining wallet / daily calculation tables.
 * Posting remains kill-switched OFF in application code.
 */
class Phase11AAutoLockAndMiningWallet extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('member_coin_locks')) {
            $fields = $this->db->getFieldNames('member_coin_locks');
            $add    = [];
            if (! in_array('purchase_ref', $fields, true)) {
                $add['purchase_ref'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'null'       => true,
                    'after'      => 'member_id',
                ];
            }
            if (! in_array('lock_kind', $fields, true)) {
                $add['lock_kind'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 32,
                    'default'    => 'voluntary',
                    'after'      => 'purchase_ref',
                ];
            }
            if ($add !== []) {
                $this->forge->addColumn('member_coin_locks', $add);
            }
            // Unique only for non-null purchase_ref enforced in app + partial unique via index where supported.
            $indexes = $this->db->getIndexData('member_coin_locks');
            if (! isset($indexes['member_coin_locks_purchase_ref_unique'])) {
                try {
                    $this->db->query(
                        'ALTER TABLE member_coin_locks ADD UNIQUE INDEX member_coin_locks_purchase_ref_unique (purchase_ref)'
                    );
                } catch (\Throwable) {
                    // MySQL unique allows multiple NULLs — OK. If index exists with another name, ignore.
                }
            }
            if (! isset($indexes['member_coin_locks_lock_kind'])) {
                try {
                    $this->db->query(
                        'ALTER TABLE member_coin_locks ADD INDEX member_coin_locks_lock_kind (lock_kind)'
                    );
                } catch (\Throwable) {
                }
            }
        }

        if (! $this->db->tableExists('mining_wallets')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'asset_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'COIN'],
                'available_balance' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'total_credited' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'total_debited' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey(['member_user_id', 'asset_code']);
            $this->forge->addKey('member_id');
            $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('mining_wallets', true);
        }

        if (! $this->db->tableExists('mining_wallet_ledger')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'entry_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'asset_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'COIN'],
                'entry_type' => ['type' => 'VARCHAR', 'constraint' => 40],
                'direction' => ['type' => 'ENUM', 'constraint' => ['credit', 'debit']],
                'amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'balance_after' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'business_date' => ['type' => 'DATE'],
                'reference_type' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'reference_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 96],
                'meta_json' => ['type' => 'TEXT', 'null' => true],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('entry_ref');
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addKey(['member_user_id', 'business_date']);
            $this->forge->addKey(['member_user_id', 'id']);
            $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('mining_wallet_ledger', true);
        }

        if (! $this->db->tableExists('mining_daily_runs')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'business_date' => ['type' => 'DATE'],
                'run_type' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'dry_run'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'completed'],
                'member_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'eligible_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
                'total_eligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'total_expected_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'total_posted_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'assumptions_json' => ['type' => 'TEXT', 'null' => true],
                'error_summary' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'started_at' => ['type' => 'DATETIME'],
                'finished_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('run_ref');
            $this->forge->addKey(['business_date', 'run_type']);
            $this->forge->createTable('mining_daily_runs', true);
        }

        if (! $this->db->tableExists('mining_daily_calculations')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'calc_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'run_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'business_date' => ['type' => 'DATE'],
                'eligible_coins' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'monthly_rate_percent' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'day_divisor' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'daily_factor' => ['type' => 'DECIMAL', 'constraint' => '24,12', 'null' => true],
                'expected_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'posted_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
                'asset_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'COIN'],
                'rate_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 40],
                'explanation' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 96],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('calc_ref');
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addKey(['business_date', 'member_user_id']);
            $this->forge->addKey('run_ref');
            $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
            $this->forge->createTable('mining_daily_calculations', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('mining_daily_calculations', true);
        $this->forge->dropTable('mining_daily_runs', true);
        $this->forge->dropTable('mining_wallet_ledger', true);
        $this->forge->dropTable('mining_wallets', true);

        if ($this->db->tableExists('member_coin_locks')) {
            $fields = $this->db->getFieldNames('member_coin_locks');
            foreach (['lock_kind', 'purchase_ref'] as $col) {
                if (in_array($col, $fields, true)) {
                    $this->forge->dropColumn('member_coin_locks', $col);
                }
            }
        }
    }
}
