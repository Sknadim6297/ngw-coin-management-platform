<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class MemberFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to(site_url('login'))->with('error', 'Please sign in to continue.');
        }

        $user = auth()->user();

        if ($user === null || ! $user->inGroup('member', 'admin')) {
            return redirect()->to(site_url('login'))->with('error', 'Member access required.');
        }

        if ((int) $user->active !== 1) {
            auth()->logout();

            return redirect()->to(site_url('login'))
                ->with('error', 'Email verification is required. Verify your email or request a new link before continuing.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
