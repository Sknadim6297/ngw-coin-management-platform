<?= $this->extend('layouts/admin') ?>
<?= $this->section('content') ?>
<div class="card-premium coming-soon-panel">
    <i class="bi bi-hourglass-split mb-3 d-block"></i>
    <h2 class="h4"><?= esc($moduleName) ?></h2>
    <p class="text-secondary mb-0">Coming in a later phase. No simulated transactions or balances are available here.</p>
</div>
<?= $this->endSection() ?>
