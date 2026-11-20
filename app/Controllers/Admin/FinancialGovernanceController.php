<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Services\FinancialGovernanceService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Single-admin approval workflow: step 1 reviews the exact terms (password), step 2 is a separate confirmation
 * (password + typed phrase + every term ticked + acknowledgement). The approving services re-validate everything.
 */
class FinancialGovernanceController extends BaseController
{
    private const TYPES = [
        'financial_policy' => FinancialGovernanceService::SUBJECT_POLICY,
        'mining_rate'      => FinancialGovernanceService::SUBJECT_RATE,
        'commission_plan'  => FinancialGovernanceService::SUBJECT_PLAN,
    ];

    public function show(string $type, string $ref, string $intent)
    {
        $deny = $this->guard($type, $intent);
        if ($deny !== null) {
            return $deny;
        }
        $gov = service('financialGovernanceService');

        try {
            $terms = $gov->terms($type, $ref);
        } catch (InvalidArgumentException $e) {
            return redirect()->to($this->subjectUrl($type, $ref))->with('error', $e->getMessage());
        }

        return view('admin/financial_governance/show', [
            'title'   => 'Single-admin ' . $intent . ' — ' . $ref,
            'type'    => $type,
            'ref'     => $ref,
            'intent'  => $intent,
            'status'  => $gov->status(),
            'terms'   => $terms,
            'review'  => $gov->pendingReview($type, $ref, $intent, (int) auth()->id()),
            'phrase'  => $gov->confirmPhrase($intent, $ref),
            'history' => $gov->history($type, $ref),
            'backUrl' => $this->subjectUrl($type, $ref),
        ]);
    }

    public function review(string $type, string $ref, string $intent)
    {
        $deny = $this->guard($type, $intent);
        if ($deny !== null) {
            return $deny;
        }

        try {
            $r = service('financialGovernanceService')->review($type, $ref, $intent, (int) auth()->id(), (string) $this->request->getPost('password'));
        } catch (InvalidArgumentException | RuntimeException $e) {
            return redirect()->to($this->selfUrl($type, $ref, $intent))->with('error', $e->getMessage());
        }

        return redirect()->to($this->selfUrl($type, $ref, $intent))
            ->with('success', 'Review ' . $r['review_ref'] . ' recorded. Confirm separately before ' . $r['expires_at'] . '.');
    }

    public function confirm(string $type, string $ref, string $intent)
    {
        $deny = $this->guard($type, $intent);
        if ($deny !== null) {
            return $deny;
        }

        $password = (string) $this->request->getPost('password');
        $input    = [
            'password'        => $password,
            'review_ref'      => (string) $this->request->getPost('review_ref'),
            'terms_hash'      => (string) $this->request->getPost('terms_hash'),
            'confirm'         => (string) $this->request->getPost('confirm'),
            'confirmed_terms' => (array) ($this->request->getPost('confirmed_terms') ?? []),
            'acknowledge'     => (string) $this->request->getPost('acknowledge') === '1',
        ];
        $lock    = $this->request->getPost('lock_version');
        $actorId = (int) auth()->id();

        try {
            match (true) {
                $type === FinancialGovernanceService::SUBJECT_RATE   => service('miningRateService')->approve($ref, $actorId, $input),
                $type === FinancialGovernanceService::SUBJECT_PLAN   => service('commissionPlanService')->approve($ref, $actorId, $input),
                $intent === FinancialGovernanceService::INTENT_ACTIVATE => service('financialPolicyService')->activate($ref, $actorId, $password, $input, $lock !== null && $lock !== '' ? (int) $lock : null),
                default                                              => service('financialPolicyService')->approve($ref, $actorId, $password, $input, $lock !== null && $lock !== '' ? (int) $lock : null),
            };
        } catch (InvalidArgumentException | RuntimeException $e) {
            return redirect()->to($this->selfUrl($type, $ref, $intent))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Single-admin governance confirm failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->to($this->selfUrl($type, $ref, $intent))->with('error', 'Approval failed. Nothing was changed.');
        }

        return redirect()->to($this->subjectUrl($type, $ref))
            ->with('success', ucfirst($intent) . 'd under single_admin governance. Kill-switches and automation are unchanged (still OFF).');
    }

    private function guard(string $type, string $intent)
    {
        if (! isset(self::TYPES[$type]) || ! in_array($intent, [FinancialGovernanceService::INTENT_APPROVE, FinancialGovernanceService::INTENT_ACTIVATE], true)
            || ($intent === FinancialGovernanceService::INTENT_ACTIVATE && $type !== FinancialGovernanceService::SUBJECT_POLICY)) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Unknown governance action.');
        }
        $user  = auth()->user();
        $perms = match ($type) {
            FinancialGovernanceService::SUBJECT_RATE => ['mining.rates.approve', 'admin.settings'],
            FinancialGovernanceService::SUBJECT_PLAN => ['commission.plans.approve', 'admin.settings'],
            default                                  => ['finance.remediation.approve', 'admin.settings'],
        };
        $allowed = false;
        foreach ($perms as $perm) {
            $allowed = $allowed || ($user !== null && $user->can($perm));
        }
        if (! $allowed) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }
        $gov = service('financialGovernanceService');
        if (! $gov->isSingleAdmin() || (int) auth()->id() !== $gov->singleAdminUserId()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'Single-admin approval is only available to the designated administrator when platform.financialGovernanceMode = single_admin.');
        }

        return null;
    }

    private function selfUrl(string $type, string $ref, string $intent): string
    {
        return site_url('admin/financial-governance/' . $type . '/' . rawurlencode($ref) . '/' . $intent);
    }

    private function subjectUrl(string $type, string $ref): string
    {
        return site_url(match ($type) {
            FinancialGovernanceService::SUBJECT_RATE => 'admin/mining/rates/' . rawurlencode($ref),
            FinancialGovernanceService::SUBJECT_PLAN => 'admin/commissions/plans/' . rawurlencode($ref),
            default                                  => 'admin/financial-policies/' . rawurlencode($ref),
        });
    }
}
