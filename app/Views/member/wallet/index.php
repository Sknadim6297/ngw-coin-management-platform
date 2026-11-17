<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php $tab = $tab ?? 'overview'; ?>
<?= view('components/admin_tabs', [
    'active' => $tab,
    'tabs'   => [
        ['key' => 'overview', 'label' => 'Overview', 'url' => site_url('member/wallet')],
        ['key' => 'transactions', 'label' => 'Coin Transactions', 'url' => site_url('member/wallet?tab=transactions')],
        ['key' => 'locking', 'label' => 'Locking History', 'url' => site_url('member/wallet?tab=locking')],
    ],
]) ?>

<?php if (empty($wallet['invariant_ok'])): ?>
    <div class="alert alert-danger">Balance invariant mismatch detected. Please contact support.</div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <?= view('components/kpi_grid', ['kpis' => array_slice($wallet['kpis'], 0, 4)]) ?>

    <div class="d-flex flex-wrap gap-2 my-4">
        <a class="btn btn-gold" href="<?= site_url('member/wallet?tab=transactions') ?>">Coin Transactions</a>
        <a class="btn btn-outline-gold" href="<?= site_url('member/wallet?tab=locking') ?>">Locking History</a>
        <a class="btn btn-outline-gold" href="<?= site_url('member/buy-coins') ?>">Buy Coins</a>
        <a class="btn btn-outline-gold" href="<?= site_url('member/locking') ?>">Lock Coins</a>
    </div>

    <div class="card-premium">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Recent Activity</h2>
            <a class="small" href="<?= site_url('member/wallet?tab=transactions') ?>">View all</a>
        </div>
        <?php if (empty($wallet['recent'])): ?>
            <div class="text-secondary">No ownership ledger activity yet.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table-premium">
                    <thead>
                    <tr>
                        <th>Date</th>
                        <th>Reference</th>
                        <th>Type</th>
                        <th>Quantity</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach (array_slice($wallet['recent'], 0, 8) as $row): ?>
                        <tr>
                            <td><?= esc($row['created_at']) ?></td>
                            <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                            <td><?= esc($row['type']) ?></td>
                            <td><?= esc($row['quantity']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($tab === 'transactions'): ?>
    <div class="card-premium mb-3">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="transactions">
            <div class="col-md-3">
                <label class="form-label" for="type">Type</label>
                <input class="form-control" id="type" name="type" value="<?= esc($filters['type'] ?? '') ?>" placeholder="PURCHASE_CREDIT">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="ref">Reference</label>
                <input class="form-control" id="ref" name="ref" value="<?= esc($filters['ref'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_from">From</label>
                <input class="form-control" type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label" for="date_to">To</label>
                <input class="form-control" type="date" id="date_to" name="date_to" value="<?= esc($filters['date_to'] ?? '') ?>">
            </div>
            <div class="col-md-2">
                <button class="btn btn-gold w-100" type="submit">Filter</button>
            </div>
        </form>
        <div class="mt-2">
            <a class="btn btn-sm btn-outline-gold" href="<?= site_url('member/wallet/transactions/export?' . http_build_query(array_filter($filters ?? []))) ?>">Export CSV</a>
        </div>
    </div>

    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Type</th>
                    <th>Credit</th>
                    <th>Debit</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($rows ?? []) === []): ?>
                    <tr><td colspan="7" class="text-secondary">No transactions found.</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $impact = (string) ($row['total_impact'] ?? '0');
                        $isCredit = str_starts_with($impact, '-') ? false : ((float) $impact >= 0);
                        ?>
                        <tr>
                            <td><?= esc($row['created_at']) ?></td>
                            <td><a href="<?= site_url('member/wallet/transactions/' . rawurlencode($row['transaction_ref'])) ?>"><?= esc($row['transaction_ref']) ?></a></td>
                            <td><?= esc($row['type']) ?></td>
                            <td><?= $isCredit ? esc($impact) : '—' ?></td>
                            <td><?= ! $isCredit ? esc(ltrim($impact, '-')) : '—' ?></td>
                            <td><?= esc($row['new_balance']) ?></td>
                            <td><span class="badge-status approved"><?= esc($row['status'] ?? 'settled') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (! empty($pager)): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
    </div>

<?php else: ?>
    <div class="card-premium mb-3">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="locking">
            <div class="col-md-4">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    <?php foreach (['active', 'matured', 'unlocked', 'cancelled'] as $opt): ?>
                        <option value="<?= esc($opt) ?>" <?= ($lockStatus ?? '') === $opt ? 'selected' : '' ?>><?= esc(ucfirst($opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-gold" type="submit">Filter</button>
            </div>
        </form>
    </div>
    <div class="card-premium">
        <div class="table-responsive">
            <table class="table-premium">
                <thead>
                <tr>
                    <th>Lock Reference</th>
                    <th>Locked Quantity</th>
                    <th>Lock Date</th>
                    <th>Maturity Date</th>
                    <th>Unlock Status</th>
                </tr>
                </thead>
                <tbody>
                <?php if (($lockRows ?? []) === []): ?>
                    <tr><td colspan="5" class="text-secondary">No locking history yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($lockRows as $lock): ?>
                        <tr>
                            <td><a href="<?= site_url('member/locking/' . rawurlencode($lock['lock_ref'])) ?>"><?= esc($lock['lock_ref']) ?></a></td>
                            <td><?= esc($lock['quantity']) ?></td>
                            <td><?= esc($lock['locked_at'] ?? '—') ?></td>
                            <td><?= esc($lock['matures_at'] ?? '—') ?></td>
                            <td><span class="badge-status <?= esc($lock['status']) ?>"><?= esc(ucfirst((string) $lock['status'])) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (! empty($pager)): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
    </div>
<?php endif; ?>
<?= $this->endSection() ?>
