<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
$activation    = $activation ?? service('memberActivationService')->statusForMember((int) auth()->id());
$walletSummary = $walletSummary ?? ['balance' => '0', 'available' => '0', 'locked' => '0', 'total_acquired' => '0'];
$pendingPayments = $pendingPayments ?? [];
$recentPurchases = $recentPurchases ?? [];
$referralSummary = $referralSummary ?? ['direct' => 0, 'total' => 0, 'active' => 0];
?>

<div class="panel-card mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
        <div>
            <div class="text-secondary small">Member</div>
            <h1 class="h4 mb-1"><?= esc($profile->member_name) ?></h1>
            <div class="text-secondary">
                ID <code><?= esc($profile->member_id) ?></code>
                · Status
                <span class="badge-status <?= esc($profile->account_status) ?>"><?= esc(ucfirst((string) $profile->account_status)) ?></span>
            </div>
            <?php if (! empty($activation['activated_at'])): ?>
                <div class="small text-secondary mt-1">Activated <?= esc($activation['activated_at']) ?></div>
            <?php endif; ?>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/profile') ?>">Profile</a>
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/wallet') ?>">Wallet</a>
            <?php if (! ($activation['is_active'] ?? false)): ?>
                <a class="btn btn-sm btn-gold" href="<?= site_url('member/buy-coins') ?>">Buy Coins</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (! ($activation['is_active'] ?? false)): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <strong>ACCOUNT STATUS: INACTIVE</strong>
        <div class="mt-1"><?= esc($activation['message'] ?? 'Purchase coins to activate your account.') ?></div>
        <a class="btn btn-gold btn-sm mt-3" href="<?= site_url('member/buy-coins') ?>">Buy Coins</a>
    </div>
<?php else: ?>
    <div class="alert alert-success mb-4" role="alert">
        <strong>ACCOUNT STATUS: ACTIVE</strong>
        <?php if (! empty($activation['activated_at'])): ?>
            · Activated <?= esc($activation['activated_at']) ?>
        <?php endif; ?>
        <?php if (! empty($activation['activation_purchase_ref'])): ?>
            · Purchase <code><?= esc($activation['activation_purchase_ref']) ?></code>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($pendingPayments !== []): ?>
    <div class="alert alert-info mb-4" role="alert">
        <strong>Payment Status: Pending Verification</strong>
        <ul class="mb-0 mt-2">
            <?php foreach ($pendingPayments as $pay): ?>
                <li>
                    <code><?= esc($pay['deposit_ref'] ?? $pay['reference'] ?? '—') ?></code>
                    · <?= esc(ucfirst((string) ($pay['status'] ?? 'pending'))) ?>
                    · <?= esc(number_format((float) ($pay['expected_amount_usdt'] ?? $pay['amount_usdt'] ?? 0), 2)) ?> USDT
                </li>
            <?php endforeach; ?>
        </ul>
        <a class="btn btn-outline-gold btn-sm mt-2" href="<?= site_url('member/buy-coins') ?>">View Buy Coins</a>
    </div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-4">
    <div class="col-lg-4">
        <div class="card-premium h-100">
            <h2 class="h6 mb-3">Coin Holdings</h2>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Owned</span><strong><?= esc($walletSummary['balance'] ?? '0') ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Available</span><strong><?= esc($walletSummary['available'] ?? '0') ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Locked</span><strong><?= esc($walletSummary['locked'] ?? '0') ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-secondary">Purchased</span><strong><?= esc($walletSummary['total_acquired'] ?? '0') ?></strong></div>
            <a class="btn btn-sm btn-outline-gold mt-3" href="<?= site_url('member/wallet') ?>">Open Wallet</a>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-premium h-100">
            <h2 class="h6 mb-3">Referral Summary</h2>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Direct</span><strong><?= (int) ($referralSummary['direct'] ?? 0) ?></strong></div>
            <div class="d-flex justify-content-between mb-2"><span class="text-secondary">Total Team</span><strong><?= (int) ($referralSummary['total'] ?? 0) ?></strong></div>
            <div class="d-flex justify-content-between"><span class="text-secondary">Active Team</span><strong><?= (int) ($referralSummary['active'] ?? 0) ?></strong></div>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/referrals') ?>">Referrals</a>
                <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/genealogy') ?>">Genealogy</a>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-premium h-100">
            <h2 class="h6 mb-3">Previews</h2>
            <div class="text-secondary small mb-2">Mining &amp; commission amounts below are simulations only — not wallet balances.</div>
            <a class="btn btn-sm btn-outline-gold d-block mb-2" href="<?= site_url('member/mining') ?>">Mining Preview</a>
            <a class="btn btn-sm btn-outline-gold d-block" href="<?= site_url('member/commissions') ?>">Commission Preview</a>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
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
</div>

<div class="card-premium mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Recent Purchases</h2>
        <a class="small" href="<?= site_url('member/buy-coins') ?>">Buy Coins</a>
    </div>
    <?php if ($recentPurchases === []): ?>
        <div class="text-secondary">No purchase requests yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead><tr><th>Reference</th><th>Coins</th><th>Status</th><th>Requested</th></tr></thead>
                <tbody>
                <?php foreach ($recentPurchases as $row): ?>
                    <tr>
                        <td><code><?= esc($row['purchase_ref'] ?? '') ?></code></td>
                        <td><?= esc(number_format((float) ($row['coin_quantity'] ?? 0), 8)) ?></td>
                        <td><span class="badge-status <?= esc($row['status'] ?? '') ?>"><?= esc(ucfirst((string) ($row['status'] ?? ''))) ?></span></td>
                        <td><?= esc($row['requested_at'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card-premium mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <h2 class="h6 mb-0">Recent Wallet Activity</h2>
        <a class="small" href="<?= site_url('member/wallet?tab=transactions') ?>">My Wallet</a>
    </div>
    <?php if (empty($recentWallet)): ?>
        <div class="text-secondary">No ownership ledger activity yet.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead><tr><th>Ref</th><th>Type</th><th>Qty</th><th>When</th></tr></thead>
                <tbody>
                <?php foreach ($recentWallet as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="card-premium mb-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="text-secondary mb-0">Referral Link</div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/referrals') ?>">My Referrals</a>
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/genealogy') ?>">Genealogy</a>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <code class="text-break" id="referralLinkValue"><?= esc($referralLink) ?></code>
        <button type="button" class="btn btn-sm btn-gold" id="copyReferralLink" data-copy-target="referralLinkValue">Copy Link</button>
        <?php if ($profile->isInactive()): ?>
            <span class="badge-status inactive">Sponsoring unlocks after activation</span>
        <?php endif; ?>
    </div>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h2 class="h5 mb-1">Coin Market Snapshot</h2>
            <div class="text-secondary">Current admin-configured price.</div>
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
            <div class="text-secondary mb-1">Minimum Purchase</div>
            <strong><?= esc($priceSummary['min_purchase_usdt'] ?? '5.00000000') ?> USDT</strong>
        </div>
        <div class="col-md-4">
            <div class="text-secondary mb-1">Buy Coins</div>
            <a class="btn btn-gold btn-sm" href="<?= site_url('member/buy-coins') ?>">Buy Now</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/dashboard-charts.js') ?>"></script>
<?= $this->endSection() ?>
