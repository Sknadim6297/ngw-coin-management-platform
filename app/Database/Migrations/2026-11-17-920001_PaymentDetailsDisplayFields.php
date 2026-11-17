<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Display fields for simplified BEP20 Payment Details page.
 */
class PaymentDetailsDisplayFields extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('usdt_payment_networks')) {
            return;
        }

        $this->addColumnIfMissing('usdt_payment_networks', 'recipient_name', [
            'type'       => 'VARCHAR',
            'constraint' => 120,
            'null'       => true,
        ]);
        $this->addColumnIfMissing('usdt_payment_networks', 'logo_stored_name', [
            'type'       => 'VARCHAR',
            'constraint' => 120,
            'null'       => true,
        ]);
        $this->addColumnIfMissing('usdt_payment_networks', 'logo_mime', [
            'type'       => 'VARCHAR',
            'constraint' => 64,
            'null'       => true,
        ]);

        if ($this->db->tableExists('payment_destination_change_proposals')) {
            $this->addColumnIfMissing('payment_destination_change_proposals', 'recipient_name', [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ]);
            $this->addColumnIfMissing('payment_destination_change_proposals', 'token_contract_address', [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ]);
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('usdt_payment_networks')) {
            foreach (['logo_mime', 'logo_stored_name', 'recipient_name'] as $col) {
                $this->dropColumnIfExists('usdt_payment_networks', $col);
            }
        }
        if ($this->db->tableExists('payment_destination_change_proposals')) {
            foreach (['token_contract_address', 'recipient_name'] as $col) {
                $this->dropColumnIfExists('payment_destination_change_proposals', $col);
            }
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    private function addColumnIfMissing(string $table, string $column, array $definition): void
    {
        if (in_array($column, $this->db->getFieldNames($table), true)) {
            return;
        }
        $this->forge->addColumn($table, [$column => $definition]);
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (! in_array($column, $this->db->getFieldNames($table), true)) {
            return;
        }
        try {
            $this->forge->dropColumn($table, $column);
        } catch (\Throwable) {
        }
    }
}
