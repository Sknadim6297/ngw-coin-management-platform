<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Explicit 1:1 deposit-to-purchase payment allocations.
 * Split payments / refunds are not supported in Phase 4B.
 */
class CreateUsdtPaymentAllocations extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'allocation_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'deposit_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'purchase_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'member_user_id' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'amount_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
            ],
            'status' => [
                'type'       => 'ENUM',
                'constraint' => ['linked', 'consumed', 'released'],
                'default'    => 'linked',
            ],
            'linked_by' => [
                'type'     => 'INT',
                'unsigned' => true,
            ],
            'consumed_by' => [
                'type'     => 'INT',
                'unsigned' => true,
                'null'     => true,
            ],
            'linked_at' => [
                'type' => 'DATETIME',
            ],
            'consumed_at' => [
                'type' => 'DATETIME',
                'null' => true,
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
        $this->forge->addUniqueKey('allocation_ref');
        $this->forge->addUniqueKey('deposit_ref');
        $this->forge->addUniqueKey('purchase_ref');
        $this->forge->addKey('member_user_id');
        $this->forge->addKey('status');
        $this->forge->addForeignKey('member_user_id', 'users', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('usdt_payment_allocations', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('usdt_payment_allocations', true);
    }
}
