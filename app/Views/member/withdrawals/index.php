<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning mb-3">
    <strong>Not Available</strong> — <?= esc($status) ?>
    · Withdrawal submission is blocked server-side.
</div>
<div class="d-flex justify-content-between mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/commission-wallet') ?>">Commission Wallet</a>
    <?php if (! empty($enabled)): ?>
        <a class="btn btn-gold btn-sm" href="<?= site_url('member/withdrawals/create') ?>">Request Withdrawal</a>
    <?php else: ?>
        <button class="btn btn-secondary btn-sm" type="button" disabled>Request Withdrawal — Not Available</button>
    <?php endif; ?>
</div>
<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm mb-0">
        <thead><tr><th>Ref</th><th>Amount</th><th>Network</th><th>Destination</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="5" class="text-secondary">No withdrawals. Live payouts are not activated.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><a href="<?= site_url('member/withdrawals/' . rawurlencode($row['withdrawal_ref'])) ?>"><code><?= esc($row['withdrawal_ref']) ?></code></a></td>
                <td><?= esc($row['amount']) ?> <?= esc($row['currency']) ?></td>
                <td><?= esc($row['network']) ?></td>
                <td><code><?= esc($row['destination_masked'] ?? '') ?></code></td>
                <td><?= esc($row['status']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
