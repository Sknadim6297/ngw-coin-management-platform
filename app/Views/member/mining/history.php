<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your mining simulation history. All rows are non-payable and non-posted.</p>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('member/mining') ?>">Back</a>
<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Date</th>
                <th>Eligible</th>
                <th>Preview</th>
                <th>Rate</th>
                <th>Status</th>
                <th>Payable</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No simulation history yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['calculation_date']) ?></td>
                        <td><?= esc($row['eligible_coins']) ?></td>
                        <td><?= esc($row['preview_amount']) ?></td>
                        <td><?= esc($row['rate_ref'] ?? '—') ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><?= ((int) $row['is_payable'] === 1) ? 'Yes' : 'No' ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
