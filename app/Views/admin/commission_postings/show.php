<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning">Kill-switch posting: <?= $postingOn ? 'ON' : 'OFF' ?> · is_executable=<?= esc((string) $row['is_executable']) ?> · is_posted=<?= esc((string) $row['is_posted']) ?></div>
<div class="card-premium mb-3">
    <h2 class="h5"><code><?= esc($row['proposal_ref']) ?></code> <span class="badge bg-secondary"><?= esc($row['status']) ?></span></h2>
    <div class="small">Purchase <?= esc($row['purchase_ref']) ?> · Level <?= esc((string) $row['level']) ?> · Beneficiary <?= esc($row['beneficiary_member_id']) ?></div>
    <div class="mt-2"><?= esc($row['proposed_amount']) ?> <?= esc($row['currency']) ?> (<?= esc($row['percent']) ?>% of <?= esc($row['purchase_amount']) ?>)</div>
    <div class="mt-3 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commission-postings') ?>">Back</a>
        <?php if ($row['status'] === 'draft' && $canPropose): ?>
            <form method="post" action="<?= site_url('admin/commission-postings/' . rawurlencode($row['proposal_ref']) . '/submit') ?>"><?= csrf_field() ?><button class="btn btn-gold btn-sm" type="submit">Submit</button></form>
        <?php endif; ?>
        <?php if ($row['status'] === 'pending_approval' && $canApprove): ?>
            <form method="post" action="<?= site_url('admin/commission-postings/' . rawurlencode($row['proposal_ref']) . '/approve') ?>"><?= csrf_field() ?><button class="btn btn-gold btn-sm" type="submit">Approve (no post)</button></form>
            <form method="post" action="<?= site_url('admin/commission-postings/' . rawurlencode($row['proposal_ref']) . '/reject') ?>" class="d-flex gap-1"><?= csrf_field() ?><input class="form-control form-control-sm" name="reason" placeholder="Reason" required><button class="btn btn-outline-danger btn-sm" type="submit">Reject</button></form>
        <?php endif; ?>
    </div>
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
