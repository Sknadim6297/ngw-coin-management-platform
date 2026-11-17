<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Unlinked payment — no matching purchase yet. Approve only after independent TXN confirmation.</p>

<div class="mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/payment-approvals?tab=unlinked') ?>">← Unlinked Deposits</a>
</div>

<?php
$pending = in_array($deposit['status'], ['submitted', 'under_review'], true);
$explorerUrl = null;
if (! empty($deposit['tx_hash'])) {
    if (! empty($network['explorer_tx_url_template'])) {
        $explorerUrl = str_replace('{tx_hash}', rawurlencode((string) $deposit['tx_hash']), (string) $network['explorer_tx_url_template']);
    } else {
        $explorerUrl = 'https://bscscan.com/tx/' . rawurlencode((string) $deposit['tx_hash']);
    }
}
$requiredConf = max(1, (int) ($network['required_confirmations'] ?? 15));
?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between mb-3">
                <div>
                    <div class="text-secondary">Payment</div>
                    <strong><?= esc($deposit['deposit_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($deposit['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($deposit['status']))) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <div class="text-secondary mb-1">Member</div>
                    <strong><?= esc($profile->member_name ?? '—') ?> · <?= esc($profile->member_id ?? $deposit['member_id']) ?></strong>
                </div>
                <div class="col-md-6">
                    <div class="text-secondary mb-1">USDT Amount</div>
                    <strong><?= esc($deposit['expected_amount_usdt']) ?></strong>
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
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($pending): ?>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Approve Payment</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/approve-payment') ?>" data-loading
                      onsubmit="return confirm('Approve this unlinked payment? Link a purchase before coins can be credited.');">
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Verified Amount (USDT)</label>
                            <input class="form-control" name="verified_amount_usdt" required value="<?= esc(old('verified_amount_usdt', $deposit['expected_amount_usdt'])) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirmations</label>
                            <input class="form-control" type="number" min="<?= $requiredConf ?>" name="confirmed_confirmations" required
                                   value="<?= esc(old('confirmed_confirmations', (string) $requiredConf)) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirmed Receiving Wallet</label>
                            <input class="form-control" name="confirmed_wallet" required value="<?= esc(old('confirmed_wallet', $deposit['destination_wallet_snapshot'])) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Confirmed USDT Token Contract</label>
                            <input class="form-control" name="confirmed_token_contract" value="<?= esc(old('confirmed_token_contract', $network['token_contract_address'] ?? '')) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Verification Evidence</label>
                            <textarea class="form-control" name="verification_evidence" rows="3" required maxlength="2000"><?= esc(old('verification_evidence', '')) ?></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="confirm_independent" value="1" id="confirm_independent" required>
                                <label class="form-check-label" for="confirm_independent">I independently checked this TXN</label>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="confirm_network" value="1" id="confirm_network" required>
                                <label class="form-check-label" for="confirm_network">Correct BEP20 / BSC network</label>
                            </div>
                            <div class="form-check mb-3">
                                <input class="form-check-input" type="checkbox" name="confirm_success" value="1" id="confirm_success" required>
                                <label class="form-check-label" for="confirm_success">Successful TXN, correct wallet/amount/confirmations</label>
                            </div>
                        </div>
                    </div>
                    <button class="btn btn-gold" type="submit">Approve Payment</button>
                </form>
            </div>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Reject Payment</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/reject') ?>" data-loading>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required maxlength="500"><?= esc(old('rejection_reason')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Reject Payment</button>
                </form>
            </div>
        <?php endif; ?>
    </div>
    <div class="col-lg-5">
        <div class="card-premium">
            <h2 class="h5 mb-3">Audit History</h2>
            <?php if ($events === []): ?>
                <div class="text-secondary">No events yet.</div>
            <?php else: ?>
                <?php foreach ($events as $event): ?>
                    <div class="mb-2"><code><?= esc($event['event_type']) ?></code> · <?= esc($event['created_at']) ?></div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
