<?php

declare(strict_types=1);

$m = new mysqli('localhost', 'root', '', 'coin_platform');
$a = $m->query('SELECT COUNT(*) c FROM audit_logs')->fetch_assoc();
$s = $m->query("SELECT last_number FROM member_id_sequences WHERE prefix='NGW'")->fetch_assoc();
$b = $m->query("SELECT available_balance, locked_balance, reserved_balance, balance FROM member_coin_balances WHERE member_id='NGW000001'")->fetch_assoc();
$l = $m->query('SELECT COUNT(*) c FROM member_coin_locks')->fetch_assoc();
$p = $m->query("SELECT status FROM coin_purchase_requests WHERE purchase_ref='PUR-10D7174B7FF83054'")->fetch_assoc();

echo json_encode([
    'audit_logs' => (int) $a['c'],
    'sequence_last' => $s['last_number'] ?? null,
    'balance' => $b,
    'locks' => (int) $l['c'],
    'purchase_status' => $p['status'] ?? null,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
