<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Immutable allocation lifecycle detail.</p>

<div class="card-premium mb-3">
    <div class="row g-3">
        <div class="col-md-4"><div class="text-secondary mb-1">Allocation</div><strong><?= esc($allocation['allocation_ref']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Status</div><strong><?= esc($allocation['status'] === 'linked' ? 'reserved (linked)' : $allocation['status']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Amount</div><strong><?= esc($allocation['amount_usdt']) ?></strong></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Deposit</div>
            <a href="<?= site_url('admin/payment-audit/' . $allocation['deposit_ref']) ?>"><?= esc($allocation['deposit_ref']) ?></a>
        </div>
        <div class="col-md-6"><div class="text-secondary mb-1">Purchase</div>
            <a href="<?= site_url('admin/purchases/' . $allocation['purchase_ref']) ?>"><?= esc($allocation['purchase_ref']) ?></a>
        </div>
        <div class="col-md-4"><div class="text-secondary mb-1">Consumed At</div><strong><?= esc($allocation['consumed_at'] ?? '—') ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Released At</div><strong><?= esc($allocation['released_at'] ?? '—') ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Release Reason</div><strong><?= esc($allocation['release_reason'] ?? '—') ?></strong></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Active Deposit Key</div><code><?= esc((string) ($allocation['deposit_active_key'] ?? 'null')) ?></code></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Active Purchase Key</div><code><?= esc((string) ($allocation['purchase_active_key'] ?? 'null')) ?></code></div>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Event Timeline</h2>
    <?php if ($events === []): ?>
        <div class="text-secondary">No allocation events recorded.</div>
    <?php else: ?>
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th><th>Transition</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= esc($event['created_at']) ?></td>
                    <td><strong><?= esc($event['event_type']) ?></strong></td>
                    <td><?= esc(($event['previous_status'] ?? '—') . ' → ' . ($event['new_status'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
