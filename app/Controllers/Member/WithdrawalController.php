<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Member commission withdrawal UI.
 * Submission remains kill-switched OFF until Platform authorization; never marks a request as paid on submit.
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

        $summary     = service('commissionWalletService')->withdrawableSummary($userId);
        $status      = service('commissionWithdrawalService')->status();
        $settings    = service('commissionWithdrawalSettingsService')->current();
        $kyc         = service('memberKycService')->statusForMember($userId);
        $enabled     = (bool) $status['withdrawal_enabled'];
        $authorized  = (bool) $status['execution_authorized'] && ! empty($kyc['is_approved']);
        $statusLabel = service('commissionWithdrawalService')->activationStatus();
        if (empty($kyc['is_approved']) && (bool) $status['execution_authorized']) {
            $statusLabel = 'KYC approval required';
        }

        $kpis = [
            KpiCardFactory::ok('posted', 'Total Posted Commission', $summary['total_posted_credits'], 'purple', 'bi-safe', 'usdt'),
            KpiCardFactory::ok('available', 'Available Commission Balance', $summary['available_balance'], 'green', 'bi-wallet2', 'usdt'),
            KpiCardFactory::ok('reserved', 'Reserved Balance', $summary['reserved_balance'], 'amber', 'bi-hourglass', 'usdt'),
            KpiCardFactory::ok('withdrawn', 'Total Withdrawn', $summary['total_withdrawn'], 'cyan', 'bi-box-arrow-up', 'usdt'),
            $authorized
                ? KpiCardFactory::ok('wd', 'Withdrawal Status', $statusLabel, 'green', 'bi-check2-circle', null, site_url('member/withdrawals'))
                : KpiCardFactory::unavailable('wd', 'Withdrawal Status', 'gold', 'bi-slash-circle', $statusLabel),
        ];

        return view('member/withdrawals/index', [
            'title'       => 'Commission Withdrawals',
            'profile'     => $profile,
            'rows'        => service('commissionWithdrawalService')->listForMember($userId, 50),
            'summary'     => $summary,
            'status'      => $status,
            'settings'    => $settings,
            'kyc'         => $kyc,
            'enabled'     => $enabled,
            'authorized'  => $authorized,
            'statusLabel' => $statusLabel,
            'kpis'        => $kpis,
            'preview'     => session()->getFlashdata('wd_preview'),
            'networks'    => config('Platform')->commissionWithdrawalNetworks,
        ]);
    }

    public function create()
    {
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

        $msg = ! empty($preview['can_execute'])
            ? 'Withdrawal preview ready. Confirm with your password to submit for admin review.'
            : 'Withdrawal preview ready. Submission remains blocked until withdrawal gates are satisfied.';

        return redirect()->to(site_url('member/withdrawals'))
            ->withInput()
            ->with('wd_preview', $preview)
            ->with('success', $msg);
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
            $submitted = service('commissionWithdrawalService')->submit(
                (string) $draft['withdrawal_ref'],
                $userId,
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/withdrawals'))->withInput()->with('error', $e->getMessage());
        }

        $ref = (string) ($submitted['withdrawal_ref'] ?? $draft['withdrawal_ref'] ?? '');

        return redirect()->to(site_url('member/withdrawals/' . rawurlencode($ref)))
            ->with(
                'success',
                'Withdrawal request ' . $ref . ' submitted for admin review. Funds are reserved; this is not a completed payout.'
            );
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
