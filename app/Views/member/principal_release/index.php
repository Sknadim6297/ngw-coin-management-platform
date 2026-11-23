<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
$summary = $summary ?? [];
$history = $history ?? ['rows' => [], 'pager' => null];
$filters = $filters ?? [];
$unit    = (string) ($summary['unit_label'] ?? 'COIN');
$posting = ! empty($summary['posting_enabled']);
?>
<p class="page-subtitle">
    Daily Principal Release schedule: <strong>0.1%</strong> of each purchase’s <strong>original</strong> locked principal per day (max 1000 days).
    Amounts are <?= esc($unit) ?> ownership principal — not USDT commission and not mining rewards.
    Business dates use <?= esc((string) ($summary['timezone'] ?? 'Asia/Kolkata')) ?>.
</p>

<?php if (! $posting): ?>
    <div class="alert alert-warning">
        <strong>Schedule preview only</strong> — daily principal release posting is OFF
        (<code>principalDailyReleasePostingEnabled=false</code>).
        No ownership ledger unlocks are created by this page. Existing locks remain the cliff 1000-day model until posting is separately authorized.
    </div>
<?php endif; ?>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-3">
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h2 class="h6">Original Principal</h2>
            <div class="h4 mb-0"><?= esc((string) ($summary['original_principal'] ?? '0.00000000')) ?> <?= esc($unit) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h2 class="h6">Today's Principal Release</h2>
            <div class="h4 mb-0"><?= esc((string) ($summary['today_principal_release'] ?? '0.00000000')) ?> <?= esc($unit) ?></div>
            <div class="small text-secondary">Business date <?= esc((string) ($summary['business_date_today'] ?? '')) ?></div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card-premium h-100">
            <h2 class="h6">Total Released Principal</h2>
            <div class="h4 mb-0"><?= esc((string) ($summary['total_released_principal'] ?? '0.00000000')) ?> <?= esc($unit) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h6">Remaining Locked Principal</h2>
            <div class="h4 mb-0"><?= esc((string) ($summary['remaining_locked_principal'] ?? '0.00000000')) ?> <?= esc($unit) ?></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium h-100">
            <h2 class="h6">Available P2P Principal</h2>
            <div class="h4 mb-0"><?= esc((string) ($summary['available_p2p_principal'] ?? '0.00000000')) ?> <?= esc($unit) ?></div>
            <div class="small text-secondary">Scheduled eligible principal only — coin selling / P2P flags remain OFF.</div>
        </div>
    </div>
</div>

<form class="card-premium mb-3" method="get" action="<?= site_url('member/principal-release') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="purchase_ref">Purchase ref</label>
            <input class="form-control" id="purchase_ref" name="purchase_ref" value="<?= esc($filters['purchase_ref'] ?? '') ?>" maxlength="40">
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
            <label class="form-label" for="per_page">Rows</label>
            <select class="form-select" id="per_page" name="per_page">
                <?php foreach ([10, 20, 25, 50] as $n): ?>
                    <option value="<?= $n ?>" <?= (int) ($filters['per_page'] ?? 20) === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/principal-release') ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <h2 class="h5 mb-3">Principal Release History</h2>
    <?php if (! empty($history['notes'])): ?>
        <div class="small text-secondary mb-2"><?= esc((string) $history['notes']) ?></div>
    <?php endif; ?>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Purchase ref</th>
                <th>Original locked</th>
                <th>Release date</th>
                <th>Released qty</th>
                <th>Cumulative released</th>
                <th>Remaining locked</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($history['rows'])): ?>
                <tr><td colspan="7" class="text-secondary">No principal release rows for this filter. Mandatory purchase locks are required to build the schedule.</td></tr>
            <?php else: foreach ($history['rows'] as $row): ?>
                <tr>
                    <td><code><?= esc((string) ($row['purchase_ref'] ?? '')) ?></code></td>
                    <td><?= esc((string) $row['original_principal']) ?></td>
                    <td><?= esc((string) $row['release_date']) ?></td>
                    <td><?= esc((string) $row['released_quantity']) ?></td>
                    <td><?= esc((string) $row['cumulative_released']) ?></td>
                    <td><?= esc((string) $row['remaining_locked']) ?></td>
                    <td><?= esc((string) $row['status']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (! empty($history['pager'])): ?>
        <div class="mt-3"><?= $history['pager']->links('principal_release') ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
