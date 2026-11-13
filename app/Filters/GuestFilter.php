<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class GuestFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return null;
        }

        $user = auth()->user();

        if ($user !== null && $user->inGroup('admin') && str_starts_with(uri_string(), 'admin')) {
            return redirect()->to(site_url('admin/dashboard'));
        }

        if ($user !== null && $user->inGroup('member', 'admin')) {
            return redirect()->to(site_url('member/dashboard'));
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
