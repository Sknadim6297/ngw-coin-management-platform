<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Append-only deposit status / review event log.
 */
class CreateUsdtDepositEvents extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'deposit_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'deposit_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'actor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 80,
            ],
            'previous_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'new_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
            ],
            'metadata' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('deposit_id');
        $this->forge->addKey('deposit_ref');
        $this->forge->addKey('event_type');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('deposit_id', 'usdt_deposit_requests', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('usdt_deposit_events', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('usdt_deposit_events', true);
    }
}
