<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Dual-control remediation detail. Settled ledgers are never silently reversed.</p>

<div class="card-premium mb-3">
    <div class="row g-3">
        <div class="col-md-4"><div class="text-secondary mb-1">Proposal</div><strong><?= esc($proposal['proposal_ref']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Status</div><strong><?= esc($proposal['status']) ?></strong></div>
        <div class="col-md-4"><div class="text-secondary mb-1">Type</div><strong><?= esc($proposal['remediation_type']) ?></strong></div>
        <div class="col-12"><div class="text-secondary mb-1">Title</div><strong><?= esc($proposal['title']) ?></strong></div>
        <div class="col-12"><div class="text-secondary mb-1">Reason</div><strong><?= nl2br(esc($proposal['reason'])) ?></strong></div>
        <?php if (! empty($proposal['evidence_notes'])): ?>
            <div class="col-12"><div class="text-secondary mb-1">Evidence</div><strong><?= nl2br(esc($proposal['evidence_notes'])) ?></strong></div>
        <?php endif; ?>
        <div class="col-md-6"><div class="text-secondary mb-1">Before State</div><pre class="small text-break"><?= esc($proposal['before_state_json'] ?? '') ?></pre></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Planned After</div><pre class="small text-break"><?= esc($proposal['planned_after_state_json'] ?? '') ?></pre></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Execution Allowed</div><strong><?= ! empty($proposal['execution_allowed']) ? 'Yes' : 'No' ?></strong></div>
        <div class="col-md-6"><div class="text-secondary mb-1">Execution Result</div><strong><?= esc($proposal['execution_result'] ?? '—') ?></strong></div>
    </div>
</div>

<?php if ($canApprove && $proposal['status'] === 'proposed'): ?>
    <div class="card-premium mb-3">
        <h2 class="h5 mb-3">Dual-Control Review</h2>
        <form method="post" action="<?= site_url('admin/finance/remediations/' . $proposal['proposal_ref'] . '/approve') ?>" class="mb-3" data-loading>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="review_note">Review Note</label>
                <textarea class="form-control" id="review_note" name="review_note" rows="3" required></textarea>
            </div>
            <div class="form-check mb-3">
                <input class="form-check-input" type="checkbox" value="1" id="confirm" name="confirm" required>
                <label class="form-check-label" for="confirm">I confirm dual-control review. I understand ledger execution remains unavailable.</label>
            </div>
            <button class="btn btn-gold" type="submit">Approve (Execution Unavailable)</button>
        </form>
        <form method="post" action="<?= site_url('admin/finance/remediations/' . $proposal['proposal_ref'] . '/reject') ?>" data-loading>
            <?= csrf_field() ?>
            <div class="mb-3">
                <label class="form-label" for="review_note_r">Rejection Note</label>
                <textarea class="form-control" id="review_note_r" name="review_note" rows="2" required></textarea>
            </div>
            <button class="btn btn-outline-gold" type="submit">Reject Proposal</button>
        </form>
    </div>
<?php endif; ?>

<div class="card-premium">
    <h2 class="h5 mb-3">Approval / Audit Timeline</h2>
    <?php if ($events === []): ?>
        <div class="text-secondary">No events.</div>
    <?php else: ?>
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th></tr></thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= esc($event['created_at']) ?></td>
                    <td><strong><?= esc($event['event_type']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
