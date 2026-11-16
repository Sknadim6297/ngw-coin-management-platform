<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="d-flex flex-wrap gap-2 my-4">
    <a class="btn btn-gold" href="<?= site_url('admin/members/create') ?>"><i class="bi bi-person-plus me-1"></i> Quick Registration</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/members') ?>">View All Members</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/purchases?status=pending') ?>">Pending Purchases</a>
    <a class="btn btn-outline-gold" href="<?= site_url('admin/coins') ?>">Coin Overview</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartMemberTrend',
            'title'   => 'Member Registration Trend (14 days)',
            'type'    => 'line',
            'dataset' => $charts['member_registration_trend'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartPurchaseTrend',
            'title'   => 'Approved Coin Purchase Trend (14 days)',
            'type'    => 'bar',
            'dataset' => $charts['coin_purchase_trend'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartInventory',
            'title'   => 'Coin Inventory Distribution',
            'type'    => 'doughnut',
            'dataset' => $charts['inventory_distribution'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartMemberStatus',
            'title'   => 'Active vs Inactive Members',
            'type'    => 'doughnut',
            'dataset' => $charts['member_status'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartMining',
            'title'   => 'Mining Trend',
            'type'    => 'line',
            'dataset' => $charts['mining_trend'],
        ]) ?>
    </div>
    <div class="col-lg-6">
        <?= view('components/dashboard_chart', [
            'id'      => 'chartCommission',
            'title'   => 'Level Commission Trend',
            'type'    => 'line',
            'dataset' => $charts['commission_trend'],
        ]) ?>
    </div>
</div>

<div class="card-premium">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 mb-0">Recent Registrations</h2>
        <span class="text-secondary small">Updated <?= esc($generatedAt) ?></span>
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

<?= $this->section('scripts') ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.6/dist/chart.umd.min.js"></script>
<script src="<?= base_url('assets/js/dashboard-charts.js') ?>"></script>
<?= $this->endSection() ?>
