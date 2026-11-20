<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Mining\MiningWalletPresenter;
use App\Models\MemberProfileModel;

/**
 * Member Mining Wallet (actual posted credits, read-only) and Mining Earnings History.
 * Always scoped to the authenticated member — no member/user id is ever read from the request.
 */
class MiningController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/mining/index', array_merge(
            ['title' => 'Mining Wallet', 'profile' => $profile],
            MiningWalletPresenter::walletPage($userId, 'member')
        ));
    }

    public function history()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/mining/history', array_merge(
            ['title' => 'Mining History', 'profile' => $profile],
            MiningWalletPresenter::historyPage(
                $userId,
                'member',
                (int) ($this->request->getGet('page') ?? 1),
                (string) ($this->request->getGet('scope') ?? 'earnings')
            )
        ));
    }
}
