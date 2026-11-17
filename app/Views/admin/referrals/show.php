<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Direct referrals for <?= esc($profile->member_id) ?>. Read-only inspection.</p>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Direct</div><div class="h4"><?= (int) $summary['total'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Active Direct</div><div class="h4"><?= (int) $summary['active'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Team Total</div><div class="h4"><?= (int) $counts['total'] ?></div></div></div>
    <div class="col-6 col-md-3"><div class="card-premium"><div class="text-secondary">Sponsor</div><div class="h6 mb-0"><?= esc($sponsor['sponsor_member_id'] ?? ($profile->is_root_sponsor ? 'ROOT' : '—')) ?></div></div></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-outline-gold" href="<?= site_url('admin/genealogy?member_id=' . rawurlencode($profile->member_id)) ?>">Genealogy</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/referrals') ?>">Back</a>
</div>

<form class="card-premium mb-4" method="get">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Filter</button>
        </div>
    </div>
</form>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Registered</th>
                <th>Status</th>
                <th>Level</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="5" class="text-secondary">No direct referrals.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><a href="<?= site_url('admin/referrals/' . rawurlencode($row['member_id'])) ?>"><?= esc($row['member_id']) ?></a></td>
                        <td><?= esc($row['member_name']) ?></td>
                        <td><?= esc($row['registered_at']) ?></td>
                        <td><?= esc($row['account_status']) ?></td>
                        <td><?= (int) ($row['referral_level'] ?? 1) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
