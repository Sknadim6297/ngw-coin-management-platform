<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use InvalidArgumentException;

/**
 * Phase 7B admin mining configuration & simulation (non-payable).
 */
class MiningController extends BaseController
{
    public function index()
    {
        if (! $this->canMining('mining.rates.view')) {
            return $this->deny();
        }

        $snap = service('miningSimulationService')->adminSnapshot();
        $kpis = [
            KpiCardFactory::ok('eligible_members', 'Mining Eligible Members', $snap['eligible_members'], 'green', 'bi-person-check', 'members'),
            KpiCardFactory::ok('ineligible_members', 'Mining Ineligible Members', $snap['ineligible_members'], 'orange', 'bi-person-x', 'members'),
            KpiCardFactory::ok('eligible_coins', 'Total Eligible Coins', $snap['total_eligible_coins'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('today_preview', "Today's Mining Preview", $snap['today_preview'], 'cyan', 'bi-lightning-charge', 'coins'),
            KpiCardFactory::ok('total_preview', 'Total Simulated Mining', $snap['total_preview'], 'teal', 'bi-graph-up', 'coins'),
            KpiCardFactory::ok('pending_rates', 'Pending Rate Approvals', $snap['pending_rate_approvals'], 'amber', 'bi-hourglass', 'rates', site_url('admin/mining/rates')),
            KpiCardFactory::ok('sim_errors', 'Simulation Errors', $snap['simulation_errors'], 'red', 'bi-exclamation-triangle', 'runs'),
            KpiCardFactory::unavailable('actual_mining', 'Actual Mining', 'gold', 'bi-safe', $snap['actual_mining_status']),
        ];

        return view('admin/mining/index', [
            'title'      => 'Mining Simulation',
            'kpis'       => $kpis,
            'snap'       => $snap,
            'canPropose' => $this->canMining('mining.rates.propose'),
            'canApprove' => $this->canMining('mining.rates.approve'),
            'canSimulate'=> $this->canMining('mining.simulate.run'),
        ]);
    }

    public function rates()
    {
        if (! $this->canMining('mining.rates.view')) {
            return $this->deny();
        }

        return view('admin/mining/rates', [
            'title'      => 'Mining Rates',
            'rows'       => service('miningRateService')->listRates(),
            'canPropose' => $this->canMining('mining.rates.propose'),
            'canApprove' => $this->canMining('mining.rates.approve'),
        ]);
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
                'rate_basis'      => (string) $this->request->getPost('rate_basis'),
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
            return redirect()->to(site_url('admin/mining/rates'))->with('error', $e->getMessage());
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
            ->with('success', 'Rate approved for simulation only. Actual mining remains inactive.');
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

        $date = trim((string) ($this->request->getPost('date') ?: date('Y-m-d')));

        try {
            $result = service('miningSimulationService')->simulateDay($date, (int) auth()->id());
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/mining'))->with('error', $e->getMessage());
        }

        $msg = $result['reused']
            ? 'Existing simulation reused (idempotent). No wallet credits.'
            : 'Simulation completed. Preview only — not payable, not posted.';

        return redirect()->to(site_url('admin/mining'))->with('success', $msg);
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
