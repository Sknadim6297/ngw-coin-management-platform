<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;

/**
 * Phase 7B member mining simulation views (read-only, non-payable).
 */
class MiningController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $today   = date('Y-m-d');
        $preview = service('miningSimulationService')->memberPreview($userId, $today);
        $eval    = $preview['eligibility'];
        $rate    = $preview['rate'];

        $kpis = [
            KpiCardFactory::ok('owned', 'Total Owned Coins', $eval['total_owned'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('available', 'Available Coins', $eval['available'], 'green', 'bi-wallet2', 'coins'),
            KpiCardFactory::ok('locked', 'Locked Coins', $eval['locked'], 'orange', 'bi-lock-fill', 'coins'),
            KpiCardFactory::ok('eligible', 'Eligible Coins', $eval['eligible_coins'], 'cyan', 'bi-check2-circle', 'coins'),
            KpiCardFactory::ok('ineligible', 'Ineligible Coins', $eval['ineligible_coins'], 'amber', 'bi-x-circle', 'coins'),
            KpiCardFactory::ok('today_preview', "Today's Mining Preview", $preview['preview_amount'], 'teal', 'bi-lightning-charge', 'coins'),
            KpiCardFactory::ok('total_preview', 'My Total Mining Preview', service('miningSimulationService')->memberPreviewTotal($userId), 'indigo', 'bi-graph-up', 'coins'),
            KpiCardFactory::unavailable('actual', 'Actual Mining', 'gold', 'bi-safe', 'Actual Mining: Not Activated'),
        ];

        return view('member/mining/index', [
            'title'   => 'Mining Simulation',
            'profile' => $profile,
            'kpis'    => $kpis,
            'preview' => $preview,
            'eval'    => $eval,
            'rate'    => $rate,
            'today'   => $today,
        ]);
    }

    public function history()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        return view('member/mining/history', [
            'title'   => 'Mining Simulation History',
            'profile' => $profile,
            'rows'    => service('miningSimulationService')->historyForMember($userId, 50),
        ]);
    }
}
