<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<p class="page-subtitle">Commission-only USDT withdrawals. Manual payout + independent TX verification required before PAID. Auto blockchain payout remains OFF.</p>

<div class="alert alert-warning mb-3">
    <?= esc($status['withdrawal_enabled'] ? 'Kill-switch ON' : 'Commission Withdrawals Not Activated') ?>
    · Blockchain auto-payout <?= ! empty($status['blockchain_payout_enabled']) ? 'ON' : 'OFF' ?>
    · Fee/minimum <?= esc($status['fee_policy_status'] ?? 'pending_business_decision') ?>
    · No active payout controls while disabled
</div>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<form class="card-premium mb-3 row g-2" method="get">
    <div class="col-md-3"><input class="form-control form-control-sm" name="member_q" placeholder="Member ID / ref" value="<?= esc($filters['member_q'] ?? '') ?>"></div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending_admin_review','approved','payout_in_progress','payout_submitted','paid','rejected','failed','cancelled','draft'] as $st): ?>
                <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-gold btn-sm" type="submit">Filter</button></div>
</form>

<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm mb-0">
        <thead>
        <tr>
            <th>Ref</th>
            <th>Member ID</th>
            <th>Name</th>
            <th>Amount</th>
            <th>Network</th>
            <th>Destination</th>
            <th>Requested</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="9" class="text-secondary">No withdrawal requests.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><code><?= esc($row['withdrawal_ref']) ?></code></td>
                <td><?= esc($row['member_id']) ?></td>
                <td><?= esc($row['member_name'] ?? '') ?></td>
                <td><?= esc($row['amount']) ?> <?= esc($row['currency']) ?></td>
                <td><?= esc($row['network']) ?></td>
                <td><code><?= esc($row['destination_masked'] ?? '') ?></code></td>
                <td><?= esc($row['requested_at'] ?? $row['created_at'] ?? '') ?></td>
                <td><?= esc($row['status']) ?></td>
                <td><a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref'])) ?>">View</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
