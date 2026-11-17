<?php

declare(strict_types=1);

$db = new mysqli('localhost', 'root', '', 'coin_platform');
$r = $db->query("SELECT id, network_key, destination_wallet, token_contract_address, is_enabled, display_status FROM usdt_payment_networks WHERE network_key='bep20'");
print_r($r ? $r->fetch_assoc() : null);
echo "--- proposals ---\n";
$p = $db->query('SELECT proposal_ref, status, proposed_by, to_destination_wallet, display_enabled, token_contract_address, created_at FROM payment_destination_change_proposals ORDER BY id DESC LIMIT 8');
while ($p && ($row = $p->fetch_assoc())) {
    print_r($row);
}
