<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Exceptions\AutomationBlockedException;
use App\Services\FinancialAutomationService;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Financial Automation Control Center — the daily-operations ON/OFF panel.
 *
 * It only flips DB-backed switches (after the readiness checks in FinancialAutomationService). It never
 * approves a policy, never creates an approver, never changes an env kill-switch and never posts anything.
 */
class FinancialAutomationController extends BaseController
{
    public function index()
    {
        if (! $this->canView()) {
            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Permission denied.');
        }

        $svc = service('financialAutomationService');
        try {
            $status = $svc->status(
                $this->request->getGet('reconcile') === '1',
                $this->request->getGet('preview') === '1'
            );
        } catch (Throwable $e) {
            log_message('error', 'Financial automation status failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->to(site_url('admin/dashboard'))->with('error', 'Financial Automation is unavailable. Ensure migrations are applied.');
        }

        return view('admin/financial_automation/index', [
            'title'      => 'Financial Automation',
            's'          => $status,
            'canControl' => $this->canControl(),
            'blocked'    => (array) session()->getFlashdata('automation_blockers'),
        ]);
    }

    public function on(string $control)
    {
        return $this->switchOne($control, true);
    }

    public function off(string $control)
    {
        return $this->switchOne($control, false);
    }

    public function startAll()
    {
        return $this->switchAll(true);
    }

    public function stopAll()
    {
        return $this->switchAll(false);
    }

    private function switchOne(string $control, bool $on)
    {
        if (! $this->canControl()) {
            return redirect()->to(site_url('admin/financial-automation'))->with('error', 'Permission denied.');
        }
        if (! in_array($control, FinancialAutomationService::ALL_CONTROLS, true)) {
            return redirect()->to(site_url('admin/financial-automation'))->with('error', 'Unknown control.');
        }

        try {
            $r = service('financialAutomationService')->setControl(
                $control,
                $on,
                (int) auth()->id(),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('confirm'),
                (string) $this->request->getPost('reason')
            );
        } catch (AutomationBlockedException $e) {
            return redirect()->to(site_url('admin/financial-automation'))
                ->with('error', 'Cannot turn ON — prerequisites are missing. Nothing was changed.')
                ->with('automation_blockers', $e->blockers());
        } catch (InvalidArgumentException | RuntimeException $e) {
            return redirect()->to(site_url('admin/financial-automation'))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Automation switch failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->to(site_url('admin/financial-automation'))->with('error', 'Unable to change the switch. Nothing was posted.');
        }

        return redirect()->to(site_url('admin/financial-automation'))->with('success', $r['message']);
    }

    private function switchAll(bool $on)
    {
        if (! $this->canControl()) {
            return redirect()->to(site_url('admin/financial-automation'))->with('error', 'Permission denied.');
        }

        try {
            $r = service('financialAutomationService')->setAll(
                $on,
                (int) auth()->id(),
                (string) $this->request->getPost('password'),
                (string) $this->request->getPost('confirm'),
                (string) $this->request->getPost('reason')
            );
        } catch (AutomationBlockedException $e) {
            return redirect()->to(site_url('admin/financial-automation'))
                ->with('error', 'Cannot start all automation — prerequisites are missing. Nothing was changed.')
                ->with('automation_blockers', $e->blockers());
        } catch (InvalidArgumentException | RuntimeException $e) {
            return redirect()->to(site_url('admin/financial-automation'))->with('error', $e->getMessage());
        } catch (Throwable $e) {
            log_message('error', 'Automation start/stop all failed: {m}', ['m' => $e->getMessage()]);

            return redirect()->to(site_url('admin/financial-automation'))->with('error', 'Unable to change the switches. Nothing was posted.');
        }

        return redirect()->to(site_url('admin/financial-automation'))->with('success', $r['message']);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('admin.settings')
            || $user->can('admin.access')
            || $user->can('finance.monitor.view')
            || $user->can('finance.remediation.approve')
        );
    }

    /** Same authority as policy approval: a finance approver or a platform administrator. */
    private function canControl(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('finance.remediation.approve')
            || $user->can('admin.settings')
        );
    }
}
