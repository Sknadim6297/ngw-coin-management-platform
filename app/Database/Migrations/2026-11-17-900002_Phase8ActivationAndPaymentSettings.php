<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 8: activation metadata + dual-control payment destination change proposals.
 */
class Phase8ActivationAndPaymentSettings extends Migration
{
    public function up(): void
    {
        // Field-name cache goes stale across migrate down/up refresh cycles.
        if (method_exists($this->db, 'resetDataCache')) {
            $this->db->resetDataCache();
        }

        $fields = $this->db->getFieldNames('member_profiles');
        if (! in_array('activated_at', $fields, true)) {
            $this->forge->addColumn('member_profiles', [
                'activated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after' => 'account_status',
                ],
            ]);
            if (method_exists($this->db, 'resetDataCache')) {
                $this->db->resetDataCache();
            }
        }

        $fields = $this->db->getFieldNames('member_profiles');
        if (! in_array('activation_purchase_ref', $fields, true)) {
            $this->forge->addColumn('member_profiles', [
                'activation_purchase_ref' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 40,
                    'null'       => true,
                    'after'      => 'activated_at',
                ],
            ]);
            if (method_exists($this->db, 'resetDataCache')) {
                $this->db->resetDataCache();
            }
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'proposal_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'network_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'network_key' => ['type' => 'VARCHAR', 'constraint' => 40],
            'from_destination_wallet' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
            'to_destination_wallet' => ['type' => 'VARCHAR', 'constraint' => 128],
            'instructions' => ['type' => 'TEXT', 'null' => true],
            'display_enabled' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'status' => ['type' => 'ENUM', 'constraint' => ['pending', 'approved', 'rejected', 'cancelled'], 'default' => 'pending'],
            'proposed_by' => ['type' => 'INT', 'unsigned' => true],
            'approved_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'approved_at' => ['type' => 'DATETIME', 'null' => true],
            'rejected_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'rejected_at' => ['type' => 'DATETIME', 'null' => true],
            'rejection_reason' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('proposal_ref');
        $this->forge->addKey(['network_id', 'status']);
        $this->forge->createTable('payment_destination_change_proposals', true);

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
        $this->forge->createTable('payment_destination_change_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('payment_destination_change_events', true);
        $this->forge->dropTable('payment_destination_change_proposals', true);

        if ($this->db->tableExists('member_profiles')) {
            if (method_exists($this->db, 'resetDataCache')) {
                $this->db->resetDataCache();
            }
            $fields = $this->db->getFieldNames('member_profiles');
            if (in_array('activation_purchase_ref', $fields, true)) {
                $this->forge->dropColumn('member_profiles', 'activation_purchase_ref');
                if (method_exists($this->db, 'resetDataCache')) {
                    $this->db->resetDataCache();
                }
            }
            $fields = $this->db->getFieldNames('member_profiles');
            if (in_array('activated_at', $fields, true)) {
                $this->forge->dropColumn('member_profiles', 'activated_at');
                if (method_exists($this->db, 'resetDataCache')) {
                    $this->db->resetDataCache();
                }
            }
        }
    }
}
