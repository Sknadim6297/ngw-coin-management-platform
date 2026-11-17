<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Commission plans require dual-control approval. Approved plans are immutable. Rates are never auto-activated.</p>
<div class="d-flex justify-content-between align-items-center mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions') ?>">Dashboard</a>
    <?php if ($canPropose): ?>
        <a class="btn btn-gold btn-sm" href="<?= site_url('admin/commissions/plans/create') ?>">Create Draft</a>
    <?php endif; ?>
</div>
<div class="table-responsive card-premium">
    <table class="table table-dark-premium align-middle mb-0">
        <thead>
            <tr>
                <th>Plan</th>
                <th>Effective</th>
                <th>Levels %</th>
                <th>Policies</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="5" class="text-secondary">No plans yet.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <a href="<?= site_url('admin/commissions/plans/' . rawurlencode($row['plan_ref'])) ?>">
                            <code><?= esc($row['plan_ref']) ?></code>
                        </a>
                        <div class="small"><?= esc($row['plan_name']) ?></div>
                    </td>
                    <td>
                        <?= esc($row['effective_from']) ?>
                        <?php if (! empty($row['effective_to'])): ?>
                            → <?= esc($row['effective_to']) ?>
                        <?php else: ?>
                            → open
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $lv = $row['levels'] ?? []; ?>
                        L1 <?= esc($lv[1] ?? '—') ?> /
                        L2 <?= esc($lv[2] ?? '—') ?> /
                        L3 <?= esc($lv[3] ?? '—') ?>
                    </td>
                    <td class="small">
                        <?= esc($row['eligible_purchase_policy']) ?><br>
                        <?= esc($row['sponsor_eligibility_policy']) ?>
                    </td>
                    <td><span class="badge bg-secondary"><?= esc($row['status']) ?></span></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
