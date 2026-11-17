<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<div class="alert alert-warning"><?= esc($status) ?> · No blockchain payouts · Destination addresses are masked</div>
<form class="card-premium mb-3 row g-2" method="get">
    <div class="col-md-3"><input class="form-control" name="member_q" placeholder="Member / ref" value="<?= esc($filters['member_q'] ?? '') ?>"></div>
    <div class="col-md-2">
        <select class="form-select" name="status">
            <option value="">All statuses</option>
            <?php foreach (['draft','submitted','under_review','approved','rejected','cancelled'] as $st): ?>
                <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-gold btn-sm" type="submit">Filter</button></div>
</form>
<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm mb-0">
        <thead><tr><th>Ref</th><th>Member</th><th>Amount</th><th>Network</th><th>Destination</th><th>Status</th><th>Paid</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-secondary">No withdrawal requests.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><a href="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref'])) ?>"><code><?= esc($row['withdrawal_ref']) ?></code></a></td>
                <td><?= esc($row['member_id']) ?></td>
                <td><?= esc($row['amount']) ?> <?= esc($row['currency']) ?></td>
                <td><?= esc($row['network']) ?></td>
                <td><code><?= esc($row['destination_masked'] ?? '') ?></code></td>
                <td><?= esc($row['status']) ?></td>
                <td><?= ! empty($row['is_paid']) ? 'Yes' : 'No' ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
