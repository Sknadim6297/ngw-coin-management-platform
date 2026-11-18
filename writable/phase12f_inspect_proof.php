<?php

declare(strict_types=1);

$j = json_decode(file_get_contents(__DIR__ . '/phase12f-remediation-proof.json'), true);
foreach ([
    'prod_lock_dryrun',
    'prod_member_refuse_wrong_confirm',
    'prod_lock_refuse_wrong_confirm',
    'prod_member_refuse_missing_allow',
    'iso_member_apply',
    'iso_lock_apply_1',
    'iso_lock_apply_2',
] as $k) {
    echo "===== {$k} code=" . ($j[$k]['code'] ?? '?') . " =====\n";
    echo substr((string) ($j[$k]['out'] ?? ''), 0, 1200) . "\n\n";
}
echo "live_unchanged_final=" . (! empty($j['live_unchanged_final']) ? 'yes' : 'no') . "\n";
echo "iso_after_lock_2=" . json_encode($j['iso_after_lock_2'] ?? null) . "\n";
