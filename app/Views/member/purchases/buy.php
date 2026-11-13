<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Submit an internal coin purchase request. This does not confirm payment or settle funds.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <?php if (! $context['can_purchase']): ?>
                <div class="alert alert-warning mb-0" role="alert">
                    <strong>Purchases unavailable.</strong>
                    <?= esc($context['block_reason'] ?? 'Please try again later.') ?>
                </div>
            <?php else: ?>
                <form method="post" action="<?= site_url('member/purchases') ?>" data-loading data-live-validate id="purchase-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">

                    <div class="mb-3">
                        <label class="form-label" for="quantity">Coin Quantity</label>
                        <input class="form-control" type="text" inputmode="decimal" id="quantity" name="quantity"
                               value="<?= esc(old('quantity')) ?>" required
                               data-validate="required|decimal|gt:0" data-label="Coin Quantity"
                               data-price="<?= esc($context['price']['current_price_usdt'] ?? '0') ?>"
                               data-max="<?= esc($context['max_quantity']) ?>">
                        <div class="field-hint" data-validate-hint data-default-hint="Must be greater than zero. Max <?= esc($context['max_quantity']) ?> coins per request.">
                            Must be greater than zero. Max <?= esc($context['max_quantity']) ?> coins per request.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="member_note">Optional Note</label>
                        <textarea class="form-control" id="member_note" name="member_note" rows="2" maxlength="500"><?= esc(old('member_note')) ?></textarea>
                    </div>

                    <div class="card-premium mb-3" style="background: rgba(212,175,55,0.05);">
                        <div class="text-secondary mb-2">Purchase Summary (server-priced)</div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Price per coin</span>
                            <strong id="summary-price"><?= esc($context['price']['current_price_usdt']) ?> USDT</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span>Estimated total</span>
                            <strong id="summary-total">—</strong>
                        </div>
                        <div class="field-hint mt-2">Final totals are calculated on the server. Client estimates are for preview only.</div>
                    </div>

                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                        <label class="form-check-label" for="confirm">
                            I understand this creates a pending purchase request only, not a paid or settled transaction.
                        </label>
                    </div>

                    <button class="btn btn-gold" type="submit">Submit Purchase Request</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <img class="coin-thumb mb-3" src="<?= base_url('assets/images/coin.svg') ?>" alt="NGW Coin" width="72" height="72">
            <div class="text-secondary mb-1">Current coin price</div>
            <div class="h3" style="color: var(--gold-bright);">
                <?php if (($context['price']['current_price_usdt'] ?? null) === null): ?>
                    Not configured
                <?php else: ?>
                    <?= esc($context['price']['current_price_usdt']) ?> USDT
                <?php endif; ?>
            </div>
            <div class="text-secondary mt-3 mb-1">Available inventory</div>
            <strong><?= ! empty($context['inventory']['is_available']) ? 'Available' : 'Unavailable' ?></strong>
            <div class="text-secondary mt-3 mb-1">Minimum purchase</div>
            <strong><?= esc($context['price']['min_purchase_usdt'] ?? '5.00000000') ?> USDT</strong>
        </div>
        <div class="notice-box">
            Duplicate form submissions are blocked. Inventory is reserved only after an administrator approves your request.
        </div>
    </div>
</div>

<script>
(() => {
    const input = document.getElementById('quantity');
    const totalEl = document.getElementById('summary-total');
    if (!input || !totalEl) return;
    const price = Number(input.dataset.price || '0');
    const update = () => {
        const qty = Number(String(input.value).replace(/,/g, ''));
        if (!Number.isFinite(qty) || qty <= 0 || !Number.isFinite(price) || price <= 0) {
            totalEl.textContent = '—';
            return;
        }
        totalEl.textContent = (qty * price).toFixed(8) + ' USDT (preview)';
    };
    input.addEventListener('input', update);
    update();
})();
</script>
<?= $this->endSection() ?>
