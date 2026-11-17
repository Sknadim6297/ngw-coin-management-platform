<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Actual balances come from posted ledger entries only. Simulation is not payable.</p>
<div class="alert alert-warning"><?= esc($wdStatus) ?> · Commission posting disabled · No automatic payouts</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <h2 class="h5">Simulation Only — Not Payable</h2>
    <p class="mb-0 small">Today preview <?= esc($sim['today_preview']) ?> · Total preview <?= esc($sim['total_preview']) ?> ·
        L1 <?= esc($sim['level1_preview']) ?> / L2 <?= esc($sim['level2_preview']) ?> / L3 <?= esc($sim['level3_preview']) ?>
        · <a href="<?= site_url('member/commissions') ?>">Open simulation</a></p>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5">Recent Ledger Entries</h2>
            <div class="table-responsive">
                <table class="table table-dark-premium table-sm mb-0">
                    <thead><tr><th>When</th><th>Type</th><th>Amount</th><th>Available After</th></tr></thead>
                    <tbody>
                    <?php if (empty($entries)): ?>
                        <tr><td colspan="4" class="text-secondary">No posted ledger entries. Balance is zero.</td></tr>
                    <?php else: foreach ($entries as $e): ?>
                        <tr>
                            <td><?= esc($e['created_at'] ?? '') ?></td>
                            <td><?= esc($e['entry_type'] ?? '') ?></td>
                            <td><?= esc($e['amount'] ?? '') ?> <?= esc($e['currency'] ?? '') ?></td>
                            <td><?= esc($e['balance_after_available'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium">
            <div class="d-flex justify-content-between mb-2">
                <h2 class="h5 mb-0">Pending Withdrawals</h2>
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/withdrawals') ?>">All</a>
            </div>
            <?php if (empty($pending)): ?>
                <div class="text-secondary">No withdrawal requests.</div>
            <?php else: foreach ($pending as $w): ?>
                <div class="small mb-2"><code><?= esc($w['withdrawal_ref']) ?></code> · <?= esc($w['amount']) ?> · <?= esc($w['status']) ?></div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
