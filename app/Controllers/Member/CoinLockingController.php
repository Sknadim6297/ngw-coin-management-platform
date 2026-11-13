<?php

declare(strict_types=1);

namespace App\Controllers\Member;

use App\Controllers\BaseController;
use InvalidArgumentException;

class CoinLockingController extends BaseController
{
    public function index()
    {
        $userId    = (int) auth()->id();
        $summary   = service('memberCoinOwnershipService')->summaryForUser($userId);
        $plans     = service('coinLockingService')->listPlans(true);
        $locks     = service('coinLockingService')->memberDashboardSnapshot($userId);
        $token     = bin2hex(random_bytes(16));
        session()->setFlashdata('coin_lock_idempotency', $token);

        return view('member/locking/index', [
            'title'   => 'Coin Locking',
            'summary' => $summary,
            'plans'   => $plans,
            'active'  => $locks['active'],
            'matured' => $locks['matured'],
            'recent'  => $locks['recent'],
            'idempotencyKey' => $token,
        ]);
    }

    public function store()
    {
        $userId = (int) auth()->id();
        $planId = (int) $this->request->getPost('plan_id');
        $qty    = trim((string) $this->request->getPost('quantity'));
        $confirm = (string) $this->request->getPost('confirm');
        $key    = trim((string) $this->request->getPost('idempotency_key'));

        if ($confirm !== '1') {
            return redirect()->back()->withInput()->with('error', 'Please confirm the lock details before submitting.');
        }

        $sessionKey = (string) session()->getFlashdata('coin_lock_idempotency');
        if ($key === '' || $sessionKey === '' || ! hash_equals($sessionKey, $key)) {
            // Accept posted key if unique; regenerate path for true duplicates via service.
            if ($key === '') {
                return redirect()->to(site_url('member/locking'))->with('error', 'Duplicate or invalid lock submission. Please try again.');
            }
        }

        try {
            $result = service('coinLockingService')->lockCoins($userId, $planId, $qty, $key !== '' ? $key : null);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        $msg = ! empty($result['duplicate'])
            ? 'Lock request was already processed.'
            : 'Coins locked successfully until ' . ($result['lock']['matures_at'] ?? '');

        return redirect()->to(site_url('member/locking'))->with('success', $msg);
    }

    public function show(string $lockRef)
    {
        $userId = (int) auth()->id();
        $lock   = service('coinLockingService')->findOwnedLock($userId, $lockRef);
        if ($lock === null) {
            return redirect()->to(site_url('member/locking'))->with('error', 'Lock not found.');
        }

        return view('member/locking/show', [
            'title'  => 'Lock Details',
            'lock'   => $lock,
            'events' => service('coinLockingService')->eventsForLock($lockRef),
            'summary'=> service('memberCoinOwnershipService')->summaryForUser($userId),
        ]);
    }

    public function unlock(string $lockRef)
    {
        $userId = (int) auth()->id();

        try {
            $result = service('coinLockingService')->unlockCoins($userId, $lockRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        $msg = ! empty($result['duplicate'])
            ? 'This lock was already unlocked.'
            : 'Coins unlocked and restored to your available balance.';

        return redirect()->to(site_url('member/locking/' . $lockRef))->with('success', $msg);
    }

    public function history()
    {
        $userId = (int) auth()->id();
        $status = trim((string) $this->request->getGet('status'));
        $result = service('coinLockingService')->searchLocks(
            $status !== '' ? $status : null,
            $userId
        );

        return view('member/locking/history', [
            'title'  => 'Lock History',
            'rows'   => $result['rows'],
            'pager'  => $result['pager'],
            'status' => $status,
            'summary'=> service('memberCoinOwnershipService')->summaryForUser($userId),
        ]);
    }
}
