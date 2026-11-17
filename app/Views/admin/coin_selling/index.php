<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Coin selling management. Approval and settlement actions remain disabled until business authorization.</p>
<div class="alert alert-warning mb-3">
    <?= ! empty($status['coin_selling_enabled']) ? 'Kill-switch ON' : 'Coin Selling Not Activated — Awaiting Business Approval.' ?>
    · Fee <?= esc($status['fee_policy_status'] ?? '') ?>
    · Settlement asset <?= esc($status['settlement_asset_status'] ?? '') ?>
    · Counterparty <?= esc($status['counterparty_status'] ?? '') ?>
    · Post-sale mining <?= esc($status['post_sale_mining_policy_status'] ?? '') ?>
</div>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<ul class="nav nav-tabs mb-3">
    <?php
    $tabs = [
        'overview'    => 'Selling Overview',
        'pending'     => 'Pending Requests',
        'lots'        => 'Matured Coin Lots',
        'history'     => 'Selling History',
        'settlement'  => 'Settlement Monitoring',
        'reconcile'   => 'Reconciliation',
    ];
    foreach ($tabs as $key => $label): ?>
        <li class="nav-item">
            <a class="nav-link<?= $tab === $key ? ' active' : '' ?>" href="<?= site_url('admin/coins/selling?tab=' . $key) ?>"><?= esc($label) ?></a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if (in_array($tab, ['overview', 'pending', 'history', 'settlement'], true)): ?>
    <form class="card-premium mb-3 row g-2" method="get">
        <input type="hidden" name="tab" value="<?= esc($tab) ?>">
        <div class="col-md-3"><input class="form-control form-control-sm" name="member_q" placeholder="Member / ref / purchase" value="<?= esc($filters['member_q'] ?? '') ?>"></div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="status">
                <option value="">All statuses</option>
                <?php foreach (['pending_review','approved','settlement_pending','settlement_submitted','completed','rejected','failed','cancelled','draft'] as $st): ?>
                    <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-gold btn-sm" type="submit">Filter</button></div>
    </form>
    <div class="table-responsive card-premium">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
            <tr>
                <th>Sell Ref</th><th>Member</th><th>Purchase</th><th>Lock</th><th>Qty</th>
                <th>Price Snapshot</th><th>Settlement</th><th>Status</th><th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="9" class="text-secondary">No sell requests.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><code><?= esc($row['sell_ref']) ?></code></td>
                    <td><?= esc($row['member_id']) ?></td>
                    <td><code><?= esc($row['purchase_ref']) ?></code></td>
                    <td><code><?= esc((string) ($row['lock_ref'] ?? '')) ?></code></td>
                    <td><?= esc($row['quantity']) ?></td>
                    <td><?= esc((string) ($row['price_snapshot_usdt'] ?? '—')) ?></td>
                    <td><?= esc(trim((string) ($row['settlement_asset'] ?? '') . ' ' . (string) ($row['settlement_amount'] ?? ''))) ?: '—' ?></td>
                    <td><?= esc($row['status']) ?></td>
                    <td><a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/coins/selling/' . rawurlencode($row['sell_ref'])) ?>">View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'lots'): ?>
    <div class="table-responsive card-premium">
        <table class="table table-sm mb-0">
            <thead><tr><th>Purchase</th><th>Member</th><th>Qty</th><th>Locked At</th><th>Matures At</th><th>Status</th><th>Matured?</th></tr></thead>
            <tbody>
            <?php if (empty($lots)): ?>
                <tr><td colspan="7" class="text-secondary">No purchase lots.</td></tr>
            <?php else: foreach ($lots as $lot): ?>
                <tr>
                    <td><code><?= esc((string) ($lot['purchase_ref'] ?? '')) ?></code></td>
                    <td><?= esc($lot['member_id']) ?></td>
                    <td><?= esc($lot['quantity']) ?></td>
                    <td><?= esc($lot['locked_at']) ?></td>
                    <td><?= esc($lot['matures_at']) ?></td>
                    <td><?= esc($lot['status']) ?></td>
                    <td><?= ! empty($lot['is_matured']) ? 'yes' : 'no' ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php if ($tab === 'reconcile' && $reconcile): ?>
    <div class="card-premium mb-3">
        <div>Mismatches: <strong><?= esc((string) ($reconcile['summary']['mismatches'] ?? 0)) ?></strong></div>
        <div class="small text-secondary"><?= esc($reconcile['note'] ?? '') ?></div>
    </div>
    <?php if (! empty($reconcile['mismatches'])): ?>
        <div class="alert alert-danger small">
            <?php foreach (array_slice($reconcile['mismatches'], 0, 30) as $m): ?>
                <div><?= esc(json_encode($m, JSON_UNESCAPED_SLASHES)) ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= $this->endSection() ?>
