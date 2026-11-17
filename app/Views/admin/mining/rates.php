<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Mining rate configurations. Approved history is immutable. Rates start disabled as drafts.</p>
<div class="mb-3 d-flex gap-2">
    <?php if ($canPropose): ?>
        <a class="btn btn-gold" href="<?= site_url('admin/mining/rates/create') ?>">Create Draft</a>
    <?php endif; ?>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/mining') ?>">Dashboard</a>
</div>
<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Ref</th><th>Month</th><th>Percent</th><th>Basis</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No rate configurations.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('admin/mining/rates/' . rawurlencode($row['rate_ref'])) ?>"><?= esc($row['rate_ref']) ?></a></td>
                        <td><?= esc($row['effective_month']) ?></td>
                        <td><?= esc($row['rate_percent']) ?>%</td>
                        <td><?= esc($row['rate_basis']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><a class="btn btn-sm btn-outline-gold" href="<?= site_url('admin/mining/rates/' . rawurlencode($row['rate_ref'])) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
