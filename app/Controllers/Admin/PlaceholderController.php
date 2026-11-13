<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PlaceholderController extends BaseController
{
    public function show(string $module)
    {
        $labels = [
            'coin-inventory'     => 'Coin Inventory',
            'coin-price'         => 'Coin Price Settings',
            'coin-purchases'     => 'Coin Purchases',
            'coin-locking'       => 'Coin Locking',
            'usdt-pending'       => 'USDT Deposits — Pending',
            'usdt-approved'      => 'USDT Deposits — Approved',
            'usdt-rejected'      => 'USDT Deposits — Rejected',
            'usdt-wallet'        => 'Wallet Settings',
            'mining'             => 'Mining Management',
            'referrals'          => 'Referral & Genealogy',
            'wallets'            => 'Wallet Management',
            'withdrawals'        => 'Withdrawals',
            'coin-selling'       => 'Coin Selling',
            'reports'            => 'Reports',
            'settings'           => 'Settings',
        ];

        if (! isset($labels[$module])) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        return view('admin/placeholders/coming_soon', [
            'title'      => $labels[$module],
            'moduleName' => $labels[$module],
        ]);
    }
}
