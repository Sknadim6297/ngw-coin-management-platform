<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$status = (string) ($policy['status'] ?? '');
$gov    = service('financialGovernanceService');
$isSole = $gov->isSingleAdmin() && (int) auth()->id() === $gov->singleAdminUserId()
    && in_array((string) $policy['domain'], ['mining', 'commission'], true);
$govUrl = site_url('admin/financial-governance/financial_policy/' . rawurlencode((string) $policy['policy_ref']));
?>
<p class="page-subtitle">
    Policy <code><?= esc($policy['policy_ref']) ?></code> ·
    <?= esc($policy['domain']) ?> ·
    <strong><?= esc($status) ?></strong>
    — Approval never enables financial kill-switches.
</p>

<div class="card-premium mb-3">
    <h2 class="h5"><?= esc($policy['title']) ?></h2>
    <div class="row g-2 small">
        <div class="col-md-3">Version <?= (int) ($policy['version_no'] ?? 0) ?></div>
        <div class="col-md-3">Effective <?= esc((string) ($policy['effective_from'] ?? '—')) ?></div>
        <div class="col-md-3">Created by #<?= (int) ($policy['created_by'] ?? 0) ?></div>
        <div class="col-md-3">Lock <?= (int) ($policy['lock_version'] ?? 0) ?></div>
    </div>
    <?php if (! empty($policy['notes'])): ?>
        <p class="mt-2 mb-0 small"><?= esc($policy['notes']) ?></p>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h3 class="h6">Proposed payload</h3>
            <pre class="small mb-0" style="white-space:pre-wrap;max-height:360px;overflow:auto;"><?= esc(json_encode($policy['payload'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium h-100">
            <h3 class="h6">Baseline at draft time</h3>
            <pre class="small mb-0" style="white-space:pre-wrap;max-height:360px;overflow:auto;"><?= esc(json_encode($policy['baseline'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
    </div>
</div>

<div class="card-premium mt-3">
    <h3 class="h6">Actions</h3>
    <div class="d-flex flex-wrap gap-3">
        <?php if ($status === 'draft' && ! empty($canDraft)): ?>
            <form method="post" action="<?= site_url('admin/financial-policies/' . rawurlencode((string) $policy['policy_ref']) . '/submit') ?>" class="border border-secondary rounded p-2">
                <?= csrf_field() ?>
                <label class="form-label small">Password reauthentication</label>
                <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
                <button class="btn btn-gold btn-sm" type="submit">Submit for approval</button>
            </form>
        <?php endif; ?>

        <?php if ($status === 'pending_approval' && ! empty($canApprove)): ?>
            <form method="post" action="<?= site_url('admin/financial-policies/' . rawurlencode((string) $policy['policy_ref']) . '/approve') ?>" class="border border-secondary rounded p-2">
                <?= csrf_field() ?>
                <div class="small text-secondary mb-1">Approver must differ from creator/submitter.</div>
                <label class="form-label small">Password reauthentication</label>
                <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
                <button class="btn btn-gold btn-sm" type="submit">Approve</button>
            </form>
            <?php if ($isSole): ?>
                <div class="border border-warning rounded p-2 small">
                    Single-admin governance is enabled.<br>
                    <a class="btn btn-outline-gold btn-sm mt-1" href="<?= $govUrl ?>/approve">Review &amp; approve as single administrator</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if ($status === 'approved' && ! empty($canApprove)): ?>
            <form method="post" action="<?= site_url('admin/financial-policies/' . rawurlencode((string) $policy['policy_ref']) . '/activate') ?>" class="border border-secondary rounded p-2">
                <?= csrf_field() ?>
                <div class="small text-secondary mb-1">Activator must differ from approver. Does not enable kill-switches.</div>
                <label class="form-label small">Password reauthentication</label>
                <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
                <button class="btn btn-gold btn-sm" type="submit">Mark ACTIVE</button>
            </form>
            <?php if ($isSole): ?>
                <div class="border border-warning rounded p-2 small">
                    Single-admin governance is enabled.<br>
                    <a class="btn btn-outline-gold btn-sm mt-1" href="<?= $govUrl ?>/activate">Review &amp; activate as single administrator</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php if (in_array($status, ['draft', 'pending_approval'], true) && (! empty($canApprove) || ! empty($canDraft))): ?>
            <form method="post" action="<?= site_url('admin/financial-policies/' . rawurlencode((string) $policy['policy_ref']) . '/reject') ?>" class="border border-secondary rounded p-2">
                <?= csrf_field() ?>
                <label class="form-label small">Rejection reason</label>
                <input class="form-control form-control-sm mb-2" name="reason" required>
                <label class="form-label small">Password</label>
                <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
                <button class="btn btn-outline-gold btn-sm" type="submit">Reject</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<div class="card-premium mt-3">
    <h3 class="h6">Event history</h3>
    <?php if (empty($policy['events'])): ?>
        <p class="small text-secondary mb-0">No events.</p>
    <?php else: ?>
        <ul class="small mb-0">
            <?php foreach ($policy['events'] as $ev): ?>
                <li>
                    <code><?= esc($ev['event_type']) ?></code>
                    <?= esc((string) ($ev['from_status'] ?? '')) ?> → <?= esc((string) ($ev['to_status'] ?? '')) ?>
                    · actor #<?= (int) ($ev['actor_user_id'] ?? 0) ?>
                    · <?= esc((string) ($ev['created_at'] ?? '')) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<p class="mt-3"><a class="btn btn-outline-gold btn-sm" href="<?= site_url('admin/financial-policies') ?>">Back to hub</a></p>
<?= $this->endSection() ?>
