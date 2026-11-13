<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateMemberProfiles extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'member_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'mobile' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'usdt_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            // Coin-purchase activation status (separate from authentication).
            'account_status' => [
                'type'       => 'ENUM',
                'constraint' => ['inactive', 'active'],
                'default'    => 'inactive',
            ],
            'is_root_sponsor' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'default'    => 0,
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
        $this->forge->addUniqueKey('member_id');
        $this->forge->addUniqueKey('user_id');
        $this->forge->addKey('account_status');
        $this->forge->addKey('mobile');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('member_profiles', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('member_profiles', true);
    }
}
