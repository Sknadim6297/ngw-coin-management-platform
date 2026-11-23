<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<p class="page-subtitle">Commission-only USDT withdrawals. Manual payout + independent TX verification required before PAID. Auto blockchain payout remains OFF.</p>

<?php $authorized = ! empty($authorized); ?>
<div class="alert <?= $authorized ? 'alert-success' : 'alert-warning' ?> mb-3">
    <?= esc($statusLabel ?? ($status['withdrawal_enabled'] ? 'Kill-switch ON' : 'Commission Withdrawals Not Activated')) ?>
    · Blockchain auto-payout <?= ! empty($status['blockchain_payout_enabled']) ? 'ON (must stay OFF)' : 'OFF' ?>
    · Fee/minimum <?= esc($status['fee_policy_status'] ?? 'pending_business_decision') ?>
    <?php if (! $authorized): ?> · Mutations blocked until gates are satisfied<?php endif; ?>
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
            <th>Amount</th>
            <th>Fee</th>
            <th>Net</th>
            <th>BEP20</th>
            <th>KYC</th>
            <th>Requested</th>
            <th>Status</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-secondary">No withdrawal requests.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><code><?= esc($row['withdrawal_ref']) ?></code></td>
                <td><?= esc($row['member_id']) ?></td>
                <td><?= esc($row['amount']) ?></td>
                <td><?= esc((string) ($row['fee_amount'] ?? '—')) ?></td>
                <td><?= esc((string) ($row['net_amount'] ?? '—')) ?></td>
                <td><code><?= esc($row['destination_masked'] ?? '') ?></code></td>
                <td><?= esc((string) ($row['kyc_status'] ?? '—')) ?></td>
                <td><?= esc($row['requested_at'] ?? $row['created_at'] ?? '') ?></td>
                <td><?= esc($row['status']) ?></td>
                <td><a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref'])) ?>">View</a></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
