<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;

class CommissionWalletController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        service('commissionWalletService')->ensureAccount($userId, (string) $profile->member_id);
        $account = service('commissionWalletService')->getAccount($userId);
        $entries = service('commissionWalletService')->recentEntries($userId, 20);
        $pending = service('commissionWithdrawalService')->listForMember($userId, 10);
        $sim     = service('commissionSimulationService')->memberSnapshot($userId);
        $wallet  = service('commissionWalletService');

        $kpis = [
            KpiCardFactory::ok('available', 'Available Commission Balance', $account['available_balance'], 'green', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('reserved', 'Reserved Commission Balance', $account['reserved_balance'], 'orange', 'bi-lock', 'usdt'),
            KpiCardFactory::ok('total', 'Total Commission Balance', $account['total_balance'], 'blue', 'bi-wallet2', 'usdt'),
            KpiCardFactory::ok('status', 'Commission Wallet Status', $wallet->postingEnabled() ? 'Posting ON' : 'Posting Disabled', 'indigo', 'bi-info-circle', null),
            KpiCardFactory::ok('sim_total', 'Simulation Preview (Not Payable)', $sim['total_preview'], 'purple', 'bi-graph-up', 'usdt', site_url('member/commissions')),
        ];

        return view('member/commission_wallet/index', [
            'title'   => 'Commission Wallet',
            'profile' => $profile,
            'kpis'    => $kpis,
            'account' => $account,
            'entries' => $entries,
            'pending' => $pending,
            'sim'     => $sim,
            'wdStatus'=> service('commissionWithdrawalService')->activationStatus(),
        ]);
    }
}
