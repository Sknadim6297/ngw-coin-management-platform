<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Secure detail for your own ownership ledger entry.</p>

<nav class="mb-3 small text-secondary" aria-label="Wallet breadcrumb">
    <a href="<?= site_url('member/dashboard') ?>">Dashboard</a>
    <span class="mx-1">→</span>
    <a href="<?= site_url('member/wallet') ?>">My Wallet</a>
    <span class="mx-1">→</span>
    <a href="<?= site_url('member/wallet/transactions') ?>">Transactions</a>
    <span class="mx-1">→</span>
    <span>Detail</span>
</nav>

<div class="card-premium mb-4">
    <div class="row g-3">
        <div class="col-md-6">
            <div class="text-secondary mb-1">Transaction Reference</div>
            <code><?= esc($txn['transaction_ref']) ?></code>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Ledger ID</div>
            <strong>#<?= (int) $txn['id'] ?></strong>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Type</div>
            <strong><?= esc($txn['type']) ?></strong>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Direction</div>
            <strong><?= esc($txn['direction']) ?></strong>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Quantity</div>
            <strong style="color: var(--gold-bright);"><?= esc($txn['quantity']) ?> coins</strong>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Timestamp</div>
            <?= esc($txn['created_at']) ?>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Member ID</div>
            <?= esc($txn['member_id']) ?>
        </div>
        <div class="col-md-3">
            <div class="text-secondary mb-1">Ledger Status</div>
            <?= esc($txn['status'] ?? 'settled') ?> (append-only)
        </div>
    </div>
</div>

<div class="card-premium mb-4">
    <h2 class="h5 mb-3">Balance Impact</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th></th>
                <th>Previous</th>
                <th>New</th>
                <th>Impact</th>
            </tr>
            </thead>
            <tbody>
            <tr>
                <td>Total Owned</td>
                <td><?= esc($txn['previous_balance']) ?></td>
                <td><?= esc($txn['new_balance']) ?></td>
                <td><?= esc($txn['total_impact'] ?? '—') ?></td>
            </tr>
            <tr>
                <td>Available</td>
                <td><?= esc($txn['previous_available'] ?? '—') ?></td>
                <td><?= esc($txn['new_available'] ?? '—') ?></td>
                <td><?= esc($txn['available_impact'] ?? '—') ?></td>
            </tr>
            <tr>
                <td>Locked</td>
                <td><?= esc($txn['previous_locked'] ?? '—') ?></td>
                <td><?= esc($txn['new_locked'] ?? '—') ?></td>
                <td><?= esc($txn['locked_impact'] ?? '—') ?></td>
            </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card-premium mb-4">
    <h2 class="h5 mb-3">Source</h2>
    <?php if (! empty($txn['purchase_ref'])): ?>
        <p class="mb-1">Purchase: <a href="<?= site_url('member/purchases/' . rawurlencode($txn['purchase_ref'])) ?>"><?= esc($txn['purchase_ref']) ?></a></p>
    <?php endif; ?>
    <?php if (! empty($txn['lock_ref'])): ?>
        <p class="mb-1">Lock: <a href="<?= site_url('member/locking/' . rawurlencode($txn['lock_ref'])) ?>"><?= esc($txn['lock_ref']) ?></a></p>
    <?php endif; ?>
    <?php if (! empty($txn['inventory_transaction_ref'])): ?>
        <p class="mb-1 text-secondary">Inventory ledger: <code><?= esc($txn['inventory_transaction_ref']) ?></code></p>
    <?php endif; ?>
    <?php if (empty($txn['purchase_ref']) && empty($txn['lock_ref'])): ?>
        <p class="text-secondary mb-0">No linked purchase or lock reference.</p>
    <?php endif; ?>
</div>

<a class="btn btn-outline-gold" href="<?= site_url('member/wallet/transactions') ?>">Back to history</a>
<?= $this->endSection() ?>
