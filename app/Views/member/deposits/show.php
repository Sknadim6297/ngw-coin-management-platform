<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Deposit request details. Evidence and hashes are not automatic payment proof.</p>

<div class="card-premium mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <div class="text-secondary">Reference</div>
            <strong><?= esc($deposit['deposit_ref']) ?></strong>
        </div>
        <span class="badge-status <?= esc($deposit['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($deposit['status']))) ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-secondary mb-1">Network</div>
            <strong><?= esc($network['network_name'] ?? $deposit['network_key']) ?></strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Expected Amount</div>
            <strong><?= esc($deposit['expected_amount_usdt']) ?> USDT</strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Submitted</div>
            <strong><?= esc($deposit['submitted_at'] ?? '—') ?></strong>
        </div>
        <div class="col-md-6">
            <div class="text-secondary mb-1">Destination Wallet (snapshot)</div>
            <code class="text-break"><?= esc($deposit['destination_wallet_snapshot']) ?></code>
        </div>
        <div class="col-md-6">
            <div class="text-secondary mb-1">Transaction Hash</div>
            <code class="text-break"><?= esc($deposit['tx_hash'] ?? '—') ?></code>
        </div>
        <?php if (! empty($deposit['evidence_stored_name'])): ?>
            <div class="col-md-6">
                <div class="text-secondary mb-1">Evidence</div>
                <a href="<?= site_url('member/deposits/' . $deposit['deposit_ref'] . '/evidence') ?>">View uploaded evidence</a>
            </div>
        <?php endif; ?>
        <?php if ($deposit['status'] === 'rejected' && ! empty($deposit['rejection_reason'])): ?>
            <div class="col-12">
                <div class="alert alert-danger mb-0"><strong>Rejection reason:</strong> <?= esc($deposit['rejection_reason']) ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($events !== []): ?>
<div class="card-premium mb-3">
    <h2 class="h5 mb-3">Review History</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th><th>From</th><th>To</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= esc($event['created_at']) ?></td>
                    <td><?= esc($event['event_type']) ?></td>
                    <td><?= esc($event['previous_status'] ?? '—') ?></td>
                    <td><?= esc($event['new_status'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-gold" href="<?= site_url('member/deposits') ?>">Back to History</a>
    <?php if (in_array($deposit['status'], ['draft', 'submitted'], true)): ?>
        <form method="post" action="<?= site_url('member/deposits/' . $deposit['deposit_ref'] . '/cancel') ?>" data-loading
              onsubmit="return confirm('Cancel this deposit request?');">
            <?= csrf_field() ?>
            <button class="btn btn-outline-gold" type="submit">Cancel Request</button>
        </form>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
