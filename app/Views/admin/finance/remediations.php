<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Dual-control remediation investigations. Ledger execution is unavailable in Phase 4D.</p>

<?php if ($canPropose): ?>
    <div class="mb-3"><a class="btn btn-gold" href="<?= site_url('admin/finance/remediations/create') ?>">Propose Investigation</a></div>
<?php endif; ?>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['proposed', 'approved', 'rejected', 'execution_unavailable'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc($opt) ?></option>
                <?php endforeach; ?>
            </select>
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
                <th>Type</th>
                <th>Title</th>
                <th>Status</th>
                <th>Proposed</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No remediation proposals.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><code><?= esc($row['proposal_ref']) ?></code></td>
                        <td><?= esc($row['remediation_type']) ?></td>
                        <td><?= esc($row['title']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><?= esc($row['proposed_at']) ?></td>
                        <td><a href="<?= site_url('admin/finance/remediations/' . $row['proposal_ref']) ?>">Open</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
