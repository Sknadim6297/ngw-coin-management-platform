<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;

class CommissionWalletController extends BaseController
{
    public function index()
    {
        if (! $this->can('commission.wallet.view')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $wallet = service('commissionWalletService')->platformSnapshot();
        $recon  = service('commissionWalletReconciliationService')->run();
        $wd     = service('commissionWithdrawalService');

        $kpis = [
            KpiCardFactory::ok('accounts', 'Commission Wallet Accounts', $wallet['account_count'], 'blue', 'bi-wallet2', 'accounts'),
            KpiCardFactory::ok('credits', 'Total Actual Commission Credits', $wallet['total_credits'], 'teal', 'bi-plus-circle', 'usdt'),
            KpiCardFactory::ok('available', 'Total Available Commission', $wallet['total_available'], 'green', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('reserved', 'Total Reserved Commission', $wallet['total_reserved'], 'orange', 'bi-lock', 'usdt'),
            KpiCardFactory::ok('withdrawals', 'Withdrawal Requests', $wd->countByStatus(), 'cyan', 'bi-cash-coin', 'requests', site_url('admin/withdrawals')),
            KpiCardFactory::ok('pending_wd', 'Pending Withdrawal Reviews', $wd->countByStatus('submitted') + $wd->countByStatus('under_review'), 'amber', 'bi-hourglass', 'requests'),
            KpiCardFactory::ok('posting', 'Commission Posting Status', $wallet['posting_status'], 'purple', 'bi-toggle-off', null),
            KpiCardFactory::ok('wd_status', 'Withdrawal Activation Status', $wallet['withdrawal_status'], 'indigo', 'bi-slash-circle', null),
            KpiCardFactory::ok('integrity', 'Commission Ledger Integrity Alerts', $recon['issue_count'], 'red', 'bi-shield-exclamation', 'issues', site_url('admin/commission-wallet/reconcile')),
        ];

        return view('admin/commission_wallet/index', [
            'title'  => 'Commission Wallet',
            'kpis'   => $kpis,
            'wallet' => $wallet,
            'recon'  => $recon,
        ]);
    }

    public function reconcile()
    {
        if (! $this->can('commission.wallet.reconcile')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        return view('admin/commission_wallet/reconcile', [
            'title'  => 'Commission Wallet Reconciliation',
            'result' => service('commissionWalletReconciliationService')->run(),
        ]);
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
