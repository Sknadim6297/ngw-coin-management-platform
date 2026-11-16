<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php if ($profile->isInactive()): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <strong>Activation required.</strong> Your account is inactive. You can browse your dashboard and profile now.
        Mining, commissions, and sponsor-only earning features unlock after your first approved coin purchase.
    </div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartHoldings',
            'title'   => 'Personal Coin Holdings',
            'type'    => 'doughnut',
            'dataset' => $charts['holdings_breakdown'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartPurchases',
            'title'   => 'Approved Purchase Activity (14 days)',
            'type'    => 'bar',
            'dataset' => $charts['purchase_activity'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartLocks',
            'title'   => 'Locking Activity (14 days)',
            'type'    => 'line',
            'dataset' => $charts['locking_activity'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartTeam',
            'title'   => 'Direct Referral Growth (14 days)',
            'type'    => 'bar',
            'dataset' => $charts['team_growth'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartMining',
            'title'   => 'Mining History',
            'type'    => 'line',
            'dataset' => $charts['mining_history'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartCommission',
            'title'   => 'Commission History',
            'type'    => 'line',
            'dataset' => $charts['commission_history'],
        ]) ?>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h6 mb-2">Active Locks</h2>
            <?php if ($activeLocks === []): ?>
                <div class="text-secondary">None</div>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach (array_slice($activeLocks, 0, 5) as $lock): ?>
                        <li><a href="<?= site_url('member/locking/' . $lock['lock_ref']) ?>"><?= esc($lock['lock_ref']) ?></a> — <?= esc($lock['quantity']) ?> (matures <?= esc($lock['matures_at']) ?>)</li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h6 mb-2">Matured Locks</h2>
            <?php if ($maturedLocks === []): ?>
                <div class="text-secondary">None ready to unlock</div>
            <?php else: ?>
                <ul class="mb-0">
                    <?php foreach (array_slice($maturedLocks, 0, 5) as $lock): ?>
                        <li><a href="<?= site_url('member/locking/' . $lock['lock_ref']) ?>"><?= esc($lock['lock_ref']) ?></a> — <?= esc($lock['quantity']) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($recentLocks !== []): ?>
<div class="card-premium mb-4">
    <h2 class="h6 mb-2">Recent Lock Activity</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Ref</th><th>Qty</th><th>Status</th><th>Locked</th></tr></thead>
            <tbody>
            <?php foreach (array_slice($recentLocks, 0, 5) as $lock): ?>
                <tr>
                    <td><a href="<?= site_url('member/locking/' . $lock['lock_ref']) ?>"><?= esc($lock['lock_ref']) ?></a></td>
                    <td><?= esc($lock['quantity']) ?></td>
                    <td><?= esc($lock['status']) ?></td>
                    <td><?= esc($lock['locked_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card-premium mb-4">
    <div class="text-secondary mb-1">Referral Link</div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <code class="text-break"><?= esc($referralLink) ?></code>
        <?php if ($profile->isInactive()): ?>
            <span class="badge-status inactive">Sponsoring unlocks after activation</span>
        <?php endif; ?>
    </div>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h2 class="h5 mb-1">Coin Market Snapshot</h2>
            <div class="text-secondary">Live admin-configured values. Submit a purchase request to buy coins.</div>
        </div>
        <img class="coin-thumb" src="<?= base_url('assets/images/coin.svg') ?>" alt="NGW Coin" width="40" height="40">
    </div>
    <div class="row g-3">
        <div class="col-md-4">
            <div class="text-secondary mb-1">Current Coin Price</div>
            <?php if (($priceSummary['current_price_usdt'] ?? null) === null): ?>
                <strong>Coin price is not configured yet.</strong>
            <?php else: ?>
                <strong><?= esc($priceSummary['current_price_usdt']) ?> USDT</strong>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Available Inventory</div>
            <?php if (empty($inventory['is_available'])): ?>
                <strong>Coin inventory is currently unavailable.</strong>
            <?php else: ?>
                <strong>Available</strong>
                <div class="field-hint">Internal units ready for purchase requests</div>
            <?php endif; ?>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Minimum Purchase</div>
            <strong><?= esc($priceSummary['min_purchase_usdt'] ?? '5.00000000') ?> USDT</strong>
        </div>
    </div>
    <div class="notice-box mt-3 d-flex flex-wrap gap-2 align-items-center justify-content-between">
        <span>Purchase requests are pending until an administrator approves them. Approval is not payment confirmation.</span>
        <a class="btn btn-gold btn-sm" href="<?= site_url('member/purchases/buy') ?>">Buy Coins</a>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/dashboard-charts.js') ?>"></script>
<?= $this->endSection() ?>
