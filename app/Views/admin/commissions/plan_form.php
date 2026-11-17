<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Create a draft commission plan. Illustrative rates (e.g. 10/5/3) are not applied until dual-control approval.</p>
<div class="card-premium">
    <form method="post" action="<?= site_url('admin/commissions/plans') ?>">
        <?= csrf_field() ?>
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Plan Name</label>
                <input class="form-control" name="plan_name" required maxlength="120" value="<?= esc(old('plan_name', '')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Effective From</label>
                <input class="form-control" type="date" name="effective_from" required value="<?= esc(old('effective_from', date('Y-m-d'))) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Effective To (optional)</label>
                <input class="form-control" type="date" name="effective_to" value="<?= esc(old('effective_to', '')) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Level Count</label>
                <select class="form-select" name="level_count">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <option value="<?= $i ?>" <?= (string) old('level_count', '3') === (string) $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Level 1 %</label>
                <input class="form-control" name="level_1_percent" required value="<?= esc(old('level_1_percent', '10')) ?>" placeholder="10">
            </div>
            <div class="col-md-3">
                <label class="form-label">Level 2 %</label>
                <input class="form-control" name="level_2_percent" value="<?= esc(old('level_2_percent', '5')) ?>" placeholder="5">
            </div>
            <div class="col-md-3">
                <label class="form-label">Level 3 %</label>
                <input class="form-control" name="level_3_percent" value="<?= esc(old('level_3_percent', '3')) ?>" placeholder="3">
            </div>
            <div class="col-md-4">
                <label class="form-label">Commission Currency</label>
                <select class="form-select" name="commission_currency">
                    <option value="USDT" selected>USDT</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Eligible Purchase Policy</label>
                <select class="form-select" name="eligible_purchase_policy">
                    <option value="approved_verified_usdt" <?= old('eligible_purchase_policy', 'approved_verified_usdt') === 'approved_verified_usdt' ? 'selected' : '' ?>>Approved + verified USDT</option>
                    <option value="approved_only" <?= old('eligible_purchase_policy') === 'approved_only' ? 'selected' : '' ?>>Approved only</option>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Sponsor Eligibility Policy</label>
                <select class="form-select" name="sponsor_eligibility_policy">
                    <option value="active_sponsors_only" <?= old('sponsor_eligibility_policy', 'active_sponsors_only') === 'active_sponsors_only' ? 'selected' : '' ?>>Active sponsors only</option>
                    <option value="any_registered" <?= old('sponsor_eligibility_policy') === 'any_registered' ? 'selected' : '' ?>>Any registered sponsor</option>
                    <option value="unresolved" <?= old('sponsor_eligibility_policy') === 'unresolved' ? 'selected' : '' ?>>Unresolved (blocks eligibility)</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?= esc(old('notes', '')) ?></textarea>
            </div>
        </div>
        <div class="mt-3 d-flex gap-2">
            <button class="btn btn-gold" type="submit">Save Draft</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/commissions/plans') ?>">Cancel</a>
        </div>
    </form>
</div>
<?= $this->endSection() ?>
