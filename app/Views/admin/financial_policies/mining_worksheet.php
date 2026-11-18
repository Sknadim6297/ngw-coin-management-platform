<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$w        = $worksheet;
$stateCls = static fn (string $s): string => $s === 'APPROVED_VERIFIED' ? 'text-success' : ($s === 'PENDING_BUSINESS_APPROVAL' ? 'text-warning' : 'text-danger');
?>
<p class="page-subtitle">
    Mining Business Decision Worksheet — <strong>read-only</strong>. Confirmed requirements, technical options and approved values are kept apart.
    A technical recommendation is not business approval, and nothing on this page changes a policy, a rate or a kill-switch.
</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies') ?>">Back to Financial Policies</a>
    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/mining/rates') ?>">Monthly Rates (dual control)</a>
    <?php if (! empty($canDraft)): ?>
        <a class="btn btn-gold btn-sm" href="<?= site_url('admin/financial-policies/create?domain=mining') ?>">New Mining Policy Draft</a>
    <?php endif; ?>
</div>

<div class="card-premium mb-3">
    <div class="row g-3 small">
        <div class="col-md-3"><div class="text-secondary">Database</div><strong><?= esc($w['database']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Verified approved decisions</div><strong class="text-success"><?= (int) $w['approved_count'] ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Not approved</div><strong class="text-warning"><?= (int) $w['pending_count'] ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Kill-switches</div>
            <?php foreach ($w['kill_switches'] as $k => $v): if ($k === 'sandbox_window_open') { continue; } ?>
                <span class="me-1"><code><?= esc($k) ?></code>=<?= ! empty($v) ? '<span class="text-danger">ON</span>' : 'false' ?></span>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">1. Confirmed business requirements</h2>
    <ul class="mb-0 small">
        <?php foreach ($w['confirmed'] as $c): ?>
            <li><strong><?= esc($c['id']) ?></strong> <?= esc($c['statement']) ?> <span class="text-secondary">(<?= esc($c['source']) ?>)</span></li>
        <?php endforeach; ?>
    </ul>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">2. Decision matrix</h2>
    <div class="table-responsive">
        <table class="table table-sm table-dark align-top mb-0 small">
            <thead>
            <tr>
                <th>Decision</th><th>Current status</th><th>Options</th><th>Recommended technical default</th>
                <th>Financial impact</th><th>Historical impact</th><th>Approved value</th><th>Effective</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($w['decisions'] as $d): ?>
                <tr>
                    <td><strong><?= esc($d['decision']) ?></strong></td>
                    <td><code><?= esc($d['current_status']) ?></code>
                        <?php if ($d['platform_default_value'] !== null): ?><div class="text-secondary">default value: <?= esc($d['platform_default_value']) ?></div><?php endif; ?></td>
                    <td><?= esc($d['available_options']) ?></td>
                    <td><?= esc($d['recommended_default']) ?> <em class="text-secondary">(not an approval)</em></td>
                    <td><?= esc($d['financial_impact']) ?></td>
                    <td><?= esc($d['historical_impact']) ?></td>
                    <td class="<?= $stateCls($d['approval_state']) ?>"><strong><?= esc($d['approved_value']) ?></strong>
                        <div class="small"><?= esc($d['approval_state']) ?></div>
                        <?php if ($d['policy_ref'] !== null): ?><div class="small text-secondary"><?= esc((string) $d['policy_ref']) ?> v<?= esc((string) $d['policy_version']) ?></div><?php endif; ?></td>
                    <td><?= esc((string) ($d['effective_date'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">3. Monthly rate records</h2>
    <?php if (empty($w['rates']['rows'])): ?>
        <p class="text-warning mb-0">No rate rows exist. <strong>PENDING_BUSINESS_APPROVAL</strong>. No rate is created automatically.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-dark mb-0 small">
                <thead><tr><th>Ref</th><th>Month</th><th>Start</th><th>End</th><th>Ver.</th><th>%</th><th>Status</th><th>Created by</th><th>Approved by</th><th>Approved at</th><th>Test-only?</th></tr></thead>
                <tbody>
                <?php foreach ($w['rates']['rows'] as $r): ?>
                    <tr>
                        <td><code><?= esc($r['rate_ref']) ?></code></td><td><?= esc($r['effective_month']) ?></td><td><?= esc($r['effective_start']) ?></td><td><?= esc($r['effective_end']) ?></td>
                        <td><?= (int) $r['version_in_month'] ?></td><td><?= esc($r['rate_percent']) ?></td><td><?= esc($r['status']) ?></td>
                        <td><?= esc((string) $r['created_by']) ?></td><td><?= esc((string) ($r['reviewed_approved_by'] ?? '—')) ?></td><td><?= esc((string) ($r['approved_at'] ?? '—')) ?></td>
                        <td><?= $r['looks_test_only'] ? '<span class="text-warning">yes</span>' : 'no' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
    <p class="small text-secondary mt-2 mb-0">
        Workflow: draft → approve by a <strong>different</strong> user (self-approval refused) → one approved rate per effective month (overlap refused) → approved rates are immutable.
        Rates are month-granular (start = first day, end = last day of the month).
    </p>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-1">4. Illustrative previews <span class="badge text-bg-warning ms-2">ILLUSTRATIVE — approves nothing</span></h2>
    <p class="small text-secondary"><?= esc($w['previews']['assumptions']) ?></p>
    <div class="table-responsive mb-3">
        <table class="table table-sm table-dark mb-0 small">
            <thead><tr><th>Days</th><th>Exact monthly</th><th>Fixed-30 daily (HALF_UP)</th><th>Calendar daily (HALF_UP)</th><th>Fixed-30 month total</th><th>Calendar month total</th><th>Calendar daily (DOWN)</th></tr></thead>
            <tbody>
            <?php foreach ($w['previews']['divisor_matrix'] as $r): ?>
                <tr><td><?= (int) $r['days_in_month'] ?></td><td><?= esc($r['exact_monthly_income']) ?></td><td><?= esc($r['half_up']['fixed_30_daily']) ?></td><td><?= esc($r['half_up']['calendar_daily']) ?></td>
                    <td><?= esc($r['half_up']['fixed_30_month_total']) ?></td><td><?= esc($r['half_up']['calendar_month_total']) ?></td><td><?= esc($r['down']['calendar_daily']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-dark mb-0 small">
            <thead><tr><th>31-day month</th><th>Independent daily total</th><th>Diff vs exact</th><th>Carry-forward total (not implemented)</th><th>Final-day: last-day credit (not implemented)</th></tr></thead>
            <tbody>
            <?php foreach ($w['previews']['rounding_matrix_31_day_month'] as $mode => $r): ?>
                <tr><td><?= esc($mode) ?></td><td><?= esc($r['independent_daily_month_total']) ?></td><td><?= esc($r['independent_vs_exact_difference']) ?></td><td><?= esc($r['carry_forward_month_total']) ?></td><td><?= esc($r['final_day_adjustment_last_day_credit']) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card-premium mb-3">
    <h2 class="h5 mb-3">5. Denomination (COIN vs USDT) — <span class="text-warning"><?= esc($w['denomination']['decision_status']) ?></span></h2>
    <p class="small text-secondary"><?= esc($w['denomination']['note']) ?></p>
    <div class="row g-3 small">
        <?php foreach (['if_coin' => 'If COIN', 'if_usdt' => 'If USDT'] as $k => $title): ?>
            <div class="col-lg-6">
                <strong><?= esc($title) ?></strong>
                <ul class="mb-0"><?php foreach ($w['denomination'][$k] as $area => $text): ?>
                    <li><em><?= esc($area) ?>:</em> <?= esc($text) ?></li>
                <?php endforeach; ?></ul>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="card-premium">
    <h2 class="h5 mb-3">6. Activation blockers from this worksheet</h2>
    <ul class="mb-0 small">
        <?php foreach ($w['blockers'] as $b): ?>
            <li><code><?= esc($b['id']) ?></code> — <?= esc($b['blocker']) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?= $this->endSection() ?>
