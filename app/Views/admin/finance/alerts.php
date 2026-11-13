<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Internal financial alerts. External notifications are not sent unless a provider is configured later.</p>

<div class="d-flex flex-wrap gap-2 mb-3">
    <form method="post" action="<?= site_url('admin/finance/monitor/run') ?>" data-loading>
        <?= csrf_field() ?>
        <button class="btn btn-gold" type="submit">Run Integrity Monitor</button>
    </form>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/finance/allocations') ?>">Allocations</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/finance/remediations') ?>">Remediation Queue</a>
</div>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Open</option>
                <option value="acknowledged" <?= $status === 'acknowledged' ? 'selected' : '' ?>>Acknowledged</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="type">Type</label>
            <input class="form-control" id="type" name="type" value="<?= esc($type) ?>" placeholder="e.g. rpc_unavailable">
        </div>
        <div class="col-md-2"><button class="btn btn-gold" type="submit">Filter</button></div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Ref</th>
                <th>Severity</th>
                <th>Type</th>
                <th>Title</th>
                <th>Count</th>
                <th>Last Seen</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" class="text-secondary">No alerts.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['alert_ref']) ?></code></td>
                        <td><?= esc($row['severity']) ?></td>
                        <td><code><?= esc($row['alert_type']) ?></code></td>
                        <td>
                            <strong><?= esc($row['title']) ?></strong>
                            <div class="text-secondary small"><?= esc($row['message']) ?></div>
                        </td>
                        <td><?= (int) $row['occurrence_count'] ?></td>
                        <td><?= esc($row['last_seen_at']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td>
                            <?php if ($canAck && $row['status'] === 'open'): ?>
                                <form method="post" action="<?= site_url('admin/finance/alerts/' . $row['alert_ref'] . '/ack') ?>">
                                    <?= csrf_field() ?>
                                    <button class="btn btn-outline-gold btn-sm" type="submit">Ack</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
