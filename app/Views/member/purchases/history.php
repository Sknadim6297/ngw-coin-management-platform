<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your coin purchase requests. Pending means awaiting admin review — not paid.</p>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                <?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc(ucfirst($opt)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-outline-gold" type="submit">Filter</button>
            <a class="btn btn-gold" href="<?= site_url('member/buy-coins') ?>">Buy Coins</a>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Requested</th>
                <th>Quantity</th>
                <th>Price</th>
                <th>Total USDT</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-secondary">No purchase requests yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['purchase_ref']) ?></td>
                        <td><?= esc($row['requested_at']) ?></td>
                        <td><?= esc($row['coin_quantity']) ?></td>
                        <td><?= esc($row['price_usdt_snapshot']) ?></td>
                        <td><?= esc($row['total_usdt']) ?></td>
                        <td><span class="badge-status <?= esc($row['status']) ?>"><?= esc(ucfirst($row['status'])) ?></span></td>
                        <td><a href="<?= site_url('member/purchases/' . $row['purchase_ref']) ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
