<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;

class PlaceholderController extends BaseController
{
    /**
     * Modules that require an active (coin-purchase activated) account.
     *
     * @var list<string>
     */
    private array $requiresActive = [
        'mining',
        'mining-wallet',
        'referrals',
        'level-income',
        'commission-wallet',
    ];

    public function show(string $module)
    {
        if ($module === 'support') {
            return redirect()->to(site_url('member/support'));
        }
        if ($module === 'p2p') {
            return redirect()->to(site_url('member/p2p'));
        }

        $labels = [
            'buy-coins'         => 'Buy Coins',
            'my-coins'          => 'My Coins',
            'mining'            => 'Daily Mining',
            'mining-wallet'     => 'Mining Wallet',
            'p2p'               => 'P2P Transfer',
            'referrals'         => 'My Referrals',
            'level-income'      => 'Level Income',
            'commission-wallet' => 'Commission Wallet',
            'withdrawal'        => 'Withdrawal',
            'transactions'      => 'Transaction History',
        ];

        if (! isset($labels[$module])) {
            throw \CodeIgniter\Exceptions\PageNotFoundException::forPageNotFound();
        }

        $profile = model(MemberProfileModel::class)->findByUserId((int) auth()->id());
        $blocked = in_array($module, $this->requiresActive, true)
            && ($profile === null || $profile->isInactive());

        return view('member/placeholders/coming_soon', [
            'title'           => $labels[$module],
            'moduleName'      => $labels[$module],
            'activationBlocked' => $blocked,
            'profile'         => $profile,
        ]);
    }
}
