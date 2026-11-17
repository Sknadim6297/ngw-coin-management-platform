<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Send USDT to the wallet below, then submit your Transaction ID.</p>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card-premium">
            <div class="text-secondary">Purchase</div>
            <div><code><?= esc($purchase['purchase_ref']) ?></code></div>
            <div class="mt-2">Exact USDT Amount: <strong><?= esc($purchase['total_usdt']) ?> USDT</strong></div>
            <div>Coins: <strong><?= esc($purchase['coin_quantity']) ?></strong></div>
        </div>

        <div class="card-premium mt-3">
            <div class="mb-2"><span class="text-secondary">Network</span><br><strong>BEP20</strong></div>
            <?php if (! empty($network['recipient_name'])): ?>
                <div class="mb-2"><span class="text-secondary">Payment Recipient Name</span><br><strong><?= esc($network['recipient_name']) ?></strong></div>
            <?php endif; ?>
            <div class="mb-2">
                <span class="text-secondary">Receiving Wallet Address</span><br>
                <code class="text-break" id="pay-wallet"><?= esc($network['destination_wallet']) ?></code>
            </div>
            <button class="btn btn-outline-gold btn-sm" type="button" id="copy-wallet">Copy Wallet Address</button>

            <?php if (! empty($qrUrl)): ?>
                <div class="text-center mt-3">
                    <div class="text-secondary mb-1">Payment QR</div>
                    <img alt="Payment QR" width="180" height="180" src="<?= esc($qrUrl) ?>" style="background:#fff;padding:.5rem;">
                </div>
            <?php endif; ?>

            <?php if (! empty($network['instructions'])): ?>
                <div class="mt-3">
                    <div class="text-secondary">Payment Instructions</div>
                    <div class="small"><?= nl2br(esc($network['instructions'])) ?></div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card-premium">
            <h2 class="h5">Submit TXN ID</h2>
            <?php if ($linkedDeposit): ?>
                <div class="alert alert-info">Linked deposit <code><?= esc($linkedDeposit['deposit_ref']) ?></code> · <?= esc($linkedDeposit['status']) ?></div>
            <?php endif; ?>
            <form method="post" action="<?= site_url('member/buy-coins/pay/' . rawurlencode($purchase['purchase_ref'])) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">
                <div class="mb-3">
                    <label class="form-label">Transaction ID (TXN ID)</label>
                    <input class="form-control" name="tx_hash" required maxlength="128"
                           placeholder="Enter your transaction ID"
                           value="<?= esc(old('tx_hash', '')) ?>">
                </div>
                <button class="btn btn-gold" type="submit">Submit TXN ID</button>
                <a class="btn btn-outline-gold" href="<?= site_url('member/buy-coins') ?>">Back</a>
            </form>
        </div>
    </div>
</div>
<script>
document.getElementById('copy-wallet')?.addEventListener('click', () => {
  const t = document.getElementById('pay-wallet')?.textContent || '';
  navigator.clipboard?.writeText(t.trim());
});
</script>
<?= $this->endSection() ?>
