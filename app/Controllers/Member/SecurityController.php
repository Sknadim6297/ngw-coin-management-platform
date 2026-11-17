<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use CodeIgniter\Shield\Models\UserModel;

class SecurityController extends BaseController
{
    public function index()
    {
        return view('member/security/index', [
            'title' => 'Security',
        ]);
    }

    public function updatePassword()
    {
        $rules = [
            'current_password' => 'required',
            'password'         => 'required|min_length[8]|max_length[72]|regex_match[/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->with('errors', $this->validator->getErrors());
        }

        $user = auth()->user();
        if ($user === null) {
            return redirect()->to(site_url('login'));
        }

        $passwords = service('passwords');
        if (! $passwords->verify((string) $this->request->getPost('current_password'), $user->password_hash)) {
            return redirect()->back()->with('error', 'Current password is incorrect.');
        }

        $user->fill([
            'password' => (string) $this->request->getPost('password'),
        ]);
        model(UserModel::class)->save($user);

        service('auditLogger')->log('member.password_changed', (int) $user->id, 'user', (string) $user->id);

        return redirect()->back()->with('success', 'Password updated successfully.');
    }
}
