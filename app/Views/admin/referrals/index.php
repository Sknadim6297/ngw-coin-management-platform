<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'commissions']) ?>
<p class="page-subtitle">Read-only referral network overview. Sponsor reassignment is not available from this panel.</p>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<form class="card-premium my-4" method="get" action="<?= site_url('admin/referrals') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-8">
            <label class="form-label" for="q">Find member</label>
            <input class="form-control" id="q" name="q" value="<?= esc($q) ?>" placeholder="Member ID or name">
        </div>
        <div class="col-md-4 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Search</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/genealogy') ?>">Genealogy & Integrity</a>
        </div>
    </div>
</form>

<?php if (! empty($error)): ?>
    <div class="alert alert-warning"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <?php $profile = $result['profile']; $counts = $result['counts']; ?>
    <div class="card-premium">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1"><?= esc($profile->member_name) ?></h2>
                <div class="text-secondary"><?= esc($profile->member_id) ?> · <?= esc($profile->account_status) ?></div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-gold" href="<?= site_url('admin/referrals/' . rawurlencode($profile->member_id)) ?>">Direct Referrals</a>
                <a class="btn btn-outline-gold" href="<?= site_url('admin/genealogy?member_id=' . rawurlencode($profile->member_id)) ?>">Genealogy</a>
            </div>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="text-secondary">Direct</div><strong><?= (int) $counts['direct'] ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Total Team</div><strong><?= (int) $counts['total'] ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Active</div><strong><?= (int) $counts['active'] ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Inactive</div><strong><?= (int) $counts['inactive'] ?></strong></div>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
