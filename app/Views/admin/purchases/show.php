<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Approve or reject this internal purchase request. Approval deducts inventory exactly once.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <div class="text-secondary">Reference</div>
                    <strong><?= esc($request['purchase_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($request['status']) ?>"><?= esc(ucfirst($request['status'])) ?></span>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Member ID</div>
                    <strong>
                        <?php if ($profile): ?>
                            <a href="<?= site_url('admin/members/' . $profile->member_id) ?>"><?= esc($profile->member_id) ?></a>
                        <?php else: ?>
                            <?= esc($request['member_id']) ?>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Member Name</div>
                    <strong><?= esc($profile->member_name ?? '—') ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Quantity</div>
                    <strong><?= esc($request['coin_quantity']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Price Snapshot</div>
                    <strong><?= esc($request['price_usdt_snapshot']) ?> USDT</strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Total USDT</div>
                    <strong><?= esc($request['total_usdt']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Requested At</div>
                    <strong><?= esc($request['requested_at']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Reviewed At</div>
                    <strong><?= esc($request['reviewed_at'] ?? '—') ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Reviewed By</div>
                    <strong><?= esc($reviewer ?? '—') ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Payment Status</div>
                    <strong><?= esc($request['payment_status'] ?? 'unpaid') ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Linked Deposit</div>
                    <strong>
                        <?php $depRef = $request['payment_deposit_ref'] ?? ($allocation['deposit_ref'] ?? null); ?>
                        <?php if (! empty($depRef)): ?>
                            <a href="<?= site_url('admin/payment-audit/' . $depRef) ?>"><?= esc($depRef) ?></a>
                            <?php if (($linkedDeposit['status'] ?? '') === 'verified'): ?>
                                <span class="badge-status verified">verified</span>
                            <?php else: ?>
                                <span class="text-secondary small">not verified — not paid</span>
                            <?php endif; ?>
                        <?php else: ?>—<?php endif; ?>
                    </strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Allocation State</div>
                    <strong>
                        <?php if (! empty($allocation)): ?>
                            <?= esc($allocation['status'] === 'linked' ? 'reserved' : $allocation['status']) ?>
                            <?php if (! empty($allocation['allocation_ref'])): ?>
                                · <a href="<?= site_url('admin/finance/allocations/' . $allocation['allocation_ref']) ?>"><?= esc($allocation['allocation_ref']) ?></a>
                            <?php endif; ?>
                            <?php if (($allocation['status'] ?? '') === 'released'): ?>
                                <div class="text-secondary small">Released <?= esc($allocation['released_at'] ?? '') ?>: <?= esc($allocation['release_reason'] ?? '') ?></div>
                            <?php endif; ?>
                        <?php else: ?>none<?php endif; ?>
                    </strong>
                </div>
                <div class="col-12">
                    <div class="text-secondary mb-1">Member Note</div>
                    <strong><?= esc($request['member_note'] ?? '—') ?></strong>
                </div>
                <?php if (! empty($request['inventory_transaction_ref'])): ?>
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Inventory Txn</div>
                        <strong><?= esc($request['inventory_transaction_ref']) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if (! empty($request['ownership_transaction_ref'])): ?>
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Ownership Txn</div>
                        <strong><?= esc($request['ownership_transaction_ref']) ?></strong>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($request['status'] === 'rejected' && ! empty($request['rejection_reason'])): ?>
                <div class="alert alert-danger mt-3 mb-0">
                    <strong>Rejection reason:</strong> <?= esc($request['rejection_reason']) ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($request['status'] === 'pending'): ?>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Approve Request</h2>
                <?php if (! empty($requiresDeposit) && empty($hasVerifiedCover)): ?>
                    <div class="alert alert-warning">
                        <strong>Payment safety gate active.</strong>
                        Approval is blocked until a verified USDT deposit covers this purchase total.
                        Screenshots and submitted hashes are not verification. Configure Phase 4B provider or keep pending.
                    </div>
                    <div class="text-secondary mb-2">Payment status: <?= esc($request['payment_status'] ?? 'unpaid') ?></div>
                <?php else: ?>
                    <?php if (! empty($requiresDeposit)): ?>
                        <div class="notice-box mb-3">A verified deposit covering this total was found. Approval will still deduct inventory and credit ownership.</div>
                    <?php else: ?>
                        <div class="alert alert-warning mb-3">Verified-deposit requirement is currently disabled for this environment. Do not disable this in production.</div>
                    <?php endif; ?>
                <form method="post" action="<?= site_url('admin/purchases/' . $request['purchase_ref'] . '/approve') ?>" data-loading
                      onsubmit="return confirm('Approve this purchase? Inventory will be deducted and ownership credited.');">
                    <?= csrf_field() ?>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                        <label class="form-check-label" for="confirm">
                            I confirm approval. This deducts inventory once and credits member ownership.
                        </label>
                    </div>
                    <button class="btn btn-gold" type="submit">Approve Purchase</button>
                </form>
                <?php endif; ?>
            </div>

            <div class="card-premium">
                <h2 class="h5 mb-3">Reject Request</h2>
                <form method="post" action="<?= site_url('admin/purchases/' . $request['purchase_ref'] . '/reject') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required
                                  maxlength="500" data-validate="required|min:3" data-label="Rejection Reason"><?= esc(old('rejection_reason')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Reject Purchase</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-premium">
            <div class="text-secondary mb-1">Available inventory</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($inventory['available_balance']) ?></div>
            <div class="text-secondary mt-3 mb-1">Total allocated</div>
            <strong><?= esc($inventory['total_allocated']) ?></strong>
            <div class="notice-box mt-3">
                Concurrent approvals lock inventory rows. Insufficient stock blocks approval without partial updates.
            </div>
        </div>
        <div class="mt-3">
            <a class="btn btn-outline-gold" href="<?= site_url('admin/purchases') ?>">Back to Queue</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
