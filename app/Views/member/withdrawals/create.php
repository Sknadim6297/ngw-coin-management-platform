<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning"><?= esc($status) ?><?php if (! $enabled): ?> · Form submission is blocked server-side.<?php endif; ?></div>
<div class="card-premium">
    <p class="small">Available posted commission: <strong><?= esc($account['available_balance'] ?? '0') ?> USDT</strong>. Simulation previews cannot be withdrawn.</p>
    <form method="post" action="<?= site_url('member/withdrawals') ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-4"><label class="form-label">Amount</label><input class="form-control" name="amount" required <?= $enabled ? '' : 'disabled' ?>></div>
            <div class="col-md-2"><label class="form-label">Currency</label><select class="form-select" name="currency" <?= $enabled ? '' : 'disabled' ?>><option value="USDT">USDT</option></select></div>
            <div class="col-md-3"><label class="form-label">Network</label>
                <select class="form-select" name="network" <?= $enabled ? '' : 'disabled' ?>>
                    <?php foreach ($networks as $n): ?><option value="<?= esc($n) ?>"><?= esc($n) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-12"><label class="form-label">Destination Address</label><input class="form-control" name="destination_address" <?= $enabled ? '' : 'disabled' ?>></div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-gold" type="submit" <?= $enabled ? '' : 'disabled' ?>>Submit Request</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/withdrawals') ?>">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
