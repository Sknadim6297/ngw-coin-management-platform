<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Admin\Concerns\AdminAuthorization;
use App\Controllers\BaseController;

/**
 * Consolidated Payment Monitoring shell — delegates data to existing ops/finance services.
 */
class PaymentMonitoringController extends BaseController
{
    use AdminAuthorization;

    public function index()
    {
        if (! $this->userCan('finance.monitor.view', 'finance.payment.review')) {
            return $this->denyPermission();
        }

        $tab = trim((string) $this->request->getGet('tab'));
        $allowed = [
            'overview', 'health', 'reconciliation', 'audit',
            'alerts', 'allocations', 'remediation', 'notifications', 'preflight',
        ];
        if (! in_array($tab, $allowed, true)) {
            $tab = 'overview';
        }

        // Reuse existing controller actions via internal redirects for deep tabs that need their own URLs.
        // Overview and selected tabs render a hub with embedded summary + links preserving POST targets.
        return match ($tab) {
            'health'          => redirect()->to(site_url('admin/payment-health')),
            'reconciliation'  => redirect()->to(site_url('admin/payment-reconciliation')),
            'audit'           => redirect()->to(site_url('admin/payment-audit')),
            'alerts'          => redirect()->to(site_url('admin/finance/alerts')),
            'allocations'     => redirect()->to(site_url('admin/finance/allocations')),
            'remediation'     => redirect()->to(site_url('admin/finance/remediations')),
            'notifications'   => redirect()->to(site_url('admin/finance/notifications')),
            'preflight'       => redirect()->to(site_url('admin/deployment-preflight')),
            default           => $this->overview(),
        };
    }

    private function overview()
    {
        $finance = null;
        try {
            $finance = service('financeDashboardService')->summary();
        } catch (\Throwable $e) {
            log_message('warning', 'Payment monitoring overview finance summary skipped: {msg}', [
                'msg' => $e->getMessage(),
            ]);
        }

        return view('admin/payment_monitoring/index', [
            'title'   => 'Payment Monitoring',
            'tab'     => 'overview',
            'finance' => $finance,
        ]);
    }
}
