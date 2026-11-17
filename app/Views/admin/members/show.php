<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php $activation = $activation ?? null; ?>
<div class="card-premium" style="max-width: 860px;">
    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap gap-2">
        <div>
            <h2 class="h4 mb-1"><?= esc($profile->member_name) ?></h2>
            <div class="text-secondary"><?= esc($profile->member_id) ?></div>
        </div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge-status <?= esc($profile->account_status) ?>"><?= esc(ucfirst($profile->account_status)) ?></span>
            <?php if (! empty($canImpersonate)): ?>
                <button class="btn btn-sm btn-gold" type="button" data-bs-toggle="modal" data-bs-target="#impersonateShowModal">
                    <i class="bi bi-box-arrow-up-right"></i> Login as Member
                </button>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6"><strong>Email</strong><div class="text-secondary"><?= esc($email ?? '—') ?></div></div>
        <div class="col-md-6"><strong>Mobile</strong><div class="text-secondary"><?= esc($profile->mobile) ?></div></div>
        <div class="col-md-6"><strong>Sponsor ID</strong><div class="text-secondary"><?= esc($relationship['sponsor_member_id'] ?? '—') ?></div></div>
        <div class="col-md-6"><strong>USDT Wallet</strong><div class="text-secondary text-break"><?= esc($profile->usdt_wallet) ?></div></div>
        <div class="col-md-6"><strong>Registered</strong><div class="text-secondary"><?= esc($profile->created_at) ?></div></div>
        <div class="col-md-6"><strong>Activation Date</strong><div class="text-secondary"><?= esc($profile->activated_at ?? '—') ?></div></div>
        <div class="col-md-6"><strong>Qualifying Purchase</strong><div class="text-secondary"><code><?= esc($profile->activation_purchase_ref ?? '—') ?></code></div></div>
        <div class="col-md-6"><strong>Purchased Coins</strong><div class="text-secondary"><?= esc($purchasedCoins ?? '0') ?></div></div>
    </div>

    <?php if ($activation): ?>
        <div class="notice-box mt-4">
            <strong>Activation reason:</strong> <code><?= esc($activation['reason_code']) ?></code><br>
            <?= esc($activation['message']) ?>
        </div>
    <?php endif; ?>

    <div class="notice-box mt-3">
        Manual unrestricted activation is disabled. Accounts become active only after a verified, admin-approved qualifying coin purchase.
    </div>
</div>

<?php if (! empty($canImpersonate)): ?>
    <?= view('components/impersonate_modal', [
        'modalId'    => 'impersonateShowModal',
        'memberId'   => (string) $profile->member_id,
        'memberName' => (string) $profile->member_name,
    ]) ?>
<?php endif; ?>
<?= $this->endSection() ?>
