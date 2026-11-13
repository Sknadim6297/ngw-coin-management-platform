<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Administrator-managed locking plans. Plans are created disabled. No ROI fields are stored.</p>

<div class="mb-3">
    <a class="btn btn-gold" href="<?= site_url('admin/locking/plans/create') ?>">Create Plan</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/locking') ?>">Back to Locks</a>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Duration</th>
                <th>Min / Max</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($plans === []): ?>
                <tr><td colspan="6" class="text-secondary">No plans configured.</td></tr>
            <?php else: ?>
                <?php foreach ($plans as $plan): ?>
                    <tr>
                        <td><code><?= esc($plan['plan_code']) ?></code></td>
                        <td><?= esc($plan['name']) ?></td>
                        <td><?= (int) $plan['duration_days'] ?> days</td>
                        <td><?= esc($plan['min_quantity']) ?> / <?= esc($plan['max_quantity'] ?? '∞') ?></td>
                        <td><?= (int) $plan['is_enabled'] === 1 ? 'Enabled' : 'Disabled' ?></td>
                        <td>
                            <form method="post" action="<?= site_url('admin/locking/plans/' . $plan['id'] . '/toggle') ?>" class="d-inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="enabled" value="<?= (int) $plan['is_enabled'] === 1 ? '0' : '1' ?>">
                                <button class="btn btn-outline-gold btn-sm" type="submit">
                                    <?= (int) $plan['is_enabled'] === 1 ? 'Disable' : 'Enable' ?>
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
