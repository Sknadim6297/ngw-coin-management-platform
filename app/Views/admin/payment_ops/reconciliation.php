<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'reconciliation']) ?>
<p class="page-subtitle">Read-only accounting reconciliation. Findings are never auto-repaired.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Total Findings</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $report['summary']['total_findings'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Critical</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $report['summary']['critical'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Warning</div><div class="h3" style="color:var(--gold-bright)"><?= (int) $report['summary']['warning'] ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Generated</div><strong><?= esc($report['generated_at']) ?></strong></div></div>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Severity</th>
                <th>Code</th>
                <th>Message</th>
                <th>Refs</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($report['findings'] === []): ?>
                <tr><td colspan="4" class="text-secondary">No discrepancies detected.</td></tr>
            <?php else: ?>
                <?php foreach ($report['findings'] as $f): ?>
                    <tr>
                        <td><span class="badge-status <?= esc($f['severity']) ?>"><?= esc($f['severity']) ?></span></td>
                        <td><code><?= esc($f['code']) ?></code></td>
                        <td><?= esc($f['message']) ?></td>
                        <td>
                            <?php if (! empty($f['deposit_ref'])): ?>
                                <a href="<?= site_url('admin/payment-audit/' . $f['deposit_ref']) ?>"><?= esc($f['deposit_ref']) ?></a><br>
                            <?php endif; ?>
                            <?php if (! empty($f['purchase_ref'])): ?>
                                <a href="<?= site_url('admin/purchases/' . $f['purchase_ref']) ?>"><?= esc($f['purchase_ref']) ?></a>
                            <?php endif; ?>
                            <?php if (! empty($f['allocation_ref'])): ?>
                                <div class="text-secondary"><?= esc($f['allocation_ref']) ?></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
