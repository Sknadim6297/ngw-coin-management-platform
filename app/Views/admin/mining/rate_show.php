<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<p class="page-subtitle">Rate <code><?= esc($rate['rate_ref']) ?></code> · status <?= esc($rate['status']) ?>. Approved rates cannot be silently edited.</p>
<div class="card-premium mb-4">
    <div class="row g-3">
        <div class="col-md-3"><div class="text-secondary">Month</div><strong><?= esc($rate['effective_month']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Percent</div><strong><?= esc($rate['rate_percent']) ?>%</strong></div>
        <div class="col-md-3"><div class="text-secondary">Basis</div><strong><?= esc($rate['rate_basis']) ?></strong></div>
        <div class="col-md-3"><div class="text-secondary">Policy</div><strong><?= esc($rate['eligibility_policy']) ?></strong></div>
    </div>
    <?php if ($canApprove && $rate['status'] === 'draft'): ?>
        <form class="mt-3" method="post" action="<?= site_url('admin/mining/rates/' . rawurlencode($rate['rate_ref']) . '/approve') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-gold" type="submit">Approve (simulation only)</button>
        </form>
    <?php endif; ?>
    <?php if ($canApprove && in_array($rate['status'], ['draft', 'approved'], true)): ?>
        <form class="mt-2" method="post" action="<?= site_url('admin/mining/rates/' . rawurlencode($rate['rate_ref']) . '/disable') ?>">
            <?= csrf_field() ?>
            <button class="btn btn-outline-gold" type="submit">Disable</button>
        </form>
    <?php endif; ?>
</div>
<div class="card-premium">
    <h2 class="h5 mb-3">Approval Audit</h2>
    <div class="table-responsive">
        <table class="table-premium">
            <thead><tr><th>When</th><th>Event</th><th>From</th><th>To</th><th>Actor</th></tr></thead>
            <tbody>
            <?php foreach ($events as $ev): ?>
                <tr>
                    <td><?= esc($ev['created_at']) ?></td>
                    <td><?= esc($ev['event_type']) ?></td>
                    <td><?= esc($ev['from_status'] ?? '—') ?></td>
                    <td><?= esc($ev['to_status'] ?? '—') ?></td>
                    <td><?= (int) $ev['actor_user_id'] ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?= $this->endSection() ?>
