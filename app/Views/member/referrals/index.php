<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Your direct referrals from the sponsor hierarchy. Levels indicate tree depth — not commission entitlement.</p>

<?= view('components/kpi_grid', ['kpis' => $kpis]) ?>

<div class="card-premium my-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div>
            <div class="text-secondary mb-1">Your Referral Link</div>
            <code class="text-break" id="memberReferralLink"><?= esc($referralLink) ?></code>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-gold btn-sm" data-copy-target="memberReferralLink">Copy Link</button>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/referrals/business') ?>">Business Volume</a>
            <a class="btn btn-outline-gold btn-sm" href="<?= site_url('member/genealogy') ?>">View Genealogy</a>
        </div>
    </div>
    <?php if ($profile->isInactive()): ?>
        <div class="small text-secondary">Your account is inactive. Others can still join under your ID after you activate; you cannot sponsor until active.</div>
    <?php endif; ?>
</div>

<form class="card-premium mb-4" method="get" action="<?= site_url('member/referrals') ?>">
    <div class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="<?= esc($filters['q'] ?? '') ?>" placeholder="Member ID or name" maxlength="64">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">All</option>
                <option value="active" <?= ($filters['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= ($filters['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="per_page">Rows</label>
            <select class="form-select" id="per_page" name="per_page">
                <?php foreach ([10, 20, 25, 50] as $n): ?>
                    <option value="<?= $n ?>" <?= (int) ($filters['per_page'] ?? 20) === $n ? 'selected' : '' ?>><?= $n ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex flex-wrap gap-2">
            <button class="btn btn-gold" type="submit">Filter</button>
            <a class="btn btn-outline-gold" href="<?= site_url('member/referrals') ?>">Reset</a>
        </div>
    </div>
</form>

<div class="card-premium">
    <h2 class="h5 mb-3">Direct Referral List</h2>
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
                <tr><td colspan="5" class="text-secondary">No direct referrals match these filters.</td></tr>
            <?php else: ?>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td><?= esc($row['member_id']) ?></td>
                        <td><?= esc($row['member_name']) ?></td>
                        <td><?= esc($row['registered_at']) ?></td>
                        <td>
                            <span class="badge-status <?= esc($row['account_status']) ?>"><?= esc($row['account_status']) ?></span>
                        </td>
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
