<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;

/**
 * Phase 11B member commission panel — estimated purchase calcs vs actual posted wallet.
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
        $calcs = service('commissionPurchaseEngineService')->calculationsForBeneficiary($userId, 50);
        service('commissionWalletService')->ensureAccount($userId, (string) $profile->member_id);
        $wallet = service('commissionWalletService')->getAccount($userId);

        $l1 = MoneyMath::normalize('0', 8);
        $l2 = MoneyMath::normalize('0', 8);
        $l3 = MoneyMath::normalize('0', 8);
        foreach ($calcs as $row) {
            if (($row['status'] ?? '') !== 'CALCULATED_NOT_POSTED') {
                continue;
            }
            $amt = (string) $row['expected_amount_usdt'];
            $lvl = (int) $row['sponsor_level'];
            if ($lvl === 1) {
                $l1 = MoneyMath::add($l1, $amt, 8);
            } elseif ($lvl === 2) {
                $l2 = MoneyMath::add($l2, $amt, 8);
            } elseif ($lvl === 3) {
                $l3 = MoneyMath::add($l3, $amt, 8);
            }
        }
        $estimatedTotal = MoneyMath::add(MoneyMath::add($l1, $l2, 8), $l3, 8);

        $kpis = [
            KpiCardFactory::ok('direct', 'Direct Referrals', $team['direct'], 'purple', 'bi-person-plus', 'members'),
            KpiCardFactory::ok('l1', 'Level 1 Estimated', $l1, 'indigo', 'bi-1-circle', 'usdt'),
            KpiCardFactory::ok('l2', 'Level 2 Estimated', $l2, 'blue', 'bi-2-circle', 'usdt'),
            KpiCardFactory::ok('l3', 'Level 3 Estimated', $l3, 'teal', 'bi-3-circle', 'usdt'),
            KpiCardFactory::ok('est_total', 'Estimated Commission', $estimatedTotal, 'cyan', 'bi-graph-up', 'usdt'),
            KpiCardFactory::exact('actual', 'Actual Posted Commission', $wallet['available_balance'] ?? '0.00000000', 'green', 'bi-safe', 'usdt', null, 'Ledger-backed posted balance'),
            KpiCardFactory::unavailable('posting', 'Posting Status', 'gold', 'bi-slash-circle', 'OFF — estimates not withdrawable'),
            KpiCardFactory::ok('sim', 'Legacy Simulation Total', $snap['total_preview'], 'amber', 'bi-lightning', 'usdt'),
        ];

        return view('member/commissions/index', [
            'title'   => 'Commissions',
            'profile' => $profile,
            'kpis'    => $kpis,
            'snap'    => $snap,
            'team'    => $team,
            'calcs'   => $calcs,
            'wallet'  => $wallet,
            'recent'  => service('commissionSimulationService')->historyForBeneficiary($userId, 15),
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
            'title'   => 'Commission History',
            'profile' => $profile,
            'calcs'   => service('commissionPurchaseEngineService')->calculationsForBeneficiary($userId, 100),
            'rows'    => service('commissionSimulationService')->historyForBeneficiary($userId, 100),
        ]);
    }
}
