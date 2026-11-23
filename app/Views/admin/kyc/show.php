<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>

<?php $status = (string) ($row['status'] ?? ''); ?>
<div class="card-premium mb-3">
    <h2 class="h5">KYC · <?= esc((string) ($row['member_id'] ?? '')) ?>
        <span class="badge bg-secondary"><?= esc($status) ?></span>
    </h2>
    <div class="mb-2"><span class="text-secondary">Legal name</span><div><?= esc((string) ($row['full_name'] ?? '')) ?></div></div>
    <div class="mb-2"><span class="text-secondary">Document type</span><div><?= esc((string) ($row['document_type'] ?? '')) ?></div></div>
    <div class="mb-2"><span class="text-secondary">Document number</span><div><code><?= esc((string) ($row['document_number'] ?? '')) ?></code></div></div>
    <?php if (! empty($row['member_notes'])): ?>
        <div class="mb-2"><span class="text-secondary">Member notes</span><div><?= esc((string) $row['member_notes']) ?></div></div>
    <?php endif; ?>
    <div class="mb-2"><span class="text-secondary">Submitted</span><div><?= esc((string) ($row['submitted_at'] ?? '—')) ?></div></div>
    <?php if (! empty($row['reviewed_at'])): ?>
        <div class="mb-2"><span class="text-secondary">Reviewed</span><div><?= esc((string) $row['reviewed_at']) ?> by user <?= esc((string) ($row['reviewed_by'] ?? '')) ?></div></div>
        <?php if (! empty($row['admin_notes'])): ?>
            <div class="mb-2"><span class="text-secondary">Admin notes</span><div><?= esc((string) $row['admin_notes']) ?></div></div>
        <?php endif; ?>
    <?php endif; ?>
    <a class="btn btn-outline-gold btn-sm mt-2" href="<?= site_url('admin/kyc') ?>">Back</a>
</div>

<?php if ($status === 'pending'): ?>
<div class="row g-3">
    <div class="col-md-6">
        <div class="card-premium">
            <h3 class="h6">Approve</h3>
            <form method="post" action="<?= site_url('admin/kyc/' . (int) $row['member_user_id'] . '/approve') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="text" name="admin_notes" placeholder="Optional notes">
                <button class="btn btn-gold btn-sm" type="submit">Approve KYC</button>
            </form>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card-premium">
            <h3 class="h6">Reject</h3>
            <form method="post" action="<?= site_url('admin/kyc/' . (int) $row['member_user_id'] . '/reject') ?>">
                <?= csrf_field() ?>
                <input class="form-control form-control-sm mb-2" type="text" name="admin_notes" required placeholder="Rejection reason (required)">
                <button class="btn btn-outline-gold btn-sm" type="submit">Reject KYC</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>
<?= $this->endSection() ?>
