<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 11E: coin selling requests + reserved ownership balance (execution OFF).
 */
class Phase11ECoinSelling extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('member_coin_balances')
            && ! $this->db->fieldExists('reserved_balance', 'member_coin_balances')
        ) {
            $this->forge->addColumn('member_coin_balances', [
                'reserved_balance' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '24,8',
                    'default'    => '0.00000000',
                    'after'      => 'locked_balance',
                ],
            ]);
        }

        if ($this->db->tableExists('member_coin_ownership_transactions')) {
            $this->db->query(
                "ALTER TABLE `member_coin_ownership_transactions`
                 MODIFY COLUMN `type` ENUM(
                    'PURCHASE_CREDIT',
                    'ADMIN_ADJUSTMENT',
                    'LOCK',
                    'UNLOCK',
                    'SALE_RESERVE',
                    'SALE_RELEASE',
                    'SALE_DEBIT'
                 ) NOT NULL"
            );

            if (! $this->db->fieldExists('previous_reserved', 'member_coin_ownership_transactions')) {
                $this->forge->addColumn('member_coin_ownership_transactions', [
                    'previous_reserved' => [
                        'type'       => 'DECIMAL',
                        'constraint' => '24,8',
                        'null'       => true,
                        'after'      => 'new_locked',
                    ],
                    'new_reserved' => [
                        'type'       => 'DECIMAL',
                        'constraint' => '24,8',
                        'null'       => true,
                        'after'      => 'previous_reserved',
                    ],
                    'sell_ref' => [
                        'type'       => 'VARCHAR',
                        'constraint' => 40,
                        'null'       => true,
                        'after'      => 'lock_ref',
                    ],
                ]);
            }
        }

        if (! $this->db->tableExists('coin_sell_requests')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'sell_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'lock_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'quantity' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'price_snapshot_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'gross_value_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'fee_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'net_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'fee_policy_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'pending_business_decision'],
                'settlement_asset' => ['type' => 'VARCHAR', 'constraint' => 16, 'null' => true],
                'settlement_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'settlement_method' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'settlement_evidence_ref' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'status' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'draft'],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 96],
                'is_reserved' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'is_settled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
                'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'requested_at' => ['type' => 'DATETIME', 'null' => true],
                'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
                'reviewer_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'completed_at' => ['type' => 'DATETIME', 'null' => true],
                'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('sell_ref');
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addKey(['member_user_id', 'status']);
            $this->forge->addKey(['purchase_ref', 'status']);
            $this->forge->addKey('lock_ref');
            $this->forge->createTable('coin_sell_requests', true);
        }

        if (! $this->db->tableExists('coin_sell_events')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'sell_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
                'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
                'payload_json' => ['type' => 'TEXT', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addKey('sell_ref');
            $this->forge->createTable('coin_sell_events', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('coin_sell_events', true);
        $this->forge->dropTable('coin_sell_requests', true);

        if ($this->db->tableExists('member_coin_ownership_transactions')) {
            $this->db->query(
                "DELETE FROM member_coin_ownership_transactions WHERE type IN ('SALE_RESERVE','SALE_RELEASE','SALE_DEBIT')"
            );
            foreach (['previous_reserved', 'new_reserved', 'sell_ref'] as $col) {
                if ($this->db->fieldExists($col, 'member_coin_ownership_transactions')) {
                    try {
                        $this->forge->dropColumn('member_coin_ownership_transactions', $col);
                    } catch (\Throwable) {
                        // Safe for PHPUnit migrate-down refresh cycles.
                    }
                }
            }
            $this->db->query(
                "ALTER TABLE `member_coin_ownership_transactions`
                 MODIFY COLUMN `type` ENUM('PURCHASE_CREDIT','ADMIN_ADJUSTMENT','LOCK','UNLOCK') NOT NULL"
            );
        }

        if ($this->db->tableExists('member_coin_balances')
            && $this->db->fieldExists('reserved_balance', 'member_coin_balances')
        ) {
            $this->forge->dropColumn('member_coin_balances', 'reserved_balance');
        }
    }
}
