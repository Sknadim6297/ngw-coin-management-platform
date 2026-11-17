<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Review submitted USDT payment. Approve only after independent explorer / wallet confirmation.</p>

<?php
$pending = in_array($deposit['status'], ['submitted', 'under_review'], true);
$explorerUrl = null;
if (! empty($network['explorer_tx_url_template']) && ! empty($deposit['tx_hash'])) {
    $explorerUrl = str_replace('{tx_hash}', rawurlencode((string) $deposit['tx_hash']), (string) $network['explorer_tx_url_template']);
} elseif (! empty($deposit['tx_hash'])) {
    $explorerUrl = 'https://bscscan.com/tx/' . rawurlencode((string) $deposit['tx_hash']);
}
$requiredConf = max(1, (int) ($network['required_confirmations'] ?? 15));
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <div class="text-secondary">Payment</div>
                    <strong><?= esc($deposit['deposit_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($deposit['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($deposit['status']))) ?></span>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Member Name / ID</div>
                    <strong><?= esc($profile->member_name ?? '—') ?> · <?= esc($profile->member_id ?? $deposit['member_id']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Purchase Reference</div>
                    <strong>
                        <?php if (! empty($purchase['purchase_ref'])): ?>
                            <a href="<?= site_url('admin/purchases/' . rawurlencode($purchase['purchase_ref'])) ?>"><?= esc($purchase['purchase_ref']) ?></a>
                        <?php else: ?>
                            <?= esc($deposit['linked_purchase_ref'] ?? '—') ?>
                        <?php endif; ?>
                    </strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">USDT Amount</div>
                    <strong><?= esc($deposit['expected_amount_usdt']) ?> USDT</strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Network</div>
                    <strong><?= esc($network['network_name'] ?? 'BEP20') ?></strong>
                </div>
                <div class="col-12">
                    <div class="text-secondary mb-1">Receiving Wallet Address</div>
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
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Payment Status</div>
                    <strong><?= esc(str_replace('_', ' ', $deposit['status'])) ?></strong>
                    <?php if (! empty($deposit['verification_method'])): ?>
                        · <?= esc($deposit['verification_method']) ?>
                    <?php endif; ?>
                </div>
                <?php if ($deposit['status'] === 'verified'): ?>
                    <div class="col-md-6"><div class="text-secondary mb-1">Verified Amount</div><strong><?= esc($deposit['verified_amount_usdt'] ?? '—') ?></strong></div>
                    <div class="col-md-6"><div class="text-secondary mb-1">Verified At</div><strong><?= esc($deposit['verified_at'] ?? '—') ?></strong></div>
                    <div class="col-md-6"><div class="text-secondary mb-1">Verified By</div><strong><?= esc($reviewer ?? '—') ?></strong></div>
                    <div class="col-12"><div class="text-secondary mb-1">Evidence</div><strong><?= esc($deposit['verification_reference'] ?? '—') ?></strong></div>
                <?php endif; ?>
                <?php if ($deposit['status'] === 'rejected' && ! empty($deposit['rejection_reason'])): ?>
                    <div class="col-12"><div class="alert alert-danger mb-0"><strong>Rejection:</strong> <?= esc($deposit['rejection_reason']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending): ?>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-2">Approve Payment</h2>
                <p class="text-secondary small mb-3">Check the TXN on BscScan / your wallet first. Do not trust the screenshot alone.</p>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/approve-payment') ?>" data-loading
                      onsubmit="return confirm('Approve this payment as independently verified? Coins are not credited until you approve the purchase.');">
                    <?= csrf_field() ?>
                    <?php if (! empty($purchase['purchase_ref'])): ?>
                        <input type="hidden" name="link_purchase_ref" value="<?= esc($purchase['purchase_ref']) ?>">
                    <?php endif; ?>

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
                            <div class="field-hint">Minimum <?= $requiredConf ?></div>
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
            </div>

            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Reject Payment</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/reject') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required maxlength="500"><?= esc(old('rejection_reason')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Reject Payment</button>
                </form>
            </div>
        <?php elseif ($deposit['status'] === 'verified'): ?>
            <div class="card-premium mb-3">
                <div class="alert alert-success mb-3">Payment approved. Next step: approve the purchase to credit coins.</div>
                <?php if (! empty($purchase['purchase_ref'])): ?>
                    <a class="btn btn-gold" href="<?= site_url('admin/purchases/' . rawurlencode($purchase['purchase_ref'])) ?>">Open Purchase → Approve</a>
                <?php else: ?>
                    <a class="btn btn-outline-gold" href="<?= site_url('admin/purchases') ?>">Open Purchases</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Audit History</h2>
            <?php if ($events === []): ?>
                <div class="text-secondary">No events yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead><tr><th>When</th><th>Event</th></tr></thead>
                        <tbody>
                        <?php foreach ($events as $event): ?>
                            <tr>
                                <td><?= esc($event['created_at']) ?></td>
                                <td>
                                    <code><?= esc($event['event_type']) ?></code>
                                    <?php if (! empty($event['from_status']) || ! empty($event['to_status'])): ?>
                                        <div class="small text-secondary"><?= esc(($event['from_status'] ?? '—') . ' → ' . ($event['to_status'] ?? '—')) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <div class="notice-box">
            Flow: Approve Payment → Approve Purchase → Coins credited · Member activated (first qualifying purchase).
            Rejected payments never credit coins or deduct inventory.
        </div>
    </div>
</div>
<?= $this->endSection() ?>
