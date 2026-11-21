<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 10A: pending email verification tokens + immutable USDT wallet change audit.
 */
class MemberProfileEmailWalletSecurity extends Migration
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
            'current_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'new_email' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'token_hash' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'used_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'cancelled_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey('user_id');
        $this->forge->addKey('new_email');
        $this->forge->addKey('expires_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('member_email_change_tokens', true);

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
            'previous_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'new_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'cooldown_until' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
            'user_agent' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('user_id');
        $this->forge->addKey('member_id');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('member_wallet_change_events', true);

        if ($this->db->tableExists('member_profiles')) {
            $fields = $this->db->getFieldNames('member_profiles');
            $add    = [];
            if (! in_array('usdt_wallet_changed_at', $fields, true)) {
                $add['usdt_wallet_changed_at'] = [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after'=> 'usdt_wallet',
                ];
            }
            if (! in_array('usdt_wallet_cooldown_until', $fields, true)) {
                $add['usdt_wallet_cooldown_until'] = [
                    'type' => 'DATETIME',
                    'null' => true,
                    'after'=> 'usdt_wallet_changed_at',
                ];
            }
            if (! in_array('pending_email', $fields, true)) {
                $add['pending_email'] = [
                    'type'       => 'VARCHAR',
                    'constraint' => 255,
                    'null'       => true,
                    'after'      => 'mobile',
                ];
            }
            if ($add !== []) {
                $this->forge->addColumn('member_profiles', $add);
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('member_profiles')) {
            $fields = $this->db->getFieldNames('member_profiles');
            foreach (['pending_email', 'usdt_wallet_cooldown_until', 'usdt_wallet_changed_at'] as $col) {
                if (in_array($col, $fields, true)) {
                    try {
                        $this->forge->dropColumn('member_profiles', $col);
                    } catch (\Throwable) {
                        // Safe for PHPUnit migrate-down refresh cycles.
                    }
                }
            }
            if (method_exists($this->db, 'resetDataCache')) {
                $this->db->resetDataCache();
            }
        }
        $this->forge->dropTable('member_wallet_change_events', true);
        $this->forge->dropTable('member_email_change_tokens', true);
    }
}
