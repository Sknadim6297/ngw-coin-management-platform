<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
$purchaseStatus = strtolower((string) ($purchase['status'] ?? 'pending'));
$paymentStatus  = strtolower((string) ($purchase['payment_status'] ?? 'unpaid'));
$depositStatus  = strtolower((string) ($deposit['status'] ?? ''));

$waitingVerify = $deposit && in_array($depositStatus, ['submitted', 'under_review'], true);
$verified      = $deposit && $depositStatus === 'verified';
$approved      = $purchaseStatus === 'approved';
$rejected      = $purchaseStatus === 'rejected' || $depositStatus === 'rejected';

if ($approved) {
    $heroTitle = 'Purchase Complete';
    $heroText  = 'Coins have been added to your wallet.';
    $heroClass = 'buy-status-hero is-success';
    $heroIcon  = 'bi-check-circle-fill';
} elseif ($rejected) {
    $heroTitle = 'Purchase Not Approved';
    $heroText  = 'This request was rejected. Contact support if you need help.';
    $heroClass = 'buy-status-hero is-danger';
    $heroIcon  = 'bi-x-circle-fill';
} elseif ($verified) {
    $heroTitle = 'Payment Approved';
    $heroText  = 'Waiting for purchase approval to add coins.';
    $heroClass = 'buy-status-hero is-success';
    $heroIcon  = 'bi-shield-check';
} elseif ($waitingVerify) {
    $heroTitle = 'Payment Submitted — Waiting for Verification';
    $heroText  = 'We received your TXN ID. Please wait while your payment is reviewed.';
    $heroClass = 'buy-status-hero is-waiting';
    $heroIcon  = 'bi-hourglass-split';
} else {
    $heroTitle = 'Purchase In Progress';
    $heroText  = 'Complete payment to continue.';
    $heroClass = 'buy-status-hero is-waiting';
    $heroIcon  = 'bi-lightning-charge-fill';
}

$stepPay     = $deposit !== null;
$stepVerify  = $verified || $approved;
$stepApprove = $approved;
?>

<div class="buy-status-page">
    <div class="<?= esc($heroClass) ?>">
        <div class="buy-status-hero-icon" aria-hidden="true"><i class="bi <?= esc($heroIcon) ?>"></i></div>
        <div>
            <p class="buy-status-kicker">Buy Coins · Status</p>
            <h1 class="buy-status-title"><?= esc($heroTitle) ?></h1>
            <p class="buy-status-text"><?= esc($heroText) ?></p>
        </div>
    </div>

    <div class="buy-status-account <?= $activation['is_active'] ? 'is-active' : 'is-inactive' ?>">
        <span class="buy-status-dot"></span>
        <div>
            <strong><?= esc(strtoupper((string) $activation['account_status'])) ?></strong>
            <span><?= esc($activation['message']) ?></span>
        </div>
    </div>

    <ol class="buy-status-steps" aria-label="Purchase progress">
        <li class="is-done">
            <span class="step-num"><i class="bi bi-check-lg"></i></span>
            <span class="step-label">Request created</span>
        </li>
        <li class="<?= $stepPay ? 'is-done' : 'is-current' ?>">
            <span class="step-num"><?= $stepPay ? '<i class="bi bi-check-lg"></i>' : '2' ?></span>
            <span class="step-label">Payment submitted</span>
        </li>
        <li class="<?= $stepVerify ? 'is-done' : ($stepPay ? 'is-current' : '') ?>">
            <span class="step-num"><?= $stepVerify ? '<i class="bi bi-check-lg"></i>' : '3' ?></span>
            <span class="step-label">Verification</span>
        </li>
        <li class="<?= $stepApprove ? 'is-done' : ($stepVerify ? 'is-current' : '') ?>">
            <span class="step-num"><?= $stepApprove ? '<i class="bi bi-check-lg"></i>' : '4' ?></span>
            <span class="step-label">Coins added</span>
        </li>
    </ol>

    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card-premium buy-status-card h-100">
                <div class="buy-status-card-head">
                    <span>Purchase</span>
                    <code><?= esc($purchase['purchase_ref']) ?></code>
                </div>
                <div class="buy-status-metrics">
                    <div>
                        <span class="label">Amount</span>
                        <strong><?= esc($purchase['total_usdt']) ?> <small>USDT</small></strong>
                    </div>
                    <div>
                        <span class="label">Coins</span>
                        <strong><?= esc($purchase['coin_quantity']) ?></strong>
                    </div>
                </div>
                <div class="buy-status-pills">
                    <span class="pill"><?= esc(ucfirst($purchaseStatus)) ?></span>
                    <span class="pill pill-muted"><?= esc(ucfirst($paymentStatus)) ?></span>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <?php if ($deposit): ?>
            <div class="card-premium buy-status-card h-100">
                <div class="buy-status-card-head">
                    <span>Payment details</span>
                    <code><?= esc($deposit['deposit_ref']) ?></code>
                </div>

                <div class="buy-status-detail">
                    <span class="label">Status</span>
                    <strong class="text-capitalize"><?= esc(str_replace('_', ' ', $depositStatus)) ?></strong>
                </div>
                <div class="buy-status-detail">
                    <span class="label">Amount sent</span>
                    <strong><?= esc($deposit['expected_amount_usdt']) ?> USDT</strong>
                </div>
                <div class="buy-status-detail">
                    <span class="label">Receiving wallet</span>
                    <code class="text-break" id="status-wallet"><?= esc($deposit['destination_wallet_snapshot']) ?></code>
                </div>
                <div class="buy-status-detail">
                    <span class="label">TXN ID</span>
                    <div class="buy-status-txn">
                        <code class="text-break" id="status-txn"><?= esc($deposit['tx_hash'] ?? '—') ?></code>
                        <button type="button" class="btn btn-outline-gold btn-sm" id="copy-txn">Copy</button>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card-premium buy-status-card h-100">
                <p class="mb-0 text-secondary">No payment has been submitted for this purchase yet.</p>
                <a class="btn btn-gold btn-sm mt-3" href="<?= site_url('member/buy-coins/pay/' . rawurlencode($purchase['purchase_ref'])) ?>">Go to Payment</a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="buy-status-actions">
        <a class="btn btn-gold" href="<?= site_url('member/buy-coins') ?>">Buy More Coins</a>
        <a class="btn btn-outline-gold" href="<?= site_url('member/purchases/' . rawurlencode($purchase['purchase_ref'])) ?>">Purchase Detail</a>
        <?php if ($deposit): ?>
            <a class="btn btn-outline-gold" href="<?= site_url('member/deposits/' . rawurlencode($deposit['deposit_ref'])) ?>">Deposit Detail</a>
        <?php endif; ?>
        <a class="btn btn-outline-gold" href="<?= site_url('member/dashboard') ?>">Dashboard</a>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
(function () {
  const btn = document.getElementById('copy-txn');
  const el = document.getElementById('status-txn');
  btn?.addEventListener('click', async () => {
    const text = (el?.textContent || '').trim();
    if (!text) return;
    try {
      await navigator.clipboard.writeText(text);
      btn.textContent = 'Copied';
      setTimeout(() => { btn.textContent = 'Copy'; }, 1400);
    } catch (e) {}
  });
})();
</script>
<?= $this->endSection() ?>
