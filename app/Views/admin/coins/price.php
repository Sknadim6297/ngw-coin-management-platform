<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Dynamic USDT price per coin. Submitted purchase requests keep an immutable price snapshot.</p>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-premium">
            <div class="text-secondary mb-1">Current Coin Price</div>
            <?php if ($settings['current_price_usdt'] === null): ?>
                <div class="h3 text-secondary">Not configured</div>
                <div class="field-hint">Set an initial price to enable purchase previews.</div>
            <?php else: ?>
                <div class="h3" style="color: var(--gold-bright);"><?= esc($settings['current_price_usdt']) ?> USDT</div>
                <div class="field-hint">Updated <?= esc($settings['price_updated_at'] ?? '—') ?></div>
            <?php endif; ?>
            <div class="mt-3 text-secondary">Minimum purchase: <?= esc($settings['min_purchase_usdt']) ?> USDT</div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5 mb-3"><?= $settings['current_price_usdt'] === null ? 'Set Initial Price' : 'Change Coin Price' ?></h2>
            <form method="post" action="<?= site_url('admin/coins/price') ?>" data-loading data-live-validate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="new_price">New Price (USDT per Coin)</label>
                    <input class="form-control" type="text" inputmode="decimal" id="new_price" name="new_price"
                           value="<?= esc(old('new_price', $settings['current_price_usdt'] ?? '')) ?>" required
                           data-validate="required|decimal|gt:0" data-label="New Price">
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="reason">Reason for Change</label>
                    <textarea class="form-control" id="reason" name="reason" rows="3" required maxlength="500"
                              data-validate="required|min:3|max:500" data-label="Reason for Change"><?= esc(old('reason')) ?></textarea>
                    <div class="field-hint" data-validate-hint></div>
                </div>
                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                    <label class="form-check-label" for="confirm">
                        I confirm this price change. Existing purchase snapshots will not be rewritten.
                    </label>
                </div>
                <button class="btn btn-gold" type="submit">Save Price</button>
                <a class="btn btn-outline-gold ms-2" href="<?= site_url('admin/coins/price/history') ?>">View History</a>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
