<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4C: verification attempt tracking and integrity flags (additive).
 */
class ExtendDepositsForPaymentOps extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('usdt_deposit_requests', [
            'last_verification_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
                'after'      => 'verified_confirmations',
            ],
            'last_verification_message' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'last_verification_status',
            ],
            'last_verification_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'last_verification_message',
            ],
            'verification_attempt_count' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 0,
                'after'    => 'last_verification_at',
            ],
            'next_verification_after' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'verification_attempt_count',
            ],
            'integrity_flag' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'next_verification_after',
                'comment'    => 'null|ok|reorg_suspected|needs_investigation',
            ],
            'integrity_checked_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'integrity_flag',
            ],
            'integrity_notes' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'integrity_checked_at',
            ],
        ]);

        $this->db->query(
            'CREATE INDEX usdt_deposit_requests_last_verify ON usdt_deposit_requests (last_verification_status, last_verification_at)'
        );
        $this->db->query(
            'CREATE INDEX usdt_deposit_requests_integrity ON usdt_deposit_requests (integrity_flag)'
        );
    }

    public function down(): void
    {
        foreach ([
            'last_verification_status',
            'last_verification_message',
            'last_verification_at',
            'verification_attempt_count',
            'next_verification_after',
            'integrity_flag',
            'integrity_checked_at',
            'integrity_notes',
        ] as $col) {
            if ($this->db->tableExists('usdt_deposit_requests') && $this->db->fieldExists($col, 'usdt_deposit_requests')) {
                try {
                    $this->forge->dropColumn('usdt_deposit_requests', $col);
                } catch (\Throwable) {
                }
            }
        }
    }
}
