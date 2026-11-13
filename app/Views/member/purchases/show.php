<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Purchase request details. Approval requires a verified USDT deposit with an exact amount match.</p>

<div class="card-premium mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <div class="text-secondary">Reference</div>
            <strong><?= esc($request['purchase_ref']) ?></strong>
        </div>
        <span class="badge-status <?= esc($request['status']) ?>"><?= esc(ucfirst($request['status'])) ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-secondary mb-1">Requested</div>
            <strong><?= esc($request['requested_at']) ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Coin Quantity</div>
            <strong><?= esc($request['coin_quantity']) ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Price Snapshot</div>
            <strong><?= esc($request['price_usdt_snapshot']) ?> USDT</strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Total USDT</div>
            <strong><?= esc($request['total_usdt']) ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Purchase Status</div>
            <strong><?= esc($request['status']) ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Payment Status</div>
            <strong><?= esc($request['payment_status'] ?? 'unpaid') ?></strong>
            <?php if (($request['payment_status'] ?? '') !== 'verified' && ($linkedDeposit['status'] ?? '') !== 'verified'): ?>
                <div class="text-secondary small">Not marked paid — unverified deposits never count as payment.</div>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Linked Deposit</div>
            <strong>
                <?php
                    $depRef = $request['payment_deposit_ref'] ?? ($allocation['deposit_ref'] ?? null);
                ?>
                <?php if (! empty($depRef) && ($linkedDeposit['status'] ?? '') === 'verified'): ?>
                    <a href="<?= site_url('member/deposits/' . $depRef) ?>"><?= esc($depRef) ?></a>
                <?php elseif (! empty($depRef)): ?>
                    <?= esc($depRef) ?> <span class="text-secondary small">(not verified)</span>
                <?php else: ?>
                    —
                <?php endif; ?>
            </strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Allocation State</div>
            <strong>
                <?php if (! empty($allocation)): ?>
                    <?= esc($allocation['status'] === 'linked' ? 'reserved' : $allocation['status']) ?>
                    <?php if ($allocation['status'] === 'released'): ?>
                        <div class="text-secondary small">Released: <?= esc($allocation['release_reason'] ?? 'n/a') ?></div>
                    <?php endif; ?>
                <?php else: ?>
                    none
                <?php endif; ?>
            </strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Reviewed</div>
            <strong><?= esc($request['reviewed_at'] ?? '—') ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Member Note</div>
            <strong><?= esc($request['member_note'] ?? '—') ?></strong>
        </div>
    </div>

    <?php if ($request['status'] === 'rejected' && ! empty($request['rejection_reason'])): ?>
        <div class="alert alert-danger mt-3 mb-0">
            <strong>Rejection reason:</strong> <?= esc($request['rejection_reason']) ?>
        </div>
    <?php endif; ?>

    <?php if ($request['status'] === 'approved'): ?>
        <div class="notice-box mt-3">
            Approved and recorded in your coin holdings after verified payment reconciliation.
        </div>
    <?php endif; ?>
</div>

<?php if ($request['status'] === 'cancelled'): ?>
<div class="alert alert-warning mb-3">This purchase was cancelled. Any reserved payment allocation was released; verified deposits remain available for relink.</div>
<?php endif; ?>
<?php if ($request['status'] === 'rejected'): ?>
<div class="alert alert-warning mb-3">This purchase was rejected. Any reserved payment allocation was released.</div>
<?php endif; ?>

<?php if ($request['status'] === 'pending' && ($request['payment_status'] ?? 'unpaid') === 'unpaid' && ! empty($availableDeposit)): ?>
<div class="card-premium mb-3">
    <h2 class="h5 mb-2"><?= (! empty($allocation) && ($allocation['status'] ?? '') === 'released') ? 'Relink Verified Deposit' : 'Link Verified Deposit' ?></h2>
    <p class="text-secondary">Exact amount match required: <?= esc($request['total_usdt']) ?> USDT. Partial or overpayment linking is rejected. Only verified deposits can be linked.</p>
    <form method="post" action="<?= site_url('member/purchases/' . $request['purchase_ref'] . '/link-deposit') ?>" data-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="deposit_ref" value="<?= esc($availableDeposit['deposit_ref']) ?>">
        <div class="mb-3">
            Available verified deposit: <strong><?= esc($availableDeposit['deposit_ref']) ?></strong>
            (<?= esc($availableDeposit['verified_amount_usdt']) ?> USDT)
        </div>
        <button class="btn btn-gold" type="submit">Link This Deposit</button>
    </form>
</div>
<?php elseif ($request['status'] === 'pending' && ($request['payment_status'] ?? 'unpaid') === 'unpaid'): ?>
<div class="alert alert-warning mb-3">
    No available verified deposit with an exact <?= esc($request['total_usdt']) ?> USDT match.
    Submit and verify a deposit first.
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-gold" href="<?= site_url('member/purchases') ?>">Back to History</a>
    <?php if ($request['status'] === 'pending'): ?>
        <form method="post" action="<?= site_url('member/purchases/' . $request['purchase_ref'] . '/cancel') ?>" data-loading
              onsubmit="return confirm('Cancel this pending purchase request?');">
            <?= csrf_field() ?>
            <button class="btn btn-outline-gold" type="submit">Cancel Request</button>
        </form>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
