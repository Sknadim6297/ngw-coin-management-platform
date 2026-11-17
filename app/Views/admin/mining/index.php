<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'mining']) ?>
<p class="page-subtitle">Mining simulation dashboard. Preview amounts are <strong>not</strong> earnings and never credit wallets.</p>
<div class="alert alert-warning">Actual Mining: Not Activated · Simulations are non-payable and non-posted.</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Current Rate</h2>
            <?php if (empty($snap['current_rate'])): ?>
                <div class="text-secondary">Not Configured — no approved rate for this month.</div>
            <?php else: ?>
                <div><code><?= esc($snap['current_rate']['rate_ref']) ?></code></div>
                <div><?= esc($snap['current_rate']['rate_percent']) ?>% · <?= esc($snap['current_rate']['rate_basis']) ?> · <?= esc($snap['current_rate']['effective_month']) ?></div>
            <?php endif; ?>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/mining/rates') ?>">Rate Configs</a>
                <?php if ($canPropose): ?>
                    <a class="btn btn-gold btn-sm" href="<?= site_url('admin/mining/rates/create') ?>">New Draft Rate</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Last Simulation Run</h2>
            <?php if (empty($snap['last_run'])): ?>
                <div class="text-secondary">No simulation runs yet.</div>
            <?php else: ?>
                <div><code><?= esc($snap['last_run']['run_ref']) ?></code></div>
                <div class="small text-secondary">Date <?= esc($snap['last_run']['calculation_date']) ?> · status <?= esc($snap['last_run']['status']) ?></div>
                <div>Preview total: <?= esc($snap['last_run']['total_preview_amount']) ?> (simulation only)</div>
            <?php endif; ?>
            <?php if ($canSimulate): ?>
                <form class="mt-3" method="post" action="<?= site_url('admin/mining/simulate') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="date" value="<?= esc(date('Y-m-d')) ?>">
                    <button class="btn btn-gold btn-sm" type="submit">Run Today Simulation</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
