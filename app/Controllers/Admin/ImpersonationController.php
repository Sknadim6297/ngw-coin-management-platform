<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 10D: create short-lived impersonation grants (POST only).
 */
class ImpersonationController extends BaseController
{
    use AdminAuthorization;

    public function grant(string $memberId)
    {
        if (! $this->userCan('admin.members.impersonate')) {
            return $this->denyPermission();
        }

        if (strtoupper($this->request->getMethod()) !== 'POST') {
            return redirect()->to(site_url('admin/members'))->with('error', 'Invalid impersonation request.');
        }

        try {
            $grant = service('impersonationService')->createGrant(
                (int) auth()->id(),
                $memberId,
                (string) $this->request->getPost('admin_password')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/members/' . rawurlencode($memberId)))
                ->with('error', $e->getMessage());
        }

        return view('admin/members/impersonate_launch', [
            'title' => 'Opening Member View',
            'grant' => $grant,
        ]);
    }
}
