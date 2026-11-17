<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Posted mining ledger and legacy simulation rows (simulation is not payable).</p>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('member/mining') ?>">Back</a>

<h2 class="h5">Posted mining ledger</h2>
<div class="table-responsive card-premium mb-4">
    <table class="table table-sm mb-0">
        <thead><tr><th>Date</th><th>Entry</th><th>Type</th><th>Amount</th><th>Balance</th></tr></thead>
        <tbody>
        <?php if ($rows === []): ?>
            <tr><td colspan="5" class="text-secondary">No posted mining credits (posting remains OFF).</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= esc($row['business_date']) ?></td>
                <td><code><?= esc($row['entry_ref']) ?></code></td>
                <td><?= esc($row['entry_type']) ?></td>
                <td><?= esc($row['amount']) ?></td>
                <td><?= esc($row['balance_after']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h2 class="h5">Legacy simulation history</h2>
<div class="alert alert-info small">SIMULATION ONLY — not wallet credits.</div>
<div class="table-responsive card-premium">
    <table class="table table-sm mb-0">
        <thead><tr><th>Date</th><th>Eligible</th><th>Preview</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($simRows)): ?>
            <tr><td colspan="4" class="text-secondary">No simulation rows.</td></tr>
        <?php endif; ?>
        <?php foreach ($simRows as $row): ?>
            <tr>
                <td><?= esc($row['calculation_date'] ?? $row['business_date'] ?? '') ?></td>
                <td><?= esc((string) ($row['eligible_coins'] ?? '')) ?></td>
                <td><?= esc((string) ($row['preview_amount'] ?? $row['amount'] ?? '')) ?></td>
                <td><?= esc((string) ($row['status'] ?? 'simulation')) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
