<?php
/** @var list<array{key:string,label:string,url:string,count?:int|string|null}> $tabs */
/** @var string $active */
$tabs = $tabs ?? [];
$active = $active ?? '';
?>
<div class="admin-tabs mb-3" role="tablist">
    <?php foreach ($tabs as $tab): ?>
        <?php
        $key = (string) ($tab['key'] ?? '');
        $isActive = $key === $active;
        $count = $tab['count'] ?? null;
        ?>
        <a class="admin-tab <?= $isActive ? 'active' : '' ?>"
           href="<?= esc($tab['url'] ?? '#') ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <?= esc((string) ($tab['label'] ?? $key)) ?>
            <?php if ($count !== null && $count !== ''): ?>
                <span class="admin-tab-count"><?= esc((string) $count) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>
