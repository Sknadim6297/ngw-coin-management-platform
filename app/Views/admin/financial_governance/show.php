<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$base   = site_url('admin/financial-governance/' . $type . '/' . rawurlencode($ref) . '/' . $intent);
$verb   = $intent === 'activate' ? 'activation' : 'approval';
$canAct = $status['ready'] && $terms['rule_problems'] === [];
?>
<p class="page-subtitle">
    <?= esc($terms['label']) ?> <code><?= esc($ref) ?></code> · status <strong><?= esc($terms['status']) ?></strong>
    · governance mode <strong><?= esc($status['mode']) ?></strong> (designated administrator #<?= (int) $status['single_admin_user_id'] ?>)
</p>

<div class="alert alert-warning small">
    You are the only administrator, so this <?= esc($verb) ?> has no independent second approver. It is limited to the
    owner-approved business rules and needs two separate steps: <strong>1. review the exact terms</strong>, then
    <strong>2. confirm</strong> with your password, the typed phrase and every term ticked. It never turns on a
    kill-switch, never enables Financial Automation and never posts COIN or USDT.
</div>

<?php foreach ($status['problems'] as $p): ?>
    <div class="alert alert-danger small mb-2"><?= esc($p) ?></div>
<?php endforeach; ?>
<?php foreach ($terms['rule_problems'] as $p): ?>
    <div class="alert alert-danger small mb-2">Not eligible for single-admin <?= esc($verb) ?>: <?= esc($p) ?></div>
<?php endforeach; ?>

<?php if (! empty($terms['requirements'])): ?>
<div class="card-premium mb-3">
    <h2 class="h6">Mandatory financial terms</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Term</th><th>Field</th><th>Required</th><th>Found</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($terms['requirements'] as $r): ?>
                <tr>
                    <td><?= esc($r['label']) ?></td>
                    <td><code><?= esc($r['key']) ?></code></td>
                    <td><?= esc($r['required']) ?></td>
                    <td><?= esc($r['actual']) ?></td>
                    <td><?= $r['ok'] ? '<span class="text-success">OK</span>' : '<span class="text-danger">' . esc($r['actual'] === 'missing' ? 'MISSING' : 'MISMATCH') . '</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card-premium mb-3">
    <h2 class="h6">Terms under review (version <?= esc($terms['version']) ?>)</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>Term</th><th>Value</th></tr></thead>
            <tbody>
            <?php foreach ($terms['terms'] as $k => $v): ?>
                <tr><td><code><?= esc($k) ?></code></td><td><?= esc($v) ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="small text-secondary mt-2">Terms fingerprint <code><?= esc(substr($terms['terms_hash'], 0, 16)) ?>…</code></div>
</div>

<?php if ($canAct && $review === null): ?>
<div class="card-premium mb-3">
    <h3 class="h6">Step 1 — Record your review</h3>
    <form method="post" action="<?= $base . '/review' ?>">
        <?= csrf_field() ?>
        <label class="form-label small">Password reauthentication</label>
        <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
        <button class="btn btn-gold btn-sm" type="submit">I have reviewed these terms</button>
    </form>
</div>
<?php elseif ($canAct): ?>
<div class="card-premium mb-3">
    <h3 class="h6">Step 2 — Confirm <?= esc($verb) ?></h3>
    <p class="small text-secondary">Review <code><?= esc($review['action_ref']) ?></code> recorded at <?= esc($review['created_at']) ?>. It can be used once and expires after <?= (int) ($status['review_ttl_seconds'] / 60) ?> minutes.</p>
    <form method="post" action="<?= $base . '/confirm' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="review_ref" value="<?= esc($review['action_ref']) ?>">
        <input type="hidden" name="terms_hash" value="<?= esc($terms['terms_hash']) ?>">
        <?php if ($type === 'financial_policy'): ?>
            <input type="hidden" name="lock_version" value="<?= esc($terms['version']) ?>">
        <?php endif; ?>
        <div class="mb-2">
            <?php foreach ($terms['terms'] as $k => $v): ?>
                <div class="form-check small">
                    <input class="form-check-input" type="checkbox" name="confirmed_terms[]" value="<?= esc($k) ?>" id="t-<?= esc(md5($k)) ?>">
                    <label class="form-check-label" for="t-<?= esc(md5($k)) ?>">I confirm <code><?= esc($k) ?></code> = <?= esc($v) ?></label>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="form-check small mb-2">
            <input class="form-check-input" type="checkbox" name="acknowledge" value="1" id="ack">
            <label class="form-check-label" for="ack">I am the single administrator and I approve without an independent second approver.</label>
        </div>
        <label class="form-label small">Type <code><?= esc($phrase) ?></code></label>
        <input class="form-control form-control-sm mb-2" name="confirm" autocomplete="off" required>
        <label class="form-label small">Password reauthentication</label>
        <input class="form-control form-control-sm mb-2" type="password" name="password" required autocomplete="current-password">
        <button class="btn btn-gold btn-sm" type="submit">Confirm <?= esc($verb) ?></button>
    </form>
</div>
<?php endif; ?>

<div class="card-premium mb-3">
    <h3 class="h6">Governance trail</h3>
    <?php if ($history === []): ?>
        <p class="small text-secondary mb-0">No governance actions yet.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table-premium">
                <thead><tr><th>When</th><th>Action</th><th>Mode</th><th>Version</th><th>Admin</th><th>Ref</th></tr></thead>
                <tbody>
                <?php foreach ($history as $h): ?>
                    <tr>
                        <td><?= esc($h['created_at']) ?></td>
                        <td><?= esc($h['action']) ?></td>
                        <td><?= esc($h['governance_mode']) ?></td>
                        <td><?= esc((string) $h['subject_version']) ?></td>
                        <td>#<?= (int) $h['actor_user_id'] ?></td>
                        <td><code><?= esc($h['action_ref']) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p><a class="btn btn-outline-gold btn-sm" href="<?= esc($backUrl) ?>">Back</a></p>
<?= $this->endSection() ?>
