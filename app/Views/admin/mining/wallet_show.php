<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Mining wallet ledger (posted entries only).</p>
<div class="alert alert-warning">Posting enabled: <?= ! empty($balance['posting_enabled']) ? 'true' : 'false' ?> · Asset <?= esc($balance['asset_code']) ?></div>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card-premium">Available<br><strong><?= esc($balance['available_balance']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium">Credited<br><strong><?= esc($balance['total_credited']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium">Debited<br><strong><?= esc($balance['total_debited']) ?></strong></div></div>
</div>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('admin/mining?tab=wallets') ?>">Back</a>
<div class="table-responsive card-premium">
    <table class="table table-sm mb-0">
        <thead>
        <tr><th>Entry</th><th>Date</th><th>Type</th><th>Dir</th><th>Amount</th><th>Balance</th></tr>
        </thead>
        <tbody>
        <?php if ($ledger === []): ?>
            <tr><td colspan="6" class="text-secondary">No ledger entries (expected while posting OFF).</td></tr>
        <?php endif; ?>
        <?php foreach ($ledger as $row): ?>
            <tr>
                <td><code><?= esc($row['entry_ref']) ?></code></td>
                <td><?= esc($row['business_date']) ?></td>
                <td><?= esc($row['entry_type']) ?></td>
                <td><?= esc($row['direction']) ?></td>
                <td><?= esc($row['amount']) ?></td>
                <td><?= esc($row['balance_after']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
