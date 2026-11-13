<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Append-only ownership ledger. Totals: owned <?= esc($summary['balance']) ?> · available <?= esc($summary['available']) ?> · locked <?= esc($summary['locked']) ?>.</p>

<form class="card-premium mb-4" method="get" action="<?= site_url('member/wallet/transactions') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="type">Type</label>
            <select class="form-select" id="type" name="type">
                <option value="">All</option>
                <?php foreach (['PURCHASE_CREDIT', 'LOCK', 'UNLOCK'] as $type): ?>
                    <option value="<?= $type ?>" <?= ($filters['type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to" value="<?= esc($filters['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-3 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/wallet/transactions/export?' . http_build_query(array_filter([
                'type' => $filters['type'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ]))) ?>">Export CSV</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Date</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Available</th>
                <th>Locked</th>
                <th>Total After</th>
                <th>Source</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-secondary">No transactions match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['new_available'] ?? '—') ?></td>
                        <td><?= esc($row['new_locked'] ?? '—') ?></td>
                        <td><?= esc($row['new_balance']) ?></td>
                        <td>
                            <?php if (! empty($row['purchase_ref'])): ?>
                                <a href="<?= site_url('member/purchases/' . rawurlencode($row['purchase_ref'])) ?>"><?= esc($row['purchase_ref']) ?></a>
                            <?php elseif (! empty($row['lock_ref'])): ?>
                                <a href="<?= site_url('member/locking/' . rawurlencode($row['lock_ref'])) ?>"><?= esc($row['lock_ref']) ?></a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
