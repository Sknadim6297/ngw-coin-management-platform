<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;

class DashboardController extends BaseController
{
    public function index()
    {
        $userId = (int) auth()->id();

        try {
            $dashboard = service('memberDashboardService')->build($userId);
        } catch (\RuntimeException $e) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/dashboard/index', [
            'title'            => 'Member Dashboard',
            'kpis'             => $dashboard['kpis'],
            'charts'           => $dashboard['charts'],
            'profile'          => $dashboard['profile'],
            'activation'       => $dashboard['activation'],
            'walletSummary'    => $dashboard['wallet_summary'],
            'pendingPayments'  => $dashboard['pending_payments'],
            'recentPurchases'  => $dashboard['recent_purchases'],
            'referralSummary'  => $dashboard['referral_summary'],
            'referralLink'     => $dashboard['referral_link'],
            'priceSummary'     => $dashboard['price_summary'],
            'inventory'        => $dashboard['inventory'],
            'activeLocks'      => $dashboard['active_locks'],
            'maturedLocks'     => $dashboard['matured_locks'],
            'recentLocks'      => $dashboard['recent_locks'],
            'recentWallet'     => $dashboard['recent_wallet'],
            'timezone'         => $dashboard['timezone'],
            'todayStart'       => $dashboard['today_start'],
            'todayEnd'         => $dashboard['today_end'],
            'generatedAt'      => $dashboard['generated_at'],
        ]);
    }
}
