<?php

declare(strict_types=1);

namespace App\Controllers\Admin\Concerns;

/**
 * Shared permission helpers for Admin controllers (Phase 10B).
 */
trait AdminAuthorization
{
    protected function userCan(string ...$permissions): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    protected function denyPermission(string $message = 'Permission denied.')
    {
        return redirect()->to(site_url('admin/dashboard'))->with('error', $message);
    }
}
