<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Authoritative available balance for internal platform units.</p>

<div class="stat-grid mb-4">
    <div class="card-premium stat-card">
        <span>Available</span>
        <h3><?= esc($inventory['available_balance']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Total Added</span>
        <h3><?= esc($inventory['total_added']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Total Allocated</span>
        <h3><?= esc($inventory['total_allocated']) ?></h3>
    </div>
</div>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-gold" href="<?= site_url('admin/coins/inventory/add') ?>">Add Coins</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/coins/inventory/history') ?>">View Ledger</a>
</div>
<?= $this->endSection() ?>
