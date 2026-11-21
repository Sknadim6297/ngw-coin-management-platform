<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Purchase-triggered commission estimates from your downline. Estimated ≠ Actual.</p>
<div class="alert alert-warning">
    Estimates are preview only and are <strong>not</strong> withdrawable.
    Actual posted, available commission is <?= esc($wallet['available_balance'] ?? '0.00000000') ?> USDT.
</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Purchase Commission Estimates</h2>
        <?php if (empty($supportViewMode)): ?>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/commissions/history') ?>">Full History</a>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
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
                <tr><td colspan="7" class="text-secondary">No purchase commission calculations yet.</td></tr>
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
</div>

<div class="card-premium my-4">
    <h2 class="h5 mb-2">Legacy simulation items</h2>
    <p class="small text-secondary">Day-batch simulation history (also non-payable).</p>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Level</th>
                    <th>Purchase Ref</th>
                    <th>Preview</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="5" class="text-secondary">No simulation history yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= esc($row['calculation_date']) ?></td>
                        <td><?= esc((string) $row['level']) ?></td>
                        <td><code><?= esc($row['purchase_ref']) ?></code></td>
                        <td><?= esc($row['preview_amount']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
