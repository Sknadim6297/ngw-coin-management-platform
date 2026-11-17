<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php $wallet = $result['overview']; $profile = $result['profile']; ?>
<p class="page-subtitle">Read-only ownership ledger for <?= esc($profile->member_id) ?> · <?= esc($profile->member_name) ?>.</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Total Owned</div><div class="h4" style="color:var(--gold-bright)"><?= esc($wallet['balance']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Available</div><div class="h4"><?= esc($wallet['available']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Locked</div><div class="h4"><?= esc($wallet['locked']) ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Purchased</div><div class="h4"><?= esc($wallet['total_acquired']) ?></div></div></div>
</div>

<form class="card-premium mb-4" method="get">
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
            <input class="form-control" id="ref" name="ref" value="<?= esc($filters['ref'] ?? '') ?>" maxlength="80">
        </div>
        <div class="col-md-3 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions/export?' . http_build_query(array_filter([
                'type' => $filters['type'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'ref' => $filters['ref'] ?? null,
            ], static fn ($v) => $v !== null && $v !== ''))) ?>">Export CSV</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <h2 class="h5 mb-3">Ownership Ledger</h2>
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
                <th>Total After</th>
                <th>Source</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="10" class="text-secondary">No ledger rows for this member.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= (int) $row['id'] ?></td>
                        <td><a href="<?= site_url('admin/wallets/' . rawurlencode($profile->member_id) . '/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                        <td><?= esc($row['created_at']) ?></td>
                        <td><?= esc($row['type']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['available_impact'] ?? '—') ?></td>
                        <td><?= esc($row['locked_impact'] ?? '—') ?></td>
                        <td><?= esc($row['new_balance']) ?></td>
                        <td><?= esc($row['source_ref'] ?: '—') ?></td>
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
