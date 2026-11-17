<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="mb-3">
    <a class="small" href="<?= site_url('member/support') ?>">← Support</a>
    <h1 class="h4 mb-1 mt-1"><?= esc($ticket['subject'] ?? '') ?></h1>
    <div class="text-secondary">
        <code><?= esc($ticket['ticket_ref'] ?? '') ?></code>
        · <?= esc($categories[$ticket['category'] ?? ''] ?? '') ?>
        · <span class="badge-status <?= esc($ticket['status'] ?? '') ?>"><?= esc($statuses[$ticket['status'] ?? ''] ?? '') ?></span>
    </div>
</div>

<div class="card-premium mb-3">
    <?php foreach ($messages as $msg): ?>
        <div class="mb-3 pb-3 border-bottom border-secondary border-opacity-25">
            <div class="d-flex justify-content-between gap-2">
                <strong><?= ($msg['sender_role'] ?? '') === 'admin' ? 'Support Team' : 'You' ?></strong>
                <span class="text-secondary small"><?= esc($msg['created_at'] ?? '') ?></span>
            </div>
            <div class="mt-1" style="white-space: pre-wrap;"><?= esc($msg['message'] ?? '') ?></div>
        </div>
    <?php endforeach; ?>
</div>

<?php if (($ticket['status'] ?? '') !== 'closed'): ?>
<div class="card-premium" style="max-width: 720px;">
    <h2 class="h6 mb-3">Reply</h2>
    <form method="post" action="<?= site_url('member/support/' . rawurlencode((string) $ticket['ticket_ref']) . '/reply') ?>">
        <?= csrf_field() ?>
        <div class="mb-3">
            <textarea class="form-control" name="message" rows="4" required minlength="5" maxlength="5000"><?= esc(old('message')) ?></textarea>
        </div>
        <button class="btn btn-gold" type="submit">Send Reply</button>
    </form>
</div>
<?php else: ?>
    <div class="alert alert-secondary">This ticket is closed.</div>
<?php endif; ?>
<?= $this->endSection() ?>
