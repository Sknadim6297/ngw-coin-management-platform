<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<?php
$detail  = $detail ?? [];
$member  = $detail['member'] ?? [];
$filters = $filters ?? [];
$rows    = $detail['rows'] ?? [];
?>
<p class="page-subtitle">
    Eligible approved purchases for <?= esc((string) ($member['member_id'] ?? '')) ?>
    at your Level <?= (int) ($member['referral_level'] ?? 0) ?> network.
    Amounts in USDT · 8 decimals · Asia/Kolkata approval dates.
</p>

<div class="card-premium mb-3">
    <div class="d-flex flex-wrap justify-content-between gap-3">
        <div>
            <h1 class="h5 mb-1"><?= esc((string) ($member['member_name'] ?? '')) ?></h1>
            <div class="text-secondary">
                ID <code><?= esc((string) ($member['member_id'] ?? '')) ?></code>
                · Level <?= (int) ($member['referral_level'] ?? 0) ?>
                · Joined <?= esc((string) ($member['joined_at'] ?? '')) ?>
            </div>
        </div>
        <div class="text-end">
            <div class="text-secondary small">Attributed Business Volume</div>
            <strong><?= esc((string) ($detail['business_volume_usdt'] ?? '0.00000000')) ?> USDT</strong>
            <div class="small text-secondary"><?= (int) ($detail['purchase_count'] ?? 0) ?> eligible purchases</div>
        </div>
    </div>
    <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('member/referrals/business?level=' . (int) ($member['referral_level'] ?? 1)) ?>">Back to Level list</a>
</div>

<form class="card-premium mb-3" method="get">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="date_from">From (approval)</label>
            <input class="form-control" type="date" id="date_from" name="date_from" value="<?= esc($filters['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-3">
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
        <div class="col-md-4 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/referrals/business/' . rawurlencode((string) ($member['member_id'] ?? ''))) ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <h2 class="h5 mb-3">Purchase history</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Purchase ref</th>
                <th>Requested</th>
                <th>Approved</th>
                <th>Amount (USDT)</th>
                <th>Status</th>
                <th>Deposit</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No eligible purchases in this range.</td></tr>
            <?php else: foreach ($rows as $row): ?>
                <tr>
                    <td><code><?= esc($row['purchase_ref']) ?></code></td>
                    <td><?= esc($row['requested_at']) ?></td>
                    <td><?= esc($row['approved_at']) ?></td>
                    <td><?= esc($row['eligible_amount_usdt']) ?></td>
                    <td><?= esc($row['purchase_status']) ?></td>
                    <td><?= esc($row['deposit_verification']) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php if (! empty($detail['pager'])): ?>
        <div class="mt-3"><?= $detail['pager']->links('bv_member') ?></div>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
