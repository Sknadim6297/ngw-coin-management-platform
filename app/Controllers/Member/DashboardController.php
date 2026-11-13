<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);

        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $relationship = model(SponsorRelationshipModel::class)->findByMemberUserId($userId);
        $referralLink = site_url('register?sponsor=' . urlencode($profile->member_id));
        $priceSummary = service('coinPriceService')->settingsSummary();
        $inventory    = service('coinInventoryService')->summary();
        $locking      = service('coinLockingService')->memberDashboardSnapshot($userId);

        return view('member/dashboard/index', [
            'title'        => 'Member Dashboard',
            'profile'      => $profile,
            'relationship' => $relationship,
            'referralLink' => $referralLink,
            'completion'   => $profile->profileCompletionPercent(),
            'priceSummary' => $priceSummary,
            'inventory'    => $inventory,
            'coinSummary'  => $locking['summary'],
            'activeLocks'  => $locking['active'],
            'maturedLocks' => $locking['matured'],
            'recentLocks'  => $locking['recent'],
        ]);
    }
}
