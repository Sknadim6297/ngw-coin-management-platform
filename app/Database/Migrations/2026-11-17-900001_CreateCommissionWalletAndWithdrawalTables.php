<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 7D: historical purchase sponsor snapshots, commission wallet ledger,
 * posting proposals, and withdrawal request architecture.
 * Real posting/payout remain disabled by application kill-switches.
 */
class CreateCommissionWalletAndWithdrawalTables extends Migration
{
    public function up(): void
    {
        // Immutable purchase-time genealogy (no silent backfill of pre-7D purchases).
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'purchase_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchaser_user_id' => ['type' => 'INT', 'unsigned' => true],
            'purchaser_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'sponsor_level' => ['type' => 'TINYINT', 'unsigned' => true],
            'sponsor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'sponsor_member_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'sponsor_relationship_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'is_missing' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'snapshot_status' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'CAPTURED'],
            'snapshot_source' => ['type' => 'VARCHAR', 'constraint' => 40, 'default' => 'purchase_approval'],
            'reason_codes_json' => ['type' => 'TEXT', 'null' => true],
            'snapshot_timestamp' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['purchase_ref', 'sponsor_level']);
        $this->forge->addKey('purchase_id');
        $this->forge->addKey('purchaser_member_id');
        $this->forge->createTable('purchase_sponsor_snapshots', true);

        // Commission wallet accounts (USDT commission currency — not coin ownership).
        // Invariant: total_balance = available_balance + reserved_balance; both sides >= 0.
        // Ledger is source of truth; balances are cached projections.
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'available_balance' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'reserved_balance' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'total_balance' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['member_user_id', 'currency']);
        $this->forge->addKey('member_id');
        $this->forge->createTable('commission_wallet_accounts', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'entry_ref' => ['type' => 'VARCHAR', 'constraint' => 48],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'entry_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'direction' => ['type' => 'ENUM', 'constraint' => ['credit', 'debit'], 'default' => 'credit'],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'available_delta' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'reserved_delta' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0'],
            'balance_after_available' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'balance_after_reserved' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'balance_after_total' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'reference_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'reference_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 120],
            'notes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('entry_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey(['member_user_id', 'created_at']);
        $this->forge->createTable('commission_wallet_ledger_entries', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'beneficiary_user_id' => ['type' => 'INT', 'unsigned' => true],
            'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'level' => ['type' => 'TINYINT', 'unsigned' => true],
            'purchase_amount' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'percent' => ['type' => 'DECIMAL', 'constraint' => '18,8'],
            'proposed_amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'status' => ['type' => 'ENUM', 'constraint' => ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled'], 'default' => 'draft'],
            'snapshot_status' => ['type' => 'VARCHAR', 'constraint' => 40],
            'preflight_json' => ['type' => 'TEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'unsigned' => true],
            'submitted_at' => ['type' => 'DATETIME', 'null' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'is_executable' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_posted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('proposal_ref');
        $this->forge->addUniqueKey(['purchase_ref', 'beneficiary_member_id', 'level', 'plan_ref']);
        $this->forge->addKey('status');
        $this->forge->createTable('commission_posting_proposals', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true],
            'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('proposal_ref');
        $this->forge->createTable('commission_posting_proposal_events', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'withdrawal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'member_user_id' => ['type' => 'INT', 'unsigned' => true],
            'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'amount' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'currency' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'USDT'],
            'network' => ['type' => 'VARCHAR', 'constraint' => 32],
            'destination_address' => ['type' => 'VARCHAR', 'constraint' => 128],
            'status' => ['type' => 'ENUM', 'constraint' => ['draft', 'submitted', 'under_review', 'approved', 'rejected', 'cancelled'], 'default' => 'draft'],
            'requested_at' => ['type' => 'DATETIME', 'null' => true],
            'reviewed_at' => ['type' => 'DATETIME', 'null' => true],
            'reviewer_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'payout_reference' => ['type' => 'VARCHAR', 'constraint' => 80, 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
            'is_reserved' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_paid' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('withdrawal_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addKey(['member_user_id', 'status']);
        $this->forge->createTable('commission_withdrawal_requests', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'withdrawal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'actor_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'from_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'to_status' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'payload_json' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('withdrawal_ref');
        $this->forge->createTable('commission_withdrawal_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('commission_withdrawal_events', true);
        $this->forge->dropTable('commission_withdrawal_requests', true);
        $this->forge->dropTable('commission_posting_proposal_events', true);
        $this->forge->dropTable('commission_posting_proposals', true);
        $this->forge->dropTable('commission_wallet_ledger_entries', true);
        $this->forge->dropTable('commission_wallet_accounts', true);
        $this->forge->dropTable('purchase_sponsor_snapshots', true);
    }
}
