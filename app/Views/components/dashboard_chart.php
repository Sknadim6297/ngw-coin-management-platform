<?php
/** @var string $id */
/** @var string $title */
/** @var string $type */
/** @var array<string, mixed> $dataset */
/** @var bool $available */
$id        = $id ?? 'chart';
$title     = $title ?? 'Chart';
$type      = $type ?? 'line';
$dataset   = $dataset ?? ['labels' => [], 'values' => []];
$available = array_key_exists('available', $dataset) ? (bool) $dataset['available'] : true;
$reason    = (string) ($dataset['reason'] ?? 'Not available');
$payload   = [
    'type'   => $type,
    'labels' => $dataset['labels'] ?? [],
    'values' => $dataset['values'] ?? [],
];
?>
<div class="card-premium chart-card h-100">
    <div class="d-flex justify-content-between align-items-start mb-3 gap-2">
        <h2 class="h6 mb-0"><?= esc($title) ?></h2>
        <?php if (! $available): ?>
            <span class="badge-status inactive">Not available</span>
        <?php endif; ?>
    </div>
    <?php if (! $available): ?>
        <div class="chart-empty text-secondary"><?= esc($reason) ?></div>
    <?php else: ?>
        <div class="chart-canvas-wrap">
            <canvas
                id="<?= esc($id) ?>"
                data-dashboard-chart
                data-chart='<?= esc(json_encode($payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), 'attr') ?>'
                aria-label="<?= esc($title) ?>"
            ></canvas>
        </div>
    <?php endif; ?>
</div>
