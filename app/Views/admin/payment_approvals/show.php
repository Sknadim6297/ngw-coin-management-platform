<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Step 1 approve payment → Step 2 approve purchase. Coins are credited only in Step 2.</p>

<div class="mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/payment-approvals') ?>">← Back to Payment Approvals</a>
</div>

<?php
$pendingPayment = $deposit && in_array($deposit['status'], ['submitted', 'under_review'], true);
$explorerUrl = null;
if ($deposit && ! empty($deposit['tx_hash'])) {
    if (! empty($network['explorer_tx_url_template'])) {
        $explorerUrl = str_replace('{tx_hash}', rawurlencode((string) $deposit['tx_hash']), (string) $network['explorer_tx_url_template']);
    } else {
        $explorerUrl = 'https://bscscan.com/tx/' . rawurlencode((string) $deposit['tx_hash']);
    }
}
$requiredConf = max(1, (int) ($network['required_confirmations'] ?? 15));
$canApprovePurchase = empty($requiresDeposit) || ! empty($hasVerifiedCover);
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <div class="text-secondary">Purchase</div>
                    <strong><?= esc($request['purchase_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($request['status']) ?>"><?= esc(ucfirst($request['status'])) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Member</div>
                    <strong>
                        <?php if ($profile): ?>
                            <a href="<?= site_url('admin/members/' . $profile->member_id) ?>"><?= esc($profile->member_name) ?></a>
                            · <?= esc($profile->member_id) ?>
                        <?php else: ?>
                            <?= esc($request['member_id']) ?>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">USDT Amount</div>
                    <strong><?= esc($request['total_usdt']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Coin Quantity</div>
                    <strong><?= esc($request['coin_quantity']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Price Snapshot</div>
                    <strong><?= esc($request['price_usdt_snapshot']) ?> USDT</strong>
                </div>
            </div>
        </div>

        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Step 1 — Payment Review</h2>
            <?php if ($deposit === null): ?>
                <div class="alert alert-warning mb-0">
                    No matching payment found yet. Ask the member to submit their TXN, or check
                    <a href="<?= site_url('admin/payment-approvals?tab=unlinked') ?>">Unlinked Deposits</a>.
                </div>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Deposit Reference</div>
                        <strong><?= esc($deposit['deposit_ref']) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Payment Status</div>
                        <strong><?= esc(str_replace('_', ' ', $deposit['status'])) ?>
                            <?php if (! empty($deposit['verification_method'])): ?>
                                · <?= esc($deposit['verification_method']) ?>
                            <?php endif; ?>
                        </strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Network</div>
                        <strong><?= esc($network['network_name'] ?? 'BEP20') ?></strong>
                    </div>
                    <div class="col-12">
                        <div class="text-secondary mb-1">Receiving Wallet</div>
                        <code class="text-break"><?= esc($deposit['destination_wallet_snapshot']) ?></code>
                    </div>
                    <div class="col-12">
                        <div class="text-secondary mb-1">Transaction Hash</div>
                        <code class="text-break"><?= esc($deposit['tx_hash'] ?? '—') ?></code>
                        <?php if ($explorerUrl): ?>
                            <div class="mt-1"><a href="<?= esc($explorerUrl) ?>" target="_blank" rel="noopener noreferrer">Open in BscScan</a></div>
                        <?php endif; ?>
                    </div>
                    <?php if (! empty($deposit['evidence_stored_name'])): ?>
                        <div class="col-md-6">
                            <div class="text-secondary mb-1">Screenshot</div>
                            <a href="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/evidence') ?>">Open screenshot</a>
                            <div class="field-hint">Screenshot alone is not proof.</div>
                        </div>
                    <?php endif; ?>
                    <?php if ($deposit['status'] === 'verified'): ?>
                        <div class="col-md-6"><div class="text-secondary mb-1">Verified Amount</div><strong><?= esc($deposit['verified_amount_usdt'] ?? '—') ?></strong></div>
                        <div class="col-md-6"><div class="text-secondary mb-1">Verified By</div><strong><?= esc($reviewer ?? '—') ?></strong></div>
                        <div class="col-12"><div class="text-secondary mb-1">Evidence</div><strong><?= esc($deposit['verification_reference'] ?? '—') ?></strong></div>
                    <?php endif; ?>
                    <?php if ($deposit['status'] === 'rejected' && ! empty($deposit['rejection_reason'])): ?>
                        <div class="col-12"><div class="alert alert-danger mb-0"><strong>Rejection:</strong> <?= esc($deposit['rejection_reason']) ?></div></div>
                    <?php endif; ?>
                </div>

                <?php if ($pendingPayment): ?>
                    <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/approve-payment') ?>" data-loading
                          onsubmit="return confirm('Approve this payment as independently verified? Coins are not credited until you approve the purchase.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="link_purchase_ref" value="<?= esc($request['purchase_ref']) ?>">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Verified Amount (USDT)</label>
                                <input class="form-control" name="verified_amount_usdt" required
                                       value="<?= esc(old('verified_amount_usdt', $deposit['expected_amount_usdt'])) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Confirmations</label>
                                <input class="form-control" type="number" min="<?= $requiredConf ?>" name="confirmed_confirmations" required
                                       value="<?= esc(old('confirmed_confirmations', (string) $requiredConf)) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Confirmed Receiving Wallet</label>
                                <input class="form-control" name="confirmed_wallet" required
                                       value="<?= esc(old('confirmed_wallet', $deposit['destination_wallet_snapshot'])) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Confirmed USDT Token Contract</label>
                                <input class="form-control" name="confirmed_token_contract"
                                       value="<?= esc(old('confirmed_token_contract', $network['token_contract_address'] ?? '')) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Verification Evidence</label>
                                <textarea class="form-control" name="verification_evidence" rows="3" required maxlength="2000"
                                          placeholder="BscScan link + what you confirmed"><?= esc(old('verification_evidence', '')) ?></textarea>
                            </div>
                            <div class="col-12">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_independent" value="1" id="confirm_independent" required>
                                    <label class="form-check-label" for="confirm_independent">I independently checked this TXN on explorer/wallet</label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" name="confirm_network" value="1" id="confirm_network" required>
                                    <label class="form-check-label" for="confirm_network">Correct network (BEP20 / BSC) and USDT token</label>
                                </div>
                                <div class="form-check mb-3">
                                    <input class="form-check-input" type="checkbox" name="confirm_success" value="1" id="confirm_success" required>
                                    <label class="form-check-label" for="confirm_success">Successful TXN, correct wallet, correct amount, enough confirmations</label>
                                </div>
                            </div>
                        </div>
                        <button class="btn btn-gold" type="submit">Approve Payment</button>
                    </form>

                    <hr class="my-4">
                    <h3 class="h6 mb-2">Reject Payment</h3>
                    <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/reject') ?>" data-loading>
                        <?= csrf_field() ?>
                        <div class="mb-3">
                            <label class="form-label" for="rejection_reason_pay">Rejection Reason</label>
                            <textarea class="form-control" id="rejection_reason_pay" name="rejection_reason" rows="2" required maxlength="500"><?= esc(old('rejection_reason')) ?></textarea>
                        </div>
                        <button class="btn btn-outline-gold" type="submit">Reject Payment</button>
                    </form>
                <?php elseif ($deposit['status'] === 'verified'): ?>
                    <div class="alert alert-success mb-0">Payment approved. Continue to Step 2.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($request['status'] === 'pending'): ?>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Step 2 — Coin Purchase Approval</h2>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Available Inventory</div>
                        <strong><?= esc($inventory['available_balance']) ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-secondary mb-1">Allocation</div>
                        <strong>
                            <?php if (! empty($allocation)): ?>
                                <?= esc($allocation['status'] === 'linked' ? 'reserved' : $allocation['status']) ?>
                            <?php else: ?>none<?php endif; ?>
                        </strong>
                    </div>
                </div>

                <?php if (! $canApprovePurchase): ?>
                    <div class="alert alert-warning mb-0">
                        <?php if ($deposit === null): ?>
                            Missing step: no payment is attached yet. Wait for the member TXN, or open Unlinked Deposits.
                        <?php elseif (in_array($deposit['status'], ['submitted', 'under_review'], true)): ?>
                            Missing step: Approve Payment in Step 1 first (independent TXN check). Purchase approval stays locked.
                        <?php else: ?>
                            Missing step: payment is not verified for this purchase total yet.
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php if (empty($allocation) && ($request['payment_status'] ?? '') !== 'deposit_linked'): ?>
                        <div class="alert alert-info mb-3">
                            Payment is verified. Allocation will be created automatically when you Approve Purchase (exact amount match).
                        </div>
                    <?php else: ?>
                        <div class="notice-box mb-3">Payment verified and ready. Approving deducts inventory and credits the member once.</div>
                    <?php endif; ?>
                    <form method="post" action="<?= site_url('admin/purchases/' . $request['purchase_ref'] . '/approve') ?>" data-loading
                          onsubmit="return confirm('Approve this purchase? Inventory will be deducted and ownership credited.');">
                        <?= csrf_field() ?>
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" value="1" id="confirm_purchase" name="confirm" required>
                            <label class="form-check-label" for="confirm_purchase">
                                I confirm approval. This deducts inventory once and credits member ownership.
                            </label>
                        </div>
                        <button class="btn btn-gold" type="submit">Approve Purchase</button>
                    </form>
                <?php endif; ?>

                <hr class="my-4">
                <h3 class="h6 mb-2">Reject Purchase</h3>
                <form method="post" action="<?= site_url('admin/purchases/' . $request['purchase_ref'] . '/reject') ?>" data-loading>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="2" required maxlength="500"><?= esc(old('rejection_reason')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Reject Purchase</button>
                </form>
            </div>
        <?php elseif ($request['status'] === 'approved'): ?>
            <div class="card-premium mb-3">
                <div class="alert alert-success mb-0">Purchase approved. Coins credited<?= ! empty($request['ownership_transaction_ref']) ? ' · ' . esc($request['ownership_transaction_ref']) : '' ?>.</div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Audit History</h2>
            <?php if ($events === []): ?>
                <div class="text-secondary">No payment events yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead><tr><th>When</th><th>Event</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= esc($event['created_at']) ?></td>
                                <td><code><?= esc($event['event_type']) ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="notice-box">
            Flow: Approve Payment → Approve Purchase → Coins credited · Member activated on first qualifying purchase.
        </div>
    </div>
</div>
<?= $this->endSection() ?>
