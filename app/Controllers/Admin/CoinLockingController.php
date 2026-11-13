<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

class CoinLockingController extends BaseController
{
    public function index()
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        $status = trim((string) $this->request->getGet('status'));
        $locks  = service('coinLockingService')->searchLocks($status !== '' ? $status : null);
        $plans  = service('coinLockingService')->listPlans(false);

        $counts = [
            'active'   => db_connect()->table('member_coin_locks')->where('status', 'active')->countAllResults(),
            'matured'  => db_connect()->table('member_coin_locks')->where('status', 'matured')->countAllResults(),
            'unlocked' => db_connect()->table('member_coin_locks')->where('status', 'unlocked')->countAllResults(),
            'plans'    => count($plans),
            'enabled'  => count(array_filter($plans, static fn ($p) => (int) $p['is_enabled'] === 1)),
        ];

        return view('admin/locking/index', [
            'title'  => 'Coin Locking',
            'rows'   => $locks['rows'],
            'pager'  => $locks['pager'],
            'status' => $status,
            'plans'  => $plans,
            'counts' => $counts,
        ]);
    }

    public function plans()
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        return view('admin/locking/plans', [
            'title' => 'Locking Plans',
            'plans' => service('coinLockingService')->listPlans(false),
        ]);
    }

    public function planCreate()
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        return view('admin/locking/plan_form', [
            'title' => 'Create Locking Plan',
        ]);
    }

    public function planStore()
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        try {
            $plan = service('coinLockingService')->createPlan([
                'plan_code'     => $this->request->getPost('plan_code'),
                'name'          => $this->request->getPost('name'),
                'description'   => $this->request->getPost('description'),
                'min_quantity'  => $this->request->getPost('min_quantity'),
                'max_quantity'  => $this->request->getPost('max_quantity'),
                'duration_days' => $this->request->getPost('duration_days'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/locking/plans'))
            ->with('success', 'Plan created (disabled by default): ' . $plan['plan_code']);
    }

    public function planToggle(int $planId)
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        $enabled = (string) $this->request->getPost('enabled') === '1';

        try {
            service('coinLockingService')->setPlanEnabled($planId, $enabled, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/locking/plans'))
            ->with('success', $enabled ? 'Plan enabled for new locks.' : 'Plan disabled. Existing locks are unchanged.');
    }

    public function show(string $lockRef)
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        $row = service('coinLockingService')->findLockByRef($lockRef);
        if ($row === null) {
            return redirect()->to(site_url('admin/locking'))->with('error', 'Lock not found.');
        }

        return view('admin/locking/show', [
            'title'   => 'Lock Details',
            'lock'    => $row,
            'events'  => service('coinLockingService')->eventsForLock($lockRef),
            'summary' => service('memberCoinOwnershipService')->summaryForUser((int) $row['member_user_id']),
        ]);
    }

    public function runMaturity()
    {
        if ($deny = $this->denyUnlessAdmin()) {
            return $deny;
        }

        $result = service('coinLockingService')->checkMaturity(100);
        if ($result['skipped']) {
            return redirect()->back()->with('error', $result['reason'] ?? 'Maturity check busy.');
        }

        return redirect()->back()->with(
            'success',
            'Maturity check: scanned ' . $result['scanned'] . ', matured ' . $result['matured'] . '. No rewards generated.'
        );
    }

    private function denyUnlessAdmin()
    {
        $user = auth()->user();
        if ($user === null || ! $user->inGroup('admin')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Administrator access required for coin locking management.');
        }

        return null;
    }
}
