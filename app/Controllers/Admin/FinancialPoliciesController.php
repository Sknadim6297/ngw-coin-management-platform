<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\FinancialPolicyService;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 12E — Admin Financial Policies hub (configuration / approval only).
 */
class FinancialPoliciesController extends BaseController
{
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $svc = service('financialPolicyService');
        $domain = strtolower(trim((string) $this->request->getGet('domain')));
        if ($domain !== '' && ! in_array($domain, FinancialPolicyService::DOMAINS, true)) {
            $domain = '';
        }

        return view('admin/financial_policies/index', [
            'title'     => 'Financial Policies',
            'catalog'   => $svc->domainCatalog(),
            'readiness' => $svc->readinessDashboard(),
            'domain'    => $domain,
            'snapshot'  => $domain !== '' ? $svc->currentSnapshot($domain) : null,
            'versions'  => $svc->listByDomain($domain !== '' ? $domain : null, 40),
            'canDraft'  => $this->canDraft(),
            'canApprove'=> $this->canApprove(),
        ]);
    }

    public function show(string $ref)
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $row = service('financialPolicyService')->findByRef($ref);
        if ($row === null) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Policy not found.');
        }

        return view('admin/financial_policies/show', [
            'title'      => 'Policy ' . $ref,
            'policy'     => $row,
            'canDraft'   => $this->canDraft(),
            'canApprove' => $this->canApprove(),
        ]);
    }

    public function create()
    {
        if (! $this->canDraft()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        $domain = strtolower(trim((string) $this->request->getGet('domain')));
        if (! in_array($domain, FinancialPolicyService::DOMAINS, true)) {
            $domain = FinancialPolicyService::DOMAIN_MINING;
        }

        return view('admin/financial_policies/create', [
            'title'   => 'Create Policy Draft',
            'domain'  => $domain,
            'catalog' => service('financialPolicyService')->domainCatalog(),
            'snapshot'=> service('financialPolicyService')->currentSnapshot($domain),
        ]);
    }

    public function store()
    {
        if (! $this->canDraft()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        try {
            $payload = $this->request->getPost('payload');
            if (! is_array($payload)) {
                $payload = [];
            }
            $row = service('financialPolicyService')->createDraft(
                (string) $this->request->getPost('domain'),
                (string) $this->request->getPost('title'),
                $payload,
                (int) auth()->id(),
                (string) $this->request->getPost('effective_from'),
                (string) $this->request->getPost('notes')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Policy draft create failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->back()->withInput()->with('error', 'Unable to create draft. Ensure migrations are applied.');
        }

        return redirect()->to(site_url('admin/financial-policies/' . rawurlencode((string) $row['policy_ref'])))
            ->with('success', 'Draft created. Dual-control approval required before ACTIVE. Kill-switches remain OFF.');
    }

    public function submit(string $ref)
    {
        if (! $this->canDraft()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        try {
            service('financialPolicyService')->submitForApproval(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/financial-policies/' . rawurlencode($ref)))
            ->with('success', 'Submitted for approval.');
    }

    public function approve(string $ref)
    {
        if (! $this->canApprove()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        try {
            service('financialPolicyService')->approve(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/financial-policies/' . rawurlencode($ref)))
            ->with('success', 'Policy approved. This does NOT enable financial kill-switches.');
    }

    public function activate(string $ref)
    {
        if (! $this->canApprove()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        try {
            service('financialPolicyService')->activate(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/financial-policies/' . rawurlencode($ref)))
            ->with('success', 'Policy marked ACTIVE for readiness tracking only. Kill-switches remain OFF.');
    }

    public function reject(string $ref)
    {
        if (! $this->canApprove() && ! $this->canDraft()) {
            return redirect()->to(site_url('admin/financial-policies'))->with('error', 'Permission denied.');
        }

        try {
            service('financialPolicyService')->reject(
                $ref,
                (int) auth()->id(),
                (string) $this->request->getPost('reason'),
                (string) $this->request->getPost('password')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/financial-policies/' . rawurlencode($ref)))
            ->with('success', 'Policy rejected.');
    }

    /** Phase 13C — read-only Mining Business Decision Worksheet (no writes, no switch changes). */
    public function miningWorksheet()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        return view('admin/financial_policies/mining_worksheet', [
            'title'     => 'Mining Business Decision Worksheet',
            'worksheet' => service('miningPolicyWorksheetService')->build(),
            'canDraft'  => $this->canDraft(),
        ]);
    }

    public function preview()
    {
        if (! $this->canView()) {
            return $this->response->setStatusCode(403)->setJSON(['error' => 'denied']);
        }

        $type = (string) $this->request->getGet('type');
        try {
            if ($type === 'mining') {
                $out = service('financialPolicyService')->miningPreview(
                    (string) $this->request->getGet('owned'),
                    (string) $this->request->getGet('percent'),
                    (string) $this->request->getGet('divisor'),
                    $this->request->getGet('days') !== null ? (int) $this->request->getGet('days') : null,
                    in_array((string) $this->request->getGet('rounding'), ['half_up', 'down'], true) ? (string) $this->request->getGet('rounding') : 'half_up'
                );
            } elseif ($type === 'commission') {
                $out = service('financialPolicyService')->commissionPreview(
                    (string) $this->request->getGet('base'),
                    (string) ($this->request->getGet('l1') ?: '10'),
                    (string) ($this->request->getGet('l2') ?: '5'),
                    (string) ($this->request->getGet('l3') ?: '3')
                );
            } else {
                return $this->response->setStatusCode(400)->setJSON(['error' => 'unknown type']);
            }
        } catch (InvalidArgumentException $e) {
            return $this->response->setStatusCode(422)->setJSON(['error' => $e->getMessage()]);
        }

        return $this->response->setJSON($out);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('admin.settings')
            || $user->can('admin.access')
            || $user->can('finance.monitor.view')
            || $user->can('finance.remediation.propose')
        );
    }

    private function canDraft(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('admin.settings')
            || $user->can('finance.remediation.propose')
        );
    }

    private function canApprove(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('finance.remediation.approve')
            || $user->can('admin.settings')
        );
    }
}
