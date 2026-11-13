<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Lock reference <?= esc($lock['lock_ref']) ?>. Ownership total is unchanged while coins are locked.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Total Owned</div><strong><?= esc($summary['balance']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Available</div><strong><?= esc($summary['available']) ?></strong></div></div>
    <div class="col-md-4"><div class="card-premium"><div class="text-secondary mb-1">Locked</div><strong><?= esc($summary['locked']) ?></strong></div></div>
</div>

<div class="card-premium mb-4">
    <div class="row g-3">
        <div class="col-md-4"><div class="text-secondary mb-1">Status</div><strong><?= esc($lock['status']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Quantity</div><strong><?= esc($lock['quantity']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Plan</div><strong><?= esc($lock['plan_name']) ?> (<?= esc($lock['plan_code']) ?>)</strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Locked At</div><strong><?= esc($lock['locked_at']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Matures At</div><strong><?= esc($lock['matures_at']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Unlocked At</div><strong><?= esc($lock['unlocked_at'] ?? '—') ?></strong></div>
    </div>

    <?php if ($lock['status'] === 'matured'): ?>
        <form class="mt-3" method="post" action="<?= site_url('member/locking/' . $lock['lock_ref'] . '/unlock') ?>" onsubmit="return confirm('Unlock coins to available balance?');">
            <?= csrf_field() ?>
            <button class="btn btn-gold" type="submit">Unlock Coins</button>
        </form>
    <?php elseif ($lock['status'] === 'active'): ?>
        <div class="notice-box mt-3">This lock is still active. Early unlock is disabled by default.</div>
    <?php endif; ?>

    <div class="mt-3"><a href="<?= site_url('member/locking') ?>">← Back to Coin Locking</a></div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Audit Events</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th><th>Detail</th></tr></thead>
            <tbody>
            <?php if ($events === []): ?>
                <tr><td colspan="3" class="text-secondary">No events.</td></tr>
            <?php else: ?>
                <?php foreach ($events as $ev): ?>
                    <tr>
                        <td><?= esc($ev['created_at']) ?></td>
                        <td><?= esc($ev['event_type']) ?></td>
                        <td><?= esc($ev['detail'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
