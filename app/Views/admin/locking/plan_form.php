<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Create a locking plan. It will remain disabled until you explicitly enable it. Do not enter ROI or return promises.</p>

<div class="card-premium">
    <form method="post" action="<?= site_url('admin/locking/plans') ?>" class="row g-3">
        <?= csrf_field() ?>
        <div class="col-md-4">
            <label class="form-label" for="plan_code">Plan Code</label>
            <input class="form-control" id="plan_code" name="plan_code" value="<?= esc(old('plan_code') ?? '') ?>" required maxlength="40">
        </div>
        <div class="col-md-8">
            <label class="form-label" for="name">Plan Name</label>
            <input class="form-control" id="name" name="name" value="<?= esc(old('name') ?? '') ?>" required maxlength="120">
        </div>
        <div class="col-12">
            <label class="form-label" for="description">Description</label>
            <textarea class="form-control" id="description" name="description" rows="3"><?= esc(old('description') ?? '') ?></textarea>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="duration_days">Duration (days)</label>
            <input class="form-control" type="number" id="duration_days" name="duration_days" min="1" max="3650" value="<?= esc(old('duration_days') ?? '1000') ?>" required>
            <div class="field-hint">Client Coin Plan default is 1000 days. Plans do not auto-lock purchases until an activation phase enables that policy.</div>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="min_quantity">Minimum Quantity</label>
            <input class="form-control" id="min_quantity" name="min_quantity" value="<?= esc(old('min_quantity') ?? '1') ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="max_quantity">Maximum Quantity (optional)</label>
            <input class="form-control" id="max_quantity" name="max_quantity" value="<?= esc(old('max_quantity') ?? '') ?>">
        </div>
        <div class="col-12">
            <div class="notice-box">Historical lock quantities cannot be edited after creation. Plans start disabled.</div>
        </div>
        <div class="col-12">
            <button class="btn btn-gold" type="submit">Create Plan</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/locking/plans') ?>">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
