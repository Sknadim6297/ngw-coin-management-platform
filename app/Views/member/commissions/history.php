<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your commission simulation history · Simulation Only — Not Payable</p>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('member/commissions') ?>">Back</a>
<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>Date</th>
                <th>Level</th>
                <th>Purchase</th>
                <th>Basis Amt</th>
                <th>%</th>
                <th>Preview</th>
                <th>Eligible</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-secondary">No items.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= esc($row['calculation_date']) ?></td>
                    <td><?= esc((string) $row['level']) ?></td>
                    <td><code><?= esc($row['purchase_ref']) ?></code></td>
                    <td><?= esc($row['purchase_amount']) ?> <?= esc($row['currency']) ?></td>
                    <td><?= esc($row['percent']) ?></td>
                    <td><?= esc($row['preview_amount']) ?></td>
                    <td><?= ! empty($row['eligible']) ? 'Yes' : 'No' ?></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
