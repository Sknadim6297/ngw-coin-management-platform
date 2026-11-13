<?php

declare(strict_types=1);

namespace App\Controllers;

class Home extends BaseController
{
    public function index()
    {
        if (auth()->loggedIn()) {
            $user = auth()->user();
            if ($user !== null && $user->inGroup('admin')) {
                return redirect()->to(site_url('admin/dashboard'));
            }

            return redirect()->to(site_url('member/dashboard'));
        }

        return redirect()->to(site_url('login'));
    }
}
