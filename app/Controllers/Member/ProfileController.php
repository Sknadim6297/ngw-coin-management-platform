<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;

class ProfileController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);

        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $relationship = model(SponsorRelationshipModel::class)->findByMemberUserId($userId);
        $email        = auth()->user()?->getEmail();

        return view('member/profile/index', [
            'title'        => 'My Profile',
            'profile'      => $profile,
            'relationship' => $relationship,
            'email'        => $email,
            'completion'   => $profile->profileCompletionPercent(),
        ]);
    }
}
