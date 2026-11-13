<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4C: append-only RPC/health snapshots. Never store RPC secrets.
 */
class CreateBlockchainHealthSnapshots extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'scope' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
                'default'    => 'global',
            ],
            'network_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'rpc_host_fingerprint' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'comment'    => 'sha256 of host only — never full URL/credentials',
            ],
            'rpc_host_redacted' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'expected_chain_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'reported_chain_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'head_block_number' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'null'     => true,
            ],
            'latency_ms' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'unavailable',
            ],
            'error_category' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'error_summary' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'checked_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'checked_at' => [
                'type' => 'DATETIME',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey(['scope', 'checked_at']);
        $this->forge->addKey('status');
        $this->forge->createTable('blockchain_health_snapshots', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('blockchain_health_snapshots', true);
    }
}
