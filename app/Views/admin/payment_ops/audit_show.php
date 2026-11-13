<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php $d = $detail['deposit']; ?>
<p class="page-subtitle">Safe detail view — no secrets, no ledger auto-repair.</p>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card-premium mb-3">
            <div class="d-flex justify-content-between mb-3">
                <div>
                    <div class="text-secondary">Deposit</div>
                    <strong><?= esc($d['deposit_ref']) ?></strong>
                </div>
                <span class="badge-status <?= esc($d['status']) ?>"><?= esc($d['status']) ?></span>
            </div>
            <div class="row g-3">
                <div class="col-md-6"><div class="text-secondary mb-1">Network</div><strong><?= esc($network['network_name'] ?? $d['network_key']) ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Member</div><strong><?= esc($d['member_id']) ?></strong></div>
                <div class="col-12"><div class="text-secondary mb-1">Tx Hash</div><code class="text-break"><?= esc($d['tx_hash'] ?? '—') ?></code></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Expected</div><strong><?= esc($d['expected_amount_usdt']) ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Verified Amount</div><strong><?= esc($d['verified_amount_usdt'] ?? '—') ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Confirmations</div><strong><?= esc((string) ($d['verified_confirmations'] ?? '—')) ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Verification Result</div><code><?= esc((string) ($d['last_verification_status'] ?? '—')) ?></code></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Error Category</div><code><?= esc((string) ($detail['error_category'] ?? '—')) ?></code></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Attempts</div><strong><?= (int) ($d['verification_attempt_count'] ?? 0) ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Consumption</div><strong><?= esc((string) ($d['payment_consumption_status'] ?? '—')) ?></strong></div>
                <div class="col-md-4"><div class="text-secondary mb-1">Reconciliation</div><strong><?= esc($detail['reconciliation_status']) ?></strong></div>
                <div class="col-md-6"><div class="text-secondary mb-1">Linked Purchase</div>
                    <?php if (! empty($d['linked_purchase_ref'])): ?>
                        <a href="<?= site_url('admin/purchases/' . $d['linked_purchase_ref']) ?>"><?= esc($d['linked_purchase_ref']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </div>
                <div class="col-md-6"><div class="text-secondary mb-1">Integrity</div><strong><?= esc((string) ($d['integrity_flag'] ?? '—')) ?></strong></div>
                <?php if (! empty($d['integrity_notes'])): ?>
                    <div class="col-12"><div class="alert alert-warning mb-0"><?= esc($d['integrity_notes']) ?></div></div>
                <?php endif; ?>
                <?php if (! empty($d['last_verification_message'])): ?>
                    <div class="col-12"><div class="text-secondary mb-1">Last Message</div><strong><?= esc($d['last_verification_message']) ?></strong></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Actions</h2>
            <?php if (! empty($retry['allowed'])): ?>
                <form method="post" action="<?= site_url('admin/payment-audit/' . $d['deposit_ref'] . '/retry-verify') ?>" class="mb-2" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn-gold" type="submit">Safe Retry Verification</button>
                </form>
            <?php else: ?>
                <div class="field-hint mb-2">Retry unavailable: <?= esc((string) ($retry['reason'] ?? 'n/a')) ?></div>
            <?php endif; ?>

            <?php if ($d['status'] === 'verified'): ?>
                <form method="post" action="<?= site_url('admin/payment-audit/' . $d['deposit_ref'] . '/integrity-recheck') ?>" data-loading>
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-gold" type="submit">Re-check Chain Integrity</button>
                </form>
                <div class="field-hint mt-2">Flags inconsistencies only. Never reverses ownership or inventory.</div>
            <?php endif; ?>
            <div class="mt-3"><a href="<?= site_url('admin/deposits/' . $d['deposit_ref']) ?>">Open classic deposit review</a></div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card-premium mb-3">
            <h2 class="h5 mb-3">Allocation</h2>
            <?php if (empty($detail['allocation'])): ?>
                <div class="text-secondary">No allocation linked.</div>
            <?php else: ?>
                <?php $a = $detail['allocation']; ?>
                <div><code><?= esc($a['allocation_ref']) ?></code></div>
                <div>Status: <strong><?= esc($a['status']) ?></strong></div>
                <div>Purchase: <?= esc($a['purchase_ref']) ?></div>
                <div>Amount: <?= esc($a['amount_usdt']) ?></div>
            <?php endif; ?>
        </div>

        <div class="card-premium">
            <h2 class="h5 mb-3">Audit Timeline</h2>
            <?php if ($detail['events'] === []): ?>
                <div class="text-secondary">No events.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead><tr><th>When</th><th>Event</th></tr></thead>
                        <tbody>
                        <?php foreach ($detail['events'] as $event): ?>
                            <tr>
                                <td><?= esc($event['created_at']) ?></td>
                                <td>
                                    <strong><?= esc($event['event_type']) ?></strong>
                                    <?php if (! empty($event['metadata'])): ?>
                                        <div class="text-secondary small text-break"><?= esc(is_string($event['metadata']) ? $event['metadata'] : json_encode($event['metadata'])) ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
