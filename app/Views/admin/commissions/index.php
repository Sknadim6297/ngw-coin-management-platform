<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'commissions']) ?>
<p class="page-subtitle">3-level purchase commission simulation. All amounts are <strong>Simulation Only — Not Payable</strong>.</p>
<div class="alert alert-warning">Actual Commission: Not Activated · Simulations never credit wallets or create payouts.</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Active Commission Plan</h2>
            <?php if (empty($snap['active_plan'])): ?>
                <div class="text-secondary">Not Configured — no approved plan covering today.</div>
            <?php else: ?>
                <div><code><?= esc($snap['active_plan']['plan_ref']) ?></code> · <?= esc($snap['active_plan']['plan_name']) ?></div>
                <div class="small text-secondary">
                    From <?= esc($snap['active_plan']['effective_from']) ?>
                    <?php if (! empty($snap['active_plan']['effective_to'])): ?>
                        to <?= esc($snap['active_plan']['effective_to']) ?>
                    <?php endif; ?>
                </div>
                <?php $lv = $snap['active_plan']['levels'] ?? []; ?>
                <div class="mt-2">L1 <?= esc($lv[1] ?? '—') ?>% · L2 <?= esc($lv[2] ?? '—') ?>% · L3 <?= esc($lv[3] ?? '—') ?>%</div>
            <?php endif; ?>
            <div class="mt-3 d-flex flex-wrap gap-2">
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions/plans') ?>">Plans</a>
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions/history') ?>">History</a>
                <?php if ($canPropose): ?>
                    <a class="btn btn-gold btn-sm" href="<?= site_url('admin/commissions/plans/create') ?>">New Draft Plan</a>
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
                <div>Preview total: <?= esc($snap['last_run']['total_preview_amount']) ?> USDT (simulation only)</div>
            <?php endif; ?>
            <?php if ($canSimulate): ?>
                <form class="mt-3" method="post" action="<?= site_url('admin/commissions/simulate') ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="date" value="<?= esc(date('Y-m-d')) ?>">
                    <button class="btn btn-gold btn-sm" type="submit">Run Today Simulation</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
