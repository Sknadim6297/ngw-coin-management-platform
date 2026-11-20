<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Financial Automation Control Center — additive run log.
 *
 * One row per scheduler attempt of a job (mining | commission) for a business date. Touches no wallet, ledger,
 * purchase or ownership table. The ON/OFF switches themselves live in `platform_settings`.
 */
class CreateFinancialAutomationRuns extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('financial_automation_runs')) {
            return;
        }

        $this->forge->addField([
            'id'            => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'run_ref'       => ['type' => 'VARCHAR', 'constraint' => 40],
            'job'           => ['type' => 'VARCHAR', 'constraint' => 24],
            'business_date' => ['type' => 'DATE'],
            'attempt'       => ['type' => 'INT', 'unsigned' => true, 'default' => 1],
            'status'        => ['type' => 'ENUM', 'constraint' => ['running', 'success', 'skipped', 'blocked', 'failed'], 'default' => 'running'],
            'message'       => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'credits_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'amount'        => ['type' => 'DECIMAL', 'constraint' => '28,8', 'default' => '0.00000000'],
            'summary_json'  => ['type' => 'TEXT', 'null' => true],
            'started_at'    => ['type' => 'DATETIME'],
            'finished_at'   => ['type' => 'DATETIME', 'null' => true],
            'created_at'    => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('run_ref');
        $this->forge->addKey(['job', 'business_date', 'status']);
        $this->forge->addKey(['status', 'started_at']);
        $this->forge->createTable('financial_automation_runs', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('financial_automation_runs', true);
    }
}
