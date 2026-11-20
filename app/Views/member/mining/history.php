<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
/** @var array<string, mixed> $history */
/** @var array<string, mixed> $summary */
$base       = site_url(($panelBase ?? 'member') . '/mining');
$walletBase = site_url(($panelBase ?? 'member') . '/wallet');
$scope      = $history['scope'];
?>
<?= view('components/admin_tabs', [
    'active' => 'history',
    'tabs'   => [
        ['key' => 'wallet', 'label' => 'Coin Wallet', 'url' => $walletBase],
        ['key' => 'mining', 'label' => 'Mining Wallet', 'url' => $base],
        ['key' => 'history', 'label' => 'Mining History', 'url' => $base . '/history'],
    ],
]) ?>

<p class="page-subtitle">Posted mining ledger entries only — estimates and simulations are never listed here. Business dates use Asia/Kolkata (IST).</p>

<?php if (! empty($summary['available'])): ?>
    <div class="d-flex flex-wrap gap-3 mb-3 small">
        <span>Total earned: <strong><?= esc($summary['total_earned']) ?> <?= esc($summary['asset']) ?></strong></span>
        <span>Wallet balance: <strong><?= esc($summary['balance']) ?> <?= esc($summary['asset']) ?></strong></span>
        <span>Credits: <strong><?= (int) $summary['credit_count'] ?></strong></span>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="History scope">
    <a class="btn btn-sm <?= $scope === 'earnings' ? 'btn-gold' : 'btn-outline-gold' ?>" href="<?= esc($base . '/history') ?>">Mining earnings</a>
    <a class="btn btn-sm <?= $scope === 'all' ? 'btn-gold' : 'btn-outline-gold' ?>" href="<?= esc($base . '/history?scope=all') ?>">All wallet transactions</a>
</div>

<div class="card-premium">
    <?php if (empty($history['available'])): ?>
        <div class="alert alert-danger mb-0" role="alert" data-mining-state="unavailable">
            Mining history is unavailable right now: <?= esc((string) $history['error']) ?> Please refresh or try again later.
        </div>
    <?php elseif ($history['rows'] === []): ?>
        <div class="text-secondary py-3" data-mining-state="empty">
            No posted mining credits yet. Earnings appear here once daily mining is posted for a business date you are eligible for.
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Business Date (IST)</th>
                    <th class="text-end">Amount (<?= esc($summary['asset'] ?? 'COIN') ?>)</th>
                    <th>Transaction Reference</th>
                    <?php if ($scope === 'all'): ?><th>Type</th><th class="text-end">Balance After</th><?php endif; ?>
                    <th>Status</th>
                    <th>Credited At (IST)</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($history['rows'] as $row): ?>
                    <tr>
                        <td><?= esc($row['business_date']) ?></td>
                        <td class="text-end"><?= $row['direction'] === 'debit' ? '−' : '' ?><?= esc($row['amount']) ?></td>
                        <td><code><?= esc($row['entry_ref']) ?></code></td>
                        <?php if ($scope === 'all'): ?>
                            <td><?= esc($row['type']) ?></td>
                            <td class="text-end"><?= esc($row['balance_after']) ?></td>
                        <?php endif; ?>
                        <td><span class="badge-status approved"><?= esc($row['status']) ?></span></td>
                        <td><?= esc($row['credited_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="d-flex flex-wrap justify-content-between align-items-center mt-3 small text-secondary">
            <span>Page <?= (int) $history['page'] ?> of <?= (int) $history['pages'] ?> · <?= (int) $history['total'] ?> entries</span>
            <?php if (! empty($pagerHtml)): ?><div><?= $pagerHtml ?></div><?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
