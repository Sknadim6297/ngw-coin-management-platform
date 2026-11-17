<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Review payments and approve purchases in one place. Approve Payment first, then Approve Purchase.</p>

<?= view('components/admin_tabs', [
    'active' => $tab,
    'tabs'   => [
        ['key' => 'all', 'label' => 'All', 'url' => site_url('admin/payment-approvals?tab=all'), 'count' => $counts['all'] ?? 0],
        ['key' => 'pending', 'label' => 'Pending', 'url' => site_url('admin/payment-approvals?tab=pending'), 'count' => $counts['pending'] ?? 0],
        ['key' => 'completed', 'label' => 'Completed', 'url' => site_url('admin/payment-approvals?tab=completed'), 'count' => $counts['completed'] ?? 0],
        ['key' => 'rejected', 'label' => 'Rejected', 'url' => site_url('admin/payment-approvals?tab=rejected'), 'count' => $counts['rejected'] ?? 0],
        ['key' => 'unlinked', 'label' => 'Unlinked Deposits', 'url' => site_url('admin/payment-approvals?tab=unlinked'), 'count' => $counts['unlinked'] ?? 0],
    ],
]) ?>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="<?= esc($tab) ?>">
        <div class="col-md-6">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search) ?>"
                   placeholder="Member ID, purchase ref, deposit ref, or TXN hash">
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Search</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member</th>
                <th>Purchase</th>
                <th>Deposit</th>
                <th>USDT</th>
                <th>Coins</th>
                <th>TXN Hash</th>
                <th>Payment</th>
                <th>Purchase Status</th>
                <th>Submitted</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr>
                    <td colspan="10" class="text-secondary">
                        No records in this tab.
                        <?php if ($tab === 'pending'): ?>
                            When a member submits a Buy Coins payment, it appears here for review.
                        <?php endif; ?>
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?= esc($row['member_name']) ?></strong>
                            <div class="small text-secondary"><?= esc($row['member_id']) ?></div>
                        </td>
                        <td><code><?= esc($row['purchase_ref'] ?? '—') ?></code></td>
                        <td><code><?= esc($row['deposit_ref'] ?? '—') ?></code></td>
                        <td><?= esc($row['usdt_amount']) ?></td>
                        <td><?= esc($row['coin_quantity']) ?></td>
                        <td>
                            <?php if (! empty($row['tx_hash'])): ?>
                                <code class="small"><?= esc(substr((string) $row['tx_hash'], 0, 12)) ?>…</code>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><span class="badge-status <?= esc(str_replace(' ', '_', (string) $row['payment_status'])) ?>"><?= esc(str_replace('_', ' ', (string) $row['payment_status'])) ?></span></td>
                        <td><span class="badge-status <?= esc((string) $row['purchase_status']) ?>"><?= esc((string) $row['purchase_status']) ?></span></td>
                        <td><?= esc($row['submitted_at'] ?? '—') ?></td>
                        <td><a class="btn btn-sm btn-outline-gold" href="<?= esc($row['review_url']) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?>
        <div class="mt-3"><?= $pager->links() ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
