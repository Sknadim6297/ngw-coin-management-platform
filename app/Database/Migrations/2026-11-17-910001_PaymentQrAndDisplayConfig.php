<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Admin USDT QR upload fields + proposal staging columns.
 * Demo / burn destinations are marked and disabled for live member payments.
 */
class PaymentQrAndDisplayConfig extends Migration
{
    public function up(): void
    {
        if ($this->db->tableExists('usdt_payment_networks')) {
            $this->addColumnIfMissing('usdt_payment_networks', 'is_demo', [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
                'null'       => false,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'display_status', [
                'type'       => 'VARCHAR',
                'constraint' => 24,
                'default'    => 'hidden',
                'null'       => false,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'qr_stored_name', [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'qr_mime', [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'qr_source', [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'none',
                'null'       => false,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'qr_decoded_payload', [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('usdt_payment_networks', 'qr_verified_at', [
                'type' => 'DATETIME',
                'null' => true,
            ]);

            // Field-name cache may be stale after addColumn — refresh before UPDATE.
            if (method_exists($this->db, 'resetDataCache')) {
                $this->db->resetDataCache();
            }
            $fields = $this->db->getFieldNames('usdt_payment_networks');
            if (in_array('is_demo', $fields, true) && in_array('display_status', $fields, true)) {
                try {
                    $this->db->query(
                        "UPDATE usdt_payment_networks
                         SET is_demo = 1,
                             is_enabled = 0,
                             display_status = 'hidden'
                         WHERE LOWER(destination_wallet) IN (
                             '0x000000000000000000000000000000000000dead',
                             '0x0000000000000000000000000000000000000000'
                         )
                         OR LOWER(network_key) LIKE '%demo%'
                         OR LOWER(network_name) LIKE '%demo%'
                         OR LOWER(network_name) LIKE '%test%evm%'"
                    );

                    $this->db->query(
                        "UPDATE usdt_payment_networks
                         SET display_status = CASE
                             WHEN is_demo = 1 THEN 'hidden'
                             WHEN is_enabled = 1 AND destination_wallet IS NOT NULL AND destination_wallet <> '' THEN 'live'
                             ELSE display_status
                         END"
                    );
                } catch (\Throwable) {
                    // Non-fatal on partially migrated schemas during tests.
                }
            }
        }

        if ($this->db->tableExists('payment_destination_change_proposals')) {
            $this->addColumnIfMissing('payment_destination_change_proposals', 'min_deposit_usdt', [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'max_deposit_usdt', [
                'type'       => 'DECIMAL',
                'constraint' => '24,8',
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'qr_mode', [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'keep',
                'null'       => false,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'qr_staging_stored_name', [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'qr_staging_mime', [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'qr_decoded_payload', [
                'type'       => 'VARCHAR',
                'constraint' => 512,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'currency_code', [
                'type'       => 'VARCHAR',
                'constraint' => 16,
                'default'    => 'USDT',
                'null'       => false,
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('usdt_payment_networks')) {
            foreach (['qr_verified_at', 'qr_decoded_payload', 'qr_source', 'qr_mime', 'qr_stored_name', 'display_status', 'is_demo'] as $col) {
                $this->dropColumnIfExists('usdt_payment_networks', $col);
            }
        }

        if ($this->db->tableExists('payment_destination_change_proposals')) {
            foreach (['currency_code', 'qr_decoded_payload', 'qr_staging_mime', 'qr_staging_stored_name', 'qr_mode', 'max_deposit_usdt', 'min_deposit_usdt'] as $col) {
                $this->dropColumnIfExists('payment_destination_change_proposals', $col);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function addColumnIfMissing(string $table, string $column, array $definition): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }
        $this->forge->addColumn($table, [$column => $definition]);
        $this->forgetFieldCache();
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (! $this->columnExists($table, $column)) {
            return;
        }
        try {
            $this->forge->dropColumn($table, $column);
        } catch (\Throwable) {
            // Safe for PHPUnit migrate-down refresh cycles.
        }
        $this->forgetFieldCache();
    }

    private function columnExists(string $table, string $column): bool
    {
        $this->forgetFieldCache();

        return in_array($column, $this->db->getFieldNames($table), true);
    }

    private function forgetFieldCache(): void
    {
        if (method_exists($this->db, 'resetDataCache')) {
            $this->db->resetDataCache();
        }
    }
}
