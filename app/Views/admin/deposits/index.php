<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Review USDT deposit evidence. Do not treat screenshots as blockchain verification.</p>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Submitted</div><div class="h3" style="color:var(--gold-bright)"><?= esc((string) $submittedCount) ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Under Review</div><div class="h3" style="color:var(--gold-bright)"><?= esc((string) $underReviewCount) ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Verified</div><div class="h3" style="color:var(--gold-bright)"><?= esc((string) $verifiedCount) ?></div></div></div>
    <div class="col-md-3"><div class="card-premium"><div class="text-secondary mb-1">Rejected</div><div class="h3" style="color:var(--gold-bright)"><?= esc((string) $rejectedCount) ?></div></div></div>
</div>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="" <?= $status === '' ? 'selected' : '' ?>>All</option>
                <?php foreach (['submitted', 'under_review', 'verified', 'rejected', 'cancelled'] as $opt): ?>
                    <option value="<?= esc($opt) ?>" <?= $status === $opt ? 'selected' : '' ?>><?= esc(str_replace('_', ' ', ucfirst($opt))) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search) ?>" placeholder="Reference, member, or tx hash">
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Apply Filters</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Reference</th>
                <th>Member</th>
                <th>Network</th>
                <th>Amount</th>
                <th>Submitted</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="7" class="text-secondary">No deposits found.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['deposit_ref']) ?></td>
                        <td><?= esc($row['member_id']) ?></td>
                        <td><?= esc($row['network_key']) ?></td>
                        <td><?= esc($row['expected_amount_usdt']) ?></td>
                        <td><?= esc($row['submitted_at'] ?? '—') ?></td>
                        <td><span class="badge-status <?= esc($row['status']) ?>"><?= esc(str_replace('_', ' ', ucfirst($row['status']))) ?></span></td>
                        <td><a href="<?= site_url('admin/deposits/' . $row['deposit_ref']) ?>">Review</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
