<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Plan detail · Simulation configuration only · No payout activation.</p>
<div class="card-premium mb-3">
    <div class="d-flex justify-content-between flex-wrap gap-2">
        <div>
            <h2 class="h5 mb-1"><?= esc($plan['plan_name']) ?></h2>
            <code><?= esc($plan['plan_ref']) ?></code>
            <span class="badge bg-secondary ms-2"><?= esc($plan['status']) ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/commissions/plans') ?>">Back</a>
            <?php if ($plan['status'] === 'draft' && $canApprove): ?>
                <form method="post" action="<?= site_url('admin/commissions/plans/' . rawurlencode($plan['plan_ref']) . '/approve') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-gold btn-sm" type="submit">Approve</button>
                </form>
                <?php $gov = service('financialGovernanceService'); ?>
                <?php if ($gov->isSingleAdmin() && (int) auth()->id() === $gov->singleAdminUserId()): ?>
                    <a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-governance/commission_plan/' . rawurlencode($plan['plan_ref']) . '/approve') ?>">Review &amp; approve as single administrator</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($plan['status'] === 'approved' && $canApprove): ?>
                <form method="post" action="<?= site_url('admin/commissions/plans/' . rawurlencode($plan['plan_ref']) . '/disable') ?>">
                    <?= csrf_field() ?>
                    <button class="btn btn-outline-danger btn-sm" type="submit">Disable</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <hr>
    <div class="row g-2 small">
        <div class="col-md-4">Effective: <?= esc($plan['effective_from']) ?> → <?= esc($plan['effective_to'] ?? 'open') ?></div>
        <div class="col-md-4">Currency: <?= esc($plan['commission_currency']) ?></div>
        <div class="col-md-4">Levels: <?= esc((string) $plan['level_count']) ?></div>
        <div class="col-md-6">Purchase policy: <?= esc($plan['eligible_purchase_policy']) ?></div>
        <div class="col-md-6">Sponsor policy: <?= esc($plan['sponsor_eligibility_policy']) ?></div>
    </div>
    <div class="mt-3">
        <?php $lv = $plan['levels'] ?? []; ?>
        <strong>Percentages:</strong>
        L1 <?= esc($lv[1] ?? '—') ?>% ·
        L2 <?= esc($lv[2] ?? '—') ?>% ·
        L3 <?= esc($lv[3] ?? '—') ?>%
    </div>
    <?php if (! empty($plan['notes'])): ?>
        <div class="mt-2 text-secondary"><?= esc($plan['notes']) ?></div>
    <?php endif; ?>
</div>

<?php if ($plan['status'] === 'draft' && $canPropose): ?>
<div class="card-premium mb-3">
    <h3 class="h6">Edit Draft</h3>
    <form method="post" action="<?= site_url('admin/commissions/plans/' . rawurlencode($plan['plan_ref']) . '/update') ?>">
        <?= csrf_field() ?>
        <div class="row g-2">
            <div class="col-md-4"><input class="form-control" name="plan_name" value="<?= esc($plan['plan_name']) ?>" required></div>
            <div class="col-md-2"><input class="form-control" type="date" name="effective_from" value="<?= esc($plan['effective_from']) ?>" required></div>
            <div class="col-md-2"><input class="form-control" type="date" name="effective_to" value="<?= esc($plan['effective_to'] ?? '') ?>"></div>
            <div class="col-md-2">
                <select class="form-select" name="level_count">
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                        <option value="<?= $i ?>" <?= (int) $plan['level_count'] === $i ? 'selected' : '' ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2"><input class="form-control" name="level_1_percent" value="<?= esc($lv[1] ?? '') ?>" placeholder="L1 %"></div>
            <div class="col-md-2"><input class="form-control" name="level_2_percent" value="<?= esc($lv[2] ?? '') ?>" placeholder="L2 %"></div>
            <div class="col-md-2"><input class="form-control" name="level_3_percent" value="<?= esc($lv[3] ?? '') ?>" placeholder="L3 %"></div>
            <div class="col-md-4">
                <select class="form-select" name="eligible_purchase_policy">
                    <option value="approved_verified_usdt" <?= $plan['eligible_purchase_policy'] === 'approved_verified_usdt' ? 'selected' : '' ?>>Approved + verified</option>
                    <option value="approved_only" <?= $plan['eligible_purchase_policy'] === 'approved_only' ? 'selected' : '' ?>>Approved only</option>
                </select>
            </div>
            <div class="col-md-4">
                <select class="form-select" name="sponsor_eligibility_policy">
                    <option value="active_sponsors_only" <?= $plan['sponsor_eligibility_policy'] === 'active_sponsors_only' ? 'selected' : '' ?>>Active sponsors</option>
                    <option value="any_registered" <?= $plan['sponsor_eligibility_policy'] === 'any_registered' ? 'selected' : '' ?>>Any registered</option>
                    <option value="unresolved" <?= $plan['sponsor_eligibility_policy'] === 'unresolved' ? 'selected' : '' ?>>Unresolved</option>
                </select>
            </div>
            <div class="col-md-4"><input class="form-control" name="notes" value="<?= esc($plan['notes'] ?? '') ?>" placeholder="Notes"></div>
        </div>
        <button class="btn btn-gold btn-sm mt-2" type="submit">Update Draft</button>
    </form>
</div>
<?php endif; ?>

<div class="card-premium">
    <h3 class="h6">Audit History</h3>
    <div class="table-responsive">
        <table class="table table-dark-premium table-sm mb-0">
            <thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach (($plan['events'] ?? []) as $ev): ?>
                <tr>
                    <td><?= esc($ev['created_at'] ?? '') ?></td>
                    <td><?= esc($ev['event_type'] ?? '') ?></td>
                    <td><?= esc((string) ($ev['actor_user_id'] ?? '')) ?></td>
                    <td><?= esc(($ev['from_status'] ?? '') . ' → ' . ($ev['to_status'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
