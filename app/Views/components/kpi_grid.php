<?php
/** @var list<array<string, mixed>> $kpis */
$kpis = $kpis ?? [];
?>
<div class="kpi-grid" role="list">
    <?php if ($kpis === []): ?>
        <div class="card-premium text-secondary">No dashboard metrics are available right now.</div>
    <?php else: ?>
        <?php foreach ($kpis as $kpi): ?>
            <div role="listitem">
                <?= view('components/kpi_card', ['kpi' => $kpi]) ?>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
