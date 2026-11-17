<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11D admin withdrawal management (mutations kill-switched OFF).
 */
class WithdrawalController extends BaseController
{
    public function index()
    {
        if (! $this->can('commission.withdrawal.view')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $filters = [
            'status'   => trim((string) $this->request->getGet('status')),
            'member_q' => trim((string) $this->request->getGet('member_q')),
        ];
        $counts = service('commissionWithdrawalService')->adminStatusCounts();
        $status = service('commissionWithdrawalService')->status();

        $kpis = [
            KpiCardFactory::ok('pending', 'Pending Review', (string) ($counts['pending_admin_review'] ?? 0), 'amber', 'bi-hourglass', 'requests'),
            KpiCardFactory::ok('approved', 'Approved', (string) ($counts['approved'] ?? 0), 'cyan', 'bi-check2', 'requests'),
            KpiCardFactory::ok('progress', 'Payout In Progress', (string) ($counts['payout_in_progress'] ?? 0), 'indigo', 'bi-arrow-repeat', 'requests'),
            KpiCardFactory::ok('submitted', 'Payout Submitted', (string) ($counts['payout_submitted'] ?? 0), 'blue', 'bi-send', 'requests'),
            KpiCardFactory::ok('paid', 'Paid', (string) ($counts['paid'] ?? 0), 'green', 'bi-cash-coin', 'requests'),
            KpiCardFactory::ok('rejected', 'Rejected', (string) ($counts['rejected'] ?? 0), 'orange', 'bi-x-circle', 'requests'),
            KpiCardFactory::ok('failed', 'Failed', (string) ($counts['failed'] ?? 0), 'red', 'bi-exclamation-triangle', 'requests'),
            KpiCardFactory::unavailable('wd', 'Execution', 'gold', 'bi-slash-circle', 'Withdrawals Not Activated'),
        ];

        return view('admin/withdrawals/index', [
            'title'     => 'Commission Withdrawals',
            'rows'      => service('commissionWithdrawalService')->adminList($filters, 100),
            'filters'   => $filters,
            'counts'    => $counts,
            'kpis'      => $kpis,
            'status'    => $status,
            'canReview' => $this->can('commission.withdrawal.review'),
            'canPay'    => $this->can('commission.withdrawal.pay'),
            'enabled'   => service('commissionWalletService')->withdrawalEnabled(),
        ]);
    }

    public function show(string $ref)
    {
        if (! $this->can('commission.withdrawal.view')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $row = service('commissionWithdrawalService')->findByRef($ref);
        if ($row === null) {
            return redirect()->to(site_url('admin/withdrawals'))->with('error', 'Withdrawal not found.');
        }

        $wallet = service('commissionWalletService')->getAccount((int) $row['member_user_id']);
        $reconcile = service('commissionWithdrawalReconciliationService')->run(50);

        return view('admin/withdrawals/show', [
            'title'     => 'Withdrawal Detail',
            'row'       => $row,
            'wallet'    => $wallet,
            'status'    => service('commissionWithdrawalService')->status(),
            'canReview' => $this->can('commission.withdrawal.review'),
            'canPay'    => $this->can('commission.withdrawal.pay'),
            'enabled'   => service('commissionWalletService')->withdrawalEnabled(),
            'reconcile' => $reconcile,
        ]);
    }

    public function approve(string $ref)
    {
        if (! $this->can('commission.withdrawal.review')) {
            return redirect()->to(site_url('admin/withdrawals'))->with('error', 'Permission denied.');
        }
        try {
            service('commissionWithdrawalService')->approve(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    public function reject(string $ref)
    {
        if (! $this->can('commission.withdrawal.review')) {
            return redirect()->to(site_url('admin/withdrawals'))->with('error', 'Permission denied.');
        }
        try {
            service('commissionWithdrawalService')->reject(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('reason'),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    public function recordPayout(string $ref)
    {
        if (! $this->can('commission.withdrawal.pay')) {
            return redirect()->to(site_url('admin/withdrawals'))->with('error', 'Permission denied.');
        }
        try {
            service('commissionWithdrawalService')->recordManualPayout(
                $ref,
                (int) auth()->id(),
                [
                    'tx_hash'      => (string) $this->request->getPost('tx_hash'),
                    'paid_amount'  => (string) $this->request->getPost('paid_amount'),
                    'network_key'  => (string) ($this->request->getPost('network_key') ?: 'bep20'),
                ],
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    public function verifyPayout(string $ref)
    {
        if (! $this->can('commission.withdrawal.pay')) {
            return redirect()->to(site_url('admin/withdrawals'))->with('error', 'Permission denied.');
        }
        try {
            service('commissionWithdrawalService')->verifyAndMarkPaid(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/withdrawals/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
