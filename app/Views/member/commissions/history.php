<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Commission history — estimated purchase calcs and legacy simulations. Not withdrawable.</p>
<a class="btn btn-outline-gold btn-sm mb-3" href="<?= site_url('member/commissions') ?>">Back</a>

<h2 class="h5">Purchase calculations</h2>
<div class="table-responsive card-premium mb-4">
    <table class="table table-dark-premium table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>Date</th>
                <th>Level</th>
                <th>Purchase</th>
                <th>Rate%</th>
                <th>Estimated</th>
                <th>Posted</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($calcs)): ?>
            <tr><td colspan="7" class="text-secondary">No purchase calculations.</td></tr>
        <?php else: ?>
            <?php foreach ($calcs as $row): ?>
                <tr>
                    <td><?= esc($row['business_date']) ?></td>
                    <td>L<?= (int) $row['sponsor_level'] ?></td>
                    <td><code><?= esc($row['purchase_ref']) ?></code></td>
                    <td><?= esc((string) ($row['rate_percent'] ?? '—')) ?></td>
                    <td><?= esc($row['expected_amount_usdt']) ?></td>
                    <td><?= esc($row['posted_amount_usdt']) ?></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<h2 class="h5">Legacy simulation history</h2>
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
