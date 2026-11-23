<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">KYC approval is required before commission USDT withdrawals. Only Admin can approve your submission.</p>

<?php
$kyc = $kyc ?? [];
$status = (string) ($kyc['status'] ?? 'none');
?>
<div class="alert <?= ! empty($kyc['is_approved']) ? 'alert-success' : 'alert-warning' ?> mb-3">
    Status: <strong><?= esc($status) ?></strong>
    <?php if (! empty($kyc['admin_notes']) && $status === 'rejected'): ?>
        <div class="small mt-1">Admin note: <?= esc((string) $kyc['admin_notes']) ?></div>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h5 mb-3">Your KYC</h2>
            <?php if ($status === 'none' || $status === 'rejected'): ?>
                <form method="post" action="<?= site_url('member/kyc') ?>">
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label" for="full_name">Full legal name</label>
                        <input class="form-control" name="full_name" id="full_name" required
                               value="<?= esc(old('full_name', $kyc['full_name'] ?? (string) ($profile->member_name ?? ''))) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="document_type">Document type</label>
                        <select class="form-select" name="document_type" id="document_type" required>
                            <?php foreach ($types as $t): ?>
                                <option value="<?= esc($t) ?>" <?= old('document_type') === $t ? 'selected' : '' ?>><?= esc($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="document_number">Document number</label>
                        <input class="form-control" name="document_number" id="document_number" required
                               value="<?= esc(old('document_number') ?? '') ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="member_notes">Notes (optional)</label>
                        <input class="form-control" name="member_notes" id="member_notes"
                               value="<?= esc(old('member_notes') ?? '') ?>">
                    </div>
                    <button class="btn btn-gold" type="submit">Submit for Admin review</button>
                </form>
            <?php else: ?>
                <div class="mb-2"><span class="text-secondary">Name</span><div><?= esc((string) ($kyc['full_name'] ?? '')) ?></div></div>
                <div class="mb-2"><span class="text-secondary">Document</span><div><?= esc((string) ($kyc['document_type'] ?? '')) ?></div></div>
                <div class="mb-2"><span class="text-secondary">Document #</span><div><code><?= esc((string) ($kyc['document_number'] ?? '')) ?></code></div></div>
                <div class="mb-2"><span class="text-secondary">Submitted</span><div><?= esc((string) ($kyc['submitted_at'] ?? '')) ?></div></div>
                <?php if ($status === 'pending'): ?>
                    <div class="small text-secondary mt-2">Waiting for Admin review. You cannot approve your own KYC.</div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card-premium">
            <h2 class="h5 mb-3">Why this matters</h2>
            <p class="small text-secondary mb-0">Commission withdrawals pay BEP20 USDT to your registered wallet. Admin must approve KYC before a withdrawal request can be submitted. Pending or rejected KYC cannot bypass that gate.</p>
            <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('member/withdrawals') ?>">Back to Withdrawals</a>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
