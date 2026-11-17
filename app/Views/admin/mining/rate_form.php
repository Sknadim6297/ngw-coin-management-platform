<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Create a draft mining rate. It remains non-payable until separately approved. No wallet credits occur.</p>
<form class="card-premium" method="post" action="<?= site_url('admin/mining/rates') ?>" data-loading>
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label" for="effective_month">Effective Month</label>
            <input class="form-control" type="month" id="effective_month" name="effective_month" value="<?= esc(old('effective_month', date('Y-m'))) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="rate_percent">Rate Percent</label>
            <input class="form-control" id="rate_percent" name="rate_percent" value="<?= esc(old('rate_percent', '1.00000000')) ?>" required>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="rate_basis">Rate Basis</label>
            <select class="form-select" id="rate_basis" name="rate_basis">
                <?php foreach (['monthly', 'daily', 'annual'] as $b): ?>
                    <option value="<?= $b ?>" <?= old('rate_basis', 'monthly') === $b ? 'selected' : '' ?>><?= $b ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label" for="notes">Notes</label>
            <textarea class="form-control" id="notes" name="notes" rows="2"><?= esc(old('notes')) ?></textarea>
        </div>
    </div>
    <div class="mt-3 d-flex gap-2">
        <button class="btn btn-gold" type="submit">Save Draft</button>
        <a class="btn btn-outline-gold" href="<?= site_url('admin/mining/rates') ?>">Cancel</a>
    </div>
</form>
<?= $this->endSection() ?>
