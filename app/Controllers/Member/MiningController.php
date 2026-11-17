<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;

/**
 * Phase 11A member mining preview (own records only; no credits / withdraw / P2P).
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

        $today = service('miningEligibilityService')->businessDate();
        $rate  = service('miningRateService')->approvedForDate($today);
        $eval  = service('miningEligibilityService')->evaluate($userId, $today, $rate);
        $expected = '0.00000000';
        if ($rate !== null && ! empty($eval['eligible'])) {
            $expected = service('miningRateService')->previewAmount(
                (string) $eval['eligible_coins'],
                $rate,
                $today
            );
        }
        $wallet = service('miningWalletService')->balanceFor($userId);
        $ledger = service('miningWalletService')->ledgerFor($userId, 20);

        $kpis = [
            KpiCardFactory::ok('owned', 'Total Owned Coins', $eval['total_owned'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('eligible', 'Eligible Mining Coins', $eval['eligible_coins'], 'cyan', 'bi-check2-circle', 'coins'),
            KpiCardFactory::ok('rate', 'Approved Monthly Rate', $rate['rate_percent'] ?? 'Not configured', 'teal', 'bi-percent', 'rates'),
            KpiCardFactory::ok('today_preview', 'Expected Daily Mining', $expected, 'indigo', 'bi-lightning-charge', 'coins'),
            KpiCardFactory::ok('start', 'Mining Start Date', $eval['mining_start_date'] ?? '—', 'green', 'bi-calendar', 'dates'),
            KpiCardFactory::ok('wallet', 'Mining Wallet Balance', $wallet['available_balance'], 'amber', 'bi-wallet2', 'coins'),
            KpiCardFactory::unavailable('actual', 'Posting Status', 'gold', 'bi-safe', 'Mining Preview — Actual Credits Not Activated'),
        ];

        return view('member/mining/index', [
            'title'    => 'Mining',
            'profile'  => $profile,
            'kpis'     => $kpis,
            'eval'     => $eval,
            'rate'     => $rate,
            'today'    => $today,
            'expected' => $expected,
            'wallet'   => $wallet,
            'ledger'   => $ledger,
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
            'title'   => 'Mining History',
            'profile' => $profile,
            'rows'    => service('miningWalletService')->ledgerFor($userId, 50),
            'simRows' => service('miningSimulationService')->historyForMember($userId, 50),
        ]);
    }
}
