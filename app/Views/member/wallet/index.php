<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Internal coin wallet from the ownership ledger. Total owned = available + locked. Not a blockchain custody wallet.</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Total Owned</div>
            <div class="h3 mb-0" style="color: var(--gold-bright);"><?= esc($wallet['balance']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Available</div>
            <div class="h3 mb-0" style="color: var(--gold-bright);"><?= esc($wallet['available']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Locked</div>
            <div class="h3 mb-0" style="color: var(--gold-bright);"><?= esc($wallet['locked']) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Purchase Credits</div>
            <div class="h3 mb-0" style="color: var(--gold-bright);"><?= esc($wallet['total_acquired']) ?></div>
            <div class="small text-secondary mt-1"><?= (int) $wallet['purchase_credit_count'] ?> credit event(s)</div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Lifetime Locked (ledger)</div>
            <div class="h4 mb-0"><?= esc($wallet['total_locked_events']) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <div class="text-secondary mb-1">Lifetime Unlocked (ledger)</div>
            <div class="h4 mb-0"><?= esc($wallet['total_unlocked_events']) ?></div>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-gold" href="<?= site_url('member/wallet/transactions') ?>">Transaction History</a>
    <a class="btn btn-outline-gold" href="<?= site_url('member/locking') ?>">Coin Locking</a>
    <a class="btn btn-outline-gold" href="<?= site_url('member/purchases/buy') ?>">Buy Coins</a>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Recent Wallet Activity</h2>
        <a class="small" href="<?= site_url('member/wallet/transactions') ?>">View all</a>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Available After</th>
                <th>Locked After</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($wallet['recent'] === []): ?>
                <tr><td colspan="6" class="text-secondary">No wallet activity yet. Approved purchases and locks will appear here.</td></tr>
            <?php else: ?>
                <?php foreach ($wallet['recent'] as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><span class="badge text-bg-dark"><?= esc($row['type']) ?></span></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['new_available'] ?? '—') ?></td>
                        <td><?= esc($row['new_locked'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
