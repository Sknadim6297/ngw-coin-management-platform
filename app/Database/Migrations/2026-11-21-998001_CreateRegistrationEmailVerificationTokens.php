<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateRegistrationEmailVerificationTokens extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'user_id' => ['type' => 'INT', 'unsigned' => true],
            'token_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'email_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'sent', 'failed', 'verified', 'expired'],
                'default'    => 'pending',
            ],
            'expires_at' => ['type' => 'DATETIME'],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
            'used_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token_hash');
        $this->forge->addKey(['user_id', 'created_at']);
        $this->forge->addKey(['status', 'expires_at']);
        $this->forge->addForeignKey('user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('registration_email_verification_tokens', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('registration_email_verification_tokens', true);
    }
}
