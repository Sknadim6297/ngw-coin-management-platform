<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4E: notification outbox + remediation reason codes / evidence path.
 */
class CreateNotificationOutboxAndRemediationExtras extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'outbox_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'email'],
            'dedupe_key' => ['type' => 'VARCHAR', 'constraint' => 190],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['pending', 'sending', 'sent', 'failed', 'cancelled'],
                'default'    => 'pending',
            ],
            'severity' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'warning'],
            'subject' => ['type' => 'VARCHAR', 'constraint' => 255],
            'body_text' => ['type' => 'TEXT'],
            'recipients_redacted' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'alert_ref' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true],
            'attempt_count' => ['type' => 'INT', 'unsigned' => true, 'default' => 0],
            'next_attempt_at' => ['type' => 'DATETIME', 'null' => true],
            'last_error' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'sent_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('outbox_ref');
        $this->forge->addUniqueKey('dedupe_key');
        $this->forge->addKey(['status', 'next_attempt_at']);
        $this->forge->createTable('notification_outbox', true);

        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'outbox_ref' => ['type' => 'VARCHAR', 'constraint' => 40],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'detail' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addKey('outbox_ref');
        $this->forge->createTable('notification_delivery_events', true);

        $this->forge->addColumn('financial_remediation_proposals', [
            'reason_code' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'remediation_type',
            ],
            'evidence_stored_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'evidence_notes',
            ],
            'evidence_original_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'evidence_stored_name',
            ],
            'evidence_mime' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
                'after'      => 'evidence_original_name',
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->dropColumn('financial_remediation_proposals', [
            'reason_code',
            'evidence_stored_name',
            'evidence_original_name',
            'evidence_mime',
        ]);
        $this->forge->dropTable('notification_delivery_events', true);
        $this->forge->dropTable('notification_outbox', true);
    }
}
