<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Append-only ownership ledger. Totals: owned <?= esc($summary['balance']) ?> · available <?= esc($summary['available']) ?> · locked <?= esc($summary['locked']) ?> coins.</p>

<nav class="mb-3 small text-secondary" aria-label="Wallet breadcrumb">
    <a href="<?= site_url('member/dashboard') ?>">Dashboard</a>
    <span class="mx-1">→</span>
    <a href="<?= site_url('member/wallet') ?>">My Wallet</a>
    <span class="mx-1">→</span>
    <span>Transactions</span>
</nav>

<form class="card-premium mb-4" method="get" action="<?= site_url('member/wallet/transactions') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label" for="type">Type</label>
            <select class="form-select" id="type" name="type">
                <option value="">All</option>
                <?php foreach (['PURCHASE_CREDIT', 'LOCK', 'UNLOCK'] as $type): ?>
                    <option value="<?= $type ?>" <?= ($filters['type'] ?? '') === $type ? 'selected' : '' ?>><?= $type ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_from">From</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To</label>
            <input class="form-control" type="date" id="date_to" name="date_to" value="<?= esc($filters['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="ref">Reference</label>
            <input class="form-control" id="ref" name="ref" value="<?= esc($filters['ref'] ?? '') ?>" placeholder="Txn / purchase / lock ref" maxlength="80">
        </div>
        <div class="col-md-1">
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <?php foreach (['id' => 'ID', 'created_at' => 'Date', 'quantity' => 'Qty', 'type' => 'Type'] as $key => $label): ?>
                    <option value="<?= $key ?>" <?= ($filters['sort'] ?? 'id') === $key ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label" for="dir">Dir</label>
            <select class="form-select" id="dir" name="dir">
                <option value="desc" <?= ($filters['dir'] ?? 'desc') === 'desc' ? 'selected' : '' ?>>Desc</option>
                <option value="asc" <?= ($filters['dir'] ?? '') === 'asc' ? 'selected' : '' ?>>Asc</option>
            </select>
        </div>
        <div class="col-md-1">
            <label class="form-label" for="per_page">Rows</label>
            <select class="form-select" id="per_page" name="per_page">
                <?php foreach ([10, 20, 25, 50] as $n): ?>
                    <option value="<?= $n ?>" <?= (int) ($filters['per_page'] ?? 20) === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/wallet/transactions/export?' . http_build_query(array_filter([
                'type' => $filters['type'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'ref' => $filters['ref'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''))) ?>">Export CSV</a>
            <a class="btn btn-outline-gold" href="<?= site_url('member/wallet/transactions') ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>ID</th>
                <th>Reference</th>
                <th>Date</th>
                <th>Type</th>
                <th>Quantity</th>
                <th>Available Δ</th>
                <th>Locked Δ</th>
                <th>Total Δ</th>
                <th>Balance After</th>
                <th>Source</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="11" class="text-secondary">No transactions match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><span class="badge text-bg-dark"><?= esc($row['type']) ?></span></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['available_impact'] ?? '—') ?></td>
                        <td><?= esc($row['locked_impact'] ?? '—') ?></td>
                        <td><?= esc($row['total_impact'] ?? '—') ?></td>
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
                        <td><?= esc($row['status'] ?? 'settled') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
