<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/payment_monitoring_tabs', ['active' => 'audit']) ?>
<p class="page-subtitle">Payment audit trail across deposits, verification, and consumption.</p>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label" for="status">Deposit Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['submitted', 'under_review', 'verified', 'rejected', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="verification_status">Verify Result</label>
            <input class="form-control" id="verification_status" name="verification_status" value="<?= esc($verification_status) ?>" placeholder="e.g. wrong_network">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="consumption">Consumption</label>
            <select class="form-select" id="consumption" name="consumption">
                <option value="">All</option>
                <?php foreach (['available', 'linked', 'consumed'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $consumption === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="integrity">Integrity</label>
            <select class="form-select" id="integrity" name="integrity">
                <option value="">All</option>
                <?php foreach (['ok', 'reorg_suspected', 'needs_investigation'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $integrity === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search) ?>" placeholder="Ref, member, tx, purchase">
        </div>
        <div class="col-md-1">
            <button class="btn btn-gold w-100" type="submit">Go</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Deposit</th>
                <th>Network</th>
                <th>Tx</th>
                <th>Verify</th>
                <th>Confirms</th>
                <th>Purchase</th>
                <th>Consumption</th>
                <th>Recon</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="9" class="text-secondary">No rows match filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['deposit_ref']) ?></td>
                        <td><?= esc($row['network_key']) ?></td>
                        <td><code class="text-break"><?= esc(mb_substr((string) ($row['tx_hash'] ?? '—'), 0, 18)) ?>…</code></td>
                        <td><code><?= esc((string) ($row['last_verification_status'] ?? $row['status'])) ?></code></td>
                        <td><?= esc((string) ($row['verified_confirmations'] ?? '—')) ?></td>
                        <td><?= esc((string) ($row['linked_purchase_ref'] ?? '—')) ?></td>
                        <td><?= esc((string) ($row['payment_consumption_status'] ?? '—')) ?></td>
                        <td><?= esc((string) ($row['reconciliation_status'] ?? 'ok')) ?></td>
                        <td><a href="<?= site_url('admin/payment-audit/' . $row['deposit_ref']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?>
        <div class="mt-3"><?= $pager->links() ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
