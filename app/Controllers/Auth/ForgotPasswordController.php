<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class ForgotPasswordController extends BaseController
{
    public function index()
    {
        return view('auth/forgot_password', [
            'title'   => 'Forgot Password',
            'appName' => config('Platform')->appName,
        ]);
    }

    public function send()
    {
        $rules = config('Validation')->forgotPassword;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email = strtolower(trim((string) $this->request->getPost('email')));
        service('passwordResetService')->requestReset($email);

        return redirect()->to(site_url('forgot-password'))
            ->with('success', 'If an account exists for that email, a password reset link has been sent.');
    }

    public function resetForm(string $token)
    {
        return view('auth/reset_password', [
            'title'   => 'Reset Password',
            'appName' => config('Platform')->appName,
            'token'   => $token,
        ]);
    }

    public function reset(string $token)
    {
        $rules = config('Validation')->resetPassword;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $ok = service('passwordResetService')->resetPassword(
            $token,
            (string) $this->request->getPost('password')
        );

        if (! $ok) {
            return redirect()->to(site_url('forgot-password'))
                ->with('error', 'This reset link is invalid or has expired.');
        }

        return redirect()->to(site_url('login'))
            ->with('success', 'Your password has been updated. You can sign in now.');
    }
}
