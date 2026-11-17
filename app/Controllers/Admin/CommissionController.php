<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Dashboard\KpiCardFactory;
use InvalidArgumentException;

/**
 * Phase 7C admin commission plan configuration & simulation (non-payable).
 */
class CommissionController extends BaseController
{
    public function index()
    {
        if (! $this->canCommission('commission.plans.view')) {
            return $this->deny();
        }

        $tab = strtolower(trim((string) ($this->request->getGet('tab') ?: 'overview')));
        if (! in_array($tab, ['overview', 'rates', 'preview', 'proposals', 'ledger', 'reconcile'], true)) {
            $tab = 'overview';
        }

        $snap   = service('commissionSimulationService')->adminSnapshot();
        $engine = service('commissionPurchaseEngineService');
        $status = $engine->status();
        $plan   = $status['active_plan'] ?? null;
        $lv     = is_array($plan) ? ($plan['levels'] ?? []) : [];

        $purchaseRef = trim((string) $this->request->getGet('purchase'));
        $purchaseCalc = null;
        if ($purchaseRef !== '') {
            try {
                $purchaseCalc = $engine->calculateForPurchase($purchaseRef, false);
            } catch (\Throwable $e) {
                $purchaseCalc = ['error' => $e->getMessage()];
            }
        }

        $calcRows = [];
        if (db_connect()->tableExists('commission_purchase_calculations')) {
            $calcRows = db_connect()->table('commission_purchase_calculations')
                ->orderBy('id', 'DESC')
                ->limit(100)
                ->get()
                ->getResultArray();
        }

        $proposals = [];
        if ($tab === 'proposals' && db_connect()->tableExists('commission_posting_proposals')) {
            $proposals = db_connect()->table('commission_posting_proposals')
                ->orderBy('id', 'DESC')
                ->limit(50)
                ->get()
                ->getResultArray();
        }

        $reconcile = $tab === 'reconcile' ? $engine->reconcile(null, 100) : null;
        if ($tab === 'reconcile' && $this->canCommission('commission.wallet.reconcile')) {
            $walletRec = service('commissionWalletReconciliationService')->run();
            $reconcile['wallet_issues'] = $walletRec['issues'] ?? [];
        }

        $expectedPosted = '0.00000000';
        foreach ($calcRows as $row) {
            if (($row['status'] ?? '') === 'CALCULATED_NOT_POSTED') {
                $expectedPosted = \App\Libraries\MoneyMath::add(
                    $expectedPosted,
                    (string) $row['expected_amount_usdt'],
                    8
                );
            }
        }

        $kpis = [
            KpiCardFactory::ok('l1', 'L1 Rate (active)', ($lv[1] ?? '—') . '%', 'indigo', 'bi-1-circle', 'rates'),
            KpiCardFactory::ok('l2', 'L2 Rate (active)', ($lv[2] ?? '—') . '%', 'blue', 'bi-2-circle', 'rates'),
            KpiCardFactory::ok('l3', 'L3 Rate (active)', ($lv[3] ?? '—') . '%', 'teal', 'bi-3-circle', 'rates'),
            KpiCardFactory::ok('approved_purchases', 'Approved Purchases', $snap['approved_purchases'], 'green', 'bi-cart-check', 'requests'),
            KpiCardFactory::ok('expected', 'Expected (purchase calcs)', $expectedPosted, 'purple', 'bi-graph-up', 'usdt'),
            KpiCardFactory::ok('sim_total', 'Simulation Preview Total', $snap['total_preview'], 'cyan', 'bi-lightning', 'usdt'),
            KpiCardFactory::ok('pending', 'Pending Plan Approvals', $snap['pending_plan_approvals'], 'amber', 'bi-hourglass', 'plans'),
            KpiCardFactory::unavailable('actual', 'Posting Status', 'gold', 'bi-safe', 'OFF — no wallet credits'),
        ];

        return view('admin/commissions/index', [
            'title'         => 'Commissions',
            'tab'           => $tab,
            'kpis'          => $kpis,
            'snap'          => $snap,
            'status'        => $status,
            'plans'         => service('commissionPlanService')->listPlans(),
            'calcRows'      => $calcRows,
            'purchaseRef'   => $purchaseRef,
            'purchaseCalc'  => $purchaseCalc,
            'proposals'     => $proposals,
            'reconcile'     => $reconcile,
            'canPropose'    => $this->canCommission('commission.plans.propose'),
            'canApprove'    => $this->canCommission('commission.plans.approve'),
            'canSimulate'   => $this->canCommission('commission.simulate.run'),
            'canExport'     => $this->canCommission('commission.export'),
            'canPosting'    => $this->canCommission('commission.posting.propose'),
            'canWallet'     => $this->canCommission('commission.wallet.view'),
        ]);
    }

    public function plans()
    {
        if (! $this->canCommission('commission.plans.view')) {
            return $this->deny();
        }

        return view('admin/commissions/plans', [
            'title'      => 'Commission Plans',
            'rows'       => service('commissionPlanService')->listPlans(),
            'canPropose' => $this->canCommission('commission.plans.propose'),
            'canApprove' => $this->canCommission('commission.plans.approve'),
        ]);
    }

    public function planCreate()
    {
        if (! $this->canCommission('commission.plans.propose')) {
            return $this->deny();
        }

        return view('admin/commissions/plan_form', [
            'title' => 'Create Commission Plan Draft',
            'plan'  => null,
        ]);
    }

    public function planStore()
    {
        if (! $this->canCommission('commission.plans.propose')) {
            return $this->deny();
        }

        try {
            $row = service('commissionPlanService')->createDraft([
                'plan_name'                  => (string) $this->request->getPost('plan_name'),
                'effective_from'             => (string) $this->request->getPost('effective_from'),
                'effective_to'               => (string) $this->request->getPost('effective_to'),
                'level_count'                => (int) $this->request->getPost('level_count'),
                'level_1_percent'            => (string) $this->request->getPost('level_1_percent'),
                'level_2_percent'            => (string) $this->request->getPost('level_2_percent'),
                'level_3_percent'            => (string) $this->request->getPost('level_3_percent'),
                'commission_currency'        => (string) $this->request->getPost('commission_currency'),
                'eligible_purchase_policy'   => (string) $this->request->getPost('eligible_purchase_policy'),
                'sponsor_eligibility_policy' => (string) $this->request->getPost('sponsor_eligibility_policy'),
                'notes'                      => (string) $this->request->getPost('notes'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commissions/plans/' . rawurlencode($row['plan_ref'])))
            ->with('success', 'Draft plan created. Dual-control approval required. No commissions posted.');
    }

    public function planShow(string $planRef)
    {
        if (! $this->canCommission('commission.plans.view')) {
            return $this->deny();
        }

        $plan = service('commissionPlanService')->findByRef($planRef);
        if ($plan === null) {
            return redirect()->to(site_url('admin/commissions/plans'))->with('error', 'Plan not found.');
        }

        return view('admin/commissions/plan_show', [
            'title'      => 'Commission Plan',
            'plan'       => $plan,
            'canPropose' => $this->canCommission('commission.plans.propose'),
            'canApprove' => $this->canCommission('commission.plans.approve'),
        ]);
    }

    public function planUpdate(string $planRef)
    {
        if (! $this->canCommission('commission.plans.propose')) {
            return $this->deny();
        }

        try {
            service('commissionPlanService')->updateDraft($planRef, [
                'plan_name'                  => (string) $this->request->getPost('plan_name'),
                'effective_from'             => (string) $this->request->getPost('effective_from'),
                'effective_to'               => (string) $this->request->getPost('effective_to'),
                'level_count'                => (int) $this->request->getPost('level_count'),
                'level_1_percent'            => (string) $this->request->getPost('level_1_percent'),
                'level_2_percent'            => (string) $this->request->getPost('level_2_percent'),
                'level_3_percent'            => (string) $this->request->getPost('level_3_percent'),
                'eligible_purchase_policy'   => (string) $this->request->getPost('eligible_purchase_policy'),
                'sponsor_eligibility_policy' => (string) $this->request->getPost('sponsor_eligibility_policy'),
                'notes'                      => (string) $this->request->getPost('notes'),
            ], (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commissions/plans/' . rawurlencode($planRef)))
            ->with('success', 'Draft plan updated.');
    }

    public function planApprove(string $planRef)
    {
        if (! $this->canCommission('commission.plans.approve')) {
            return $this->deny();
        }

        try {
            service('commissionPlanService')->approve($planRef, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commissions/plans/' . rawurlencode($planRef)))
            ->with('success', 'Plan approved for simulation only. Actual commission posting remains inactive.');
    }

    public function planDisable(string $planRef)
    {
        if (! $this->canCommission('commission.plans.approve')) {
            return $this->deny();
        }

        try {
            service('commissionPlanService')->disable($planRef, (int) auth()->id());
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/commissions/plans/' . rawurlencode($planRef)))
            ->with('success', 'Plan disabled.');
    }

    public function simulate()
    {
        if (! $this->canCommission('commission.simulate.run')) {
            return $this->deny();
        }

        $date = trim((string) ($this->request->getPost('date') ?: date('Y-m-d')));

        try {
            $result = service('commissionSimulationService')->simulateDay($date, (int) auth()->id());
        } catch (\Throwable $e) {
            return redirect()->to(site_url('admin/commissions'))->with('error', $e->getMessage());
        }

        $msg = $result['reused']
            ? 'Existing simulation reused (idempotent). No wallet credits.'
            : 'Simulation completed. Preview only — not payable, not posted.';

        return redirect()->to(site_url('admin/commissions'))->with('success', $msg);
    }

    public function history()
    {
        if (! $this->canCommission('commission.plans.view')) {
            return $this->deny();
        }

        $filters = [
            'date_from'    => trim((string) $this->request->getGet('date_from')),
            'date_to'      => trim((string) $this->request->getGet('date_to')),
            'plan_ref'     => trim((string) $this->request->getGet('plan_ref')),
            'level'        => $this->request->getGet('level'),
            'member_q'     => trim((string) $this->request->getGet('member_q')),
            'purchase_ref' => trim((string) $this->request->getGet('purchase_ref')),
            'eligible'     => $this->request->getGet('eligible'),
            'status'       => trim((string) $this->request->getGet('status')),
        ];
        $page    = max(1, (int) $this->request->getGet('page'));
        $perPage = 25;
        $offset  = ($page - 1) * $perPage;
        $svc     = service('commissionSimulationService');

        return view('admin/commissions/history', [
            'title'      => 'Commission Simulation History',
            'filters'    => $filters,
            'rows'       => $svc->searchHistory($filters, $perPage, $offset),
            'total'      => $svc->countHistory($filters),
            'page'       => $page,
            'perPage'    => $perPage,
            'canExport'  => $this->canCommission('commission.export'),
        ]);
    }

    public function export()
    {
        if (! $this->canCommission('commission.export')) {
            return $this->deny();
        }

        $filters = [
            'date_from'    => trim((string) $this->request->getGet('date_from')),
            'date_to'      => trim((string) $this->request->getGet('date_to')),
            'plan_ref'     => trim((string) $this->request->getGet('plan_ref')),
            'level'        => $this->request->getGet('level'),
            'member_q'     => trim((string) $this->request->getGet('member_q')),
            'purchase_ref' => trim((string) $this->request->getGet('purchase_ref')),
            'eligible'     => $this->request->getGet('eligible'),
            'status'       => trim((string) $this->request->getGet('status')),
        ];

        $csv = service('commissionSimulationService')->exportCsv($filters);

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="commission-simulation-' . date('Ymd-His') . '.csv"')
            ->setBody($csv);
    }

    private function canCommission(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can($permission);
    }

    private function deny()
    {
        return redirect()->to(site_url('admin/dashboard'))
            ->with('error', 'You do not have commission configuration permission.');
    }
}
