<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Posting proposals require dual-control. Approval does <strong>not</strong> credit wallets while posting is DISABLED.</p>
<div class="alert alert-warning">Commission Posting Kill-Switch: <?= $postingOn ? 'ON' : 'OFF' ?></div>
<?php if ($canPropose): ?>
<div class="card-premium mb-3">
    <h2 class="h6">Create Draft Proposal</h2>
    <form method="post" action="<?= site_url('admin/commission-postings') ?>" class="row g-2 align-items-end">
        <?= csrf_field() ?>
        <div class="col-md-4"><label class="form-label">Purchase Ref</label><input class="form-control" name="purchase_ref" required></div>
        <div class="col-md-2"><label class="form-label">Level</label>
            <select class="form-select" name="level"><?php for ($i=1;$i<=3;$i++): ?><option value="<?= $i ?>"><?= $i ?></option><?php endfor; ?></select>
        </div>
        <div class="col-md-3"><label class="form-label">Plan Ref (optional)</label><input class="form-control" name="plan_ref"></div>
        <div class="col-md-2"><button class="btn btn-gold btn-sm" type="submit">Create Draft</button></div>
    </form>
</div>
<?php endif; ?>
<div class="table-responsive card-premium">
    <table class="table table-dark-premium table-sm mb-0">
        <thead><tr><th>Ref</th><th>Purchase</th><th>Beneficiary</th><th>Lvl</th><th>Amount</th><th>Status</th><th>Posted</th></tr></thead>
        <tbody>
        <?php if (empty($rows)): ?>
            <tr><td colspan="7" class="text-secondary">No proposals.</td></tr>
        <?php else: foreach ($rows as $row): ?>
            <tr>
                <td><a href="<?= site_url('admin/commission-postings/' . rawurlencode($row['proposal_ref'])) ?>"><code><?= esc($row['proposal_ref']) ?></code></a></td>
                <td><code><?= esc($row['purchase_ref']) ?></code></td>
                <td><?= esc($row['beneficiary_member_id']) ?></td>
                <td><?= esc((string) $row['level']) ?></td>
                <td><?= esc($row['proposed_amount']) ?> <?= esc($row['currency']) ?></td>
                <td><?= esc($row['status']) ?></td>
                <td><?= ! empty($row['is_posted']) ? 'Yes' : 'No' ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
<?= $this->endSection() ?>
