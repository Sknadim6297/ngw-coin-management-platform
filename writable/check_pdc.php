<?php

declare(strict_types=1);

$db = new mysqli('localhost', 'root', '', 'coin_platform');
echo "=== network ===\n";
$n = $db->query("SELECT id, destination_wallet, token_contract_address, is_enabled, display_status FROM usdt_payment_networks WHERE network_key='bep20'")->fetch_assoc();
print_r($n);
echo "=== proposals ===\n";
$r = $db->query('SELECT id, proposal_ref, status, proposed_by, to_destination_wallet, token_contract_address, display_enabled, created_at FROM payment_destination_change_proposals ORDER BY id DESC LIMIT 10');
while ($r && ($row = $r->fetch_assoc())) {
    print_r($row);
}
