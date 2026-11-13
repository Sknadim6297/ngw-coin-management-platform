<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Member lock <?= esc($lock['lock_ref']) ?>. Historical quantities are immutable.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Member Total</div><strong><?= esc($summary['balance']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Available</div><strong><?= esc($summary['available']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Locked</div><strong><?= esc($summary['locked']) ?></strong></div></div>
</div>

<div class="card-premium mb-4">
    <div class="row g-3">
        <div class="col-md-3"><div class="text-secondary mb-1">Member</div><strong><?= esc($lock['member_id']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Status</div><strong><?= esc($lock['status']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Quantity</div><strong><?= esc($lock['quantity']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Plan</div><strong><?= esc($lock['plan_code']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Locked At</div><strong><?= esc($lock['locked_at']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Matures At</div><strong><?= esc($lock['matures_at']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Matured At</div><strong><?= esc($lock['matured_at'] ?? '—') ?></strong></div>
        <div class="col-md-3"><div class="text-secondary mb-1">Unlocked At</div><strong><?= esc($lock['unlocked_at'] ?? '—') ?></strong></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Lock Ledger Ref</div><code><?= esc($lock['ownership_lock_txn_ref'] ?? '—') ?></code></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Unlock Ledger Ref</div><code><?= esc($lock['ownership_unlock_txn_ref'] ?? '—') ?></code></div>
    </div>
    <div class="mt-3"><a href="<?= site_url('admin/locking') ?>">← Back</a></div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Lock Audit History</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if ($events === []): ?>
                <tr><td colspan="4" class="text-secondary">No events.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><?= esc($ev['created_at']) ?></td>
                        <td><?= esc($ev['event_type']) ?></td>
                        <td><?= esc($ev['actor_user_id'] ?? '—') ?></td>
                        <td><?= esc($ev['detail'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
