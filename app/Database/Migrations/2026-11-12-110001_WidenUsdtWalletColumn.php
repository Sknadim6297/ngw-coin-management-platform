<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class WidenUsdtWalletColumn extends Migration
{
    public function up(): void
    {
        $this->forge->modifyColumn('member_profiles', [
            'usdt_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);
    }

    public function down(): void
    {
        $this->forge->modifyColumn('member_profiles', [
            'usdt_wallet' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
        ]);
    }
}
