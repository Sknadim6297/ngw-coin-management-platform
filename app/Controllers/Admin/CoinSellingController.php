<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11E admin coin selling management (mutations OFF).
 */
class CoinSellingController extends BaseController
{
    public function index()
    {
        if (! $this->can('coin.selling.view') && ! $this->can('admin.coins.manage')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $tab = strtolower(trim((string) ($this->request->getGet('tab') ?: 'overview')));
        if (! in_array($tab, ['overview', 'pending', 'lots', 'history', 'settlement', 'reconcile'], true)) {
            $tab = 'overview';
        }

        $filters = [
            'status'   => trim((string) ($this->request->getGet('status') ?: '')),
            'member_q' => trim((string) ($this->request->getGet('member_q') ?: '')),
        ];
        if ($tab === 'pending' && $filters['status'] === '') {
            $filters['status'] = 'pending_review';
        }

        $counts = service('coinSellingService')->adminStatusCounts();
        $status = service('coinSellingService')->status();
        $rows   = service('coinSellingService')->adminList($filters, 100);
        $lots   = [];
        if ($tab === 'lots') {
            $lots = db_connect()->table('member_coin_locks')
                ->where('purchase_ref !=', '')
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
            $biz = service('miningEligibilityService')->businessDate();
            foreach ($lots as &$lot) {
                $lot['is_matured'] = service('coinSellingService')->isLotMaturedByBusinessDate($lot, $biz);
            }
            unset($lot);
        }

        $reconcile = $tab === 'reconcile'
            ? service('coinSellingReconciliationService')->run(200)
            : null;

        $kpis = [
            KpiCardFactory::ok('pending', 'Pending Requests', (string) ($counts['pending_review'] ?? 0), 'amber', 'bi-hourglass', 'requests'),
            KpiCardFactory::ok('approved', 'Approved', (string) ($counts['approved'] ?? 0), 'cyan', 'bi-check2', 'requests'),
            KpiCardFactory::ok('settling', 'Settlement Queue', (string) (($counts['settlement_pending'] ?? 0) + ($counts['settlement_submitted'] ?? 0)), 'indigo', 'bi-cash', 'requests'),
            KpiCardFactory::ok('done', 'Completed', (string) ($counts['completed'] ?? 0), 'green', 'bi-bag-check', 'requests'),
            KpiCardFactory::unavailable('sell', 'Execution', 'gold', 'bi-slash-circle', 'Coin Selling Not Activated'),
        ];

        return view('admin/coin_selling/index', [
            'title'     => 'Coin Selling',
            'tab'       => $tab,
            'kpis'      => $kpis,
            'counts'    => $counts,
            'rows'      => $rows,
            'lots'      => $lots,
            'filters'   => $filters,
            'status'    => $status,
            'reconcile' => $reconcile,
            'canReview' => $this->can('coin.selling.review'),
            'enabled'   => service('coinSellingService')->enabled(),
        ]);
    }

    public function show(string $ref)
    {
        if (! $this->can('coin.selling.view') && ! $this->can('admin.coins.manage')) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }
        $row = service('coinSellingService')->findByRef($ref);
        if ($row === null) {
            return redirect()->to(site_url('admin/coins/selling'))->with('error', 'Sell request not found.');
        }

        return view('admin/coin_selling/show', [
            'title'     => 'Sell Request',
            'row'       => $row,
            'status'    => service('coinSellingService')->status(),
            'canReview' => $this->can('coin.selling.review'),
            'enabled'   => service('coinSellingService')->enabled(),
        ]);
    }

    public function approve(string $ref)
    {
        if (! $this->can('coin.selling.review')) {
            return redirect()->to(site_url('admin/coins/selling'))->with('error', 'Permission denied.');
        }
        try {
            service('coinSellingService')->approve($ref, (int) auth()->id(), (string) $this->request->getPost('password'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/coins/selling/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/coins/selling/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    public function reject(string $ref)
    {
        if (! $this->can('coin.selling.review')) {
            return redirect()->to(site_url('admin/coins/selling'))->with('error', 'Permission denied.');
        }
        try {
            service('coinSellingService')->reject(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('reason'),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException|RuntimeException $e) {
            return redirect()->to(site_url('admin/coins/selling/' . rawurlencode($ref)))->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/coins/selling/' . rawurlencode($ref)))->with('error', 'Unexpected state.');
    }

    private function can(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }
}
