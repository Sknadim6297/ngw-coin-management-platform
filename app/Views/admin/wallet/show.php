<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php $wallet = $result['overview']; $profile = $result['profile']; ?>
<p class="page-subtitle">Read-only wallet audit for <?= esc($profile->member_id) ?>. No balance manipulation controls are provided.</p>

<?= view('components/kpi_grid', ['kpis' => $wallet['kpis']]) ?>

<div class="d-flex flex-wrap gap-2 my-4">
    <a class="btn btn-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions') ?>">Transaction History</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/reconcile?member_id=' . rawurlencode($profile->member_id)) ?>">Reconcile This Wallet</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/members/' . rawurlencode($profile->member_id)) ?>">Member Profile</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/wallets') ?>">Back to Lookup</a>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Recent Ownership Activity</h2>
        <a class="small" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions') ?>">Full ledger</a>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Available Δ</th>
                <th>Locked Δ</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($wallet['recent'] === []): ?>
                <tr><td colspan="6" class="text-secondary">No ledger rows for this member.</td></tr>
            <?php else: ?>
                <?php foreach ($wallet['recent'] as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['available_impact'] ?? '—') ?></td>
                        <td><?= esc($row['locked_impact'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
