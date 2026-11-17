<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use InvalidArgumentException;

/**
 * Phase 11A admin mining panel (rates, dry-run preview, wallet monitor).
 * Never enables live posting.
 */
class MiningController extends BaseController
{
    public function index()
    {
        if (! $this->canMining('mining.rates.view')) {
            return $this->deny();
        }

        $tab = strtolower(trim((string) ($this->request->getGet('tab') ?: 'overview')));
        if (! in_array($tab, ['overview', 'rates', 'preview', 'wallets', 'history', 'p2p'], true)) {
            $tab = 'overview';
        }

        $bizDate = service('miningEligibilityService')->businessDate();
        $date    = trim((string) ($this->request->getGet('date') ?: $bizDate));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $bizDate;
        }

        $status  = service('miningDailyEngineService')->status();
        $preview = service('miningDailyEngineService')->dryRun($date, 100, false);
        $snap    = service('miningSimulationService')->adminSnapshot();
        $missing = service('coinLockingService')->previewMissingMandatoryLocks(25);
        $runs    = [];
        if (db_connect()->tableExists('mining_daily_runs')) {
            $runs = db_connect()->table('mining_daily_runs')
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray();
        }

        $walletRows = [];
        if ($tab === 'wallets' && db_connect()->tableExists('mining_wallets')) {
            $walletRows = db_connect()->table('mining_wallets')
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
        }

        $p2pTransfers = [];
        $p2pReconcile = null;
        $p2pStatus    = service('miningP2pTransferService')->status();
        if ($tab === 'p2p') {
            if (! $this->canMining('mining.p2p.monitor') && ! $this->canMining('mining.rates.view')) {
                return $this->deny();
            }
            $p2pTransfers = service('miningP2pTransferService')->adminSearch([
                'date_from'    => trim((string) ($this->request->getGet('date_from') ?: '')),
                'date_to'      => trim((string) ($this->request->getGet('date_to') ?: '')),
                'member_id'    => trim((string) ($this->request->getGet('member_id') ?: '')),
                'status'       => trim((string) ($this->request->getGet('status') ?: '')),
                'transfer_ref' => trim((string) ($this->request->getGet('transfer_ref') ?: '')),
            ], 100);
            $p2pReconcile = service('miningP2pTransferService')->reconcile(200);
        }

        $kpis = [
            KpiCardFactory::ok('eligible_members', 'Eligible Members (preview)', (string) ($preview['totals']['eligible_count'] ?? 0), 'green', 'bi-person-check', 'members'),
            KpiCardFactory::ok('eligible_coins', 'Eligible Coins (total owned)', (string) ($preview['totals']['total_eligible_coins'] ?? '0'), 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('monthly_rate', 'Current Monthly Rate', $preview['rate']['rate_percent'] ?? 'Not configured', 'cyan', 'bi-percent', 'rates'),
            KpiCardFactory::ok('expected_daily', 'Expected Daily Mining', (string) ($preview['totals']['total_expected_amount'] ?? '0'), 'teal', 'bi-lightning-charge', 'coins'),
            KpiCardFactory::ok('posted', 'Actual Posted Mining', (string) ($preview['totals']['total_posted_amount'] ?? '0'), 'indigo', 'bi-safe', 'coins'),
            KpiCardFactory::unavailable('posting', 'Posting Status', 'gold', 'bi-slash-circle', 'OFF — dry-run / simulation only'),
            KpiCardFactory::ok('pending_rates', 'Pending Rate Approvals', $snap['pending_rate_approvals'], 'amber', 'bi-hourglass', 'rates'),
            KpiCardFactory::ok('missing_locks', 'Missing Mandatory Locks', (string) count($missing), 'orange', 'bi-lock', 'locks'),
            KpiCardFactory::unavailable('p2p', 'P2P Transfers', 'gold', 'bi-arrow-left-right', 'OFF — monitoring only'),
        ];

        return view('admin/mining/index', [
            'title'        => 'Mining',
            'tab'          => $tab,
            'date'         => $date,
            'kpis'         => $kpis,
            'status'       => $status,
            'preview'      => $preview,
            'snap'         => $snap,
            'rates'        => service('miningRateService')->listRates(),
            'runs'         => $runs,
            'wallets'      => $walletRows,
            'missing'      => $missing,
            'p2pTransfers' => $p2pTransfers,
            'p2pReconcile' => $p2pReconcile,
            'p2pStatus'    => $p2pStatus,
            'p2pFilters'   => [
                'date_from'    => trim((string) ($this->request->getGet('date_from') ?: '')),
                'date_to'      => trim((string) ($this->request->getGet('date_to') ?: '')),
                'member_id'    => trim((string) ($this->request->getGet('member_id') ?: '')),
                'status'       => trim((string) ($this->request->getGet('status') ?: '')),
                'transfer_ref' => trim((string) ($this->request->getGet('transfer_ref') ?: '')),
            ],
            'canPropose'   => $this->canMining('mining.rates.propose'),
            'canApprove'   => $this->canMining('mining.rates.approve'),
            'canSimulate'  => $this->canMining('mining.simulate.run'),
            'canP2p'       => $this->canMining('mining.p2p.monitor') || $this->canMining('mining.rates.view'),
        ]);
    }

    public function rates()
    {
        return redirect()->to(site_url('admin/mining?tab=rates'));
    }

    public function rateCreate()
    {
        if (! $this->canMining('mining.rates.propose')) {
            return $this->deny();
        }

        return view('admin/mining/rate_form', [
            'title' => 'Create Mining Rate Draft',
        ]);
    }

    public function rateStore()
    {
        if (! $this->canMining('mining.rates.propose')) {
            return $this->deny();
        }

        try {
            $row = service('miningRateService')->createDraft([
                'effective_month' => (string) $this->request->getPost('effective_month'),
                'rate_percent'    => (string) $this->request->getPost('rate_percent'),
                'rate_basis'      => (string) ($this->request->getPost('rate_basis') ?: 'monthly'),
                'notes'           => (string) $this->request->getPost('notes'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/mining/rates/' . rawurlencode($row['rate_ref'])))
            ->with('success', 'Draft rate created. Dual-control approval required. No mining credits posted.');
    }

    public function rateShow(string $rateRef)
    {
        if (! $this->canMining('mining.rates.view')) {
            return $this->deny();
        }

        try {
            $rate = service('miningRateService')->findByRef($rateRef);
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/mining?tab=rates'))->with('error', $e->getMessage());
        }

        return view('admin/mining/rate_show', [
            'title'      => 'Mining Rate',
            'rate'       => $rate,
            'events'     => service('miningRateService')->eventsFor($rateRef),
            'canApprove' => $this->canMining('mining.rates.approve'),
        ]);
    }

    public function rateApprove(string $rateRef)
    {
        if (! $this->canMining('mining.rates.approve')) {
            return $this->deny();
        }

        try {
            service('miningRateService')->approve($rateRef, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/mining/rates/' . rawurlencode($rateRef)))
            ->with('success', 'Rate approved for dry-run / simulation only. Actual mining posting remains OFF.');
    }

    public function rateDisable(string $rateRef)
    {
        if (! $this->canMining('mining.rates.approve')) {
            return $this->deny();
        }

        try {
            service('miningRateService')->disable($rateRef, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/mining/rates/' . rawurlencode($rateRef)))
            ->with('success', 'Rate disabled.');
    }

    public function simulate()
    {
        if (! $this->canMining('mining.simulate.run')) {
            return $this->deny();
        }

        $date = trim((string) ($this->request->getPost('date') ?: service('miningEligibilityService')->businessDate()));

        try {
            $result = service('miningDailyEngineService')->dryRun($date, 200, true, (int) auth()->id());
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/mining?tab=preview'))->with('error', $e->getMessage());
        }

        $msg = 'DRY-RUN completed (run ' . ($result['run_ref'] ?? 'n/a') . '). No wallet credits posted.';

        return redirect()->to(site_url('admin/mining?tab=preview&date=' . rawurlencode($date)))
            ->with('success', $msg);
    }

    public function walletShow(int $memberUserId)
    {
        if (! $this->canMining('mining.rates.view')) {
            return $this->deny();
        }

        $balance = service('miningWalletService')->balanceFor($memberUserId);
        $ledger  = service('miningWalletService')->ledgerFor($memberUserId, 100);

        return view('admin/mining/wallet_show', [
            'title'         => 'Mining Wallet',
            'memberUserId'  => $memberUserId,
            'balance'       => $balance,
            'ledger'        => $ledger,
        ]);
    }

    private function canMining(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }

    private function deny()
    {
        return redirect()->to(site_url('admin/dashboard'))
            ->with('error', 'You do not have mining configuration permission.');
    }
}
