<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your mining eligibility and dry-run preview. Locked purchased coins remain eligible under total-owned policy.</p>
<div class="alert alert-warning">Mining Preview — Actual Credits Not Activated · No withdrawal · No P2P transfer</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Eligibility</h2>
            <div class="mb-2">Status: <strong><?= ! empty($eval['eligible']) ? 'Eligible (preview)' : 'Not eligible / not configured' ?></strong></div>
            <div class="small text-secondary mb-2">Reasons: <?= esc(implode(', ', $eval['reason_codes'] ?? [])) ?></div>
            <div>Policy: total owned coins (available + locked)</div>
            <div class="mt-2">Available <?= esc($eval['available']) ?> · Locked <?= esc($eval['locked']) ?></div>
            <div class="mt-2">Mining start: <?= esc((string) ($eval['mining_start_date'] ?? '—')) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Approved rate / wallet</h2>
            <?php if ($rate === null): ?>
                <div class="text-secondary">Not Configured</div>
            <?php else: ?>
                <div><?= esc($rate['rate_percent']) ?>% · <?= esc($rate['rate_basis']) ?> · <?= esc($rate['effective_month']) ?></div>
            <?php endif; ?>
            <div class="mt-2">Expected today (preview): <strong><?= esc($expected) ?></strong></div>
            <div class="mt-2">Mining wallet (<?= esc($wallet['asset_code']) ?>): <strong><?= esc($wallet['available_balance']) ?></strong></div>
            <div class="small text-secondary mt-2">Posted balance only — simulation is never shown as credited income.</div>
            <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('member/mining/history') ?>">Mining History</a>
        </div>
    </div>
</div>

<?php if ($ledger !== []): ?>
    <div class="card-premium">
        <h2 class="h5 mb-3">Recent posted ledger</h2>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Balance</th></tr></thead>
                <tbody>
                <?php foreach ($ledger as $row): ?>
                    <tr>
                        <td><?= esc($row['business_date']) ?></td>
                        <td><?= esc($row['entry_type']) ?></td>
                        <td><?= esc($row['amount']) ?></td>
                        <td><?= esc($row['balance_after']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
