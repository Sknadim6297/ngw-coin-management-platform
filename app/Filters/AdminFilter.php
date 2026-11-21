<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AdminFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! auth()->loggedIn()) {
            return redirect()->to(site_url('admin/login'))->with('error', 'Please sign in to continue.');
        }

        $user = auth()->user();
        if ($user === null) {
            return redirect()->to(site_url('login'))->with('error', 'Administrator access required.');
        }

        if ((int) $user->active !== 1) {
            auth()->logout();

            return redirect()->to(site_url('admin/login'))
                ->with('error', 'This account is not active. Complete the required verification before continuing.');
        }

        // Platform admins OR dedicated finance roles (Phase 4E least privilege).
        $allowed = $user->inGroup('admin')
            || $user->inGroup('finance_viewer', 'finance_operator', 'finance_approver', 'finance_auditor')
            || $user->can('admin.access');

        if (! $allowed) {
            return redirect()->to(site_url('login'))->with('error', 'Administrator access required.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
