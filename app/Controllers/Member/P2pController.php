<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11C member mining P2P UI (execution kill-switched OFF).
 */
class P2pController extends BaseController
{
    public function index()
    {
        $userId  = (int) auth()->id();
        $profile = model(MemberProfileModel::class)->findByUserId($userId);
        if ($profile === null) {
            return redirect()->to(site_url('login'))->with('error', 'Member profile not found.');
        }

        $svc     = service('miningP2pTransferService');
        $summary = $svc->memberWalletSummary($userId);
        $history = $svc->historyForMember($userId, 30);
        $status  = $svc->status();

        $kpis = [
            KpiCardFactory::ok('posted', 'Actual Posted Balance', $summary['available_balance'], 'purple', 'bi-wallet2', 'coins'),
            KpiCardFactory::ok('available', 'Available for Transfer', $summary['available_balance'], 'cyan', 'bi-arrow-left-right', 'coins'),
            KpiCardFactory::ok('pending', 'Pending Transfers', (string) $summary['pending_count'], 'amber', 'bi-hourglass', 'transfers'),
            KpiCardFactory::ok('sent', 'Total Sent', $summary['total_sent'], 'orange', 'bi-box-arrow-up', 'coins'),
            KpiCardFactory::ok('recv', 'Total Received', $summary['total_received'], 'green', 'bi-box-arrow-in-down', 'coins'),
            KpiCardFactory::unavailable('p2p', 'P2P Status', 'gold', 'bi-slash-circle', 'P2P Transfers Not Activated'),
        ];

        return view('member/p2p/index', [
            'title'    => 'Mining P2P Transfer',
            'profile'  => $profile,
            'kpis'     => $kpis,
            'summary'  => $summary,
            'history'  => $history,
            'status'   => $status,
            'preview'  => session()->getFlashdata('p2p_preview'),
            'enabled'  => $svc->enabled(),
        ]);
    }

    public function lookup()
    {
        $userId = (int) auth()->id();
        try {
            $receiver = service('miningP2pTransferService')->lookupReceiver(
                (string) $this->request->getPost('receiver_member_id'),
                $userId
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/p2p'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/p2p'))
            ->withInput()
            ->with('success', 'Receiver verified: ' . $receiver['member_id'] . ' · ' . $receiver['member_name']);
    }

    public function preview()
    {
        $userId = (int) auth()->id();
        try {
            $preview = service('miningP2pTransferService')->preview(
                $userId,
                (string) $this->request->getPost('receiver_member_id'),
                (string) $this->request->getPost('amount')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('member/p2p'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/p2p'))
            ->withInput()
            ->with('p2p_preview', $preview)
            ->with('success', 'Transfer preview ready. Execution remains disabled.');
    }

    public function transfer()
    {
        $userId = (int) auth()->id();
        try {
            service('miningP2pTransferService')->execute(
                $userId,
                (string) $this->request->getPost('receiver_member_id'),
                (string) $this->request->getPost('amount'),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('idempotency_key')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('member/p2p'))->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('member/p2p'))->with('error', 'Unexpected transfer state.');
    }
}
