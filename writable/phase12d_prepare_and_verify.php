<?php

declare(strict_types=1);

/**
 * Phase 12D isolated verification on coin_platform_phase12d (never coin_platform).
 */

$mysqli = new mysqli('localhost', 'root', '');
if ($mysqli->connect_error) {
    fwrite(STDERR, $mysqli->connect_error);
    exit(1);
}

$target = 'coin_platform_phase12d';
$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
echo "DB {$target} ensured\n";

// Schema-only clone from production (no customer secrets/data dump of balances beyond structure).
$dumpFile = __DIR__ . '/phase12d-schema.sql';
$cmd = 'mysqldump -u root --no-data --routines=false --triggers=false coin_platform > '
    . escapeshellarg($dumpFile);
exec($cmd, $out, $code);
echo "mysqldump_schema_exit={$code}\n";
if ($code !== 0 || ! is_file($dumpFile) || filesize($dumpFile) < 100) {
    echo "BLOCKED: mysqldump schema failed\n";
    exit(2);
}

$mysqli->query("DROP DATABASE `{$target}`");
$mysqli->query("CREATE DATABASE `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
$import = 'mysql -u root ' . escapeshellarg($target) . ' < ' . escapeshellarg($dumpFile);
exec($import, $out2, $code2);
echo "mysql_import_exit={$code2}\n";

// Minimal fixtures: sequence at 981233, ROOT + NGW000001 profiles without passwords/secrets
$d = new mysqli('localhost', 'root', '', $target);
$now = date('Y-m-d H:i:s');
$d->query("INSERT INTO member_id_sequences (prefix, last_number, created_at, updated_at) VALUES ('NGW', 981233, '{$now}', '{$now}')");
// users + profiles minimal if tables exist
if ($d->query("SHOW TABLES LIKE 'users'")->num_rows) {
    $d->query("INSERT INTO users (username, status, active, created_at, updated_at) VALUES ('root_fix', 'active', 1, '{$now}', '{$now}')");
    $rootUid = (int) $d->insert_id;
    $d->query("INSERT INTO users (username, status, active, created_at, updated_at) VALUES ('ngw1_fix', 'active', 1, '{$now}', '{$now}')");
    $mUid = (int) $d->insert_id;
    $d->query("INSERT INTO member_profiles (user_id, member_id, member_name, account_status, is_root_sponsor, created_at, updated_at) VALUES
        ({$rootUid}, 'ROOT0001', 'Root', 'active', 1, '{$now}', '{$now}'),
        ({$mUid}, 'NGW000001', 'Test Member', 'active', 0, '{$now}', '{$now}')");
}

// Counts for restore evidence
$tables = [];
$r = $d->query('SHOW TABLES');
while ($row = $r->fetch_row()) {
    $tables[] = $row[0];
}
file_put_contents(__DIR__ . '/phase12d-restore-evidence.json', json_encode([
    'database'     => $target,
    'table_count'  => count($tables),
    'tables'       => $tables,
    'schema_dump'  => basename($dumpFile),
    'schema_bytes' => filesize($dumpFile),
    'generated_at' => date('c'),
    'note'         => 'Schema-only restore into isolated DB; live coin_platform untouched',
], JSON_PRETTY_PRINT));

echo 'tables=' . count($tables) . "\n";
echo "OK\n";
