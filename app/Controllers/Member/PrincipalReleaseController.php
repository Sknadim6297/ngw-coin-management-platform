<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
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

        return view('member/principal_release/index', [
            'title'   => 'Daily Principal Release',
            'profile' => $profile,
            'summary' => $summary,
            'history' => $history,
            'filters' => $history['filters'],
        ]);
    }
}
