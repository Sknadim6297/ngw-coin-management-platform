<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Mining simulation preview for your balances. This is <strong>not</strong> guaranteed income and does not credit your wallet.</p>
<div class="alert alert-warning">Simulation Only · Actual Mining: Not Activated</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Eligibility</h2>
            <div class="mb-2">Status: <strong><?= ! empty($eval['eligible']) ? 'Eligible (simulation)' : 'Not eligible / not configured' ?></strong></div>
            <div class="small text-secondary mb-2">Reasons: <?= esc(implode(', ', $eval['reason_codes'] ?? [])) ?></div>
            <div>Policy: available coins only · locked coins excluded</div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Current Approved Rate</h2>
            <?php if ($rate === null): ?>
                <div class="text-secondary">Not Configured</div>
            <?php else: ?>
                <div><?= esc($rate['rate_percent']) ?>% · <?= esc($rate['rate_basis']) ?> · <?= esc($rate['effective_month']) ?></div>
                <div class="small text-secondary mt-2">Daily factor is derived from basis (monthly ÷ days-in-month, annual ÷ 365).</div>
            <?php endif; ?>
            <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('member/mining/history') ?>">Simulation History</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
