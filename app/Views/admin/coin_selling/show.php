<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning">
    <?= ! empty($status['coin_selling_enabled']) ? 'Kill-switch ON' : 'Coin Selling Not Activated' ?>
    · Approve / reject / settlement controls remain disabled in Phase 11E
    · Admin cannot edit coin balances
</div>
<div class="card-premium mb-3">
    <h2 class="h5"><code><?= esc($row['sell_ref']) ?></code> <span class="badge bg-secondary"><?= esc($row['status']) ?></span></h2>
    <div>Member <?= esc($row['member_id']) ?> · Purchase <code><?= esc($row['purchase_ref']) ?></code> · Lock <code><?= esc((string) ($row['lock_ref'] ?? '')) ?></code></div>
    <div>Qty <?= esc($row['quantity']) ?> · Price snapshot <?= esc((string) ($row['price_snapshot_usdt'] ?? '—')) ?></div>
    <div class="small text-secondary mt-2">Settlement <?= esc((string) ($row['settlement_asset'] ?? 'pending')) ?> <?= esc((string) ($row['settlement_amount'] ?? '')) ?> · Evidence <?= esc((string) ($row['settlement_evidence_ref'] ?? '—')) ?></div>
    <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('admin/coins/selling') ?>">Back</a>
</div>
<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h3 class="h6">Review</h3>
            <form method="post" action="<?= site_url('admin/coins/selling/' . rawurlencode($row['sell_ref']) . '/approve') ?>" class="mb-2">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="password" name="password" disabled>
                <button class="btn btn-outline-gold btn-sm" type="submit" disabled>Approve</button>
            </form>
            <form method="post" action="<?= site_url('admin/coins/selling/' . rawurlencode($row['sell_ref']) . '/reject') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="text" name="reason" disabled>
                <input class="form-control form-control-sm mb-2" type="password" name="password" disabled>
                <button class="btn btn-outline-gold btn-sm" type="submit" disabled>Reject</button>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h3 class="h6">Audit History</h3>
            <table class="table table-sm mb-0">
                <thead><tr><th>When</th><th>Event</th><th>Status</th></tr></thead>
                <tbody>
                <?php if (empty($row['events'])): ?>
                    <tr><td colspan="3" class="text-secondary">No events.</td></tr>
                <?php else: foreach ($row['events'] as $ev): ?>
                    <tr>
                        <td><?= esc($ev['created_at'] ?? '') ?></td>
                        <td><?= esc($ev['event_type'] ?? '') ?></td>
                        <td><?= esc(($ev['from_status'] ?? '') . ' → ' . ($ev['to_status'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
