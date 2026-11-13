<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 4D finance ops UI: alerts, remediation, allocation lifecycle.
 */
class FinanceOpsController extends BaseController
{
    public function alerts()
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $status = trim((string) $this->request->getGet('status'));
        $type   = trim((string) $this->request->getGet('type'));
        $result = service('financialAlertService')->search(
            $status !== '' ? $status : null,
            $type !== '' ? $type : null
        );

        return view('admin/finance/alerts', [
            'title'  => 'Financial Alerts',
            'rows'   => $result['rows'],
            'pager'  => $result['pager'],
            'status' => $status,
            'type'   => $type,
            'canAck' => $this->canFinance('finance.alerts.acknowledge'),
        ]);
    }

    public function acknowledgeAlert(string $alertRef)
    {
        if (! $this->canFinance('finance.alerts.acknowledge')) {
            return $this->deny();
        }

        try {
            service('financialAlertService')->acknowledge(
                $alertRef,
                (int) auth()->id(),
                (string) $this->request->getPost('ack_note')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/finance/alerts'))->with('success', 'Alert acknowledged.');
    }

    public function remediations()
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $status = trim((string) $this->request->getGet('status'));
        $result = service('financialRemediationService')->search($status !== '' ? $status : null);

        return view('admin/finance/remediations', [
            'title'     => 'Remediation Queue',
            'rows'      => $result['rows'],
            'pager'     => $result['pager'],
            'status'    => $status,
            'canPropose'=> $this->canFinance('finance.remediation.propose'),
            'canApprove'=> $this->canFinance('finance.remediation.approve'),
        ]);
    }

    public function remediationCreate()
    {
        if (! $this->canFinance('finance.remediation.propose')) {
            return $this->deny();
        }

        return view('admin/finance/remediation_form', [
            'title' => 'Propose Remediation',
        ]);
    }

    public function remediationStore()
    {
        if (! $this->canFinance('finance.remediation.propose')) {
            return $this->deny();
        }

        try {
            $evidenceFile = null;
            $file = $this->request->getFile('evidence_file');
            if ($file !== null && $file->isValid() && ! $file->hasMoved()) {
                $mime = (string) $file->getMimeType();
                $allowed = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
                if (! in_array($mime, $allowed, true)) {
                    throw new InvalidArgumentException('Evidence file must be JPEG, PNG, WebP, or PDF.');
                }
                if ($file->getSize() > 5 * 1024 * 1024) {
                    throw new InvalidArgumentException('Evidence file must be 5MB or smaller.');
                }
                $dir = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'remediation_evidence';
                if (! is_dir($dir)) {
                    mkdir($dir, 0755, true);
                }
                $stored = $file->getRandomName();
                $file->move($dir, $stored);
                $evidenceFile = [
                    'stored_name'   => $stored,
                    'original_name' => $file->getClientName(),
                    'mime'          => $mime,
                ];
            }

            $type = trim((string) $this->request->getPost('remediation_type'));
            $row = service('financialRemediationService')->propose(
                (int) auth()->id(),
                $type,
                trim((string) $this->request->getPost('title')),
                trim((string) $this->request->getPost('reason')),
                $this->nullablePost('evidence_notes'),
                $this->nullablePost('entity_type'),
                $this->nullablePost('entity_id'),
                ['note' => 'Captured at proposal time; no ledger mutation.'],
                ['note' => 'Planned investigation outcome only — execution unavailable in Phase 4E.'],
                $this->nullablePost('idempotency_key'),
                $this->nullablePost('reason_code') ?? $type,
                $evidenceFile
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/finance/remediations/' . $row['proposal_ref']))
            ->with('success', 'Remediation proposed. Dual-control review required. Execution is unavailable.');
    }

    public function remediationShow(string $proposalRef)
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $row = service('financialRemediationService')->findByRef($proposalRef);
        if ($row === null) {
            return redirect()->to(site_url('admin/finance/remediations'))->with('error', 'Proposal not found.');
        }

        return view('admin/finance/remediation_show', [
            'title'      => 'Remediation Detail',
            'proposal'   => $row,
            'events'     => service('financialRemediationService')->eventsFor($proposalRef),
            'canApprove' => $this->canFinance('finance.remediation.approve'),
        ]);
    }

    public function remediationApprove(string $proposalRef)
    {
        if (! $this->canFinance('finance.remediation.approve')) {
            return $this->deny();
        }

        try {
            service('financialRemediationService')->approve(
                $proposalRef,
                (int) auth()->id(),
                (string) $this->request->getPost('review_note'),
                $this->request->getPost('confirm') === '1'
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/finance/remediations/' . $proposalRef))
            ->with('success', 'Proposal dual-approved. Ledger execution remains unavailable by design.');
    }

    public function remediationReject(string $proposalRef)
    {
        if (! $this->canFinance('finance.remediation.approve')) {
            return $this->deny();
        }

        try {
            service('financialRemediationService')->reject(
                $proposalRef,
                (int) auth()->id(),
                (string) $this->request->getPost('review_note')
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        return redirect()->to(site_url('admin/finance/remediations/' . $proposalRef))
            ->with('success', 'Proposal rejected.');
    }

    public function allocations()
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $status = trim((string) $this->request->getGet('status'));
        $model  = model(\App\Models\UsdtPaymentAllocationModel::class);
        if ($status !== '') {
            $model->where('status', $status);
        }
        $rows = $model->orderBy('id', 'DESC')->paginate(20);

        return view('admin/finance/allocations', [
            'title'  => 'Payment Allocations',
            'rows'   => $rows,
            'pager'  => $model->pager,
            'status' => $status,
        ]);
    }

    public function allocationShow(string $allocationRef)
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $row = model(\App\Models\UsdtPaymentAllocationModel::class)
            ->where('allocation_ref', $allocationRef)
            ->first();
        if ($row === null) {
            return redirect()->to(site_url('admin/finance/allocations'))->with('error', 'Allocation not found.');
        }

        $events = db_connect()->table('usdt_payment_allocation_events')
            ->where('allocation_ref', $allocationRef)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return view('admin/finance/allocation_show', [
            'title'      => 'Allocation Lifecycle',
            'allocation' => $row,
            'events'     => $events,
        ]);
    }

    public function runMonitor()
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        $result = service('paymentIntegrityMonitorService')->run(50, 'admin:' . auth()->id());

        $msg = $result['skipped']
            ? ('Monitor skipped: ' . ($result['reason'] ?? 'busy'))
            : ('Monitor complete. Findings=' . $result['findings'] . ', alerts=' . $result['alerts_raised'] . '. No ledger mutation.');

        return redirect()->to(site_url('admin/finance/alerts'))
            ->with($result['skipped'] ? 'error' : 'success', $msg);
    }

    public function dashboard()
    {
        if (! $this->canFinance('finance.monitor.view')) {
            return $this->deny();
        }

        return view('admin/finance/dashboard', [
            'title' => 'Finance Overview',
            'stats' => service('financeDashboardService')->summary(),
        ]);
    }

    public function notifications()
    {
        if (! $this->canFinance('finance.notifications.manage')) {
            return $this->deny();
        }

        $rows = db_connect()->table('notification_outbox')
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()
            ->getResultArray();

        return view('admin/finance/notifications', [
            'title'       => 'Notification Outbox',
            'rows'        => $rows,
            'configured'  => service('financialNotificationService')->isDeliveryConfigured(),
        ]);
    }

    private function canFinance(string $permission): bool
    {
        $user = auth()->user();

        // Phase 4E: least privilege — no blanket admin bypass for finance actions.
        return $user !== null && $user->can($permission);
    }

    private function deny()
    {
        return redirect()->to(site_url('admin/dashboard'))
            ->with('error', 'You do not have permission for this financial control.');
    }

    private function nullablePost(string $key): ?string
    {
        $v = $this->request->getPost($key);
        if ($v === null) {
            return null;
        }
        $v = trim((string) $v);

        return $v === '' ? null : $v;
    }
}
