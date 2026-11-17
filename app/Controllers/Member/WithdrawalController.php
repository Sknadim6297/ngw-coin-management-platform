<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

class WithdrawalController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/withdrawals/index', [
            'title'   => 'Withdrawals',
            'profile' => $profile,
            'rows'    => service('commissionWithdrawalService')->listForMember($userId, 50),
            'status'  => service('commissionWithdrawalService')->activationStatus(),
            'enabled' => service('commissionWalletService')->withdrawalEnabled(),
        ]);
    }

    public function create()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        if (! service('commissionWalletService')->withdrawalEnabled()) {
            return redirect()->to(site_url('member/withdrawals'))
                ->with('error', 'Withdrawals Not Available. Submission remains blocked.');
        }

        $account = service('commissionWalletService')->getAccount($userId);

        return view('member/withdrawals/create', [
            'title'   => 'Request Withdrawal',
            'profile' => $profile,
            'account' => $account,
            'status'  => service('commissionWithdrawalService')->activationStatus(),
            'enabled' => true,
            'networks'=> config('Platform')->commissionWithdrawalNetworks,
        ]);
    }

    public function store()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        // Always block live submission path while disabled; draft create still validates.
        if (! service('commissionWalletService')->withdrawalEnabled()) {
            return redirect()->to(site_url('member/withdrawals'))
                ->with('error', 'Withdrawals Not Activated. Submission is blocked server-side.');
        }

        try {
            $draft = service('commissionWithdrawalService')->createDraft($userId, (string) $profile->member_id, [
                'amount'              => (string) $this->request->getPost('amount'),
                'currency'            => (string) $this->request->getPost('currency'),
                'network'             => (string) $this->request->getPost('network'),
                'destination_address' => (string) $this->request->getPost('destination_address'),
            ]);
            service('commissionWithdrawalService')->submit($draft['withdrawal_ref'], $userId);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/withdrawals'))->with('success', 'Submitted.');
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
