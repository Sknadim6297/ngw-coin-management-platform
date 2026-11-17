<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Sell eligible owned coins only after the mandatory 1000-day lock matures. Mining income and immature lots cannot be sold here.</p>

<?php if (empty($enabled)): ?>
    <div class="alert alert-warning">
        <strong>Coin Selling Not Activated — Awaiting Business Approval.</strong>
        · Indicative Admin price only
        · Fees / settlement asset / counterparty pending business decision
        · Submit remains blocked server-side.
    </div>
<?php else: ?>
    <div class="alert alert-danger">Selling kill-switch reports ON but Phase 11E still refuses execution until separately authorized.</div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-3">
    <h2 class="h5 mb-3">Purchase Lots</h2>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead>
            <tr>
                <th>Purchase ID</th>
                <th>Coins</th>
                <th>Lock Date</th>
                <th>Maturity Date</th>
                <th>Status</th>
                <th>Eligible Coins</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($summary['lots'])): ?>
                <tr><td colspan="6" class="text-secondary">No purchase lots yet.</td></tr>
            <?php else: foreach ($summary['lots'] as $lot): ?>
                <tr>
                    <td><code><?= esc($lot['purchase_ref']) ?></code></td>
                    <td><?= esc($lot['coin_quantity']) ?></td>
                    <td><?= esc($lot['lock_start_date']) ?></td>
                    <td><?= esc($lot['lock_end_date']) ?></td>
                    <td><?= esc($lot['maturity_status']) ?></td>
                    <td><?= esc($lot['eligible_quantity']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <div class="small text-secondary mt-2">Business date (Asia/Kolkata): <?= esc($summary['business_date']) ?> · Indicative price: <?= esc((string) ($summary['indicative_price_usdt'] ?? 'not configured')) ?> USDT</div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Selling Preview</h2>
            <form method="post" action="<?= site_url('member/coin-selling/preview') ?>" class="mb-3">
                <?= csrf_field() ?>
                <label class="form-label" for="purchase_ref">Matured purchase lot</label>
                <select class="form-select mb-2" name="purchase_ref" id="purchase_ref" required>
                    <option value="">Select lot</option>
                    <?php foreach ($summary['lots'] as $lot): ?>
                        <?php if (! empty($lot['is_matured']) && \App\Libraries\MoneyMath::isPositive((string) ($lot['eligible_quantity'] ?? '0'), 8)): ?>
                            <option value="<?= esc($lot['purchase_ref']) ?>" <?= old('purchase_ref') === $lot['purchase_ref'] ? 'selected' : '' ?>>
                                <?= esc($lot['purchase_ref']) ?> · eligible <?= esc($lot['eligible_quantity']) ?>
                            </option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <label class="form-label" for="quantity">Quantity</label>
                <input class="form-control" type="text" name="quantity" id="quantity" value="<?= esc(old('quantity') ?? '') ?>" required placeholder="0.00000000">
                <button class="btn btn-outline-gold btn-sm mt-2" type="submit">Preview Sale</button>
            </form>

            <?php if (! empty($preview) && is_array($preview)): ?>
                <div class="notice-box mb-3">
                    <div><strong>Preview</strong> · <?= esc($preview['note']) ?></div>
                    <div>Lot <?= esc($preview['purchase_ref']) ?> · Qty <?= esc($preview['quantity']) ?></div>
                    <div>Indicative price <?= esc((string) ($preview['indicative_price'] ?? 'n/a')) ?> USDT</div>
                    <div>Gross <?= $preview['gross_value_usdt'] === null ? 'n/a (price missing)' : esc($preview['gross_value_usdt']) . ' USDT' ?></div>
                    <div>Fee / net <?= esc($preview['fee_policy_status']) ?></div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?= site_url('member/coin-selling') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="purchase_ref" value="<?= esc(old('purchase_ref') ?? '') ?>">
                <input type="hidden" name="quantity" value="<?= esc(old('quantity') ?? '') ?>">
                <input type="hidden" name="idempotency_key" value="<?= esc(bin2hex(random_bytes(16))) ?>">
                <label class="form-label" for="password">Confirm with password</label>
                <input class="form-control mb-2" type="password" name="password" id="password" autocomplete="current-password" <?= empty($enabled) ? 'disabled' : 'required' ?>>
                <button class="btn btn-gold" type="submit" <?= empty($enabled) ? 'disabled' : '' ?>>Confirm Sale Request</button>
            </form>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h2 class="h5 mb-3">Selling History</h2>
            <div class="table-responsive">
                <table class="table table-dark-premium table-sm mb-0">
                    <thead><tr><th>Ref</th><th>Purchase</th><th>Qty</th><th>Status</th><th>Date</th></tr></thead>
                    <tbody>
                    <?php if (empty($history)): ?>
                        <tr><td colspan="5" class="text-secondary">No sell requests yet.</td></tr>
                    <?php else: foreach ($history as $row): ?>
                        <tr>
                            <td><code><?= esc($row['sell_ref']) ?></code></td>
                            <td><code><?= esc($row['purchase_ref']) ?></code></td>
                            <td><?= esc($row['quantity']) ?></td>
                            <td><?= esc($row['status']) ?></td>
                            <td><?= esc($row['created_at'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
