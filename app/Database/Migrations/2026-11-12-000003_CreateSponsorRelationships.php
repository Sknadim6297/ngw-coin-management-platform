<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSponsorRelationships extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'member_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'sponsor_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'sponsor_member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
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
        $this->forge->addUniqueKey('member_user_id');
        $this->forge->addKey('sponsor_user_id');
        $this->forge->addKey('sponsor_member_id');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('sponsor_user_id', 'users', 'id', 'RESTRICT', 'CASCADE');
        $this->forge->createTable('sponsor_relationships', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('sponsor_relationships', true);
    }
}
