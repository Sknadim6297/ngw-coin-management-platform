<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Financial alert email outbox. Recipients are redacted. Delivery requires explicit SMTP + recipient config.</p>

<div class="notice-box mb-3">
    Delivery configured: <strong><?= ! empty($configured) ? 'Yes' : 'No' ?></strong>
    — set <code>financeNotifications.emailEnabled=true</code>, recipients, and Email SMTP in <code>.env</code>.
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ref</th>
                <th>Status</th>
                <th>Subject</th>
                <th>Recipients</th>
                <th>Attempts</th>
                <th>Next / Sent</th>
                <th>Last Error</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-secondary">No outbox rows.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['outbox_ref']) ?></code></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><?= esc($row['subject']) ?></td>
                        <td><code><?= esc($row['recipients_redacted'] ?? '—') ?></code></td>
                        <td><?= (int) $row['attempt_count'] ?></td>
                        <td><?= esc($row['sent_at'] ?? $row['next_attempt_at'] ?? '—') ?></td>
                        <td class="small text-break"><?= esc($row['last_error'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
