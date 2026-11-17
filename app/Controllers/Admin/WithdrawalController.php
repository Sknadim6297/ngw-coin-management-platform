<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

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

        return view('admin/withdrawals/index', [
            'title'    => 'Commission Withdrawals',
            'rows'     => service('commissionWithdrawalService')->adminList($filters, 100),
            'filters'  => $filters,
            'status'   => service('commissionWithdrawalService')->activationStatus(),
            'canReview'=> $this->can('commission.withdrawal.review'),
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

        return view('admin/withdrawals/show', [
            'title'     => 'Withdrawal Detail',
            'row'       => $row,
            'wallet'    => $wallet,
            'status'    => service('commissionWithdrawalService')->activationStatus(),
            'canReview' => $this->can('commission.withdrawal.review'),
        ]);
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
