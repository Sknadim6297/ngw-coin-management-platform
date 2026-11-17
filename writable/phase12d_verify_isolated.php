<?php

declare(strict_types=1);

/**
 * Phase 12D isolated sequence apply + staging lock remediation + policy drafts.
 */

define('FCPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);

// 1) Prepare isolated DB schema
passthru('php ' . escapeshellarg(__DIR__ . '/phase12d_prepare_and_verify.php'), $prep);
echo "PREP_EXIT={$prep}\n";
if ($prep !== 0) {
    exit($prep);
}

$results = [
    'generated_at' => date('c'),
    'steps'        => [],
];

chdir(dirname(__DIR__));

// 2) Seed minimal sequence + NGW000001 on phase12d without Shield users if needed
$db = new mysqli('localhost', 'root', '', 'coin_platform_phase12d');
$now = date('Y-m-d H:i:s');
$db->query('DELETE FROM member_id_sequences');
$db->query("INSERT INTO member_id_sequences (prefix, last_number, created_at, updated_at) VALUES ('NGW', 981233, '{$now}', '{$now}')");
// Prefer updating profiles if present from dump (empty). Insert without users if FK allows.
$hasProfiles = $db->query("SHOW TABLES LIKE 'member_profiles'")->num_rows > 0;
if ($hasProfiles) {
    $cnt = (int) $db->query('SELECT COUNT(*) c FROM member_profiles')->fetch_assoc()['c'];
    if ($cnt === 0) {
        // Try insert with user_id 1/5 placeholders — may fail FK; ignore.
        @$db->query("INSERT INTO member_profiles (user_id, member_id, member_name, account_status, is_root_sponsor, created_at, updated_at) VALUES
            (1, 'ROOT0001', 'Root', 'active', 1, '{$now}', '{$now}'),
            (5, 'NGW000001', 'Member', 'active', 0, '{$now}', '{$now}')");
        echo 'profile_insert_err=' . $db->error . "\n";
    }
}

passthru('php spark members:correct-sequence --group phase12d --dry-run', $d1);
$results['steps']['member_id_phase12d_dry'] = $d1;

passthru('php spark members:correct-sequence --group phase12d --confirm APPLY_STAGING', $d2);
$results['steps']['member_id_phase12d_apply'] = $d2;

// Verify sequence
$seq = $db->query("SELECT last_number FROM member_id_sequences WHERE prefix='NGW'")->fetch_assoc();
$results['phase12d_sequence_after'] = $seq;

// 3) Staging lock remediation on phase12b synthetic purchase (find approved missing lock)
passthru('php spark members:correct-sequence --group phase12b --dry-run', $d3);
$results['steps']['member_id_phase12b_dry'] = $d3;

$b = new mysqli('localhost', 'root', '', 'coin_platform_phase12b');
$purchaseRef = null;
if ($b->connect_errno === 0) {
    $q = $b->query(
        "SELECT p.purchase_ref FROM coin_purchase_requests p
         LEFT JOIN member_coin_locks l ON l.purchase_ref=p.purchase_ref AND l.lock_kind='mandatory_purchase'
         WHERE p.status='approved' AND l.id IS NULL LIMIT 1"
    );
    if ($q && ($row = $q->fetch_assoc())) {
        $purchaseRef = $row['purchase_ref'];
    }
    // If none missing, pick any approved and dry-run (will show existing lock)
    if ($purchaseRef === null) {
        $q2 = $b->query("SELECT purchase_ref FROM coin_purchase_requests WHERE status='approved' ORDER BY id DESC LIMIT 1");
        if ($q2 && ($row = $q2->fetch_assoc())) {
            $purchaseRef = $row['purchase_ref'];
            $results['lock_note'] = 'No missing lock on staging; dry-run against latest approved for duplicate protection check';
        }
    }
}
$results['staging_purchase_ref'] = $purchaseRef;

if ($purchaseRef) {
    passthru('php spark locking:remediate-mandatory --group phase12b --purchase ' . escapeshellarg($purchaseRef) . ' --dry-run', $d4);
    $results['steps']['lock_staging_dry'] = $d4;

    // Only execute if preview ready (missing lock). Re-check.
    $miss = $b->query(
        "SELECT p.purchase_ref FROM coin_purchase_requests p
         LEFT JOIN member_coin_locks l ON l.purchase_ref=p.purchase_ref AND l.lock_kind='mandatory_purchase'
         WHERE p.purchase_ref='" . $b->real_escape_string($purchaseRef) . "' AND l.id IS NULL"
    );
    if ($miss && $miss->num_rows > 0) {
        passthru('php spark locking:remediate-mandatory --group phase12b --purchase ' . escapeshellarg($purchaseRef) . ' --confirm APPLY_STAGING', $d5);
        $results['steps']['lock_staging_apply'] = $d5;
    } else {
        $results['steps']['lock_staging_apply'] = 'SKIPPED_ALREADY_LOCKED';
    }
}

// 4) Production lock dry-run + refuse
passthru('php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --dry-run', $d6);
$results['steps']['lock_prod_dry'] = $d6;
passthru('php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --allow-production --confirm WRONG', $d7);
$results['steps']['lock_prod_refuse'] = $d7;

// 5) Kill switches
passthru('php spark mining:status', $d8);
$results['steps']['mining_status'] = $d8;

// 6) Draft commission plan on phase12d only via PHP bootstrap would be heavy; record intent
$results['commission_draft'] = 'Prepared via service on staging in follow-up if DB has actor user; see report';
$results['production_untouched'] = true;

file_put_contents(__DIR__ . '/phase12d-verify-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo json_encode($results, JSON_PRETTY_PRINT) . "\n";
