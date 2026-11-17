<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 9C: platform settings (key/value), settings audit, support tickets.
 */
class CreatePlatformSettingsAndSupportTickets extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'setting_value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'updated_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
        $this->forge->addUniqueKey('setting_key');
        $this->forge->createTable('platform_settings', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'setting_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'previous_value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'new_value' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'changed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'changed_at' => [
                'type' => 'DATETIME',
            ],
            'ip_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 45,
                'null'       => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('setting_key');
        $this->forge->addKey('changed_by');
        $this->forge->addKey('changed_at');
        $this->forge->addForeignKey('changed_by', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('settings_audit', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'ticket_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'member_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'member_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'category' => [
                'type'       => 'ENUM',
                'constraint' => ['account', 'payment', 'coin_purchase', 'wallet', 'other'],
                'default'    => 'other',
            ],
            'subject' => [
                'type'       => 'VARCHAR',
                'constraint' => 180,
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['open', 'in_progress', 'resolved', 'closed'],
                'default'    => 'open',
            ],
            'last_reply_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_reply_by' => [
                'type'       => 'ENUM',
                'constraint' => ['member', 'admin'],
                'null'       => true,
            ],
            'closed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'closed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
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
        $this->forge->addUniqueKey('ticket_ref');
        $this->forge->addKey('member_user_id');
        $this->forge->addKey('member_id');
        $this->forge->addKey('status');
        $this->forge->addKey('category');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('support_tickets', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'ticket_id' => [
                'type'     => 'BIGINT',
                'unsigned' => true,
            ],
            'ticket_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'sender_role' => [
                'type'       => 'ENUM',
                'constraint' => ['member', 'admin'],
            ],
            'sender_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'message' => [
                'type' => 'TEXT',
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('ticket_id');
        $this->forge->addKey('ticket_ref');
        $this->forge->addKey('created_at');
        $this->forge->addForeignKey('ticket_id', 'support_tickets', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('sender_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('support_ticket_messages', true);

        $now = date('Y-m-d H:i:s');
        $defaults = [
            'platform_name'                 => 'Next Generation Wealth',
            'platform_logo'                 => '',
            'contact_email'                 => '',
            'support_contact'               => '',
            'timezone'                      => 'UTC',
            'date_format'                   => 'Y-m-d H:i',
            'registration_enabled'          => '1',
            'sponsor_required'              => '1',
            'registration_instructions'     => '',
            'notify_admin_alerts'           => '1',
            'notify_member_events'          => '0',
            'notify_payment_submission'     => '1',
            'notify_purchase_approval'      => '1',
        ];
        foreach ($defaults as $key => $value) {
            $this->db->table('platform_settings')->insert([
                'setting_key'   => $key,
                'setting_value' => $value,
                'updated_by'    => null,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable('support_ticket_messages', true);
        $this->forge->dropTable('support_tickets', true);
        $this->forge->dropTable('settings_audit', true);
        $this->forge->dropTable('platform_settings', true);
    }
}
