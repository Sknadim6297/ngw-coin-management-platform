<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your USDT deposit requests. Status reflects internal review, not automatic settlement.</p>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                <?php foreach (['submitted', 'under_review', 'verified', 'rejected', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc(str_replace('_', ' ', ucfirst($opt))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-outline-gold" type="submit">Filter</button>
            <a class="btn btn-gold" href="<?= site_url('member/deposits/create') ?>">New Deposit</a>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Network</th>
                <th>Amount</th>
                <th>Submitted</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="6" class="text-secondary">No deposit requests yet.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['deposit_ref']) ?></td>
                        <td><?= esc($row['network_key']) ?></td>
                        <td><?= esc($row['expected_amount_usdt']) ?></td>
                        <td><?= esc($row['submitted_at'] ?? '—') ?></td>
                        <td><span class="badge-status <?= esc($row['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($row['status']))) ?></span></td>
                        <td><a href="<?= site_url('member/deposits/' . $row['deposit_ref']) ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
