<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="card-premium" style="max-width: 760px;">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h2 class="h4 mb-1"><?= esc($profile->member_name) ?></h2>
            <div class="text-secondary"><?= esc($profile->member_id) ?></div>
        </div>
        <span class="badge-status <?= esc($profile->account_status) ?>"><?= esc(ucfirst($profile->account_status)) ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-6"><strong>Email</strong><div class="text-secondary"><?= esc($email ?? '—') ?></div></div>
        <div class="col-md-6"><strong>Mobile</strong><div class="text-secondary"><?= esc($profile->mobile) ?></div></div>
        <div class="col-md-6"><strong>Sponsor ID</strong><div class="text-secondary"><?= esc($relationship['sponsor_member_id'] ?? '—') ?></div></div>
        <div class="col-md-6"><strong>Profile Completion</strong><div class="text-secondary"><?= esc($completion) ?>%</div></div>
        <div class="col-12"><strong>USDT Wallet</strong><div class="text-secondary text-break"><?= esc($profile->usdt_wallet) ?></div></div>
        <div class="col-md-6"><strong>Registered</strong><div class="text-secondary"><?= esc($profile->created_at) ?></div></div>
    </div>
</div>
<?= $this->endSection() ?>
