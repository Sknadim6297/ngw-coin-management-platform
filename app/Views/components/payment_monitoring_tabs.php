<?= view('components/admin_tabs', [
    'active' => $active ?? 'overview',
    'tabs'   => [
        ['key' => 'overview', 'label' => 'Overview', 'url' => site_url('admin/payment-monitoring')],
        ['key' => 'health', 'label' => 'Payment Health', 'url' => site_url('admin/payment-health')],
        ['key' => 'reconciliation', 'label' => 'Reconciliation', 'url' => site_url('admin/payment-reconciliation')],
        ['key' => 'audit', 'label' => 'Audit History', 'url' => site_url('admin/payment-audit')],
        ['key' => 'alerts', 'label' => 'Alerts', 'url' => site_url('admin/finance/alerts')],
        ['key' => 'allocations', 'label' => 'Allocations', 'url' => site_url('admin/finance/allocations')],
        ['key' => 'remediation', 'label' => 'Remediation', 'url' => site_url('admin/finance/remediations')],
        ['key' => 'notifications', 'label' => 'Notifications', 'url' => site_url('admin/finance/notifications')],
        ['key' => 'preflight', 'label' => 'Deployment Checks', 'url' => site_url('admin/deployment-preflight')],
    ],
]) ?>
