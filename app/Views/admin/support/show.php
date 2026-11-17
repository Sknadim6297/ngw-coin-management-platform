<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
    <div>
        <a class="small" href="<?= site_url('admin/support') ?>">← Support Tickets</a>
        <h1 class="h4 mb-1 mt-1"><?= esc($ticket['subject'] ?? '') ?></h1>
        <div class="text-secondary">
            <code><?= esc($ticket['ticket_ref'] ?? '') ?></code>
            · <?= esc($categories[$ticket['category'] ?? ''] ?? '') ?>
            · <span class="badge-status <?= esc($ticket['status'] ?? '') ?>"><?= esc($statuses[$ticket['status'] ?? ''] ?? '') ?></span>
        </div>
    </div>
    <?php if ($member !== null): ?>
        <div class="card-premium p-3">
            <div class="text-secondary small">Member</div>
            <strong><?= esc($member->member_name) ?></strong>
            <div><a href="<?= site_url('admin/members/' . rawurlencode($member->member_id)) ?>"><?= esc($member->member_id) ?></a></div>
            <div class="small text-secondary"><?= esc(ucfirst((string) $member->account_status)) ?></div>
        </div>
    <?php endif; ?>
</div>

<div class="card-premium mb-3">
    <?php if (($messages ?? []) === []): ?>
        <div class="text-secondary">No messages yet.</div>
    <?php else: ?>
        <?php foreach ($messages as $msg): ?>
            <div class="mb-3 pb-3 border-bottom border-secondary border-opacity-25">
                <div class="d-flex justify-content-between gap-2">
                    <strong><?= ($msg['sender_role'] ?? '') === 'admin' ? 'Admin' : 'Member' ?></strong>
                    <span class="text-secondary small"><?= esc($msg['created_at'] ?? '') ?></span>
                </div>
                <div class="mt-1" style="white-space: pre-wrap;"><?= esc($msg['message'] ?? '') ?></div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card-premium">
            <h2 class="h6 mb-3">Reply</h2>
            <form method="post" action="<?= site_url('admin/support/' . rawurlencode((string) $ticket['ticket_ref']) . '/reply') ?>">
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="message">Message</label>
                    <textarea class="form-control" id="message" name="message" rows="4" required minlength="5" maxlength="5000"><?= esc(old('message')) ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="status">Set Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($statuses as $key => $label): ?>
                            <option value="<?= esc($key) ?>" <?= ($ticket['status'] ?? '') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button class="btn btn-gold" type="submit">Send Reply</button>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card-premium">
            <h2 class="h6 mb-3">Update Status</h2>
            <form method="post" action="<?= site_url('admin/support/' . rawurlencode((string) $ticket['ticket_ref']) . '/status') ?>">
                <?= csrf_field() ?>
                <select class="form-select mb-3" name="status">
                    <?php foreach ($statuses as $key => $label): ?>
                        <option value="<?= esc($key) ?>" <?= ($ticket['status'] ?? '') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn-outline-gold w-100" type="submit">Save Status</button>
            </form>
        </div>
    </div>
</div>
<?= $this->endSection() ?>
