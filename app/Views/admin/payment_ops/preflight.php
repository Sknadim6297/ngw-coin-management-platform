<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'preflight']) ?>
<p class="page-subtitle">Read-only deployment preflight. Secrets are never printed. This does not deploy or enable live payments.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Controlled Review Ready</div>
            <strong><?= ! empty($report['ready_for_controlled_review']) ? 'Yes' : 'No — blockers present' ?></strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Live Payments Recommended</div>
            <strong><?= ! empty($report['live_payments_recommended']) ? 'Yes' : 'No' ?></strong>
            <div class="field-hint mt-1">Always No until explicit ops sign-off outside this tool.</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Generated</div>
            <strong><?= esc($report['generated_at']) ?></strong>
        </div>
    </div>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Detail</th>
                <th>Blocker</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($report['checks'] as $check): ?>
                <tr>
                    <td><?= esc($check['label']) ?></td>
                    <td><span class="badge-status <?= esc($check['status']) ?>"><?= esc($check['status']) ?></span></td>
                    <td><?= esc($check['detail']) ?></td>
                    <td><?= ! empty($check['blocker']) ? 'Yes' : 'No' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="notice-box mt-3">
    Missing PHP <code>intl</code> is a deployment blocker. Windows Plesk/IIS: enable the intl extension in PHP settings, recycle the app pool, then re-run this preflight.
</div>
<?= $this->endSection() ?>
