<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMemberIdSequences extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'prefix' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'last_number' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
                'default'  => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('prefix');
        $this->forge->createTable('member_id_sequences', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('member_id_sequences', true);
    }
}
