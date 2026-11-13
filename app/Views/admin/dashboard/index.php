<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Membership overview only. Financial balances and earnings are not shown in Step 1.</p>

<div class="stat-grid mb-4">
    <div class="card-premium stat-card">
        <span>Total Members</span>
        <h3><?= esc($totalMembers) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Active Members</span>
        <h3><?= esc($activeMembers) ?></h3>
    </div>
    <div class="card-premium stat-card">
        <span>Inactive Members</span>
        <h3><?= esc($inactiveMembers) ?></h3>
    </div>
</div>

<div class="d-flex flex-wrap gap-2 mb-4">
    <a class="btn btn-gold" href="<?= site_url('admin/members/create') ?>"><i class="bi bi-person-plus me-1"></i> Quick Registration</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/members') ?>">View All Members</a>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Recent Registrations</h2>
    </div>
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Status</th>
                <th>Registered</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($recentRegistrations === []): ?>
                <tr><td colspan="5" class="text-secondary">No members yet.</td></tr>
            <?php else: ?>
                <?php foreach ($recentRegistrations as $member): ?>
                    <tr>
                        <td><?= esc($member->member_id) ?></td>
                        <td><?= esc($member->member_name) ?></td>
                        <td>
                            <span class="badge-status <?= esc($member->account_status) ?>">
                                <?= esc(ucfirst($member->account_status)) ?>
                            </span>
                        </td>
                        <td><?= esc($member->created_at) ?></td>
                        <td><a href="<?= site_url('admin/members/' . $member->member_id) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
