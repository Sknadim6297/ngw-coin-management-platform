<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Only posted level commission USDT can be withdrawn to your registered BEP20 wallet. Mining income and purchased coins are not withdrawable here.</p>

<?php if (empty($enabled)): ?>
    <div class="alert alert-warning">
        <strong>Commission Withdrawals Not Activated</strong>
        · Fee / minimum amounts are pending business decision
        · Submit remains disabled and reservation endpoints are blocked server-side.
    </div>
<?php else: ?>
    <div class="alert alert-danger">Withdrawal kill-switch reports ON but Phase 11D still refuses execution until separately authorized.</div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-3">
    <div class="col-lg-5">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Registered Payout Wallet</h2>
            <div class="mb-2"><span class="text-secondary">Network</span><div>BEP20</div></div>
            <div class="mb-2"><span class="text-secondary">USDT Address</span>
                <div><code class="text-break"><?= esc((string) ($profile->usdt_wallet ?: 'Not set — update in Profile')) ?></code></div>
            </div>
            <div class="small text-secondary">Arbitrary destination addresses are not allowed. Change wallet from Profile with reauthentication and cooldown.</div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Request Withdrawal</h2>
            <form method="post" action="<?= site_url('member/withdrawals/preview') ?>" class="mb-3">
                <?= csrf_field() ?>
                <label class="form-label" for="amount">Amount (USDT)</label>
                <input class="form-control" type="text" name="amount" id="amount"
                       value="<?= esc(old('amount') ?? '') ?>" required placeholder="0.00000000">
                <button class="btn btn-outline-gold btn-sm mt-2" type="submit">Fee / Net Preview</button>
            </form>

            <?php if (! empty($preview) && is_array($preview)): ?>
                <div class="notice-box mb-3">
                    <div><strong>Preview</strong> · <?= esc($preview['note']) ?></div>
                    <div>Amount <?= esc($preview['amount']) ?> USDT</div>
                    <div>Fee <?= $preview['fee_amount'] === null ? 'pending business decision' : esc($preview['fee_amount']) ?></div>
                    <div>Net payout <?= esc($preview['net_amount']) ?> USDT</div>
                    <div>Available <?= esc($preview['available_balance']) ?></div>
                    <div>To <?= esc($preview['destination_masked']) ?> · <?= esc($preview['network']) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= site_url('member/withdrawals') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="amount" value="<?= esc(old('amount') ?? '') ?>">
                <input type="hidden" name="network" value="BEP20">
                <input type="hidden" name="idempotency_key" value="<?= esc(bin2hex(random_bytes(16))) ?>">
                <label class="form-label" for="password">Confirm with password</label>
                <input class="form-control mb-2" type="password" name="password" id="password" autocomplete="current-password" <?= empty($enabled) ? 'disabled' : 'required' ?>>
                <button class="btn btn-gold" type="submit" <?= empty($enabled) ? 'disabled' : '' ?>>Confirm Withdrawal</button>
            </form>
        </div>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">Withdrawal History</h2>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
            <tr>
                <th>Ref</th>
                <th>Date</th>
                <th>Amount</th>
                <th>Network</th>
                <th>Destination</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="6" class="text-secondary">No withdrawals yet.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><a href="<?= site_url('member/withdrawals/' . rawurlencode($row['withdrawal_ref'])) ?>"><code><?= esc($row['withdrawal_ref']) ?></code></a></td>
                    <td><?= esc($row['created_at'] ?? '') ?></td>
                    <td><?= esc($row['amount']) ?> <?= esc($row['currency']) ?></td>
                    <td><?= esc($row['network']) ?></td>
                    <td><code><?= esc($row['destination_masked'] ?? '') ?></code></td>
                    <td><?= esc($row['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
