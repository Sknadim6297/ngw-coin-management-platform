<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="page-subtitle mb-0">Manage registered member accounts. Accounts activate on first approved coin purchase.</p>
    <a class="btn btn-gold" href="<?= site_url('admin/members/create') ?>">Register Member</a>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Mobile</th>
                <th>Status</th>
                <th>Registered</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($members === []): ?>
                <tr><td colspan="6" class="text-secondary">No members found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <tr>
                        <td><?= esc($member->member_id) ?></td>
                        <td><?= esc($member->member_name) ?></td>
                        <td><?= esc($member->mobile) ?></td>
                        <td><span class="badge-status <?= esc($member->account_status) ?>"><?= esc(ucfirst($member->account_status)) ?></span></td>
                        <td><?= esc($member->created_at) ?></td>
                        <td><a href="<?= site_url('admin/members/' . $member->member_id) ?>">Profile</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3">
        <?= $pager->links() ?>
    </div>
</div>
<?= $this->endSection() ?>
