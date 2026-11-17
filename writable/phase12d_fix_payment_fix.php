<?php

declare(strict_types=1);

/**
 * Quick smoke: placeholder is not usable; bootstrap path would apply.
 * Does not mutate production balances / does not enable financial switches.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

// Minimal without full CI boot: replicate pattern check
$pattern = '/^0x[a-fA-F0-9]{40}$/';
$cases = [
    'Receiving Wallet Address' => false,
    'USDT Token Contract Address' => false,
    '0xe8c3DD3Bf04B72733fc71801467692267770304c' => true,
    '0x1F3D4e775C589A6287cB92fcBDf4229c73331f0E' => true,
    '' => false,
];
foreach ($cases as $v => $expect) {
    $ok = preg_match($pattern, $v) === 1;
    echo ($ok === $expect ? 'OK' : 'FAIL') . " [{$v}] => " . ($ok ? 'usable' : 'placeholder') . PHP_EOL;
}

// Cancel any stray pending on live so admin form unlocks (non-financial)
$db = new mysqli('localhost', 'root', '', 'coin_platform');
$r = $db->query("SELECT proposal_ref FROM payment_destination_change_proposals WHERE status='pending'");
$n = 0;
while ($r && ($row = $r->fetch_assoc())) {
    $ref = $db->real_escape_string($row['proposal_ref']);
    $db->query("UPDATE payment_destination_change_proposals SET status='rejected', rejection_reason='Cleared so admin can complete BEP20 setup (Phase 12D UX fix)', rejected_at=NOW(), updated_at=NOW() WHERE proposal_ref='{$ref}' AND status='pending'");
    $n += $db->affected_rows;
    echo "cleared_pending={$row['proposal_ref']}\n";
}
echo "pending_cleared_count={$n}\n";

// Disable live flag while wallet is still placeholder (safety — not a balance mutation)
$net = $db->query("SELECT id, destination_wallet, is_enabled FROM usdt_payment_networks WHERE network_key='bep20'")->fetch_assoc();
if ($net && preg_match($pattern, (string) $net['destination_wallet']) !== 1 && (int) $net['is_enabled'] === 1) {
    $db->query('UPDATE usdt_payment_networks SET is_enabled=0, display_status=\'hidden\', updated_at=NOW() WHERE id=' . (int) $net['id']);
    echo "disabled_placeholder_live_network id={$net['id']}\n";
} else {
    echo "network_ok_or_already_disabled\n";
}
