<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="text-secondary"><?= number_format((int) ($total ?? 0)) ?> tickets</div>
    <a class="btn btn-gold btn-sm" href="<?= site_url('member/support/create') ?>">Create Ticket</a>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ticket</th>
                <th>Category</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (($rows ?? []) === []): ?>
                <tr><td colspan="6" class="text-secondary">No support tickets yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['ticket_ref'] ?? '') ?></code></td>
                        <td><?= esc($categories[$row['category'] ?? ''] ?? '') ?></td>
                        <td><?= esc($row['subject'] ?? '') ?></td>
                        <td><span class="badge-status <?= esc($row['status'] ?? '') ?>"><?= esc($statuses[$row['status'] ?? ''] ?? '') ?></span></td>
                        <td><?= esc($row['updated_at'] ?? '') ?></td>
                        <td><a href="<?= site_url('member/support/' . rawurlencode((string) ($row['ticket_ref'] ?? ''))) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
