<?= $this->extend('layouts/member') ?>
<?= $this->section('content') ?>
<div class="card-premium coming-soon-panel">
    <i class="bi bi-hourglass-split mb-3 d-block"></i>
    <h2 class="h4"><?= esc($moduleName) ?></h2>
    <?php if (! empty($activationBlocked)): ?>
        <p class="text-secondary">This feature requires an active account. Activation happens after your first approved coin purchase.</p>
    <?php else: ?>
        <p class="text-secondary">Coming in a later phase. No simulated earnings or balances are shown here.</p>
    <?php endif; ?>
</div>
<?= $this->endSection() ?>
