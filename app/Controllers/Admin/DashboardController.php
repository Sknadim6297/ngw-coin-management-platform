<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;

class DashboardController extends BaseController
{
    public function index()
    {
        $dashboard = service('adminDashboardService')->build();

        return view('admin/dashboard/index', [
            'title'               => 'Admin Dashboard',
            'kpis'                => $dashboard['kpis'],
            'charts'              => $dashboard['charts'],
            'recentRegistrations' => $dashboard['recent_registrations'],
            'timezone'            => $dashboard['timezone'],
            'todayStart'          => $dashboard['today_start'],
            'todayEnd'            => $dashboard['today_end'],
            'generatedAt'         => $dashboard['generated_at'],
        ]);
    }
}
