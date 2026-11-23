<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
$summary = $summary ?? [];
$levels  = $summary['levels'] ?? [];
$level   = (int) ($level ?? 1);
$list    = $list ?? ['rows' => [], 'pager' => null, 'summary' => []];
$filters = $filters ?? [];
?>
<p class="page-subtitle">
    Level-wise Business Volume from approved, deposit-verified purchases in your three-level referral network.
    Rates L1 10% · L2 5% · L3 3% are informational only — this page does not calculate or post commissions.
    Dates use <?= esc((string) ($summary['timezone'] ?? 'Asia/Kolkata')) ?> (approval time).
</p>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="row g-3 my-3">
    <?php for ($n = 1; $n <= 3; $n++): $lv = $levels[$n] ?? []; ?>
        <div class="col-md-4">
            <div class="card-premium h-100">
                <h2 class="h6 mb-2">Level <?= $n ?> <span class="text-secondary">(<?= esc((string) ($lv['commission_percent_info'] ?? '')) ?>% info)</span></h2>
                <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Business Volume</span><strong><?= esc((string) ($lv['business_volume_usdt'] ?? '0.00000000')) ?> USDT</strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Referrals</span><strong><?= (int) ($lv['referral_member_count'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between mb-1"><span class="text-secondary">Purchasing members</span><strong><?= (int) ($lv['purchasing_member_count'] ?? 0) ?></strong></div>
                <div class="d-flex justify-content-between"><span class="text-secondary">Approved purchases</span><strong><?= (int) ($lv['purchase_count'] ?? 0) ?></strong></div>
            </div>
        </div>
    <?php endfor; ?>
</div>

<div class="section-hub-links mb-3">
    <?php for ($n = 1; $n <= 3; $n++): ?>
        <a class="admin-tab <?= $level === $n ? 'active' : '' ?>"
           href="<?= site_url('member/referrals/business?' . http_build_query(array_filter([
               'level' => $n,
               'q' => $filters['q'] ?? null,
               'date_from' => $filters['date_from'] ?? null,
               'date_to' => $filters['date_to'] ?? null,
               'per_page' => $filters['per_page'] ?? null,
           ], static fn ($v) => $v !== null && $v !== ''))) ?>">Level <?= $n ?></a>
    <?php endfor; ?>
</div>

<form class="card-premium mb-3" method="get" action="<?= site_url('member/referrals/business') ?>">
    <input type="hidden" name="level" value="<?= $level ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="q">Member ID / name</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '') ?>" maxlength="64" placeholder="Search">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_from">From (approval)</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="date_to">To (approval)</label>
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
            <a class="btn btn-outline-gold" href="<?= site_url('member/referrals/business?level=' . $level) ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0">Level <?= $level ?> members</h2>
        <div class="small text-secondary">
            Level volume <?= esc((string) ($list['summary']['volume_usdt'] ?? '0.00000000')) ?> USDT
            · <?= (int) ($list['summary']['purchase_count'] ?? 0) ?> purchases
            (totals are not limited to this page)
        </div>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Level</th>
                <th>Joined</th>
                <th>Purchases</th>
                <th>Business Volume (USDT)</th>
                <th>Latest purchase</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (empty($list['rows'])): ?>
                <tr><td colspan="8" class="text-secondary">No referrals at this level<?= ($filters['q'] ?? '') !== '' ? ' match your search' : '' ?>.</td></tr>
            <?php else: foreach ($list['rows'] as $row): ?>
                <tr>
                    <td><code><?= esc($row['member_id']) ?></code></td>
                    <td><?= esc($row['member_name']) ?></td>
                    <td><?= (int) $row['referral_level'] ?></td>
                    <td><?= esc($row['joined_at']) ?></td>
                    <td><?= (int) $row['purchase_count'] ?></td>
                    <td><?= esc($row['business_volume_usdt']) ?></td>
                    <td><?= esc((string) ($row['latest_purchase_at'] ?? '—')) ?></td>
                    <td>
                        <a class="btn btn-outline-gold btn-sm"
                           href="<?= site_url('member/referrals/business/' . rawurlencode($row['member_id'])) ?>">Details</a>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (! empty($list['pager'])): ?>
        <div class="mt-3"><?= $list['pager']->links('bv_level') ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
