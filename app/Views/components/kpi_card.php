<?php
/** @var array<string, mixed> $kpi */
$status  = (string) ($kpi['status'] ?? 'ok');
$accent  = (string) ($kpi['accent'] ?? 'gold');
$icon    = (string) ($kpi['icon'] ?? 'bi-graph-up');
$label   = (string) ($kpi['label'] ?? 'Metric');
$display = (string) ($kpi['display'] ?? '—');
$unit    = $kpi['unit'] ?? null;
$href    = $kpi['href'] ?? null;
$reason  = $kpi['reason'] ?? null;
$isLive  = $status === 'ok';

// Compact coin/number display: trim trailing zeros on decimal strings.
if ($isLive && is_numeric($display) && str_contains($display, '.')) {
    $display = rtrim(rtrim($display, '0'), '.');
    if ($display === '' || $display === '-') {
        $display = '0';
    }
}
?>
<article class="kpi-card kpi-accent-<?= esc($accent) ?> kpi-status-<?= esc($status) ?>"<?= $reason ? ' title="' . esc($reason) . '"' : '' ?>>
    <div class="kpi-card-top">
        <span class="kpi-icon" aria-hidden="true"><i class="bi <?= esc($icon) ?>"></i></span>
        <?php if ($href): ?>
            <a class="kpi-link" href="<?= esc($href) ?>" aria-label="View details for <?= esc($label) ?>">
                <i class="bi bi-arrow-up-right"></i>
            </a>
        <?php endif; ?>
    </div>
    <div class="kpi-label"><?= esc($label) ?></div>
    <div class="kpi-value<?= $isLive ? '' : ' is-muted' ?>">
        <?= esc($display) ?>
        <?php if ($isLive && $unit): ?>
            <span class="kpi-unit"><?= esc($unit) ?></span>
        <?php endif; ?>
    </div>
</article>
