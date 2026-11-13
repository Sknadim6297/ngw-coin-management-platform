<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Review member coin purchase requests. Approval deducts inventory; rejection does not. This is not payment verification.</p>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Pending Queue</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc((string) $pendingCount) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Approved</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc((string) $approvedCount) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium">
            <div class="text-secondary mb-1">Available Inventory</div>
            <div class="h3" style="color: var(--gold-bright);"><?= esc($inventory['available_balance']) ?></div>
        </div>
    </div>
</div>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc(ucfirst($opt)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search) ?>" placeholder="Reference or Member ID">
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Apply Filters</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Member</th>
                <th>Quantity</th>
                <th>Price Snapshot</th>
                <th>Total USDT</th>
                <th>Requested</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-secondary">No purchase requests found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['purchase_ref']) ?></td>
                        <td><?= esc($row['member_id']) ?></td>
                        <td><?= esc($row['coin_quantity']) ?></td>
                        <td><?= esc($row['price_usdt_snapshot']) ?></td>
                        <td><?= esc($row['total_usdt']) ?></td>
                        <td><?= esc($row['requested_at']) ?></td>
                        <td><span class="badge-status <?= esc($row['status']) ?>"><?= esc(ucfirst($row['status'])) ?></span></td>
                        <td><a href="<?= site_url('admin/purchases/' . $row['purchase_ref']) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
