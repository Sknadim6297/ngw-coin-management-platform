<?php

declare(strict_types=1);

$db = new mysqli('localhost', 'root', '', 'coin_platform_phase12d');
$db->query('SET FOREIGN_KEY_CHECKS=0');
$now = date('Y-m-d H:i:s');
$ref = 'PUR-P12D-LOCK-TEST001';

$db->query("DELETE FROM member_coin_locks WHERE purchase_ref='{$ref}'");
$db->query("DELETE FROM coin_purchase_requests WHERE purchase_ref='{$ref}'");
$db->query('DELETE FROM member_coin_balances WHERE member_user_id IN (1,5)');
$db->query("DELETE FROM member_profiles WHERE member_id IN ('ROOT0001','NGW000001')");
$db->query('DELETE FROM users WHERE id IN (1,5)');

// Minimal Shield-like users rows (disable FK for synthetic staging fixture)
$cols = [];
$cr = $db->query('SHOW COLUMNS FROM users');
while ($c = $cr->fetch_assoc()) {
    $cols[] = $c['Field'];
}
$hasUsername = in_array('username', $cols, true);
$hasStatus   = in_array('status', $cols, true);
$hasActive   = in_array('active', $cols, true);

$u1 = "INSERT INTO users (id" . ($hasUsername ? ', username' : '') . ($hasStatus ? ', status' : '') . ($hasActive ? ', active' : '') . ', created_at, updated_at) VALUES (1'
    . ($hasUsername ? ", 'root_p12d'" : '')
    . ($hasStatus ? ", 'active'" : '')
    . ($hasActive ? ', 1' : '')
    . ", '{$now}', '{$now}')";
$u5 = "INSERT INTO users (id" . ($hasUsername ? ', username' : '') . ($hasStatus ? ', status' : '') . ($hasActive ? ', active' : '') . ', created_at, updated_at) VALUES (5'
    . ($hasUsername ? ", 'ngw_p12d'" : '')
    . ($hasStatus ? ", 'active'" : '')
    . ($hasActive ? ', 1' : '')
    . ", '{$now}', '{$now}')";
$db->query($u1);
$db->query($u5);

$db->query("INSERT INTO member_profiles (user_id, member_id, member_name, account_status, is_root_sponsor, created_at, updated_at) VALUES
    (1, 'ROOT0001', 'Root', 'active', 1, '{$now}', '{$now}'),
    (5, 'NGW000001', 'Member', 'active', 0, '{$now}', '{$now}')");

$db->query("INSERT INTO member_coin_balances
    (member_user_id, member_id, balance, available_balance, locked_balance, reserved_balance, total_acquired, created_at, updated_at)
    VALUES (5, 'NGW000001', '5.00000000', '5.00000000', '0.00000000', '0.00000000', '5.00000000', '{$now}', '{$now}')");

$db->query("INSERT INTO coin_purchase_requests
    (purchase_ref, member_user_id, member_id, coin_quantity, price_usdt_snapshot, total_usdt, status, reviewed_by, requested_at, reviewed_at, created_at, updated_at)
    VALUES ('{$ref}', 5, 'NGW000001', '5.00000000', '1.00000000', '5.00000000', 'approved', 1, '{$now}', '{$now}', '{$now}', '{$now}')");

$db->query('SET FOREIGN_KEY_CHECKS=1');
echo "seeded={$ref} err=" . ($db->error ?: 'none') . "\n";

chdir(dirname(__DIR__));
passthru('php spark locking:remediate-mandatory --group phase12d --purchase ' . escapeshellarg($ref) . ' --dry-run', $e1);
echo "DRY={$e1}\n";
passthru('php spark locking:remediate-mandatory --group phase12d --purchase ' . escapeshellarg($ref) . ' --confirm APPLY_STAGING', $e2);
echo "APPLY={$e2}\n";

$b = $db->query('SELECT available_balance, locked_balance, balance FROM member_coin_balances WHERE member_user_id=5')->fetch_assoc();
$l = $db->query("SELECT lock_ref, plan_code, quantity, status, locked_at, matures_at FROM member_coin_locks WHERE purchase_ref='{$ref}'")->fetch_assoc();
file_put_contents(__DIR__ . '/phase12d-lock-remediation-result.json', json_encode([
    'purchase_ref' => $ref,
    'dry_exit'     => $e1,
    'apply_exit'   => $e2,
    'balance'      => $b,
    'lock'         => $l,
], JSON_PRETTY_PRINT));
echo json_encode(['balance' => $b, 'lock' => $l], JSON_PRETTY_PRINT) . "\n";
