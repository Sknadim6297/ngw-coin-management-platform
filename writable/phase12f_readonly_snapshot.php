<?php

declare(strict_types=1);

/**
 * Phase 12F — READ-ONLY production snapshot (SELECT only).
 * Never INSERT/UPDATE/DELETE/ALTER.
 */

$label = $argv[1] ?? 'before';
$outFile = __DIR__ . '/phase12f-prod-' . preg_replace('/[^a-z0-9_-]/i', '', $label) . '.json';

$mysqli = @new mysqli('localhost', 'root', '', 'coin_platform');
if ($mysqli->connect_errno) {
    fwrite(STDERR, 'DB connect failed: ' . $mysqli->connect_error . PHP_EOL);
    exit(1);
}
$mysqli->query('SET SESSION TRANSACTION READ ONLY');
$mysqli->begin_transaction();

function q(mysqli $db, string $sql): array
{
    $res = $db->query($sql);
    if ($res === false) {
        throw new RuntimeException($db->error . ' :: ' . $sql);
    }
    if ($res === true) {
        return [];
    }
    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }

    return $rows;
}

function one(mysqli $db, string $sql): ?array
{
    $rows = q($db, $sql);

    return $rows[0] ?? null;
}

function countTable(mysqli $db, string $table): int
{
    $row = one($db, 'SELECT COUNT(*) AS c FROM `' . str_replace('`', '', $table) . '`');

    return (int) ($row['c'] ?? 0);
}

$tables = [];
foreach (q($mysqli, 'SHOW TABLES') as $r) {
    $tables[] = array_values($r)[0];
}

$members = q($mysqli, 'SELECT member_id FROM member_profiles ORDER BY id ASC LIMIT 50');
$seq = one($mysqli, "SELECT prefix, last_number, updated_at FROM member_id_sequences WHERE prefix='NGW'");
$bal = one($mysqli, "SELECT member_id, available_balance, locked_balance, reserved_balance, balance
    FROM member_coin_balances WHERE member_id='NGW000001'");
$purchase = one($mysqli, "SELECT purchase_ref, member_id, status, coin_quantity, total_usdt, reviewed_at
    FROM coin_purchase_requests WHERE purchase_ref='PUR-10D7174B7FF83054'");
$lock = one($mysqli, "SELECT lock_ref, purchase_ref, lock_kind, quantity, status, locked_at, matures_at
    FROM member_coin_locks WHERE purchase_ref='PUR-10D7174B7FF83054' LIMIT 1");

$snapshot = [
    'label'           => $label,
    'captured_at'     => date('c'),
    'database'        => 'coin_platform',
    'read_only'       => true,
    'table_count'     => count($tables),
    'member_count'    => countTable($mysqli, 'member_profiles'),
    'member_ids'      => array_column($members, 'member_id'),
    'sequence'        => $seq,
    'target_purchase' => $purchase,
    'target_balance'  => $bal,
    'target_lock'     => $lock,
    'counts'          => [
        'approved_purchases' => (int) (one($mysqli, "SELECT COUNT(*) c FROM coin_purchase_requests WHERE status='approved'")['c'] ?? 0),
        'mandatory_locks'    => countTable($mysqli, 'member_coin_locks'),
        'mining_ledger'      => $mysqli->query("SHOW TABLES LIKE 'mining_wallet_ledger'")->num_rows
            ? countTable($mysqli, 'mining_wallet_ledger') : 0,
        'commission_ledger'  => $mysqli->query("SHOW TABLES LIKE 'commission_wallet_ledger_entries'")->num_rows
            ? countTable($mysqli, 'commission_wallet_ledger_entries') : 0,
        'p2p_transfers'      => $mysqli->query("SHOW TABLES LIKE 'mining_p2p_transfers'")->num_rows
            ? countTable($mysqli, 'mining_p2p_transfers') : 0,
        'withdrawals'        => $mysqli->query("SHOW TABLES LIKE 'commission_withdrawal_requests'")->num_rows
            ? countTable($mysqli, 'commission_withdrawal_requests') : 0,
        'coin_sells'         => $mysqli->query("SHOW TABLES LIKE 'coin_sell_requests'")->num_rows
            ? countTable($mysqli, 'coin_sell_requests') : 0,
        'audit_logs'         => countTable($mysqli, 'audit_logs'),
    ],
];

$mysqli->rollback();
$mysqli->close();

file_put_contents($outFile, json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo $outFile . PHP_EOL;
echo json_encode([
    'label' => $label,
    'member_count' => $snapshot['member_count'],
    'sequence_last' => $seq['last_number'] ?? null,
    'balance' => $bal,
    'audit_logs' => $snapshot['counts']['audit_logs'],
], JSON_PRETTY_PRINT) . PHP_EOL;
