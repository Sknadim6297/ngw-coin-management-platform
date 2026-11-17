<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <p class="page-subtitle mb-0">Member accounts activate on first approved coin purchase.</p>
    <a class="btn btn-gold" href="<?= site_url('admin/members/create') ?>">Register Member</a>
</div>

<?php
$statusKey = $status ?? 'all';
if ($statusKey === null || $statusKey === '') {
    $statusKey = 'all';
}
?>
<?= view('components/admin_tabs', [
    'active' => $statusKey,
    'tabs'   => [
        ['key' => 'all', 'label' => 'All Members', 'url' => site_url('admin/members')],
        ['key' => 'active', 'label' => 'Active Members', 'url' => site_url('admin/members?status=active')],
        ['key' => 'inactive', 'label' => 'Inactive Members', 'url' => site_url('admin/members?status=inactive')],
    ],
]) ?>

<div class="card-premium mb-3">
    <form method="get" class="row g-2 align-items-end">
        <?php if ($statusKey !== 'all'): ?>
            <input type="hidden" name="status" value="<?= esc($statusKey) ?>">
        <?php endif; ?>
        <div class="col-md-6">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" type="search" id="q" name="q" value="<?= esc($search ?? '') ?>"
                   placeholder="Member ID, name, or mobile">
        </div>
        <div class="col-md-3">
            <button class="btn btn-gold" type="submit">Search</button>
        </div>
    </form>
</div>

<div class="card-premium">
    <div class="table-responsive">
        <table class="table-premium">
            <thead>
            <tr>
                <th>Member ID</th>
                <th>Name</th>
                <th>Sponsor</th>
                <th>Status</th>
                <th>Purchased Coins</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php if ($members === []): ?>
                <tr><td colspan="6" class="text-secondary">No members found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <?php
                    $uid = (int) $member->user_id;
                    $sponsor = $sponsors[$uid] ?? '—';
                    $coins = $purchasedCoins[$uid] ?? '0';
                    ?>
                    <tr>
                        <td><?= esc($member->member_id) ?></td>
                        <td><?= esc($member->member_name) ?></td>
                        <td><?= esc($sponsor) ?></td>
                        <td><span class="badge-status <?= esc($member->account_status) ?>"><?= esc(ucfirst($member->account_status)) ?></span></td>
                        <td><?= esc($coins) ?></td>
                        <td><a href="<?= site_url('admin/members/' . $member->member_id) ?>">View Profile</a></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div class="mt-3"><?= $pager->links() ?></div>
</div>
<?= $this->endSection() ?>
