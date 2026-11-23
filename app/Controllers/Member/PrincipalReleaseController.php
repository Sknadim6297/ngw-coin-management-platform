<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;

/**
 * Phase 3 — Daily principal release visibility (schedule preview; posting OFF).
 */
class PrincipalReleaseController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $filters = [
            'date_from'    => $this->request->getGet('date_from'),
            'date_to'      => $this->request->getGet('date_to'),
            'purchase_ref' => $this->request->getGet('purchase_ref'),
            'per_page'     => $this->request->getGet('per_page'),
            'page'         => $this->request->getGet('page'),
        ];

        try {
            $summary = service('principalDailyReleaseService')->summaryForMember($userId);
            $history = service('principalDailyReleaseService')->historyForMember($userId, $filters);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/principal-release'))->with('error', $e->getMessage());
        }

        $unit = (string) ($summary['unit_label'] ?? 'COIN');
        $kpis = [
            KpiCardFactory::ok('orig', 'Original Principal', $summary['original_principal'], 'purple', 'bi-safe', $unit),
            KpiCardFactory::ok('today', "Today's Principal Release", $summary['today_principal_release'], 'cyan', 'bi-calendar-day', $unit),
            KpiCardFactory::ok('released', 'Total Released Principal', $summary['total_released_principal'], 'green', 'bi-unlock', $unit),
            KpiCardFactory::ok('remain', 'Remaining Locked Principal', $summary['remaining_locked_principal'], 'amber', 'bi-lock', $unit),
            KpiCardFactory::ok('p2p', 'Available P2P Principal', $summary['available_p2p_principal'], 'blue', 'bi-arrow-left-right', $unit),
        ];

        return view('member/principal_release/index', [
            'title'   => 'Daily Principal Release',
            'profile' => $profile,
            'summary' => $summary,
            'history' => $history,
            'filters' => $history['filters'],
            'kpis'    => $kpis,
        ]);
    }
}
