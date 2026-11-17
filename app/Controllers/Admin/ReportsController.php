<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use InvalidArgumentException;

/**
 * Phase 9B admin reports & analytics (read-only).
 */
class ReportsController extends BaseController
{
    public function index()
    {
        if (! $this->canViewReports()) {
            return redirect()->to(site_url('admin/dashboard'))
                ->with('error', 'You do not have permission to view reports.');
        }

        $tab = service('adminReportsService')->normalizeTab($this->request->getGet('tab'));

        try {
            $report = service('adminReportsService')->build(
                $tab,
                $this->filterInput(),
                $this->canViewSensitivePayments()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/reports?tab=' . rawurlencode($tab)))
                ->with('error', $e->getMessage());
        }

        return view('admin/reports/index', [
            'title'            => 'Reports & Analytics',
            'tab'              => $report['tab'],
            'tabs'             => $report['tabs'],
            'filters'          => $report['filters'],
            'kpis'             => $report['kpis'] ?? [],
            'rows'             => $report['rows'] ?? [],
            'total'            => (int) ($report['total'] ?? 0),
            'notes'            => $report['notes'] ?? [],
            'ownership'        => $report['ownership'] ?? null,
            'locks'            => $report['locks'] ?? [],
            'unlocks'          => $report['unlocks'] ?? [],
            'prices'           => $report['prices'] ?? [],
            'reconciled'       => $report['reconciled'] ?? null,
            'platform'         => $report['platform'] ?? null,
            'levels'           => $report['levels'] ?? null,
            'snapshot'         => $report['snapshot'] ?? null,
            'includeSensitive' => (bool) ($report['include_sensitive'] ?? false),
            'canExport'        => $this->canExportReports(),
            'exportUrl'        => site_url('admin/reports/export?' . http_build_query(array_filter([
                'tab'       => $tab,
                'date_from' => $this->request->getGet('date_from'),
                'date_to'   => $this->request->getGet('date_to'),
                'status'    => $this->request->getGet('status'),
                'sponsor'   => $this->request->getGet('sponsor'),
                'member_id' => $this->request->getGet('member_id'),
                'q'         => $this->request->getGet('q'),
            ], static fn ($v) => $v !== null && $v !== ''))),
        ]);
    }

    public function export()
    {
        if (! $this->canExportReports()) {
            return redirect()->to(site_url('admin/reports'))
                ->with('error', 'You do not have permission to export reports.');
        }

        $tab = service('adminReportsService')->normalizeTab($this->request->getGet('tab'));

        try {
            $csv = service('adminReportsService')->exportCsv(
                $tab,
                $this->filterInput(),
                $this->canViewSensitivePayments()
            );
        } catch (InvalidArgumentException $e) {
            return redirect()->to(site_url('admin/reports?tab=' . rawurlencode($tab)))
                ->with('error', $e->getMessage());
        }

        $filename = 'ngw-report-' . $tab . '-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody($csv);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterInput(): array
    {
        return [
            'date_from' => $this->request->getGet('date_from'),
            'date_to'   => $this->request->getGet('date_to'),
            'status'    => $this->request->getGet('status'),
            'sponsor'   => $this->request->getGet('sponsor'),
            'member_id' => $this->request->getGet('member_id'),
            'q'         => $this->request->getGet('q'),
            'page'      => $this->request->getGet('page'),
            'per_page'  => $this->request->getGet('per_page'),
        ];
    }

    private function canViewReports(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->can('admin.reports.view')
            || $user->can('admin.access')
            || $user->inGroup('admin');
    }

    private function canExportReports(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->can('admin.reports.export')
            || $user->can('admin.reports.view')
            || $user->inGroup('admin');
    }

    private function canViewSensitivePayments(): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        return $user->inGroup('admin')
            || $user->can('finance.monitor.view')
            || $user->inGroup('finance_viewer', 'finance_operator', 'finance_approver', 'finance_auditor');
    }
}
