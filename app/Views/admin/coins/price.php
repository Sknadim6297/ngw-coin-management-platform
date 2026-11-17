<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">USDT price per coin. Existing purchase requests keep their price snapshot.</p>

<?php $tab = $tab ?? 'settings'; ?>
<?= view('components/admin_tabs', [
    'active' => $tab,
    'tabs'   => [
        ['key' => 'settings', 'label' => 'Current Price', 'url' => site_url('admin/coins/price')],
        ['key' => 'history', 'label' => 'Price History', 'url' => site_url('admin/coins/price?tab=history')],
    ],
]) ?>

<?php if ($tab === 'history'): ?>
    <div class="card-premium mb-3">
        <strong>Current price:</strong>
        <?= $settings['current_price_usdt'] !== null ? esc($settings['current_price_usdt']) . ' USDT' : 'Not configured' ?>
    </div>
    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Previous</th>
                    <th>New</th>
                    <th>Reason</th>
                    <th>Changed By</th>
                    <th>Effective At</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($rows ?? []) === []): ?>
                    <tr><td colspan="5" class="text-secondary">No price history yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= $row['previous_price_usdt'] !== null ? esc($row['previous_price_usdt']) : '—' ?></td>
                            <td><?= esc($row['new_price_usdt']) ?></td>
                            <td><?= esc($row['reason']) ?></td>
                            <td><?= esc(($actorNames[(int) $row['actor_user_id']] ?? ('#' . $row['actor_user_id']))) ?></td>
                            <td><?= esc($row['effective_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (! empty($pager)): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
    </div>
<?php else: ?>
    <div class="row g-3">
        <div class="col-lg-5">
            <div class="card-premium">
                <div class="text-secondary mb-1">Current Coin Price</div>
                <?php if ($settings['current_price_usdt'] === null): ?>
                    <div class="h3 text-secondary">Not configured</div>
                <?php else: ?>
                    <div class="h3" style="color: var(--gold-bright);"><?= esc($settings['current_price_usdt']) ?> USDT</div>
                    <div class="field-hint">Updated <?= esc($settings['price_updated_at'] ?? '—') ?></div>
                <?php endif; ?>
                <div class="mt-3 text-secondary">Minimum purchase: <?= esc($settings['min_purchase_usdt']) ?> USDT</div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card-premium">
                <h2 class="h5 mb-3"><?= $settings['current_price_usdt'] === null ? 'Set Initial Price' : 'Update Price' ?></h2>
                <form method="post" action="<?= site_url('admin/coins/price') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="new_price">New Price (USDT per Coin)</label>
                        <input class="form-control" type="text" inputmode="decimal" id="new_price" name="new_price"
                               value="<?= esc(old('new_price', $settings['current_price_usdt'] ?? '')) ?>" required
                               data-validate="required|decimal|gt:0" data-label="New Price">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reason">Reason for Change</label>
                        <textarea class="form-control" id="reason" name="reason" rows="3" required maxlength="500"
                                  data-validate="required|min:3|max:500" data-label="Reason for Change"><?= esc(old('reason')) ?></textarea>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                        <label class="form-check-label" for="confirm">
                            I confirm this price change. Existing purchase snapshots will not be rewritten.
                        </label>
                    </div>
                    <button class="btn btn-gold" type="submit">Save Price</button>
                </form>
            </div>
        </div>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
