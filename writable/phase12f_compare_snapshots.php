<?php

declare(strict_types=1);

$before = json_decode(file_get_contents(__DIR__ . '/phase12f-prod-before.json'), true);
$after  = json_decode(file_get_contents(__DIR__ . '/phase12f-prod-after.json'), true);

$keys = ['member_count', 'member_ids', 'sequence', 'target_purchase', 'target_balance', 'target_lock', 'counts', 'table_count'];
$diff = [];
foreach ($keys as $k) {
    if (($before[$k] ?? null) != ($after[$k] ?? null)) {
        $diff[$k] = ['before' => $before[$k] ?? null, 'after' => $after[$k] ?? null];
    }
}

$out = [
    'identical_core_state' => $diff === [],
    'differences' => $diff,
    'before_captured_at' => $before['captured_at'] ?? null,
    'after_captured_at' => $after['captured_at'] ?? null,
];
file_put_contents(__DIR__ . '/phase12f-before-after-compare.json', json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL);
echo json_encode($out, JSON_PRETTY_PRINT) . PHP_EOL;
