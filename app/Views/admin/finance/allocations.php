<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'allocations']) ?>
<p class="page-subtitle">Payment allocation lifecycle (reserved/linked → consumed | released).</p>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['linked', 'consumed', 'released'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt === 'linked' ? 'reserved (linked)' : $opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-gold" type="submit">Filter</button></div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Allocation</th>
                <th>Deposit</th>
                <th>Purchase</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Linked</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-secondary">No allocations.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['allocation_ref']) ?></code></td>
                        <td><?= esc($row['deposit_ref']) ?></td>
                        <td><?= esc($row['purchase_ref']) ?></td>
                        <td><?= esc($row['amount_usdt']) ?></td>
                        <td><?= esc($row['status'] === 'linked' ? 'reserved' : $row['status']) ?></td>
                        <td><?= esc($row['linked_at'] ?? '—') ?></td>
                        <td><a href="<?= site_url('admin/finance/allocations/' . $row['allocation_ref']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
