<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use App\Models\MemberWalletChangeEventModel;
use App\Models\SponsorRelationshipModel;
use InvalidArgumentException;

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
        $activation   = service('memberActivationService')->statusForMember($userId);
        $walletEvents = model(MemberWalletChangeEventModel::class)->recentForUser($userId, 5);

        return view('member/profile/index', [
            'title'        => 'My Profile',
            'profile'      => $profile,
            'relationship' => $relationship,
            'email'        => $email,
            'activation'   => $activation,
            'completion'   => $profile->profileCompletionPercent(),
            'walletEvents' => $walletEvents,
            'walletCooldownUntil' => service('memberProfileService')->walletCooldownUntil($userId),
        ]);
    }

    public function update()
    {
        $rules = config('Validation')->memberProfileUpdate;
        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $result = service('memberProfileService')->updateOwnProfile(
                (int) auth()->id(),
                [
                    'member_name'            => (string) $this->request->getPost('member_name'),
                    'mobile'                 => (string) $this->request->getPost('mobile'),
                    'email'                  => (string) $this->request->getPost('email'),
                    'usdt_wallet'            => (string) $this->request->getPost('usdt_wallet'),
                    'current_password'       => (string) $this->request->getPost('current_password'),
                    'confirm_wallet_change'  => (string) $this->request->getPost('confirm_wallet_change'),
                ],
                $this->request->getIPAddress(),
                (string) $this->request->getUserAgent()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            log_message('error', 'Member profile update failed: {msg}', ['msg' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to update profile. Please try again.');
        }

        if ($result['changed'] === []) {
            $msg = 'No profile changes detected.';
        } elseif (in_array('pending_email', $result['changed'], true)) {
            $msg = 'Profile saved. Check the new email inbox to verify before your login email changes.';
        } else {
            $msg = 'Profile updated successfully.';
        }

        return redirect()->to(site_url('member/profile'))->with('success', $msg);
    }

    public function cancelPendingEmail()
    {
        try {
            service('memberProfileService')->cancelPendingEmailChange((int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/profile'))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/profile'))->with('success', 'Pending email change cancelled.');
    }
}
