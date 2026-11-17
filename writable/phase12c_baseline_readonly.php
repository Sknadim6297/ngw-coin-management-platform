<?php

declare(strict_types=1);

/**
 * Phase 12C read-only production baseline. No writes.
 */

$db = new mysqli('localhost', 'root', '', 'coin_platform');
if ($db->connect_error) {
    fwrite(STDERR, 'CONNECT_FAIL: ' . $db->connect_error . PHP_EOL);
    exit(1);
}

$out = [
    'generated_at' => date('c'),
    'database'     => $db->query('SELECT DATABASE()')->fetch_row()[0],
];

$scalar = static function (mysqli $db, string $sql): ?string {
    $r = $db->query($sql);
    if ($r === false) {
        return 'ERR:' . $db->error;
    }
    $row = $r->fetch_row();

    return $row !== null ? (string) $row[0] : null;
};

$tableExists = static function (mysqli $db, string $table): bool {
    $t = $db->real_escape_string($table);
    $r = $db->query("SHOW TABLES LIKE '{$t}'");

    return $r !== false && $r->num_rows > 0;
};

$out['counts'] = [
    'member_profiles'                  => $scalar($db, 'SELECT COUNT(*) FROM member_profiles'),
    'coin_purchase_requests'           => $scalar($db, 'SELECT COUNT(*) FROM coin_purchase_requests'),
    'purchases_approved'               => $scalar($db, "SELECT COUNT(*) FROM coin_purchase_requests WHERE status='approved'"),
    'purchases_pending'                => $scalar($db, "SELECT COUNT(*) FROM coin_purchase_requests WHERE status='pending'"),
    'coin_inventory_available'         => $scalar($db, 'SELECT COALESCE(SUM(available_balance),0) FROM coin_inventory'),
    'coin_inventory_total_added'       => $scalar($db, 'SELECT COALESCE(SUM(total_added),0) FROM coin_inventory'),
    'coin_inventory_total_allocated'   => $scalar($db, 'SELECT COALESCE(SUM(total_allocated),0) FROM coin_inventory'),
    'member_coin_balances_rows'        => $scalar($db, 'SELECT COUNT(*) FROM member_coin_balances'),
    'ownership_available'              => $scalar($db, 'SELECT COALESCE(SUM(available_balance),0) FROM member_coin_balances'),
    'ownership_locked'                 => $scalar($db, 'SELECT COALESCE(SUM(locked_balance),0) FROM member_coin_balances'),
    'ownership_reserved'               => $scalar($db, 'SELECT COALESCE(SUM(reserved_balance),0) FROM member_coin_balances'),
    'ownership_total_balance'          => $scalar($db, 'SELECT COALESCE(SUM(balance),0) FROM member_coin_balances'),
    'member_coin_locks'                => $scalar($db, 'SELECT COUNT(*) FROM member_coin_locks'),
    'member_coin_locks_active'         => $scalar($db, "SELECT COUNT(*) FROM member_coin_locks WHERE status='active'"),
    'mining_wallets'                   => $scalar($db, 'SELECT COUNT(*) FROM mining_wallets'),
    'mining_available_balance'         => $scalar($db, 'SELECT COALESCE(SUM(available_balance),0) FROM mining_wallets'),
    'mining_wallet_ledger'             => $scalar($db, 'SELECT COUNT(*) FROM mining_wallet_ledger'),
    'commission_wallet_accounts'       => $scalar($db, 'SELECT COUNT(*) FROM commission_wallet_accounts'),
    'commission_available'             => $scalar($db, 'SELECT COALESCE(SUM(available_balance),0) FROM commission_wallet_accounts'),
    'commission_reserved'              => $scalar($db, 'SELECT COALESCE(SUM(reserved_balance),0) FROM commission_wallet_accounts'),
    'commission_wallet_ledger'         => $scalar($db, 'SELECT COUNT(*) FROM commission_wallet_ledger_entries'),
    'mining_p2p_transfers'             => $scalar($db, 'SELECT COUNT(*) FROM mining_p2p_transfers'),
    'commission_withdrawal_requests'   => $scalar($db, 'SELECT COUNT(*) FROM commission_withdrawal_requests'),
    'coin_sell_requests'               => $scalar($db, 'SELECT COUNT(*) FROM coin_sell_requests'),
    'usdt_deposit_requests'            => $scalar($db, 'SELECT COUNT(*) FROM usdt_deposit_requests'),
    'migrations'                       => $scalar($db, 'SELECT COUNT(*) FROM migrations'),
    'commission_plans'                 => $scalar($db, 'SELECT COUNT(*) FROM commission_plans'),
    'commission_plans_approved'        => $scalar($db, "SELECT COUNT(*) FROM commission_plans WHERE status='approved'"),
    'mining_rate_configs'              => $scalar($db, 'SELECT COUNT(*) FROM mining_rate_configs'),
    'mining_rate_configs_approved'     => $scalar($db, "SELECT COUNT(*) FROM mining_rate_configs WHERE status='approved'"),
];

$members = [];
$r = $db->query('SELECT member_id, account_status, user_id, is_root_sponsor, activation_purchase_ref FROM member_profiles ORDER BY id');
while ($row = $r->fetch_assoc()) {
    $members[] = $row;
}
$out['members'] = $members;

$out['member_id_audit'] = [
    'existing_ids'           => array_column($members, 'member_id'),
    'numeric_ids'            => [],
    'collision_NGW1234'      => null,
    'collision_NGW981234'    => null,
    'collision_NGW000001'    => null,
    'max_existing_ngw_num'   => 0,
];
foreach ($members as $m) {
    $id = (string) $m['member_id'];
    if (preg_match('/^NGW(\d+)$/', $id, $mm)) {
        $num = (int) $mm[1];
        $out['member_id_audit']['numeric_ids'][] = [
            'member_id' => $id,
            'numeric'   => $num,
            'digits'    => strlen($mm[1]),
        ];
        $out['member_id_audit']['max_existing_ngw_num'] = max(
            $out['member_id_audit']['max_existing_ngw_num'],
            $num
        );
    }
}

$check = static function (mysqli $db, string $id): ?array {
    $stmt = $db->prepare('SELECT member_id, account_status FROM member_profiles WHERE member_id = ? LIMIT 1');
    $stmt->bind_param('s', $id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();

    return $res ?: null;
};
$out['member_id_audit']['collision_NGW1234']   = $check($db, 'NGW1234');
$out['member_id_audit']['collision_NGW981234'] = $check($db, 'NGW981234');
$out['member_id_audit']['collision_NGW000001'] = $check($db, 'NGW000001');

$out['member_id_sequence'] = $db->query('SELECT * FROM member_id_sequences')->fetch_all(MYSQLI_ASSOC);

// Target purchase remediation
$stmt = $db->prepare("SELECT purchase_ref, member_user_id, member_id, coin_quantity, price_usdt_snapshot, total_usdt, status, reviewed_at, reviewed_by, inventory_transaction_ref, ownership_transaction_ref, payment_deposit_ref, payment_status FROM coin_purchase_requests WHERE purchase_ref LIKE 'PUR-10D7174B7FF%' LIMIT 5");
$stmt->execute();
$res = $stmt->get_result();
$purchases = [];
while ($row = $res->fetch_assoc()) {
    $purchases[] = $row;
}
$out['target_purchases'] = $purchases;
$purchase = $purchases[0] ?? null;

if ($purchase) {
    $userId = (int) $purchase['member_user_id'];
    $pref   = (string) $purchase['purchase_ref'];
    $memberId = (string) $purchase['member_id'];
    $qty = (string) $purchase['coin_quantity'];

    $own = $db->query("SELECT member_user_id, member_id, balance, available_balance, locked_balance, reserved_balance, total_acquired FROM member_coin_balances WHERE member_user_id = {$userId} LIMIT 1");
    $ownership = $own ? $own->fetch_assoc() : null;
    $out['target_ownership'] = $ownership;

    $locks = [];
    $lr = $db->query(
        "SELECT lock_ref, member_id, purchase_ref, lock_kind, plan_code, quantity, status, locked_at, matures_at, idempotency_key
         FROM member_coin_locks
         WHERE member_user_id = {$userId} OR purchase_ref = '" . $db->real_escape_string($pref) . "'"
    );
    while ($lr && ($row = $lr->fetch_assoc())) {
        $locks[] = $row;
    }
    $out['target_locks'] = $locks;

    $existingMandatory = null;
    foreach ($locks as $l) {
        if (($l['purchase_ref'] ?? '') === $pref && ($l['lock_kind'] ?? '') === 'mandatory_purchase') {
            $existingMandatory = $l;
        }
    }

    $avail = $ownership['available_balance'] ?? '0';
    $locked = $ownership['locked_balance'] ?? '0';
    $reserved = $ownership['reserved_balance'] ?? '0';
    $balance = $ownership['balance'] ?? '0';

    // Projection: move qty from available → locked (balance unchanged)
    $afterAvail  = bcsub((string) $avail, $qty, 8);
    $afterLocked = bcadd((string) $locked, $qty, 8);

    $reviewedAt = $purchase['reviewed_at'] ?? date('Y-m-d H:i:s');
    $maturesAt  = date('Y-m-d H:i:s', strtotime($reviewedAt . ' +1000 days'));

    $out['remediation_preview'] = [
        'action'                 => 'NONE — preview only; require separate authorization before CoinLockingService::lockMandatoryForPurchase',
        'purchase_ref'           => $pref,
        'member_id'              => $memberId,
        'member_user_id'         => $userId,
        'purchase_status'        => $purchase['status'],
        'coin_quantity'          => $qty,
        'reviewed_at'            => $reviewedAt,
        'proposed_plan'          => 'LOCK1000',
        'proposed_lock_kind'     => 'mandatory_purchase',
        'proposed_duration_days' => 1000,
        'proposed_locked_at'     => $reviewedAt,
        'proposed_matures_at'    => $maturesAt,
        'idempotency_key'        => 'mandatory-lock:' . $pref,
        'existing_mandatory_lock'=> $existingMandatory,
        'duplicate_protection'   => 'purchase_ref unique + idempotency_key mandatory-lock:{purchase_ref}',
        'before'                 => [
            'available_balance' => $avail,
            'locked_balance'    => $locked,
            'reserved_balance'  => $reserved,
            'balance'           => $balance,
        ],
        'after_projection'       => [
            'available_balance' => $afterAvail,
            'locked_balance'    => $afterLocked,
            'reserved_balance'  => $reserved,
            'balance'           => $balance,
            'note'              => 'Total ownership balance unchanged; available→locked only',
        ],
        'preconditions'          => [
            'purchase_approved'           => ($purchase['status'] ?? '') === 'approved',
            'sufficient_available'        => bccomp((string) $avail, $qty, 8) >= 0,
            'no_existing_mandatory_lock'  => $existingMandatory === null,
            'ownership_credit_present'    => $ownership !== null && bccomp((string) ($ownership['total_acquired'] ?? '0'), '0', 8) > 0,
        ],
    ];
}

// Missing mandatory locks sample
$missing = [];
$mr = $db->query(
    "SELECT p.purchase_ref, p.member_id, p.coin_quantity, p.reviewed_at
     FROM coin_purchase_requests p
     LEFT JOIN member_coin_locks l
       ON l.purchase_ref = p.purchase_ref AND l.lock_kind = 'mandatory_purchase'
     WHERE p.status = 'approved' AND l.id IS NULL
     ORDER BY p.id ASC LIMIT 50"
);
while ($mr && ($row = $mr->fetch_assoc())) {
    $missing[] = $row;
}
$out['missing_mandatory_locks'] = $missing;

// Commission plans + levels
$plans = [];
$pr = $db->query('SELECT id, plan_ref, plan_name, status, effective_from, effective_to, approved_at, level_count FROM commission_plans ORDER BY id DESC LIMIT 10');
while ($pr && ($row = $pr->fetch_assoc())) {
    $levels = [];
    $lr = $db->query('SELECT * FROM commission_plan_levels WHERE plan_id = ' . (int) $row['id'] . ' ORDER BY level_number ASC');
    while ($lr && ($lv = $lr->fetch_assoc())) {
        $levels[] = $lv;
    }
    $row['levels'] = $levels;
    $plans[] = $row;
}
$out['commission_plans'] = $plans;

$rates = [];
$rr = $db->query('SELECT id, rate_ref, status, rate_percent, rate_basis, effective_month, approved_at, eligibility_policy FROM mining_rate_configs ORDER BY id DESC LIMIT 10');
while ($rr && ($row = $rr->fetch_assoc())) {
    $rates[] = $row;
}
$out['mining_rate_configs'] = $rates;

$out['coin_settings'] = $db->query('SELECT id, current_price_usdt, min_purchase_usdt, price_updated_at FROM coin_settings ORDER BY id DESC LIMIT 1')->fetch_assoc();

$inv = $db->query('SELECT * FROM coin_inventory ORDER BY id DESC LIMIT 1');
$out['coin_inventory'] = $inv ? $inv->fetch_assoc() : null;

$net = [];
$nr = $db->query('SELECT id, network_key, chain_id, token_contract_address, token_symbol, token_decimals, destination_wallet, required_confirmations, verifier_type, is_enabled, is_demo, display_status FROM usdt_payment_networks');
if ($nr === false) {
    $nr = $db->query('SELECT * FROM usdt_payment_networks');
}
while ($nr && ($row = $nr->fetch_assoc())) {
    // Redact full destination if needed — keep truncated for report
    if (! empty($row['destination_wallet'])) {
        $w = (string) $row['destination_wallet'];
        $row['destination_wallet_redacted'] = substr($w, 0, 6) . '…' . substr($w, -4);
        unset($row['destination_wallet']);
    }
    if (! empty($row['resolved_rpc_url'])) {
        unset($row['resolved_rpc_url']);
    }
    $net[] = $row;
}
$out['usdt_payment_networks'] = $net;

$mig = [];
$mgr = $db->query('SELECT version, class, batch, time FROM migrations ORDER BY id DESC LIMIT 15');
while ($mgr && ($row = $mgr->fetch_assoc())) {
    $mig[] = $row;
}
$out['recent_migrations'] = $mig;

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
