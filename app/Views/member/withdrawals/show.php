<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="alert alert-warning"><?= esc($status) ?></div>
<div class="card-premium">
    <h2 class="h5"><code><?= esc($row['withdrawal_ref']) ?></code> · <?= esc($row['status']) ?></h2>
    <div><?= esc($row['amount']) ?> <?= esc($row['currency']) ?> · <?= esc($row['network']) ?></div>
    <div class="mt-1">Destination: <code><?= esc($row['destination_masked'] ?? '') ?></code></div>
    <a class="btn btn-outline-gold btn-sm mt-3" href="<?= site_url('member/withdrawals') ?>">Back</a>
</div>
<?= $this->endSection() ?>
