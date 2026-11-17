<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11D member commission withdrawal UI (submission kill-switched OFF).
 */
class WithdrawalController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $summary = service('commissionWalletService')->withdrawableSummary($userId);
        $status  = service('commissionWithdrawalService')->status();
        $enabled = service('commissionWalletService')->withdrawalEnabled();

        $kpis = [
            KpiCardFactory::ok('posted', 'Total Posted Commission', $summary['total_posted_credits'], 'purple', 'bi-safe', 'usdt'),
            KpiCardFactory::ok('available', 'Available Commission Balance', $summary['available_balance'], 'green', 'bi-wallet2', 'usdt'),
            KpiCardFactory::ok('reserved', 'Reserved Balance', $summary['reserved_balance'], 'amber', 'bi-hourglass', 'usdt'),
            KpiCardFactory::ok('withdrawn', 'Total Withdrawn', $summary['total_withdrawn'], 'cyan', 'bi-box-arrow-up', 'usdt'),
            KpiCardFactory::unavailable('wd', 'Withdrawal Status', 'gold', 'bi-slash-circle', 'Commission Withdrawals Not Activated'),
        ];

        return view('member/withdrawals/index', [
            'title'    => 'Commission Withdrawals',
            'profile'  => $profile,
            'rows'     => service('commissionWithdrawalService')->listForMember($userId, 50),
            'summary'  => $summary,
            'status'   => $status,
            'enabled'  => $enabled,
            'kpis'     => $kpis,
            'preview'  => session()->getFlashdata('wd_preview'),
            'networks' => config('Platform')->commissionWithdrawalNetworks,
        ]);
    }

    public function create()
    {
        // Keep create route as alias to index form while disabled.
        return redirect()->to(site_url('member/withdrawals'));
    }

    public function preview()
    {
        $userId = (int) auth()->id();
        try {
            $preview = service('commissionWithdrawalService')->preview(
                $userId,
                (string) $this->request->getPost('amount')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/withdrawals'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/withdrawals'))
            ->withInput()
            ->with('wd_preview', $preview)
            ->with('success', 'Withdrawal preview ready. Submission remains disabled.');
    }

    public function store()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        if (! service('commissionWalletService')->withdrawalEnabled()) {
            return redirect()->to(site_url('member/withdrawals'))
                ->with('error', 'Commission Withdrawals Not Activated. Submission is blocked server-side.');
        }

        try {
            $draft = service('commissionWithdrawalService')->createDraft($userId, (string) $profile->member_id, [
                'amount'              => (string) $this->request->getPost('amount'),
                'currency'            => 'USDT',
                'network'             => (string) ($this->request->getPost('network') ?: 'BEP20'),
                'destination_address' => (string) $profile->usdt_wallet,
                'idempotency_key'     => (string) $this->request->getPost('idempotency_key'),
            ]);
            service('commissionWithdrawalService')->submit(
                $draft['withdrawal_ref'],
                $userId,
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/withdrawals'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/withdrawals'))->with('error', 'Unexpected withdrawal state.');
    }

    public function show(string $ref)
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $row = service('commissionWithdrawalService')->findOwned($ref, $userId);
        if ($row === null) {
            return redirect()->to(site_url('member/withdrawals'))->with('error', 'Withdrawal not found.');
        }

        return view('member/withdrawals/show', [
            'title'   => 'Withdrawal Detail',
            'profile' => $profile,
            'row'     => $row,
            'status'  => service('commissionWithdrawalService')->activationStatus(),
        ]);
    }
}
