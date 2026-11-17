<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 11D: expand withdrawal statuses + payout evidence (execution remains OFF).
 */
class Phase11DCommissionWithdrawalPayout extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('commission_withdrawal_requests')) {
            // Expand status vocabulary for Phase 11D workflow.
            $this->db->query("ALTER TABLE `commission_withdrawal_requests`
                MODIFY COLUMN `status` ENUM(
                    'draft',
                    'submitted',
                    'under_review',
                    'pending_admin_review',
                    'approved',
                    'rejected',
                    'payout_in_progress',
                    'payout_submitted',
                    'paid',
                    'failed',
                    'cancelled'
                ) NOT NULL DEFAULT 'draft'");

            $fields = [
                'destination_address_snapshot' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 128,
                    'null'       => true,
                    'after'      => 'destination_address',
                ],
                'wallet_changed_at_snapshot' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after'=> 'destination_address_snapshot',
                ],
                'fee_amount' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '24,8',
                    'null'       => true,
                    'after'      => 'amount',
                ],
                'net_amount' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '24,8',
                    'null'       => true,
                    'after'      => 'fee_amount',
                ],
                'fee_policy_status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'default'    => 'pending_business_decision',
                    'after'      => 'net_amount',
                ],
                'paid_amount' => [
                    'type'       => 'DECIMAL',
                    'constraint' => '24,8',
                    'null'       => true,
                ],
                'paid_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
                'verification_status' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'null'       => true,
                ],
                'address_change_review_required' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 0,
                ],
            ];

            foreach ($fields as $name => $def) {
                if (! $this->db->fieldExists($name, 'commission_withdrawal_requests')) {
                    $this->forge->addColumn('commission_withdrawal_requests', [$name => $def]);
                }
            }
        }

        if (! $this->db->tableExists('commission_withdrawal_payout_evidence')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'evidence_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'withdrawal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'approved_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'paid_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
                'token_contract' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
                'blockchain_network' => ['type' => 'VARCHAR', 'constraint' => 32],
                'destination_address' => ['type' => 'VARCHAR', 'constraint' => 128],
                'tx_hash' => ['type' => 'VARCHAR', 'constraint' => 80],
                'tx_hash_normalized' => ['type' => 'VARCHAR', 'constraint' => 80],
                'tx_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'recorded'],
                'block_number' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
                'confirmations' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'verification_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'blocked'],
                'verification_method' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'manual_record'],
                'verification_message' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
                'verification_details_json' => ['type' => 'TEXT', 'null' => true],
                'payment_timestamp' => ['type' => 'DATETIME', 'null' => true],
                'recorded_by' => ['type' => 'INT', 'unsigned' => true],
                'verified_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
                'verified_at' => ['type' => 'DATETIME', 'null' => true],
                'audit_ref' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('evidence_ref');
            $this->forge->addUniqueKey('tx_hash_normalized');
            $this->forge->addKey('withdrawal_ref');
            $this->forge->createTable('commission_withdrawal_payout_evidence', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('commission_withdrawal_payout_evidence', true);
    }
}
