<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>

<?php
$authorized = ! empty($authorized);
$canMutate  = $authorized && ! empty($canReview);
$canSettle  = $authorized && ! empty($canPay);
$rowStatus  = (string) ($row['status'] ?? '');
?>
<div class="alert <?= $authorized ? 'alert-success' : 'alert-warning' ?>">
    <?= esc($statusLabel ?? ($status['withdrawal_enabled'] ? 'Kill-switch ON' : 'Commission Withdrawals Not Activated')) ?>
    <?php if (! $authorized): ?>
        · Approve / reject / payout / settle remain blocked until gates are satisfied
    <?php else: ?>
        · Manual payout + independent TX verification required before PAID
    <?php endif; ?>
    · Admin cannot edit wallet balances
</div>

<div class="card-premium mb-3">
    <h2 class="h5"><code><?= esc($row['withdrawal_ref']) ?></code> <span class="badge bg-secondary"><?= esc($row['status']) ?></span></h2>
    <div>Member <?= esc($row['member_id']) ?> · <?= esc($row['amount']) ?> <?= esc($row['currency']) ?> · <?= esc($row['network']) ?></div>
    <div class="mt-1">Fee <?= esc((string) ($row['fee_amount'] ?? '—')) ?> · Net payout <?= esc((string) ($row['net_amount'] ?? '—')) ?> · KYC <?= esc((string) ($kycStatus ?? '—')) ?></div>
    <div class="mt-1">Destination snapshot: <code><?= esc($row['destination_address_snapshot'] ?? $row['destination_masked'] ?? '') ?></code></div>
    <div class="mt-2 small text-secondary">
        Available <?= esc($wallet['available_balance'] ?? '0') ?> · Reserved <?= esc($wallet['reserved_balance'] ?? '0') ?>
        · Reserved flag <?= ! empty($row['is_reserved']) ? 'yes' : 'no' ?>
        · Paid flag <?= ! empty($row['is_paid']) ? 'yes' : 'no' ?>
        · Verification <?= esc((string) ($row['verification_status'] ?? 'n/a')) ?>
    </div>
    <?php if (! empty($row['address_change_review_required'])): ?>
        <div class="alert alert-danger mt-3 mb-0">Address changed after request — explicit secure review required. Do not silently redirect payout.</div>
    <?php endif; ?>
    <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('admin/withdrawals') ?>">Back</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h3 class="h6">Review Request</h3>
            <p class="small text-secondary"><?= $canMutate ? 'Approve reserves remain locked until payout verification. Reject releases the reservation.' : 'Financial actions stay disabled while withdrawals are not authorized.' ?></p>
            <form method="post" action="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref']) . '/approve') ?>" class="mb-2">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="password" name="password" placeholder="Reauth password" <?= $canMutate && $rowStatus === 'pending_admin_review' ? 'required' : 'disabled' ?>>
                <button class="btn btn-outline-gold btn-sm" type="submit" <?= $canMutate && $rowStatus === 'pending_admin_review' ? '' : 'disabled' ?>>Approve</button>
            </form>
            <form method="post" action="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref']) . '/reject') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="text" name="reason" placeholder="Rejection reason" <?= $canMutate && $rowStatus === 'pending_admin_review' ? 'required' : 'disabled' ?>>
                <input class="form-control form-control-sm mb-2" type="password" name="password" placeholder="Reauth password" <?= $canMutate && $rowStatus === 'pending_admin_review' ? 'required' : 'disabled' ?>>
                <button class="btn btn-outline-gold btn-sm" type="submit" <?= $canMutate && $rowStatus === 'pending_admin_review' ? '' : 'disabled' ?>>Reject</button>
            </form>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h3 class="h6">Record Manual Payment</h3>
            <p class="small text-secondary">TX hash alone is not proof of payment. Independent verification required before PAID.</p>
            <form method="post" action="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref']) . '/record-payout') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="text" name="tx_hash" placeholder="0x… transaction hash" <?= $canSettle && in_array($rowStatus, ['approved', 'payout_in_progress'], true) ? 'required' : 'disabled' ?>>
                <input class="form-control form-control-sm mb-2" type="text" name="paid_amount" placeholder="Paid amount" <?= $canSettle && in_array($rowStatus, ['approved', 'payout_in_progress'], true) ? '' : 'disabled' ?>>
                <input class="form-control form-control-sm mb-2" type="password" name="password" placeholder="Reauth password" <?= $canSettle && in_array($rowStatus, ['approved', 'payout_in_progress'], true) ? 'required' : 'disabled' ?>>
                <button class="btn btn-outline-gold btn-sm" type="submit" <?= $canSettle && in_array($rowStatus, ['approved', 'payout_in_progress'], true) ? '' : 'disabled' ?>>Record Payout</button>
            </form>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h3 class="h6">Verify Payout</h3>
            <p class="small text-secondary">Provider readiness: blocked unless EVM verifier is configured. Never mark PAID on admin confirmation alone.</p>
            <form method="post" action="<?= site_url('admin/withdrawals/' . rawurlencode($row['withdrawal_ref']) . '/verify-payout') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="password" name="password" placeholder="Reauth password" <?= $canSettle && $rowStatus === 'payout_submitted' ? 'required' : 'disabled' ?>>
                <button class="btn btn-outline-gold btn-sm" type="submit" <?= $canSettle && $rowStatus === 'payout_submitted' ? '' : 'disabled' ?>>Verify &amp; Mark Paid</button>
            </form>
        </div>
    </div>
</div>

<div class="card-premium mb-3">
    <h3 class="h6">Payout Evidence</h3>
    <?php if (empty($row['evidence'])): ?>
        <div class="text-secondary small">No payout evidence recorded.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead><tr><th>Evidence</th><th>TX</th><th>Amount</th><th>Verification</th><th>Recorded</th></tr></thead>
                <tbody>
                <?php foreach ($row['evidence'] as $ev): ?>
                    <tr>
                        <td><code><?= esc($ev['evidence_ref']) ?></code></td>
                        <td><code class="text-break"><?= esc($ev['tx_hash']) ?></code></td>
                        <td><?= esc((string) ($ev['paid_amount'] ?? '')) ?></td>
                        <td><?= esc($ev['verification_status']) ?></td>
                        <td><?= esc($ev['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card-premium">
    <h3 class="h6">Audit History</h3>
    <table class="table table-dark-premium table-sm mb-0">
        <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($row['events'])): ?>
            <tr><td colspan="4" class="text-secondary">No events.</td></tr>
        <?php else: foreach ($row['events'] as $ev): ?>
            <tr>
                <td><?= esc($ev['created_at'] ?? '') ?></td>
                <td><?= esc($ev['event_type'] ?? '') ?></td>
                <td><?= esc((string) ($ev['actor_user_id'] ?? '')) ?></td>
                <td><?= esc(($ev['from_status'] ?? '') . ' → ' . ($ev['to_status'] ?? '')) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
