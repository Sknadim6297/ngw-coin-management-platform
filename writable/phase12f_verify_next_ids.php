<?php

declare(strict_types=1);

$m = new mysqli('localhost', 'root', '', 'coin_platform_phase12f');
$last = (int) ($m->query("SELECT last_number FROM member_id_sequences WHERE prefix='NGW'")->fetch_assoc()['last_number'] ?? 0);
$ids = [];
$r = $m->query('SELECT member_id FROM member_profiles ORDER BY id');
while ($row = $r->fetch_assoc()) {
    $ids[] = $row['member_id'];
}
$lockCount = (int) $m->query("SELECT COUNT(*) c FROM member_coin_locks WHERE purchase_ref='PUR-10D7174B7FF83054'")->fetch_assoc()['c'];
$bal = $m->query("SELECT available_balance, locked_balance, reserved_balance, balance FROM member_coin_balances WHERE member_id='NGW000001'")->fetch_assoc();

$next1 = 'NGW' . str_pad((string) max($last + 1, 1234), 4, '0', STR_PAD_LEFT);
$next2 = 'NGW' . str_pad((string) (max($last + 1, 1234) + 1), 4, '0', STR_PAD_LEFT);

chdir(dirname(__DIR__));
$outLines = [];
exec('php spark locking:remediate-mandatory --group phase12f --purchase PUR-10D7174B7FF83054 --confirm APPLY_STAGING 2>&1', $outLines, $code);

$out = [
    'database' => 'coin_platform_phase12f',
    'member_ids_preserved' => $ids,
    'sequence_last' => $last,
    'expected_next_ids' => [$next1, $next2],
    'balance' => $bal,
    'lock_count' => $lockCount,
    'second_remediation_exit' => $code,
    'second_remediation_out' => implode("\n", $outLines),
];
file_put_contents(__DIR__ . '/phase12f-next-ids.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo "seq={$last} next={$next1},{$next2} locks={$lockCount} second_exit={$code}\n";
echo (str_contains(implode("\n", $outLines), 'ALREADY REMEDIATED') || str_contains(implode("\n", $outLines), 'already_remediated') ? "noop=yes\n" : "noop=check_log\n");
