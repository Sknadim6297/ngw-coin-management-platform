<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class PlaceholderController extends BaseController
{
    public function show(string $module)
    {
        $redirects = [
            'reports'        => 'admin/reports',
            'settings'       => 'admin/settings',
            'coin-inventory' => 'admin/coins/inventory',
            'coin-price'     => 'admin/coins/price',
            'coin-purchases' => 'admin/payment-approvals',
            'coin-locking'   => 'admin/locking',
            'usdt-pending'   => 'admin/payment-approvals?tab=pending',
            'usdt-approved'  => 'admin/payment-approvals?tab=completed',
            'usdt-rejected'  => 'admin/payment-approvals?tab=rejected',
            'usdt-wallet'    => 'admin/payment-details',
            'mining'         => 'admin/mining',
            'referrals'      => 'admin/referrals',
            'wallets'        => 'admin/wallets',
            'withdrawals'    => 'admin/withdrawals',
        ];

        if (isset($redirects[$module])) {
            return redirect()->to(site_url($redirects[$module]));
        }

        if ($module === 'coin-selling') {
            return view('admin/placeholders/coming_soon', [
                'title'      => 'Coin Selling',
                'moduleName' => 'Coin Selling',
            ]);
        }

        throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
    }
}
