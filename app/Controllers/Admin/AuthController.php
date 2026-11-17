<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Authentication\Authenticators\Session;

class AuthController extends BaseController
{
    public function login()
    {
        return view('auth/admin_login', [
            'title'   => 'Admin Login',
            'appName' => config('Platform')->appName,
        ]);
    }

    public function attempt()
    {
        $rules = config('Validation')->adminLogin;

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $email    = strtolower(trim((string) $this->request->getPost('email')));
        $password = (string) $this->request->getPost('password');
        $remember = (bool) $this->request->getPost('remember');

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        if ($remember) {
            $authenticator->remember(true);
        }

        $result = $authenticator->attempt([
            'email'    => $email,
            'password' => $password,
        ]);

        if (! $result->isOK()) {
            return redirect()->back()->withInput()->with('error', 'Invalid email or password.');
        }

        $user = $result->extraInfo();

        $financeAccess = $user->inGroup('finance_viewer', 'finance_operator', 'finance_approver', 'finance_auditor');
        if (! $user->inGroup('admin') && ! $financeAccess) {
            auth()->logout();

            return redirect()->back()->withInput()->with('error', 'Administrator access required.');
        }

        // Finance-only users land on finance overview; platform admins keep dashboard.
        if (! $user->inGroup('admin') && $financeAccess) {
            return redirect()->to(site_url('admin/finance'));
        }

        return redirect()->to(site_url('admin/dashboard'));
    }

    public function logout()
    {
        $adminId = (int) (auth()->id() ?? 0);
        if ($adminId > 0) {
            service('impersonationService')->revokeAllForAdmin($adminId, 'admin_logout');
        }
        auth()->logout();

        return redirect()->to(site_url('admin/login'))->with('success', 'You have been signed out.');
    }
}
