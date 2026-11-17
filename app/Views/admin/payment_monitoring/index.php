<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Payment health, reconciliation, alerts, and allocation tools in one place.</p>

<?= view('components/payment_monitoring_tabs', ['active' => 'overview']) ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <a class="card-premium d-block text-decoration-none h-100" href="<?= site_url('admin/payment-health') ?>">
            <div class="text-secondary mb-1">Payment Health</div>
            <strong>Provider &amp; ops checks</strong>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card-premium d-block text-decoration-none h-100" href="<?= site_url('admin/payment-reconciliation') ?>">
            <div class="text-secondary mb-1">Reconciliation</div>
            <strong>Integrity differences</strong>
        </a>
    </div>
    <div class="col-md-4">
        <a class="card-premium d-block text-decoration-none h-100" href="<?= site_url('admin/finance/alerts') ?>">
            <div class="text-secondary mb-1">Alerts</div>
            <strong>Financial warnings</strong>
        </a>
    </div>
</div>

<?php if (! empty($finance) && is_array($finance)): ?>
    <div class="card-premium">
        <h2 class="h5 mb-3">Finance Snapshot</h2>
        <div class="row g-3">
            <?php
            $labels = [
                'open_alerts' => 'Open Alerts',
                'pending_investigations' => 'Pending Investigations',
                'reconciliation_issues' => 'Reconciliation Issues',
                'reconciliation_critical' => 'Critical Findings',
                'allocations_reserved' => 'Allocations Reserved',
                'allocations_consumed' => 'Allocations Consumed',
                'pending_remediations' => 'Pending Remediations',
                'notification_failures' => 'Notification Failures',
            ];
            foreach ($labels as $key => $label):
                if (! array_key_exists($key, $finance)) {
                    continue;
                }
                $value = $finance[$key];
            ?>
                <div class="col-md-4">
                    <div class="text-secondary mb-1"><?= esc($label) ?></div>
                    <strong><?= esc(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value) ?></strong>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php else: ?>
    <div class="notice-box">Open a tab above for health, reconciliation, audit, alerts, allocations, remediation, or deployment checks.</div>
<?php endif; ?>
<?= $this->endSection() ?>
