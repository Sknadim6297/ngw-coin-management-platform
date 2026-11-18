<?php

declare(strict_types=1);

/**
 * Phase 12F — create isolated DB from production dump for restore rehearsal + remediation proof.
 * NEVER writes to coin_platform.
 */

$mysql = 'C:\\xampp\\mysql\\bin\\mysql.exe';
$dump  = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
$private = __DIR__ . DIRECTORY_SEPARATOR . 'phase12f-private';
if (! is_dir($private)) {
    mkdir($private, 0750, true);
}

$evidence = [
    'started_at' => date('c'),
    'source_database' => 'coin_platform',
    'target_database' => 'coin_platform_phase12f',
    'backup_method' => null,
    'errors' => [],
];

$t0 = microtime(true);

if (! is_file($mysql) || ! is_file($dump)) {
    $evidence['errors'][] = 'mysqldump/mysql binaries not found under C:\\xampp\\mysql\\bin';
    file_put_contents(__DIR__ . '/phase12f-restore-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT));
    fwrite(STDERR, "mysql tools missing\n");
    exit(1);
}

// 1) Schema-only dump (always)
$schemaFile = $private . DIRECTORY_SEPARATOR . 'coin_platform-schema-' . date('Ymd-His') . '.sql';
$cmdSchema = '"' . $dump . '" --user=root --single-transaction --routines --triggers --no-data coin_platform';
exec($cmdSchema . ' > "' . $schemaFile . '" 2>&1', $schemaOut, $schemaCode);
$evidence['schema_dump'] = [
    'file' => basename($schemaFile),
    'bytes' => is_file($schemaFile) ? filesize($schemaFile) : 0,
    'exit' => $schemaCode,
];

// 2) Full dump attempt (restricted private dir; not public web)
$dataFile = $private . DIRECTORY_SEPARATOR . 'coin_platform-full-' . date('Ymd-His') . '.sql';
$cmdFull = '"' . $dump . '" --user=root --single-transaction --routines --triggers --hex-blob coin_platform';
exec($cmdFull . ' > "' . $dataFile . '" 2>&1', $fullOut, $fullCode);
$evidence['full_dump'] = [
    'file' => basename($dataFile),
    'bytes' => is_file($dataFile) ? filesize($dataFile) : 0,
    'exit' => $fullCode,
    'note' => 'Stored under writable/phase12f-private (not for public web). Contains operational DB content; do not publish.',
];
$evidence['backup_method'] = $fullCode === 0 ? 'mysqldump_full_single_transaction' : 'mysqldump_schema_only_fallback';
$evidence['backup_timestamp'] = date('c');

// Create isolated DB
passthru('"' . $mysql . '" -u root -e "DROP DATABASE IF EXISTS coin_platform_phase12f; CREATE DATABASE coin_platform_phase12f CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"', $createCode);
$evidence['create_db_exit'] = $createCode;

$restoreFile = ($fullCode === 0 && is_file($dataFile) && filesize($dataFile) > 1000) ? $dataFile : $schemaFile;
$evidence['restore_source'] = basename($restoreFile);
$tRestore = microtime(true);
passthru('"' . $mysql . '" -u root coin_platform_phase12f < "' . $restoreFile . '"', $restoreCode);
$evidence['restore_exit'] = $restoreCode;
$evidence['restore_duration_sec'] = round(microtime(true) - $tRestore, 3);

$mysqli = new mysqli('localhost', 'root', '', 'coin_platform_phase12f');
$tableCount = 0;
$hasSeq = false;
$hasLocks = false;
$hasPurchases = false;
$hasBalances = false;
if (! $mysqli->connect_errno) {
    $res = $mysqli->query('SHOW TABLES');
    while ($res && ($row = $res->fetch_row())) {
        $tableCount++;
        $t = $row[0];
        if ($t === 'member_id_sequences') {
            $hasSeq = true;
        }
        if ($t === 'member_coin_locks') {
            $hasLocks = true;
        }
        if ($t === 'coin_purchase_requests') {
            $hasPurchases = true;
        }
        if ($t === 'member_coin_balances') {
            $hasBalances = true;
        }
    }
    $checksum = [];
    foreach (['member_profiles', 'member_id_sequences', 'coin_purchase_requests', 'member_coin_balances', 'member_coin_locks'] as $t) {
        $r = $mysqli->query("SHOW TABLES LIKE '" . $mysqli->real_escape_string($t) . "'");
        if ($r && $r->num_rows) {
            $c = $mysqli->query('SELECT COUNT(*) c FROM `' . $t . '`')->fetch_assoc();
            $checksum[$t] = (int) ($c['c'] ?? 0);
        }
    }
    $evidence['integrity'] = [
        'table_count' => $tableCount,
        'has_member_id_sequences' => $hasSeq,
        'has_member_coin_locks' => $hasLocks,
        'has_coin_purchase_requests' => $hasPurchases,
        'has_member_coin_balances' => $hasBalances,
        'row_counts' => $checksum,
    ];
    $mysqli->close();
} else {
    $evidence['errors'][] = 'Could not connect to restored DB: ' . $mysqli->connect_error;
}

$evidence['total_duration_sec'] = round(microtime(true) - $t0, 3);
$evidence['complete_restore_rehearsal'] = ($restoreCode === 0 && $fullCode === 0 && $tableCount > 0);
$evidence['finished_at'] = date('c');

file_put_contents(__DIR__ . '/phase12f-restore-evidence.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
echo json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(($restoreCode === 0 && $createCode === 0) ? 0 : 1);
