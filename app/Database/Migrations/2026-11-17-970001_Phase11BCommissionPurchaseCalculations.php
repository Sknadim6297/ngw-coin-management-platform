<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 11B: purchase-triggered commission calculations (preview / not posted).
 */
class Phase11BCommissionPurchaseCalculations extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('commission_purchase_calculations')) {
            return;
        }

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'calc_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'purchase_id' => ['type' => 'BIGINT', 'unsigned' => true, 'null' => true],
            'purchaser_user_id' => ['type' => 'INT', 'unsigned' => true],
            'purchaser_member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
            'sponsor_level' => ['type' => 'TINYINT', 'unsigned' => true],
            'beneficiary_user_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'beneficiary_member_id' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'commission_base_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
            'rate_percent' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'null' => true],
            'expected_amount_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
            'posted_amount_usdt' => ['type' => 'DECIMAL', 'constraint' => '24,8', 'default' => '0.00000000'],
            'plan_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'plan_effective_from' => ['type' => 'DATE', 'null' => true],
            'business_date' => ['type' => 'DATE'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 40],
            'eligibility_status' => ['type' => 'VARCHAR', 'constraint' => 40],
            'reason_codes' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'assumptions_json' => ['type' => 'TEXT', 'null' => true],
            'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 96],
            'created_by' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('calc_ref');
        $this->forge->addUniqueKey('idempotency_key');
        $this->forge->addUniqueKey(['purchase_ref', 'sponsor_level']);
        $this->forge->addKey(['beneficiary_user_id', 'business_date']);
        $this->forge->addKey(['status', 'business_date']);
        $this->forge->createTable('commission_purchase_calculations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('commission_purchase_calculations', true);
    }
}
