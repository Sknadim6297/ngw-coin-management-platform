<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning"><?= esc($status) ?> · Executable approve/payout disabled in Phase 7D</div>
<div class="card-premium mb-3">
    <h2 class="h5"><code><?= esc($row['withdrawal_ref']) ?></code> <span class="badge bg-secondary"><?= esc($row['status']) ?></span></h2>
    <div>Member <?= esc($row['member_id']) ?> · <?= esc($row['amount']) ?> <?= esc($row['currency']) ?> · <?= esc($row['network']) ?></div>
    <div class="mt-1">Destination (masked): <code><?= esc($row['destination_masked'] ?? '') ?></code></div>
    <div class="mt-2 small text-secondary">Available commission: <?= esc($wallet['available_balance'] ?? '0') ?> · Reserved: <?= esc($wallet['reserved_balance'] ?? '0') ?></div>
    <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('admin/withdrawals') ?>">Back</a>
</div>
<div class="card-premium">
    <h3 class="h6">Audit</h3>
    <table class="table table-dark-premium table-sm mb-0">
        <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach (($row['events'] ?? []) as $ev): ?>
            <tr><td><?= esc($ev['created_at'] ?? '') ?></td><td><?= esc($ev['event_type'] ?? '') ?></td><td><?= esc((string) ($ev['actor_user_id'] ?? '')) ?></td><td><?= esc(($ev['from_status'] ?? '') . ' → ' . ($ev['to_status'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
