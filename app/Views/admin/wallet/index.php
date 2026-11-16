<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Read-only member coin wallet lookup. Balances come from the ownership ledger cache. Direct balance editing is not available.</p>

<form class="card-premium mb-4" method="get" action="<?= site_url('admin/wallets') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-6">
            <label class="form-label" for="member_id">Member ID</label>
            <input class="form-control" id="member_id" name="member_id" value="<?= esc($memberId) ?>" placeholder="e.g. NGW000001" required>
        </div>
        <div class="col-md-6 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Lookup Wallet</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/reconcile') ?>">Reconciliation</a>
        </div>
    </div>
</form>

<?php if (! empty($error)): ?>
    <div class="alert alert-warning"><?= esc($error) ?></div>
<?php endif; ?>

<?php if ($result !== null): ?>
    <?php $wallet = $result['overview']; $profile = $result['profile']; ?>
    <div class="card-premium mb-4">
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
            <div>
                <h2 class="h5 mb-1"><?= esc($profile->member_name) ?></h2>
                <div class="text-secondary">Member ID <?= esc($profile->member_id) ?> · <?= esc($profile->account_status) ?></div>
            </div>
            <a class="btn btn-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id)) ?>">Open Wallet Audit</a>
        </div>
        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="text-secondary">Total Owned</div><strong style="color:var(--gold-bright)"><?= esc($wallet['balance']) ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Available</div><strong><?= esc($wallet['available']) ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Locked</div><strong><?= esc($wallet['locked']) ?></strong></div>
            <div class="col-6 col-md-3"><div class="text-secondary">Purchase Credits</div><strong><?= esc($wallet['total_acquired']) ?></strong></div>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
