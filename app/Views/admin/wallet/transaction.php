<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php $profile = $result['profile']; ?>
<p class="page-subtitle">Immutable ownership ledger audit detail for <?= esc($profile->member_id) ?>.</p>

<div class="card-premium mb-4">
    <div class="row g-3">
        <div class="col-md-4"><div class="text-secondary mb-1">Transaction Ref</div><code><?= esc($txn['transaction_ref']) ?></code></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Type</div><?= esc($txn['type']) ?></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Direction</div><?= esc($txn['direction']) ?></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Quantity</div><strong><?= esc($txn['quantity']) ?></strong></div>
        <div class="col-md-2"><div class="text-secondary mb-1">Created</div><?= esc($txn['created_at']) ?></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Purchase Ref</div><?= esc($txn['purchase_ref'] ?? '—') ?></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Lock Ref</div><?= esc($txn['lock_ref'] ?? '—') ?></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Inventory Ref</div><?= esc($txn['inventory_transaction_ref'] ?? '—') ?></div>
    </div>
</div>

<div class="card-premium mb-4">
    <h2 class="h5 mb-3">Balance Snapshots</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Field</th><th>Previous</th><th>New</th></tr></thead>
            <tbody>
            <tr><td>Total</td><td><?= esc($txn['previous_balance']) ?></td><td><?= esc($txn['new_balance']) ?></td></tr>
            <tr><td>Available</td><td><?= esc($txn['previous_available'] ?? '—') ?></td><td><?= esc($txn['new_available'] ?? '—') ?></td></tr>
            <tr><td>Locked</td><td><?= esc($txn['previous_locked'] ?? '—') ?></td><td><?= esc($txn['new_locked'] ?? '—') ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions') ?>">Back to ledger</a>
<a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id)) ?>">Wallet overview</a>
<?= $this->endSection() ?>
