<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<?= view('components/earnings_hub_nav', ['section' => 'wallets']) ?>
<p class="page-subtitle">Review member KYC required for commission withdrawals. Members cannot approve their own KYC.</p>

<form class="card-premium mb-3 row g-2" method="get">
    <div class="col-md-3">
        <input class="form-control form-control-sm" name="member_q" placeholder="Member ID / name"
               value="<?= esc($filters['member_q'] ?? '') ?>">
    </div>
    <div class="col-md-3">
        <select class="form-select form-select-sm" name="status">
            <option value="">All statuses</option>
            <?php foreach (['pending', 'approved', 'rejected'] as $st): ?>
                <option value="<?= $st ?>" <?= ($filters['status'] ?? '') === $st ? 'selected' : '' ?>><?= $st ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2"><button class="btn btn-gold btn-sm" type="submit">Filter</button></div>
</form>

<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm mb-0">
        <thead>
        <tr>
            <th>Member ID</th>
            <th>Name</th>
            <th>Document</th>
            <th>Doc #</th>
            <th>Status</th>
            <th>Submitted</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-secondary">No KYC submissions.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><?= esc((string) ($row['member_id'] ?? '')) ?></td>
                <td><?= esc((string) ($row['full_name'] ?? '')) ?></td>
                <td><?= esc((string) ($row['document_type'] ?? '')) ?></td>
                <td><code><?= esc((string) ($row['document_number_masked'] ?? '')) ?></code></td>
                <td><?= esc((string) ($row['status'] ?? '')) ?></td>
                <td><?= esc((string) ($row['submitted_at'] ?? '')) ?></td>
                <td>
                    <a class="btn btn-outline-gold btn-sm"
                       href="<?= site_url('admin/kyc/' . (int) $row['member_user_id']) ?>">Review</a>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
