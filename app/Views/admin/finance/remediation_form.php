<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Propose a dual-control investigation. This does not change financial ledgers.</p>

<div class="card-premium">
    <form method="post" action="<?= site_url('admin/finance/remediations') ?>" enctype="multipart/form-data" data-loading data-live-validate>
        <?= csrf_field() ?>
        <div class="row g-3 mb-3">
            <div class="col-md-6">
                <label class="form-label" for="remediation_type">Type</label>
                <select class="form-select" id="remediation_type" name="remediation_type" required>
                    <option value="deposit_integrity">Deposit integrity</option>
                    <option value="allocation_anomaly">Allocation anomaly</option>
                    <option value="ledger_mismatch">Ledger mismatch</option>
                    <option value="payment_coverage">Payment coverage</option>
                    <option value="reorg_investigation">Reorg investigation</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="reason_code">Reason Code</label>
                <select class="form-select" id="reason_code" name="reason_code" required>
                    <option value="deposit_integrity">deposit_integrity</option>
                    <option value="allocation_anomaly">allocation_anomaly</option>
                    <option value="ledger_mismatch">ledger_mismatch</option>
                    <option value="payment_coverage">payment_coverage</option>
                    <option value="reorg_investigation">reorg_investigation</option>
                    <option value="other">other</option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label" for="title">Title</label>
            <input class="form-control" id="title" name="title" required maxlength="180" value="<?= esc(old('title')) ?>">
        </div>
        <div class="mb-3">
            <label class="form-label" for="reason">Documented Reason</label>
            <textarea class="form-control" id="reason" name="reason" rows="4" required><?= esc(old('reason')) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="evidence_notes">Evidence Notes</label>
            <textarea class="form-control" id="evidence_notes" name="evidence_notes" rows="3"><?= esc(old('evidence_notes')) ?></textarea>
        </div>
        <div class="mb-3">
            <label class="form-label" for="evidence_file">Evidence Attachment (optional)</label>
            <input class="form-control" type="file" id="evidence_file" name="evidence_file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf">
            <div class="field-hint">JPEG/PNG/WebP/PDF, max 5MB. Stored privately under writable uploads.</div>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label" for="entity_type">Entity Type</label>
                <input class="form-control" id="entity_type" name="entity_type" value="<?= esc(old('entity_type')) ?>" placeholder="usdt_deposit_request">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="entity_id">Entity ID</label>
                <input class="form-control" id="entity_id" name="entity_id" value="<?= esc(old('entity_id')) ?>" placeholder="DEP-...">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="idempotency_key">Idempotency Key</label>
                <input class="form-control" id="idempotency_key" name="idempotency_key" value="<?= esc(old('idempotency_key')) ?>">
            </div>
        </div>
        <button class="btn btn-gold" type="submit">Submit Proposal</button>
    </form>
</div>
<?= $this->endSection() ?>
