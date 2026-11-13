<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Links purchases to verified deposits for Phase 4 payment safety.
 * Existing approved purchases remain intact; new approvals may require a verified deposit.
 */
class AddPurchasePaymentSafetyColumns extends Migration
{
    public function up(): void
    {
        $this->forge->addColumn('coin_purchase_requests', [
            'payment_deposit_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
                'null'       => true,
                'after'      => 'ownership_transaction_ref',
            ],
            'payment_status' => [
                'type'       => 'ENUM',
                'constraint' => ['unpaid', 'deposit_linked', 'verified'],
                'default'    => 'unpaid',
                'after'      => 'payment_deposit_ref',
            ],
        ]);
        $this->db->query('CREATE INDEX coin_purchase_requests_payment_deposit_ref ON coin_purchase_requests (payment_deposit_ref)');
        $this->db->query('CREATE INDEX coin_purchase_requests_payment_status ON coin_purchase_requests (payment_status)');
    }

    public function down(): void
    {
        $this->forge->dropColumn('coin_purchase_requests', ['payment_deposit_ref', 'payment_status']);
    }
}
