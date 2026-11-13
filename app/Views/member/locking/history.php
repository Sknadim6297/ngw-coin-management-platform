<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your lock history. Available <?= esc($summary['available']) ?> · Locked <?= esc($summary['locked']) ?> · Total <?= esc($summary['balance']) ?></p>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <?php foreach (['active', 'matured', 'unlocked', 'cancelled'] as $opt): ?>
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
                <th>Plan</th>
                <th>Qty</th>
                <th>Status</th>
                <th>Locked</th>
                <th>Matures</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No locks found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('member/locking/' . $row['lock_ref']) ?>"><?= esc($row['lock_ref']) ?></a></td>
                        <td><?= esc($row['plan_name']) ?></td>
                        <td><?= esc($row['quantity']) ?></td>
                        <td><?= esc($row['status']) ?></td>
                        <td><?= esc($row['locked_at']) ?></td>
                        <td><?= esc($row['matures_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pager): ?><div class="mt-3"><?= $pager->links() ?></div><?php endif; ?>
</div>
<?= $this->endSection() ?>
