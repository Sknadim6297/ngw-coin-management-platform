<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?php
$filters = $filters ?? [];
$status  = $filters['status'] ?? '';
?>

<?= view('components/admin_tabs', [
    'active' => $status === null || $status === '' ? 'all' : $status,
    'tabs'   => [
        ['key' => 'all', 'label' => 'All', 'url' => site_url('admin/support'), 'count' => $counts['all'] ?? 0],
        ['key' => 'open', 'label' => 'Open', 'url' => site_url('admin/support?status=open'), 'count' => $counts['open'] ?? 0],
        ['key' => 'in_progress', 'label' => 'In Progress', 'url' => site_url('admin/support?status=in_progress'), 'count' => $counts['in_progress'] ?? 0],
        ['key' => 'resolved', 'label' => 'Resolved', 'url' => site_url('admin/support?status=resolved'), 'count' => $counts['resolved'] ?? 0],
        ['key' => 'closed', 'label' => 'Closed', 'url' => site_url('admin/support?status=closed'), 'count' => $counts['closed'] ?? 0],
    ],
]) ?>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= esc($status) ?>"><?php endif; ?>
        <div class="col-md-3">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '') ?>" placeholder="Ticket, subject, member">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="member_id">Member ID</label>
            <input class="form-control" id="member_id" name="member_id" value="<?= esc($filters['member_id'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All</option>
                <?php foreach ($categories as $key => $label): ?>
                    <option value="<?= esc($key) ?>" <?= ($filters['category'] ?? '') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-gold w-100" type="submit">Filter</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ticket</th>
                <th>Member</th>
                <th>Category</th>
                <th>Subject</th>
                <th>Status</th>
                <th>Updated</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if (($rows ?? []) === []): ?>
                <tr><td colspan="7" class="text-secondary">No support tickets found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['ticket_ref'] ?? '') ?></code></td>
                        <td><a href="<?= site_url('admin/members/' . rawurlencode((string) ($row['member_id'] ?? ''))) ?>"><?= esc($row['member_id'] ?? '') ?></a></td>
                        <td><?= esc($categories[$row['category'] ?? ''] ?? ($row['category'] ?? '')) ?></td>
                        <td><?= esc($row['subject'] ?? '') ?></td>
                        <td><span class="badge-status <?= esc($row['status'] ?? '') ?>"><?= esc($statuses[$row['status'] ?? ''] ?? ucfirst((string) ($row['status'] ?? ''))) ?></span></td>
                        <td><?= esc($row['updated_at'] ?? '') ?></td>
                        <td><a href="<?= site_url('admin/support/' . rawurlencode((string) ($row['ticket_ref'] ?? ''))) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
