<?php

declare(strict_types=1);

namespace App\Controllers\Auth;

use App\Controllers\BaseController;

class LoginController extends BaseController
{
    public function index()
    {
        return view('auth/login', [
            'title'   => 'Member Login',
            'appName' => config('Platform')->appName,
        ]);
    }

    public function attempt()
    {
        $rules = config('Validation')->memberLogin;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $memberId = (string) $this->request->getPost('member_id');
        $password = (string) $this->request->getPost('password');
        $remember = (bool) $this->request->getPost('remember');

        $result = service('memberAuthService')->attempt($memberId, $password, $remember);

        if (! $result['success']) {
            return redirect()->back()->withInput()->with('error', $result['message']);
        }

        return redirect()->to(site_url('member/dashboard'));
    }

    public function logout()
    {
        auth()->logout();

        return redirect()->to(site_url('login'))->with('success', 'You have been signed out.');
    }
}
