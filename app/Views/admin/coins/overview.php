<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Internal coin accounting only. Manual inventory entries are unverified internal records unless independently verified later.</p>

<div class="stat-grid mb-4" style="grid-template-columns: repeat(4, minmax(0, 1fr));">
    <div class="card-premium stat-card">
        <span>Total Coins Added</span>
        <h3><?= esc($inventory['total_added']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Available Inventory</span>
        <h3><?= esc($inventory['available_balance']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Total Allocated</span>
        <h3><?= esc($inventory['total_allocated']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Current Price (USDT)</span>
        <h3><?= $price['current_price_usdt'] !== null ? esc($price['current_price_usdt']) : '—' ?></h3>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-gold" href="<?= site_url('admin/coins/inventory/add') ?>"><i class="bi bi-plus-circle me-1"></i> Add Coins</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/coins/price') ?>">Update Price</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/coins/inventory/history') ?>">Inventory History</a>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Recent Inventory Transactions</h2>
                <img class="coin-thumb" src="<?= base_url('assets/images/coin.svg') ?>" alt="NGW Coin" width="32" height="32">
            </div>
            <?php if ($recentInventory === []): ?>
                <div class="text-secondary">No inventory transactions yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead>
                        <tr>
                            <th>Ref</th>
                            <th>Type</th>
                            <th>Qty</th>
                            <th>Balance</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentInventory as $row): ?>
                            <tr>
                                <td><?= esc($row['transaction_ref']) ?></td>
                                <td><?= esc($row['type']) ?></td>
                                <td><?= esc($row['direction'] === 'credit' ? '+' : '-') ?><?= esc($row['quantity']) ?></td>
                                <td><?= esc($row['new_balance']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Recent Price Changes</h2>
            <?php if ($recentPrices === []): ?>
                <div class="text-secondary">No price changes recorded yet.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table-premium">
                        <thead>
                        <tr>
                            <th>Previous</th>
                            <th>New</th>
                            <th>By</th>
                            <th>When</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($recentPrices as $row): ?>
                            <tr>
                                <td><?= $row['previous_price_usdt'] !== null ? esc($row['previous_price_usdt']) : '—' ?></td>
                                <td><?= esc($row['new_price_usdt']) ?></td>
                                <td><?= esc($actorNames[(int) $row['actor_user_id']] ?? ('#' . $row['actor_user_id'])) ?></td>
                                <td><?= esc($row['effective_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="notice-box mt-4">
    Inventory Adjustment Summary: Available = Total Added − Total Allocated. Allocations occur when purchase requests are approved. Current allocated total: <?= esc($inventory['total_allocated']) ?>.
</div>
<?= $this->endSection() ?>
