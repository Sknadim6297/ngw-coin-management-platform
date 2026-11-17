<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'overview']) ?>
<p class="page-subtitle">Unified finance overview from live database counts. No fabricated totals.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Open Alerts</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $stats['open_alerts'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Integrity Investigations</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $stats['pending_investigations'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Recon Issues</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $stats['reconciliation_issues'] ?></div><div class="text-secondary small">Critical: <?= (int) $stats['reconciliation_critical'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Pending Remediations</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $stats['pending_remediations'] ?></div></div></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Reserved Allocations</div><strong><?= (int) $stats['allocations_reserved'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Consumed Allocations</div><strong><?= (int) $stats['allocations_consumed'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Released Allocations</div><strong><?= (int) $stats['allocations_released'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Notification Failures</div><strong><?= (int) $stats['notification_failures'] ?></strong><div class="text-secondary small"><?= ! empty($stats['notifications_configured']) ? 'Delivery configured' : 'Email delivery off' ?></div></div></div>
</div>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-gold" href="<?= site_url('admin/finance/alerts') ?>">Alerts</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/payment-reconciliation') ?>">Reconciliation</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/finance/remediations') ?>">Remediation</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/finance/notifications') ?>">Outbox</a>
</div>
<div class="field-hint mt-3">Generated <?= esc($stats['generated_at']) ?></div>
<?= $this->endSection() ?>
