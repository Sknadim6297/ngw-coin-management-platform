<?php

declare(strict_types=1);

$d = new mysqli('localhost', 'root', '', 'coin_platform');
$b = $d->query("SELECT available_balance, locked_balance, balance FROM member_coin_balances WHERE member_id='NGW000001'")->fetch_assoc();
$s = $d->query("SELECT last_number FROM member_id_sequences WHERE prefix='NGW'")->fetch_assoc();
$locks = $d->query('SELECT COUNT(*) c FROM member_coin_locks')->fetch_assoc();
echo json_encode([
    'live_balance' => $b,
    'live_seq'     => $s,
    'live_locks'   => $locks,
], JSON_PRETTY_PRINT) . PHP_EOL;
