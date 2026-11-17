<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;

/**
 * Phase 7C member commission simulation views (read-only, non-payable).
 * Privacy: only beneficiary-scoped previews; no purchaser payment secrets.
 */
class CommissionController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $snap = service('commissionSimulationService')->memberSnapshot($userId);
        $team = service('referralTeamService')->countsForSponsor($userId);

        $kpis = [
            KpiCardFactory::ok('direct', 'My Direct Referrals', $team['direct'], 'purple', 'bi-person-plus', 'members'),
            KpiCardFactory::ok('l1m', 'Level 1 Team', $team['levels'][1]['total'] ?? 0, 'cyan', 'bi-1-circle', 'members'),
            KpiCardFactory::ok('l2m', 'Level 2 Team', $team['levels'][2]['total'] ?? 0, 'indigo', 'bi-2-circle', 'members'),
            KpiCardFactory::ok('l3m', 'Level 3 Team', $team['levels'][3]['total'] ?? 0, 'teal', 'bi-3-circle', 'members'),
            KpiCardFactory::ok('l1', 'Level 1 Commission Preview', $snap['level1_preview'], 'indigo', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('l2', 'Level 2 Commission Preview', $snap['level2_preview'], 'blue', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('l3', 'Level 3 Commission Preview', $snap['level3_preview'], 'teal', 'bi-cash', 'usdt'),
            KpiCardFactory::ok('today', "My Today's Commission Preview", $snap['today_preview'], 'cyan', 'bi-calendar-day', 'usdt'),
            KpiCardFactory::ok('total', 'My Total Commission Preview', $snap['total_preview'], 'purple', 'bi-graph-up', 'usdt'),
            KpiCardFactory::unavailable('actual', 'Actual Commission', 'gold', 'bi-safe', $snap['actual_status']),
        ];

        $recent = service('commissionSimulationService')->historyForBeneficiary($userId, 15);

        return view('member/commissions/index', [
            'title'   => 'Commission Simulation',
            'profile' => $profile,
            'kpis'    => $kpis,
            'snap'    => $snap,
            'team'    => $team,
            'recent'  => $recent,
        ]);
    }

    public function history()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/commissions/history', [
            'title'   => 'Commission Simulation History',
            'profile' => $profile,
            'rows'    => service('commissionSimulationService')->historyForBeneficiary($userId, 100),
        ]);
    }
}
