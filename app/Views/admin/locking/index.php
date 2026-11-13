<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Coin locking overview. Disabling a plan does not alter existing active locks. Maturity never creates ROI.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Active</div><strong><?= (int) $counts['active'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Matured</div><strong><?= (int) $counts['matured'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Unlocked</div><strong><?= (int) $counts['unlocked'] ?></strong></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Enabled Plans</div><strong><?= (int) $counts['enabled'] ?> / <?= (int) $counts['plans'] ?></strong></div></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-gold" href="<?= site_url('admin/locking/plans') ?>">Manage Plans</a>
    <form method="post" action="<?= site_url('admin/locking/maturity/run') ?>">
        <?= csrf_field() ?>
        <button class="btn btn-outline-gold" type="submit">Run Maturity Check</button>
    </form>
</div>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['active', 'matured', 'unlocked', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-gold" type="submit">Filter</button></div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ref</th>
                <th>Member</th>
                <th>Plan</th>
                <th>Qty</th>
                <th>Status</th>
                <th>Matures</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-secondary">No locks yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['lock_ref']) ?></code></td>
                        <td><?= esc($row['member_id']) ?></td>
                        <td><?= esc($row['plan_code']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><?= esc($row['matures_at']) ?></td>
                        <td><a href="<?= site_url('admin/locking/' . $row['lock_ref']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
