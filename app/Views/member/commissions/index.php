<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your commission previews from referred purchases. <strong>Simulation Only — Not Payable.</strong></p>
<div class="alert alert-warning">Actual Commission: Not Activated · Previews never credit your wallet.</div>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h5 mb-0">Recent Simulation Items</h2>
        <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/commissions/history') ?>">Full History</a>
    </div>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Level</th>
                    <th>Purchase Ref</th>
                    <th>Purchase Amt</th>
                    <th>%</th>
                    <th>Preview</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($recent)): ?>
                <tr><td colspan="7" class="text-secondary">No simulation history yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recent as $row): ?>
                    <tr>
                        <td><?= esc($row['calculation_date']) ?></td>
                        <td><?= esc((string) $row['level']) ?></td>
                        <td><code><?= esc($row['purchase_ref']) ?></code></td>
                        <td><?= esc($row['purchase_amount']) ?> <?= esc($row['currency']) ?></td>
                        <td><?= esc($row['percent']) ?></td>
                        <td><?= esc($row['preview_amount']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p class="small text-secondary mt-2 mb-0">Purchaser payment details are hidden. Genealogy uses current sponsor relationships.</p>
</div>
<?= $this->endSection() ?>
