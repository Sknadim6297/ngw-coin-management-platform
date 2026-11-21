<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;

class RegistrationEmailVerificationController extends BaseController
{
    public function verify(string $token)
    {
        if (! service('registrationEmailVerificationService')->verify($token)) {
            return redirect()->to(site_url('login'))
                ->with('error', 'This verification link is invalid or expired. Request a new link to continue.');
        }

        return redirect()->to(site_url('login'))
            ->with('success', 'Email verified. You can now sign in.');
    }

    public function resendForm()
    {
        return view('auth/resend_verification', [
            'title'    => 'Resend Email Verification',
            'appName'  => config('Platform')->appName,
            'memberId' => (string) session()->getFlashdata('verification_member_id'),
        ]);
    }

    public function resend()
    {
        $memberId = strtoupper(trim((string) $this->request->getPost('member_id')));
        $password = (string) $this->request->getPost('password');
        $profile  = model(MemberProfileModel::class)->findByMemberId($memberId);
        $user     = $profile !== null
            ? model(UserModel::class)->findById((int) $profile->user_id)
            : null;

        if ($user === null
            || ! service('passwords')->verify($password, (string) $user->password_hash)
        ) {
            return redirect()->back()->withInput()->with('error', 'Member ID or password is invalid.');
        }

        if ((int) $user->active === 1) {
            return redirect()->to(site_url('login'))->with('success', 'This account is already verified. You can sign in.');
        }

        try {
            $result = service('registrationEmailVerificationService')->sendForUser((int) $user->id);
        } catch (\RuntimeException) {
            log_message('error', 'Registration verification resend failed for user {user_id}.', [
                'user_id' => (int) $user->id,
            ]);

            return redirect()->back()->withInput()->with('error', 'Verification email could not be sent. Please try again later or contact support.');
        }

        if ($result['throttled']) {
            return redirect()->back()->withInput()->with('error', 'Verification email resend limit reached. Try again later.');
        }
        if (! $result['sent']) {
            return redirect()->back()->withInput()->with('error', 'Verification email could not be sent. Please try again later or contact support.');
        }

        return redirect()->to(site_url('login'))->with('success', 'Verification email sent. Check your inbox.');
    }
}
