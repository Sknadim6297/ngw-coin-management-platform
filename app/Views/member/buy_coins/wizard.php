<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Buy coins with USDT on BEP20. Coins are credited after payment and admin approval.</p>

<div class="alert <?= $activation['is_active'] ? 'alert-success' : 'alert-warning' ?>">
    <strong>ACCOUNT STATUS: <?= esc(strtoupper($activation['account_status'])) ?></strong>
    · <?= esc($activation['message']) ?>
</div>

<?php
$canPurchase = ! empty($context['can_purchase']);
$paymentReady = ! empty($payment['ready']);
?>

<?php if (! $canPurchase): ?>
    <div class="alert alert-warning">
        <?= esc($context['block_code'] === 'price_unavailable'
            ? 'Coin purchasing is not available right now. Please try again later.'
            : 'Coins are currently unavailable. Please try again later.') ?>
    </div>
<?php elseif (! $paymentReady): ?>
    <div class="alert alert-warning">
        Payments are not available right now. Please try again later.
    </div>
<?php else: ?>
<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium">
            <form method="post" action="<?= site_url('member/buy-coins') ?>" id="buy-coins-form">
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">

                <div class="mb-3">
                    <label class="form-label">Purchase Amount (USDT)</label>
                    <input class="form-control" type="text" inputmode="decimal" name="purchase_amount_usdt" id="purchase_amount_usdt"
                           value="<?= esc(old('purchase_amount_usdt', '')) ?>" required
                           data-price="<?= esc($context['price']['current_price_usdt'] ?? '0') ?>">
                    <div class="field-hint">Minimum <?= esc($context['price']['min_purchase_usdt'] ?? '5') ?> USDT</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Network</label>
                    <input class="form-control" type="text" value="BEP20" readonly>
                </div>

                <div class="card-premium mb-3" style="background: rgba(212,175,55,0.05);">
                    <div class="d-flex justify-content-between"><span>Coin price</span><strong><?= esc($context['price']['current_price_usdt'] ?? '—') ?> USDT</strong></div>
                    <div class="d-flex justify-content-between"><span>You will receive</span><strong id="est-coins">—</strong></div>
                </div>

                <div class="mb-3">
                    <label class="form-label">Optional note</label>
                    <textarea class="form-control" name="member_note" rows="2"><?= esc(old('member_note', '')) ?></textarea>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirm" required>
                    <label class="form-check-label" for="confirm">I understand coins are added after payment and admin approval.</label>
                </div>

                <button class="btn btn-gold" type="submit">Continue to Payment</button>
            </form>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="notice-box">
            Pay USDT on BEP20 to the wallet shown on the next screen, then submit your TXN ID.
        </div>
    </div>
</div>
<script>
(function () {
  const input = document.getElementById('purchase_amount_usdt');
  const out = document.getElementById('est-coins');
  const price = parseFloat(input?.dataset.price || '0');
  function update() {
    const amt = parseFloat(input.value || '0');
    if (!price || !amt || amt <= 0) { out.textContent = '—'; return; }
    out.textContent = (amt / price).toFixed(8) + ' coins';
  }
  input?.addEventListener('input', update);
  update();
})();
</script>
<?php endif; ?>
<?= $this->endSection() ?>
