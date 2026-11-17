<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11E member coin selling UI (execution OFF).
 */
class CoinSellingController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $svc     = service('coinSellingService');
        $summary = $svc->memberLotSummary($userId);
        $enabled = $svc->enabled();

        $kpis = [
            KpiCardFactory::ok('owned', 'Total Owned Coins', $summary['totals']['total_owned'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('locked', 'Locked Coins', $summary['totals']['total_locked'], 'amber', 'bi-lock', 'coins'),
            KpiCardFactory::ok('matured', 'Matured Coins', $summary['totals']['total_matured'], 'cyan', 'bi-hourglass-split', 'coins'),
            KpiCardFactory::ok('eligible', 'Eligible to Sell', $summary['totals']['eligible_selling_qty'], 'green', 'bi-shop', 'coins'),
            KpiCardFactory::unavailable('sell', 'Selling Status', 'gold', 'bi-slash-circle', 'Coin Selling Not Activated — Awaiting Business Approval.'),
        ];

        return view('member/coin_selling/index', [
            'title'    => 'Coin Selling',
            'profile'  => $profile,
            'summary'  => $summary,
            'history'  => $svc->listForMember($userId, 30),
            'status'   => $svc->status(),
            'enabled'  => $enabled,
            'kpis'     => $kpis,
            'preview'  => session()->getFlashdata('sell_preview'),
        ]);
    }

    public function preview()
    {
        $userId = (int) auth()->id();
        try {
            $preview = service('coinSellingService')->preview(
                $userId,
                (string) $this->request->getPost('purchase_ref'),
                (string) $this->request->getPost('quantity')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/coin-selling'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/coin-selling'))
            ->withInput()
            ->with('sell_preview', $preview)
            ->with('success', 'Selling preview ready. Submission remains disabled.');
    }

    public function store()
    {
        $userId = (int) auth()->id();
        try {
            service('coinSellingService')->submit(
                $userId,
                (string) $this->request->getPost('purchase_ref'),
                (string) $this->request->getPost('quantity'),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('idempotency_key')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/coin-selling'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/coin-selling'))->with('error', 'Unexpected sell state.');
    }
}
