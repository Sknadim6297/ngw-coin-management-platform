<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Simulation history · Simulation Only — Not Payable · is_payable=0 · is_posted=0</p>
<form class="card-premium mb-3" method="get" action="<?= site_url('admin/commissions/history') ?>">
    <div class="row g-2 align-items-end">
        <div class="col-md-2"><label class="form-label">From</label><input type="date" class="form-control" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" class="form-control" name="date_to" value="<?= esc($filters['date_to'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Plan</label><input class="form-control" name="plan_ref" value="<?= esc($filters['plan_ref'] ?? '') ?>"></div>
        <div class="col-md-1"><label class="form-label">Level</label>
            <select class="form-select" name="level">
                <option value="">All</option>
                <?php for ($i = 1; $i <= 3; $i++): ?>
                    <option value="<?= $i ?>" <?= (string) ($filters['level'] ?? '') === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Member</label><input class="form-control" name="member_q" value="<?= esc($filters['member_q'] ?? '') ?>"></div>
        <div class="col-md-2"><label class="form-label">Purchase Ref</label><input class="form-control" name="purchase_ref" value="<?= esc($filters['purchase_ref'] ?? '') ?>"></div>
        <div class="col-md-1"><label class="form-label">Eligible</label>
            <select class="form-select" name="eligible">
                <option value="">All</option>
                <option value="1" <?= (string) ($filters['eligible'] ?? '') === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= (string) ($filters['eligible'] ?? '') === '0' ? 'selected' : '' ?>>No</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select" name="status">
                <option value="">All</option>
                <?php foreach (['simulated', 'skipped', 'error'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2 d-flex gap-2">
            <button class="btn btn-gold btn-sm" type="submit">Filter</button>
            <?php if ($canExport): ?>
                <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions/history/export?' . http_build_query(array_filter($filters, static fn ($v) => $v !== null && $v !== ''))) ?>">CSV</a>
            <?php endif; ?>
        </div>
    </div>
</form>

<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm align-middle mb-0">
        <thead>
            <tr>
                <th>Date</th>
                <th>Purchase</th>
                <th>Beneficiary</th>
                <th>Lvl</th>
                <th>Amount</th>
                <th>%</th>
                <th>Preview</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="8" class="text-secondary">No simulation items.</td></tr>
        <?php else: ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= esc($row['calculation_date']) ?></td>
                    <td><code><?= esc($row['purchase_ref']) ?></code><div class="small text-secondary"><?= esc($row['purchaser_member_id']) ?></div></td>
                    <td><?= esc($row['beneficiary_member_id']) ?></td>
                    <td><?= esc((string) $row['level']) ?></td>
                    <td><?= esc($row['purchase_amount']) ?> <?= esc($row['currency']) ?></td>
                    <td><?= esc($row['percent']) ?></td>
                    <td><?= esc($row['preview_amount']) ?> <span class="small text-secondary">sim</span></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>
</div>
<p class="small text-secondary mt-2">Showing page <?= (int) $page ?> · <?= (int) $total ?> matching rows (bounded).</p>
<?= $this->endSection() ?>
