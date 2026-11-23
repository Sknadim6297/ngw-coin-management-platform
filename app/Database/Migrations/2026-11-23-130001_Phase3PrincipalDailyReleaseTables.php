<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 3 — tables for daily principal release posting (empty until posting is authorized).
 * Read-only schedule visibility does not require rows here.
 */
class Phase3PrincipalDailyReleaseTables extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('member_principal_daily_releases')) {
            $this->forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
                'release_ref' => ['type' => 'VARCHAR', 'constraint' => 48],
                'lock_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
                'purchase_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'member_user_id' => ['type' => 'INT', 'unsigned' => true],
                'member_id' => ['type' => 'VARCHAR', 'constraint' => 32],
                'business_date' => ['type' => 'DATE'],
                'day_index' => ['type' => 'INT', 'unsigned' => true],
                'original_principal' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'released_quantity' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'cumulative_released' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'remaining_locked' => ['type' => 'DECIMAL', 'constraint' => '24,8'],
                'asset_code' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'COIN'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'posted'],
                'ownership_txn_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
                'idempotency_key' => ['type' => 'VARCHAR', 'constraint' => 80],
                'created_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $this->forge->addKey('id', true);
            $this->forge->addUniqueKey('release_ref');
            $this->forge->addUniqueKey('idempotency_key');
            $this->forge->addUniqueKey(['lock_ref', 'business_date']);
            $this->forge->addKey(['member_user_id', 'business_date']);
            $this->forge->addKey('purchase_ref');
            $this->forge->createTable('member_principal_daily_releases', true);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('member_principal_daily_releases', true);
    }
}
