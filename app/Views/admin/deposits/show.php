<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Inspect deposit evidence. Screenshots and hashes alone cannot mark a deposit verified.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
                <div>
                    <div class="text-secondary">Reference</div>
                    <strong><?= esc($deposit['deposit_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($deposit['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($deposit['status']))) ?></span>
            </div>
            <?php
                $explorerUrl = null;
                if (! empty($network['explorer_tx_url_template']) && ! empty($deposit['tx_hash'])) {
                    $explorerUrl = str_replace('{tx_hash}', rawurlencode((string) $deposit['tx_hash']), (string) $network['explorer_tx_url_template']);
                }
            ?>
            <div class="row g-3">
                <div class="col-md-6"><div class="text-secondary mb-1">Member</div><strong><?= esc($profile->member_id ?? $deposit['member_id']) ?> · <?= esc($profile->member_name ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Network</div><strong><?= esc($network['network_name'] ?? $deposit['network_key']) ?> <?php if (! empty($network['chain_id'])): ?>(chain <?= (int) $network['chain_id'] ?>)<?php endif; ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Expected Amount</div><strong><?= esc($deposit['expected_amount_usdt']) ?> USDT</strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Verified Amount</div><strong><?= esc($deposit['verified_amount_usdt'] ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Verified Token</div><code class="text-break"><?= esc($deposit['verified_token_contract'] ?? '—') ?></code></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Verified Destination</div><code class="text-break"><?= esc($deposit['verified_destination'] ?? $deposit['destination_wallet_snapshot']) ?></code></div>
                <div class="col-12"><div class="text-secondary mb-1">Tx Hash</div>
                    <code class="text-break"><?= esc($deposit['tx_hash'] ?? '—') ?></code>
                    <?php if ($explorerUrl): ?>
                        <div class="mt-1"><a href="<?= esc($explorerUrl) ?>" target="_blank" rel="noopener noreferrer">Open in explorer</a></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4"><div class="text-secondary mb-1">Block</div><strong><?= esc((string) ($deposit['verified_block_number'] ?? '—')) ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Confirmations</div><strong><?= esc((string) ($deposit['verified_confirmations'] ?? '—')) ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Verified At</div><strong><?= esc($deposit['verified_at'] ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Provider</div><strong><?= esc($deposit['verification_method'] ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Payment Consumption</div><strong><?= esc($deposit['payment_consumption_status'] ?? 'available') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Linked Purchase</div><strong><?= esc($deposit['linked_purchase_ref'] ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Submitted</div><strong><?= esc($deposit['submitted_at'] ?? '—') ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Reviewed By</div><strong><?= esc($reviewer ?? '—') ?></strong></div>
                <?php if (! empty($deposit['evidence_stored_name'])): ?>
                    <div class="col-md-6"><div class="text-secondary mb-1">Evidence</div><a href="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/evidence') ?>">Open evidence</a></div>
                <?php endif; ?>
                <?php if (! empty($deposit['review_notes'])): ?>
                    <div class="col-12"><div class="text-secondary mb-1">Review Notes</div><strong><?= nl2br(esc($deposit['review_notes'])) ?></strong></div>
                <?php endif; ?>
                <?php if ($deposit['status'] === 'rejected' && ! empty($deposit['rejection_reason'])): ?>
                    <div class="col-12"><div class="alert alert-danger mb-0"><strong>Rejection:</strong> <?= esc($deposit['rejection_reason']) ?></div></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (in_array($deposit['status'], ['submitted', 'under_review'], true)): ?>
            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Mark Under Review</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/under-review') ?>" data-loading>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="review_notes_ur">Optional notes</label>
                        <textarea class="form-control" id="review_notes_ur" name="review_notes" rows="2" maxlength="2000"><?= esc(old('review_notes')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Mark Under Review</button>
                </form>
            </div>

            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Record Review Notes</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/notes') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="review_notes">Notes</label>
                        <textarea class="form-control" id="review_notes" name="review_notes" rows="3" required maxlength="2000"><?= esc(old('review_notes', $deposit['review_notes'] ?? '')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Save Notes (does not verify)</button>
                </form>
            </div>

            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Trusted Verification / Retry</h2>
                <p class="text-secondary">Runs the configured EVM JSON-RPC provider. Timeouts and malformed responses never mark payment verified. Screenshots cannot bypass this.</p>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/attempt-verify') ?>" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn-gold" type="submit">Retry Provider Verification</button>
                </form>
                <form class="mt-3" method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/manual-verify') ?>" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-gold" type="submit">Try Mark Verified From Screenshot</button>
                </form>
                <div class="field-hint mt-2">The screenshot/manual path is intentionally blocked.</div>
            </div>

            <div class="card-premium mb-3">
                <h2 class="h5 mb-3">Reject Deposit</h2>
                <form method="post" action="<?= site_url('admin/deposits/' . $deposit['deposit_ref'] . '/reject') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="rejection_reason">Rejection Reason</label>
                        <textarea class="form-control" id="rejection_reason" name="rejection_reason" rows="3" required maxlength="500"><?= esc(old('rejection_reason')) ?></textarea>
                    </div>
                    <button class="btn btn-outline-gold" type="submit">Reject Deposit</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Event Log</h2>
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
                                    <?= esc($event['event_type']) ?>
                                    <div class="field-hint"><?= esc(($event['previous_status'] ?? '—') . ' → ' . ($event['new_status'] ?? '—')) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <a class="btn btn-outline-gold" href="<?= site_url('admin/deposits') ?>">Back to Deposits</a>
    </div>
</div>
<?= $this->endSection() ?>
