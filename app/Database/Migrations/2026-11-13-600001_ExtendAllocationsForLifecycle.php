<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 4D: allocation release fields + active lock keys (unique only while linked/consumed).
 */
class ExtendAllocationsForLifecycle extends Migration
{
    public function up(): void
    {
        // Drop unique constraints on deposit_ref / purchase_ref so released rows can keep history
        // while a new active allocation may reuse the same deposit/purchase later.
        try {
            $this->db->query('ALTER TABLE usdt_payment_allocations DROP INDEX deposit_ref');
        } catch (\Throwable $e) {
            // Index name may vary; ignore if already absent.
        }
        try {
            $this->db->query('ALTER TABLE usdt_payment_allocations DROP INDEX purchase_ref');
        } catch (\Throwable $e) {
            // ignore
        }

        $this->forge->addColumn('usdt_payment_allocations', [
            'deposit_active_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'deposit_ref',
            ],
            'purchase_active_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'purchase_ref',
            ],
            'released_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
                'after'    => 'consumed_by',
            ],
            'released_at' => [
                'type'  => 'DATETIME',
                'null'  => true,
                'after' => 'consumed_at',
            ],
            'release_reason' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
                'after'      => 'released_at',
            ],
        ]);

        // Backfill active keys for existing linked/consumed rows.
        $this->db->query(
            "UPDATE usdt_payment_allocations
             SET deposit_active_key = deposit_ref,
                 purchase_active_key = purchase_ref
             WHERE status IN ('linked', 'consumed')"
        );

        $this->db->query(
            'CREATE UNIQUE INDEX usdt_alloc_active_deposit ON usdt_payment_allocations (deposit_active_key)'
        );
        $this->db->query(
            'CREATE UNIQUE INDEX usdt_alloc_active_purchase ON usdt_payment_allocations (purchase_active_key)'
        );
        $this->db->query(
            'CREATE INDEX usdt_alloc_deposit_ref ON usdt_payment_allocations (deposit_ref)'
        );
        $this->db->query(
            'CREATE INDEX usdt_alloc_purchase_ref ON usdt_payment_allocations (purchase_ref)'
        );
    }

    public function down(): void
    {
        foreach ([
            'deposit_active_key',
            'purchase_active_key',
            'released_by',
            'released_at',
            'release_reason',
        ] as $col) {
            if ($this->db->tableExists('usdt_payment_allocations') && $this->db->fieldExists($col, 'usdt_payment_allocations')) {
                try {
                    $this->forge->dropColumn('usdt_payment_allocations', $col);
                } catch (\Throwable) {
                }
            }
        }
    }
}
