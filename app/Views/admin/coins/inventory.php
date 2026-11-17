<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Manage platform coin stock. Members can buy only when available inventory is greater than zero.</p>

<?php $tab = $tab ?? 'overview'; ?>
<?= view('components/admin_tabs', [
    'active' => $tab,
    'tabs'   => [
        ['key' => 'overview', 'label' => 'Overview', 'url' => site_url('admin/coins/inventory')],
        ['key' => 'add', 'label' => 'Add Coins', 'url' => site_url('admin/coins/inventory?tab=add')],
        ['key' => 'history', 'label' => 'Inventory History', 'url' => site_url('admin/coins/inventory?tab=history')],
    ],
]) ?>

<?php if (empty($inventory['is_available'])): ?>
    <div class="alert alert-warning">Coins Out of Stock — members cannot buy until you add inventory.</div>
<?php endif; ?>

<div class="stat-grid mb-4">
    <div class="card-premium stat-card">
        <span>Total Added</span>
        <h3><?= esc($inventory['total_added']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Available Coins</span>
        <h3><?= esc($inventory['available_balance']) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Allocated Coins</span>
        <h3><?= esc($inventory['total_allocated']) ?></h3>
    </div>
</div>

<?php if ($tab === 'add'): ?>
    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card-premium">
                <h2 class="h5 mb-3">Add Coins</h2>
                <form method="post" action="<?= site_url('admin/coins/inventory/add') ?>" data-loading data-live-validate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="idempotency_key" value="<?= esc($idempotencyKey) ?>">
                    <div class="mb-3">
                        <label class="form-label" for="quantity">Coin Quantity</label>
                        <input class="form-control" type="text" inputmode="decimal" id="quantity" name="quantity"
                               value="<?= esc(old('quantity')) ?>" required
                               data-validate="required|decimal|gt:0" data-label="Coin Quantity">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="reference">Reference / Source Note</label>
                        <input class="form-control" type="text" id="reference" name="reference"
                               value="<?= esc(old('reference')) ?>" maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="remarks">Admin Remarks</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="3" maxlength="1000"><?= esc(old('remarks')) ?></textarea>
                    </div>
                    <div class="form-check mb-4">
                        <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                        <label class="form-check-label" for="confirm">
                            I confirm this is an internal inventory record.
                        </label>
                    </div>
                    <button class="btn btn-gold" type="submit">Credit Inventory</button>
                </form>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card-premium">
                <div class="text-secondary mb-1">Current available balance</div>
                <div class="h3" style="color: var(--gold-bright);"><?= esc($inventory['available_balance']) ?></div>
            </div>
        </div>
    </div>
<?php elseif ($tab === 'history'): ?>
    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Ref</th>
                    <th>Type</th>
                    <th>Direction</th>
                    <th>Quantity</th>
                    <th>Previous</th>
                    <th>New</th>
                    <th>Actor</th>
                    <th>When</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($rows ?? []) === []): ?>
                    <tr><td colspan="8" class="text-secondary">No ledger entries yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= esc($row['transaction_ref']) ?></td>
                            <td><?= esc($row['type']) ?></td>
                            <td><?= esc($row['direction']) ?></td>
                            <td><?= esc($row['quantity']) ?></td>
                            <td><?= esc($row['previous_balance']) ?></td>
                            <td><?= esc($row['new_balance']) ?></td>
                            <td><?= esc(($actorNames[(int) ($row['actor_user_id'] ?? 0)] ?? '—')) ?></td>
                            <td><?= esc($row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (! empty($pager)): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
    </div>
<?php else: ?>
    <div class="d-flex flex-wrap gap-2">
        <a class="btn btn-gold" href="<?= site_url('admin/coins/inventory?tab=add') ?>">Add Coins</a>
        <a class="btn btn-outline-gold" href="<?= site_url('admin/coins/inventory?tab=history') ?>">View History</a>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
