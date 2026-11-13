<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Administrator-managed USDT payment network configuration.
 * Networks are disabled by default until explicitly enabled.
 */
class CreateUsdtPaymentNetworks extends Migration
{
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'network_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 40,
            ],
            'network_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
            ],
            'token_symbol' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'default'    => 'USDT',
            ],
            'token_contract_address' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'token_decimals' => [
                'type'     => 'TINYINT',
                'unsigned' => true,
                'default'  => 18,
            ],
            'destination_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 128,
                'null'       => true,
            ],
            'address_pattern' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'tx_hash_pattern' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'required_confirmations' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 12,
            ],
            'min_deposit_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
                'default'    => '5.00000000',
            ],
            'max_deposit_usdt' => [
                'type'       => 'DECIMAL',
                'constraint' => '18,8',
                'default'    => '100000.00000000',
            ],
            'instructions' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'is_enabled' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'sort_order' => [
                'type'     => 'INT',
                'unsigned' => true,
                'default'  => 100,
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
        $this->forge->addUniqueKey('network_key');
        $this->forge->addKey('is_enabled');
        $this->forge->createTable('usdt_payment_networks', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('usdt_payment_networks', true);
    }
}
