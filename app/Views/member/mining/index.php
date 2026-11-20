<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
/** @var array<string, mixed> $summary */
/** @var array<string, mixed> $recent */
$base       = site_url(($panelBase ?? 'member') . '/mining');
$walletBase = site_url(($panelBase ?? 'member') . '/wallet');
?>
<?= view('components/admin_tabs', [
    'active' => 'mining',
    'tabs'   => [
        ['key' => 'wallet', 'label' => 'Coin Wallet', 'url' => $walletBase],
        ['key' => 'mining', 'label' => 'Mining Wallet', 'url' => $base],
        ['key' => 'history', 'label' => 'Mining History', 'url' => $base . '/history'],
    ],
]) ?>

<p class="page-subtitle">Actual mining income posted to your Mining Wallet. Amounts are in <?= esc($summary['asset']) ?>, 8 decimals; business dates use Asia/Kolkata (IST).</p>

<?php if (empty($summary['available'])): ?>
    <div class="alert alert-danger" role="alert" data-mining-state="unavailable">
        Mining earnings are unavailable right now: <?= esc((string) $summary['error']) ?> Your balance has not changed — please try again later.
    </div>
<?php else: ?>
    <?php if (empty($summary['wallet_reconciled'])): ?>
        <div class="alert alert-warning small">Your mining wallet totals are being reviewed by support. The figures below show posted ledger entries.</div>
    <?php endif; ?>
    <div class="small text-secondary mb-2" data-mining-state="ok">
        Daily mining posting:
        <?php if ($postingActive === true): ?>
            <span class="badge-status approved">Active</span>
        <?php elseif ($postingActive === false): ?>
            <span class="badge-status pending">Paused</span> — credits already posted remain in your wallet.
        <?php else: ?>
            <span class="badge-status pending">Status unavailable</span>
        <?php endif; ?>
        · Mining Wallet withdrawal is not available.
    </div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Mining Wallet Transactions</h2>
        <a class="small" href="<?= esc($base . '/history?scope=all') ?>">View all</a>
    </div>
    <?php if (empty($recent['available'])): ?>
        <div class="text-danger">Transaction history unavailable: <?= esc((string) $recent['error']) ?></div>
    <?php elseif ($recent['rows'] === []): ?>
        <div class="text-secondary">No mining wallet transactions yet. Credits appear here once daily mining is posted for a business date you are eligible for.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead><tr><th>Business Date (IST)</th><th>Reference</th><th>Type</th><th class="text-end">Amount (<?= esc($summary['asset']) ?>)</th><th class="text-end">Balance After</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($recent['rows'] as $row): ?>
                    <tr>
                        <td><?= esc($row['business_date']) ?></td>
                        <td><code><?= esc($row['entry_ref']) ?></code></td>
                        <td><?= esc($row['type']) ?></td>
                        <td class="text-end"><?= $row['direction'] === 'debit' ? '−' : '+' ?><?= esc($row['amount']) ?></td>
                        <td class="text-end"><?= esc($row['balance_after']) ?></td>
                        <td><span class="badge-status approved"><?= esc($row['status']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php if ($preview !== null): ?>
    <details class="card-premium mb-4">
        <summary class="h6 mb-0">Mining eligibility &amp; estimate (not income)</summary>
        <div class="alert alert-info small mt-3 mb-3">ESTIMATE ONLY — this is not a credit. Only the posted entries above are income.</div>
        <div class="row g-3">
            <div class="col-md-6">
                <div>Status: <strong><?= ! empty($preview['eval']['eligible']) ? 'Eligible' : 'Not eligible / not configured' ?></strong></div>
                <div class="small text-secondary">Reasons: <?= esc(implode(', ', $preview['eval']['reason_codes'] ?? [])) ?></div>
                <div class="mt-2">Eligible coins: <?= esc((string) $preview['eval']['eligible_coins']) ?> (available <?= esc((string) $preview['eval']['available']) ?> + locked <?= esc((string) $preview['eval']['locked']) ?>)</div>
                <div class="mt-2">Mining start: <?= esc((string) ($preview['eval']['mining_start_date'] ?? '—')) ?></div>
            </div>
            <div class="col-md-6">
                <div>Approved rate: <?= $preview['rate'] === null ? 'Not configured' : esc($preview['rate']['rate_percent'] . '% · ' . $preview['rate']['rate_basis'] . ' · ' . $preview['rate']['effective_month']) ?></div>
                <div class="mt-2">Estimated for <?= esc($preview['today']) ?>: <strong><?= esc($preview['expected']) ?></strong> (estimate)</div>
            </div>
        </div>
    </details>
<?php endif; ?>
<?= $this->endSection() ?>
