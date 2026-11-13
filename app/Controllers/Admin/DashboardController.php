<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\MemberProfileModel;

class DashboardController extends BaseController
{
    public function index()
    {
        $profiles = model(MemberProfileModel::class);

        return view('admin/dashboard/index', [
            'title'               => 'Admin Dashboard',
            'totalMembers'        => $profiles->countByStatus(),
            'activeMembers'       => $profiles->countByStatus('active'),
            'inactiveMembers'     => $profiles->countByStatus('inactive'),
            'recentRegistrations' => $profiles->recentRegistrations(8),
        ]);
    }
}
