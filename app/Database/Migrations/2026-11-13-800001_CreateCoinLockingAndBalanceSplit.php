<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 5: available/locked balance split, locking plans, member locks, lock audit events.
 * Total owned (balance) = available_balance + locked_balance.
 */
class CreateCoinLockingAndBalanceSplit extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('member_coin_balances', [
            'available_balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
                'after'      => 'balance',
            ],
            'locked_balance' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'default'    => '0.00000000',
                'after'      => 'available_balance',
            ],
        ]);

        // Existing totals were entirely available before locking existed.
        $this->db->query(
            'UPDATE member_coin_balances SET available_balance = balance, locked_balance = 0.00000000'
        );

        $this->db->query(
            "ALTER TABLE member_coin_ownership_transactions
             MODIFY COLUMN `type` ENUM('PURCHASE_CREDIT','ADMIN_ADJUSTMENT','LOCK','UNLOCK') NOT NULL"
        );

        $this->forge->addColumn('member_coin_ownership_transactions', [
            'lock_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'purchase_ref',
            ],
            'previous_available' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
                'after'      => 'new_balance',
            ],
            'new_available' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
                'after'      => 'previous_available',
            ],
            'previous_locked' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
                'after'      => 'new_available',
            ],
            'new_locked' => [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
                'after'      => 'previous_locked',
            ],
        ]);
        $this->db->query(
            'ALTER TABLE member_coin_ownership_transactions ADD INDEX member_coin_ownership_transactions_lock_ref (lock_ref)'
        );

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'plan_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'description' => ['type' => 'TEXT', 'null' => true],
            'min_quantity' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000001'],
            'max_quantity' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
            'duration_days' => ['type' => 'INT', 'unsigned' => true],
            'is_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'allow_early_unlock' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('plan_code');
        $this->forge->addKey('is_enabled');
        $this->forge->createTable('coin_locking_plans', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'lock_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'plan_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'plan_code' => ['type' => 'VARCHAR', 'constraint' => 40],
            'plan_name' => ['type' => 'VARCHAR', 'constraint' => 120],
            'quantity' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'duration_days' => ['type' => 'INT', 'unsigned' => true],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['active', 'matured', 'unlocked', 'cancelled'],
                'default'    => 'active',
            ],
            'locked_at' => ['type' => 'DATETIME'],
            'matures_at' => ['type' => 'DATETIME'],
            'matured_at' => ['type' => 'DATETIME', 'null' => true],
            'unlocked_at' => ['type' => 'DATETIME', 'null' => true],
            'cancelled_at' => ['type' => 'DATETIME', 'null' => true],
            'ownership_lock_txn_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'ownership_unlock_txn_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'unlock_idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('lock_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addUniqueKey('unlock_idempotency_key');
        $this->forge->addKey(['member_user_id', 'status']);
        $this->forge->addKey(['status', 'matures_at']);
        $this->forge->addKey('plan_id');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('plan_id', 'coin_locking_plans', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('member_coin_locks', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'lock_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'detail' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'metadata' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('lock_ref');
        $this->forge->addKey('event_type');
        $this->forge->createTable('member_coin_lock_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('member_coin_lock_events', true);
        $this->forge->dropTable('member_coin_locks', true);
        $this->forge->dropTable('coin_locking_plans', true);

        // Remove Phase 5 ledger rows before shrinking the ENUM (MySQL truncates otherwise).
        if ($this->db->tableExists('member_coin_ownership_transactions')) {
            $this->db->query(
                "DELETE FROM member_coin_ownership_transactions WHERE type IN ('LOCK','UNLOCK')"
            );

            $indexes = $this->db->getIndexData('member_coin_ownership_transactions');
            if (isset($indexes['member_coin_ownership_transactions_lock_ref'])) {
                $this->db->query(
                    'ALTER TABLE member_coin_ownership_transactions DROP INDEX member_coin_ownership_transactions_lock_ref'
                );
            }

            $fields = $this->db->getFieldNames('member_coin_ownership_transactions');
            foreach (['lock_ref', 'previous_available', 'new_available', 'previous_locked', 'new_locked'] as $col) {
                if (in_array($col, $fields, true)) {
                    $this->forge->dropColumn('member_coin_ownership_transactions', $col);
                }
            }

            $this->db->query(
                "ALTER TABLE member_coin_ownership_transactions
                 MODIFY COLUMN `type` ENUM('PURCHASE_CREDIT','ADMIN_ADJUSTMENT') NOT NULL"
            );
        }

        if ($this->db->tableExists('member_coin_balances')) {
            $fields = $this->db->getFieldNames('member_coin_balances');
            foreach (['available_balance', 'locked_balance'] as $col) {
                if (in_array($col, $fields, true)) {
                    $this->forge->dropColumn('member_coin_balances', $col);
                }
            }
        }
    }
}
