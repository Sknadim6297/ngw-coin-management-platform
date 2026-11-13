<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your membership overview with live coin ownership and locking status from the ledger.</p>

<?php if ($profile->isInactive()): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <strong>Activation required.</strong> Your account is inactive. You can browse your dashboard and profile now.
        Mining, commissions, and sponsor-only earning features unlock after your first approved coin purchase.
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Member Name</div>
            <strong><?= esc($profile->member_name) ?></strong>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Member ID</div>
            <strong><?= esc($profile->member_id) ?></strong>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Account Status</div>
            <span class="badge-status <?= esc($profile->account_status) ?>"><?= esc(ucfirst($profile->account_status)) ?></span>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Sponsor ID</div>
            <strong><?= esc($relationship['sponsor_member_id'] ?? '—') ?></strong>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Registration Date</div>
            <strong><?= esc($profile->created_at) ?></strong>
        </div>
    </div>
    <div class="col-md-6 col-xl-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Profile Completion</div>
            <strong><?= esc($completion) ?>%</strong>
            <div class="progress mt-2" style="height: 8px; background: #2a2a31;">
                <div class="progress-bar" style="width: <?= esc($completion) ?>%; background: linear-gradient(90deg, #F4D77B, #D4AF37);"></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Total Owned Coins</div>
            <strong style="color: var(--gold-bright);"><?= esc($coinSummary['balance']) ?></strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Available Coins</div>
            <strong style="color: var(--gold-bright);"><?= esc($coinSummary['available']) ?></strong>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Locked Coins</div>
            <strong style="color: var(--gold-bright);"><?= esc($coinSummary['locked']) ?></strong>
            <div class="mt-2"><a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/locking') ?>">Manage Locks</a></div>
        </div>
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
