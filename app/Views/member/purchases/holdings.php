<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Internal coin ownership from approved purchases. Total owned = available + locked.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card-premium">
            <div class="text-secondary mb-1">Total Owned</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($summary['balance']) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-premium">
            <div class="text-secondary mb-1">Available</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($summary['available'] ?? $summary['balance']) ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-premium">
            <div class="text-secondary mb-1">Locked</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($summary['locked'] ?? '0.00000000') ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card-premium">
            <div class="text-secondary mb-1">Total Acquired</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($summary['total_acquired']) ?></div>
            <div class="mt-2"><a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/locking') ?>">Coin Locking</a></div>
        </div>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Ownership Ledger</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Balance After</th>
                <th>Purchase / Lock Ref</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No ownership records yet. Approved purchases will appear here.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['transaction_ref']) ?></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['new_balance']) ?></td>
                        <td>
                            <?php if (! empty($row['purchase_ref'])): ?>
                                <a href="<?= site_url('member/purchases/' . $row['purchase_ref']) ?>"><?= esc($row['purchase_ref']) ?></a>
                            <?php elseif (! empty($row['lock_ref'])): ?>
                                <a href="<?= site_url('member/locking/' . $row['lock_ref']) ?>"><?= esc($row['lock_ref']) ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
