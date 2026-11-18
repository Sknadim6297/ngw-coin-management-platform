<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use CodeIgniter\Events\Events;
use Config\Database;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Phase 13C — production-snapshot rehearsal, LOCK1000 audit, migration readiness, scheduler/monitoring readiness.
 *
 * SAFETY MODEL
 *  - The live database `coin_platform` is touched ONLY through a temporary, randomly-passworded MySQL account that is
 *    granted SELECT on that schema alone (SHOW GRANTS is verified before use; the account is dropped afterwards).
 *  - The backup is an EXISTING Phase 12F mysqldump. Nothing is extracted from production here.
 *  - The dump is restored only into marker-guarded isolated databases created by this phase:
 *      coin_platform_phase13c_snapshot (audit-only, SELECT-only account) and coin_platform_phase13c (disposable clone).
 *    A database without our marker table that is not empty is never touched.
 *  - Every mutation (migrations, LOCK1000 what-if) runs against the clone only, through the real Spark command.
 */
class Phase13CVerificationService
{
    public const SNAP_GROUP   = 'phase13c_snapshot';
    public const SNAP_DB      = 'coin_platform_phase13c_snapshot';
    public const CLONE_GROUP  = 'phase13c';
    public const CLONE_DB     = 'coin_platform_phase13c';
    public const LIVE_DB      = 'coin_platform';
    public const LIVE_RO_GROUP = 'phase13c_live_ro';
    public const LIVE_RO_USER = 'p13c_live_ro';
    public const MARKER       = 'phase13c_marker';
    public const DUMP_REL     = 'writable/phase12f-private/coin_platform-full-20261117-134752.sql';
    public const EVIDENCE_REL = 'writable/phase12f-restore-evidence.json';
    public const PURCHASE     = 'PUR-10D7174B7FF83054';
    public const MEMBER       = 'NGW000001';
    public const MIGRATIONS   = ['2026-11-17-993001', '2026-11-18-994001'];

    /** @var list<array{id: string, ok: bool, detail: string}> */
    protected array $checks = [];
    /** @var array<string, mixed> */
    protected array $out = [];
    protected ?string $liveRoPassword = null;

    /** @return array<string, mixed> */
    public function run(): array
    {
        $started = microtime(true);
        $this->out = [
            'phase'      => '13C',
            'started_at' => gmdate('c'),
            'notes'      => 'Preparation only. No production posting, migration, remediation, scheduler or deployment.',
        ];
        $admin = null;

        try {
            $admin = $this->adminConnection();
            $this->out['preflight'] = $this->preflight($admin);

            // ---- live read-only fingerprint BEFORE
            $this->createLiveReadOnlyUser($admin);
            $live = $this->liveRoConnection();
            $this->out['live_fingerprint_before'] = $this->fingerprint($live, self::LIVE_DB);

            // ---- stage A: backup source verification
            $this->out['backup_source'] = $this->verifyBackupSource();
            $backupOk = $this->out['backup_source']['classification'] !== 'BLOCKED';

            if ($backupOk) {
                // ---- stage B/C: isolated restore + validation
                $this->out['snapshot_restore'] = $this->restoreInto($admin, self::SNAP_DB, 'snapshot (audit-only)');
                $this->out['clone_restore']    = $this->restoreInto($admin, self::CLONE_DB, 'disposable clone');
                $this->grantAccounts($admin);

                $snap  = $this->connect(self::SNAP_GROUP);
                $clone = $this->connect(self::CLONE_GROUP);

                $tables = $this->tables($snap, self::SNAP_DB);
                $baseSnapSums  = $this->checksums($snap, self::SNAP_DB, $tables);
                $baseCloneSums = $this->checksums($clone, self::CLONE_DB, $this->tables($clone, self::CLONE_DB));
                $this->out['snapshot_validation'] = $this->validateRestore($admin, $snap, self::SNAP_DB, $live, $baseSnapSums, $baseCloneSums);

                // ---- stage D: historical ownership rehearsal (read-only)
                $this->out['historical_audit'] = $this->withDefault(self::SNAP_GROUP, self::SNAP_DB, fn (): array => $this->historicalAudit($snap));

                // ---- stage E: LOCK1000 audit (snapshot, dry-run only)
                $this->out['lock1000_audit'] = $this->lock1000Audit($snap);

                // ---- stage F: migration readiness on the clone
                $this->out['migration_readiness'] = $this->migrationReadiness($admin, $clone, $baseCloneSums);

                // ---- stage G: what-if remediation on the clone
                $this->out['lock1000_simulation'] = $this->lock1000Simulation($clone);

                // ---- stage H: legacy commands (clone)
                $this->out['legacy_commands'] = $this->legacyCommandSafety($clone);

                // ---- stage I: monitoring (mock alerts, clone)
                $this->out['monitoring'] = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, fn (): array => $this->monitoring());

                // ---- stage J: rollback proof — restore the clone again and compare to the pristine baseline
                $this->out['rollback_proof'] = $this->rollbackProof($admin, $baseSnapSums);

                // ---- snapshot must still be bit-identical
                $afterSnap = $this->checksums($snap, self::SNAP_DB, $tables);
                $this->out['snapshot_pristine_after_audit'] = $this->assertTrue('snapshot_pristine_after_audit', $afterSnap === $baseSnapSums, 'CHECKSUM TABLE of all ' . count($tables) . ' snapshot tables identical before and after all audits.');
            } else {
                $this->out['snapshot_restore'] = ['classification' => 'BLOCKED', 'reason' => 'No verified backup.'];
            }

            // ---- stage K: worksheet + scheduler readiness (read-only live)
            $this->out['live_readiness'] = $this->withDefault(self::LIVE_RO_GROUP, self::LIVE_DB, fn (): array => $this->liveReadiness());

            // ---- live read-only fingerprint AFTER
            $this->out['live_fingerprint_after'] = $this->fingerprint($live, self::LIVE_DB);
            $this->out['live_unchanged']         = $this->assertTrue('live_unchanged', $this->out['live_fingerprint_before'] === $this->out['live_fingerprint_after'], 'Live fingerprint (counts, balances, switches, latest migration) identical before and after Phase 13C.');
        } catch (Throwable $e) {
            $this->out['fatal'] = get_class($e) . ': ' . $e->getMessage();
            $this->checks[]     = ['id' => 'fatal', 'ok' => false, 'detail' => $e->getMessage()];
        } finally {
            config('Database')->defaultGroup = 'default';
            try {
                if ($admin instanceof mysqli) {
                    $admin->query("DROP USER IF EXISTS '" . self::LIVE_RO_USER . "'@'localhost'");
                    $this->out['live_ro_account_dropped'] = true;
                }
            } catch (Throwable) {
                $this->out['live_ro_account_dropped'] = false;
            }
        }

        $this->out['checks']      = $this->checks;
        $this->out['pass']        = count(array_filter($this->checks, static fn (array $c): bool => $c['ok']));
        $this->out['fail']        = count(array_filter($this->checks, static fn (array $c): bool => ! $c['ok']));
        $this->out['duration_s']  = round(microtime(true) - $started, 2);
        $this->out['finished_at'] = gmdate('c');
        unset($this->out['live_ro_password']);

        return $this->out;
    }

    // ===================================================================== plumbing

    /** @return array{ok: bool, detail: string} */
    protected function assertTrue(string $id, bool $ok, string $detail): array
    {
        $this->checks[] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];

        return ['ok' => $ok, 'detail' => $detail];
    }

    protected function adminConnection(): mysqli
    {
        $a = config('Database')->default;
        $c = @mysqli_connect((string) $a['hostname'], (string) $a['username'], (string) $a['password'], '', (int) ($a['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot connect to MySQL for provisioning.');
        }
        $c->set_charset('utf8mb4');

        return $c;
    }

    protected function connect(string $group): mysqli
    {
        $g = config('Database')->{$group};
        $c = @mysqli_connect((string) $g['hostname'], (string) $g['username'], (string) $g['password'], (string) $g['database'], (int) ($g['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot connect with group ' . $group . ' (database ' . $g['database'] . ').');
        }
        $c->set_charset('utf8mb4');

        return $c;
    }

    /** @return list<array<string, mixed>> */
    protected function q(mysqli $c, string $sql): array
    {
        $r = $c->query($sql);

        return $r instanceof \mysqli_result ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    protected function one(mysqli $c, string $sql): ?string
    {
        $rows = $this->q($c, $sql);
        if ($rows === []) {
            return null;
        }
        $v = array_values($rows[0])[0];

        return $v === null ? null : (string) $v;
    }

    /** @return list<string> */
    protected function tables(mysqli $c, string $db): array
    {
        return array_values(array_map(static fn (array $r): string => (string) $r['n'], $this->q($c, "SELECT table_name n FROM information_schema.tables WHERE table_schema = '" . $c->real_escape_string($db) . "' AND table_type = 'BASE TABLE' AND table_name <> '" . self::MARKER . "' ORDER BY 1")));
    }

    /** @return array<string, string> table => checksum */
    protected function checksums(mysqli $c, string $db, array $tables): array
    {
        $out = [];
        foreach (array_chunk($tables, 25) as $chunk) {
            $list = implode(',', array_map(static fn (string $t): string => '`' . $db . '`.`' . $t . '`', $chunk));
            foreach ($this->q($c, 'CHECKSUM TABLE ' . $list) as $r) {
                $out[(string) explode('.', (string) $r['Table'], 2)[1]] = (string) $r['Checksum'];
            }
        }
        ksort($out);

        return $out;
    }

    /** Run $fn with the application's DEFAULT connection bound to $group (verified by database name). */
    protected function withDefault(string $group, string $expectedDb, callable $fn): array
    {
        $dbc = config('Database');
        if ($group === self::LIVE_RO_GROUP) {
            if ($this->liveRoPassword === null) {
                throw new RuntimeException('Live read-only account not available.');
            }
            $d = $dbc->default;
            $dbc->{$group} = array_merge($d, ['username' => self::LIVE_RO_USER, 'password' => $this->liveRoPassword, 'database' => self::LIVE_DB]);
        }
        $dbc->defaultGroup = $group;
        Database::connect($group, false);
        db_connect($group, false);
        try {
            $actual = (string) db_connect()->getDatabase();
            if ($actual !== $expectedDb) {
                throw new RuntimeException('Default connection bound to "' . $actual . '", expected "' . $expectedDb . '".');
            }

            return $fn();
        } finally {
            $dbc->defaultGroup = 'default';
        }
    }

    /**
     * @param list<string> $args
     * @param array<string, string> $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected function spark(array $args, array $env = []): array
    {
        $cmd  = array_merge([PHP_BINARY, ROOTPATH . 'spark'], $args);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, array_merge(getenv(), $env));
        if (! is_resource($proc)) {
            return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    // ===================================================================== preflight / live read-only

    /** @return array<string, mixed> */
    protected function preflight(mysqli $admin): array
    {
        $d    = config('Database')->default;
        $info = service('financialSandboxService')->inspectConnection('default');
        $mysql = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        $data  = (string) $this->one($admin, 'SELECT @@datadir');
        $this->assertTrue('mysql_cli_present', is_file($mysql), 'mysql client: ' . (is_file($mysql) ? 'found' : 'MISSING'));
        $free  = $data !== '' && is_dir($data) ? (float) disk_free_space($data) : 0.0;
        $this->assertTrue('disk_space', $free > 500 * 1024 * 1024, 'free disk at datadir: ' . round($free / 1048576) . ' MB');

        $r = [
            'configured_default' => ['hostname' => $d['hostname'], 'port' => $d['port'] ?? 3306, 'database' => $d['database']],
            'server'             => ['hostname' => $this->one($admin, 'SELECT @@hostname'), 'version' => $this->one($admin, 'SELECT @@version'), 'port' => $this->one($admin, 'SELECT @@port')],
            'classification'     => 'LOCAL XAMPP OPERATIONAL COPY of the application database (' . $d['database'] . '). It is NOT proven to be the deployed production host: "localhost" is not treated as safe, so the live database is only ever read through a verified SELECT-only account.',
            'default_group_is_live' => $d['database'] === self::LIVE_DB,
            'sandbox_view'       => ['is_allowed_isolated' => $info['is_allowed'], 'is_forbidden' => $info['is_forbidden']],
            'environment'        => ENVIRONMENT,
            'mysql_client'       => $mysql,
            'kill_switches'      => (new ProductionSafetyGate())->killSwitchSnapshot(),
        ];
        $cfg = config('Platform');
        $seven = [];
        foreach (['miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled', 'commissionPostingEnabled', 'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled'] as $k) {
            $seven[$k] = (bool) $cfg->{$k};
        }
        $r['kill_switches'] = $seven;
        $switches = array_filter($seven, static fn ($v): bool => $v === true);
        $this->assertTrue('kill_switches_all_false', $switches === [], 'All seven production switches false: ' . json_encode($r['kill_switches']));

        return $r;
    }

    protected function createLiveReadOnlyUser(mysqli $admin): void
    {
        $this->liveRoPassword = bin2hex(random_bytes(16));
        $u = "'" . self::LIVE_RO_USER . "'@'localhost'";
        $admin->query('CREATE USER IF NOT EXISTS ' . $u . " IDENTIFIED BY '" . $this->liveRoPassword . "'");
        $admin->query('ALTER USER ' . $u . " IDENTIFIED BY '" . $this->liveRoPassword . "'");
        $admin->query('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $u);
        $admin->query('GRANT SELECT ON `' . self::LIVE_DB . '`.* TO ' . $u);
        $grants = array_map(static fn (array $r): string => (string) array_values($r)[0], $this->q($admin, 'SHOW GRANTS FOR ' . $u));
        $bad    = array_values(array_filter($grants, static fn (string $g): bool => ! preg_match('/^GRANT (USAGE ON \*\.\*|SELECT ON `' . self::LIVE_DB . '`\.\*) TO /', $g)));
        $this->assertTrue('live_connection_read_only', $bad === [] && count($grants) >= 2, 'Live account grants verified SELECT-only: ' . json_encode(array_map(static fn (string $g): string => preg_replace('/IDENTIFIED BY.*/', '', $g) ?? $g, $grants)));
        if ($bad !== []) {
            throw new RuntimeException('Live account is not read-only; aborting (production inspection NOT VERIFIED).');
        }
        $this->out['live_access'] = ['account' => self::LIVE_RO_USER, 'privileges' => 'SELECT on ' . self::LIVE_DB . '.* only', 'password' => 'random, never logged, account dropped afterwards'];
    }

    protected function liveRoConnection(): mysqli
    {
        $d = config('Database')->default;
        $c = @mysqli_connect((string) $d['hostname'], self::LIVE_RO_USER, (string) $this->liveRoPassword, self::LIVE_DB, (int) ($d['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot open the read-only live connection.');
        }

        return $c;
    }

    /** @return array<string, mixed> */
    protected function fingerprint(mysqli $c, string $db): array
    {
        $tables = $this->tables($c, $db);
        $has    = static fn (string $t): bool => in_array($t, $tables, true);
        $t      = static fn (string $n): string => '`' . $db . '`.`' . $n . '`';
        $cnt    = fn (string $n): ?int => $has($n) ? (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t($n)) : null;
        $sum    = fn (string $n, string $col): ?string => $has($n) ? (string) ($this->one($c, 'SELECT COALESCE(SUM(`' . $col . '`),0) FROM ' . $t($n)) ?? '0') : null;

        $rowCounts = [];
        foreach ($tables as $n) {
            $rowCounts[$n] = (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t($n));
        }

        return [
            'database'              => $db,
            'table_count'           => count($tables),
            'members'               => $cnt('member_profiles'),
            'member_ids'            => $has('member_profiles') ? array_map(static fn (array $r): string => (string) $r['member_id'], $this->q($c, 'SELECT member_id FROM ' . $t('member_profiles') . ' ORDER BY id')) : null,
            'member_id_sequences'   => $has('member_id_sequences') ? $this->q($c, 'SELECT prefix, last_number FROM ' . $t('member_id_sequences') . ' ORDER BY prefix') : null,
            'approved_purchases'    => $has('coin_purchase_requests') ? (int) $this->one($c, "SELECT COUNT(*) FROM " . $t('coin_purchase_requests') . " WHERE status = 'approved'") : null,
            'owned_total'           => $sum('member_coin_balances', 'balance'),
            'available_total'       => $sum('member_coin_balances', 'available_balance'),
            'locked_total'          => $sum('member_coin_balances', 'locked_balance'),
            'reserved_total'        => $sum('member_coin_balances', 'reserved_balance'),
            'ownership_ledger_rows' => $cnt('member_coin_ownership_transactions'),
            'locks_total'           => $cnt('member_coin_locks'),
            'mandatory_locks'       => $has('member_coin_locks') ? (int) $this->one($c, 'SELECT COUNT(*) FROM ' . $t('member_coin_locks') . " WHERE lock_kind = '" . \App\Services\CoinLockingService::KIND_MANDATORY_PURCHASE . "'") : null,
            'mining_wallet_total'   => $sum('mining_wallets', 'available_balance'),
            'mining_ledger_rows'    => $cnt('mining_wallet_ledger'),
            'commission_ledger_rows' => $cnt('commission_wallet_ledger_entries'),
            'latest_migration'      => $has('migrations') ? $this->one($c, 'SELECT version FROM ' . $t('migrations') . ' ORDER BY id DESC LIMIT 1') : null,
            'switches'              => (new ProductionSafetyGate())->killSwitchSnapshot(),
            'row_counts'            => $rowCounts,
        ];
    }

    // ===================================================================== A. backup source

    /** @return array<string, mixed> */
    protected function verifyBackupSource(): array
    {
        $dump = ROOTPATH . self::DUMP_REL;
        $evid = ROOTPATH . self::EVIDENCE_REL;
        $r    = ['file' => self::DUMP_REL, 'classification' => 'BLOCKED', 'findings' => []];

        if (! is_file($dump)) {
            $this->assertTrue('backup_present', false, 'No existing authorized backup found; STOPPING snapshot rehearsal (nothing is fabricated or extracted).');
            $r['findings'][] = 'backup file missing';

            return $r;
        }
        $this->assertTrue('backup_present', true, 'Existing Phase 12F full dump found.');

        $bytes = (int) filesize($dump);
        $r += ['bytes' => $bytes, 'sha256' => hash_file('sha256', $dump), 'file_mtime_utc' => gmdate('c', (int) filemtime($dump))];
        $evidence = is_file($evid) ? json_decode((string) file_get_contents($evid), true) : null;
        $r['evidence_file'] = is_array($evidence) ? 'present' : 'missing';
        $this->assertTrue('backup_size_matches_evidence', is_array($evidence) && (int) ($evidence['full_dump']['bytes'] ?? -1) === $bytes, 'Dump size ' . $bytes . ' bytes equals the Phase 12F evidence record.');

        $header = ['host' => null, 'database' => null, 'server' => null, 'completed' => null];
        $tableCreates = 0;
        $danger = [];
        $inserts = [];
        $fh = fopen($dump, 'rb');
        $lineNo = 0;
        while (($line = fgets($fh)) !== false) {
            $lineNo++;
            if ($lineNo < 12 && preg_match('/^-- Host: (\S+)\s+Database: (\S+)/', $line, $m)) {
                $header['host'] = $m[1];
                $header['database'] = $m[2];
            }
            if ($lineNo < 12 && preg_match('/^-- Server version\s+(.+)$/', $line, $m)) {
                $header['server'] = trim($m[1]);
            }
            if (preg_match('/^-- Dump completed on (.+)$/', $line, $m)) {
                $header['completed'] = trim($m[1]);
            }
            if (preg_match('/^CREATE TABLE `/', $line)) {
                $tableCreates++;
            }
            if (preg_match('/^\s*(USE\s|CREATE\s+DATABASE|DROP\s+DATABASE|CREATE\s+USER|DROP\s+USER|GRANT\s|SET\s+PASSWORD|ALTER\s+USER)/i', $line)) {
                $danger[] = 'line ' . $lineNo . ': ' . substr(trim($line), 0, 40);
            }
            if (preg_match('/^INSERT INTO `([a-z0-9_]+)`/', $line, $m)) {
                $inserts[$m[1]] = ($inserts[$m[1]] ?? 0) + 1;
            }
        }
        fclose($fh);

        $r['header']                = $header;
        $r['format']                = 'mysqldump (single-transaction) full schema+data, plain SQL';
        $r['create_table_count']    = $tableCreates;
        $r['tables_with_data']      = count($inserts);
        $r['dangerous_statements']  = $danger;
        $r['evidence_backup_timestamp'] = $evidence['backup_timestamp'] ?? null;

        $this->assertTrue('backup_complete_marker', $header['completed'] !== null, 'Dump trailer "Dump completed on ' . ($header['completed'] ?? '?') . '" present.');
        $this->assertTrue('backup_no_dangerous_statements', $danger === [], 'No USE / CREATE|DROP DATABASE / account statements in the dump.');
        $this->assertTrue('backup_source_identity', $header['database'] === self::LIVE_DB && $header['host'] === 'localhost', 'Dump header: host=' . $header['host'] . ' database=' . $header['database'] . ' server=' . $header['server'] . ' (local XAMPP copy).');
        $this->assertTrue('backup_contains_data', $tableCreates >= 70 && isset($inserts['member_profiles'], $inserts['coin_purchase_requests'], $inserts['member_coin_ownership_transactions']), $tableCreates . ' tables; data present for ' . count($inserts) . ' tables (not schema-only).');
        $this->assertTrue('backup_not_in_public_path', ! str_contains(self::DUMP_REL, 'public'), 'Dump lives in writable/phase12f-private (git-ignored, outside the web root).');

        $ok = $header['completed'] !== null && $danger === [] && $tableCreates >= 70 && isset($inserts['member_profiles']);
        $r['classification'] = $ok ? 'SOURCE_VERIFIED_LOCAL_COPY' : 'BLOCKED';
        $r['authorization']  = 'Created by the user-requested Phase 12F backup/restore task (evidence record present). A written statement that this file represents the DEPLOYED production host is NOT available.';

        return $r;
    }

    // ===================================================================== B/C. restore + validation

    /** @return array<string, mixed> */
    protected function restoreInto(mysqli $admin, string $db, string $purpose): array
    {
        $e = $admin->real_escape_string($db);
        $exists = $this->q($admin, "SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $e . "'") !== [];
        $action = 'created';
        if ($exists) {
            $names = array_map(static fn (array $r): string => (string) $r['n'], $this->q($admin, "SELECT table_name n FROM information_schema.tables WHERE table_schema = '" . $e . "'"));
            if (in_array(self::MARKER, $names, true)) {
                $action = 'reused (marker present; own tables wiped before restore)';
                $admin->query('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($names as $n) {
                    if ($n !== self::MARKER) {
                        $admin->query('DROP TABLE IF EXISTS `' . $db . '`.`' . $n . '`');
                    }
                }
                $admin->query('SET FOREIGN_KEY_CHECKS = 1');
            } elseif ($names === []) {
                $action = 'reused (empty database)';
            } else {
                throw new RuntimeException('Database ' . $db . ' exists, has no Phase 13C marker and is not empty. Refusing to touch or drop it.');
            }
        } else {
            $admin->query('CREATE DATABASE `' . $db . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
        $admin->query('CREATE TABLE IF NOT EXISTS `' . $db . '`.`' . self::MARKER . "` (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(160) NOT NULL, created_at DATETIME NOT NULL)");
        if ($this->one($admin, 'SELECT COUNT(*) FROM `' . $db . '`.`' . self::MARKER . '`') === '0') {
            $admin->query('INSERT INTO `' . $db . '`.`' . self::MARKER . "` (note, created_at) VALUES ('created by Phase 13C; " . $admin->real_escape_string($purpose) . "; safe to wipe', NOW())");
        }

        $a   = config('Database')->default;
        $env = getenv();
        if ((string) $a['password'] !== '') {
            $env['MYSQL_PWD'] = (string) $a['password'];
        }
        $mysql = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysql.exe';
        $t0    = microtime(true);
        $proc  = proc_open([$mysql, '--no-defaults', '-u' . $a['username'], '-h' . $a['hostname'], '-P' . ($a['port'] ?? 3306), '--default-character-set=utf8mb4', $db], [0 => ['file', ROOTPATH . self::DUMP_REL, 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        $stderr = '';
        if (is_resource($proc)) {
            stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
        } else {
            $exit = -1;
        }
        $dur = round(microtime(true) - $t0, 2);
        $this->assertTrue('restore_' . $db, $exit === 0, 'mysql restore into ' . $db . ' exit=' . $exit . ' in ' . $dur . 's' . ($exit === 0 ? '' : ' stderr=' . substr($stderr, 0, 200)));

        return ['database' => $db, 'purpose' => $purpose, 'action' => $action, 'exit' => $exit, 'duration_s' => $dur, 'stderr' => $exit === 0 ? '' : substr($stderr, 0, 300)];
    }

    protected function grantAccounts(mysqli $admin): void
    {
        $dbc = config('Database');
        foreach ([[self::SNAP_GROUP, self::SNAP_DB, 'SELECT'], [self::CLONE_GROUP, self::CLONE_DB, 'ALL PRIVILEGES']] as [$group, $db, $priv]) {
            $g    = $dbc->{$group};
            $user = $admin->real_escape_string((string) $g['username']);
            $host = $admin->real_escape_string((string) $g['hostname']);
            $pass = $admin->real_escape_string((string) $g['password']);
            $u    = "'" . $user . "'@'" . $host . "'";
            $admin->query('CREATE USER IF NOT EXISTS ' . $u . " IDENTIFIED BY '" . $pass . "'");
            $admin->query('ALTER USER ' . $u . " IDENTIFIED BY '" . $pass . "'");
            $admin->query('REVOKE ALL PRIVILEGES, GRANT OPTION FROM ' . $u);
            $admin->query('GRANT ' . $priv . ' ON `' . $db . '`.* TO ' . $u);
            $admin->query('FLUSH PRIVILEGES');
        }
        $grants = array_map(static fn (array $r): string => (string) array_values($r)[0], $this->q($admin, "SHOW GRANTS FOR '" . config('Database')->{self::SNAP_GROUP}['username'] . "'@'localhost'"));
        $bad    = array_filter($grants, static fn (string $g): bool => ! preg_match('/^GRANT (USAGE ON \*\.\*|SELECT ON `' . self::SNAP_DB . '`\.\*) TO /', $g));
        $this->assertTrue('snapshot_account_select_only', $bad === [], 'Snapshot account privileges verified SELECT-only on ' . self::SNAP_DB . '.');
    }

    /**
     * @param array<string, string> $snapSums
     * @param array<string, string> $cloneSums
     * @return array<string, mixed>
     */
    protected function validateRestore(mysqli $admin, mysqli $snap, string $db, mysqli $live, array $snapSums, array $cloneSums): array
    {
        $fp   = $this->fingerprint($snap, $db);
        $evid = json_decode((string) file_get_contents(ROOTPATH . self::EVIDENCE_REL), true);
        $exp  = $evid['integrity']['row_counts'] ?? [];
        $r    = ['fingerprint' => $fp];

        $this->assertTrue('restore_table_count', $fp['table_count'] === (int) ($evid['integrity']['table_count'] ?? 73), 'table_count=' . $fp['table_count'] . ' (evidence ' . ($evid['integrity']['table_count'] ?? '?') . ')');
        $rowOk = true;
        foreach ($exp as $table => $n) {
            $rowOk = $rowOk && (int) ($fp['row_counts'][$table] ?? -1) === (int) $n;
        }
        $this->assertTrue('restore_row_counts_vs_evidence', $rowOk, 'Row counts of ' . implode(',', array_keys($exp)) . ' equal the Phase 12F evidence.');
        $this->assertTrue('restore_schema_version', $fp['latest_migration'] === '2026-11-17-992001', 'latest migration in snapshot=' . $fp['latest_migration']);
        $this->assertTrue('restore_clone_identical_to_snapshot', $snapSums === $cloneSums, 'Snapshot and clone are table-for-table identical (CHECKSUM TABLE on ' . count($snapSums) . ' tables).');

        // table integrity (root, read-only operation)
        $badTables = [];
        foreach ($this->tables($admin, $db) as $t) {
            foreach ($this->q($admin, 'CHECK TABLE `' . $db . '`.`' . $t . '` QUICK') as $row) {
                if (($row['Msg_type'] ?? '') === 'status' && ! in_array(strtolower((string) $row['Msg_text']), ['ok', 'table is already up to date'], true)) {
                    $badTables[] = $t . ':' . $row['Msg_text'];
                }
            }
        }
        $r['check_table_failures'] = $badTables;
        $this->assertTrue('restore_check_table', $badTables === [], 'CHECK TABLE on all tables: ' . ($badTables === [] ? 'OK' : implode(',', $badTables)));

        // foreign-key orphans
        $orphans = [];
        $fks = $this->q($snap, "SELECT k.CONSTRAINT_NAME cn, k.TABLE_NAME tn, k.COLUMN_NAME cc, k.REFERENCED_TABLE_NAME rt, k.REFERENCED_COLUMN_NAME rc FROM information_schema.KEY_COLUMN_USAGE k WHERE k.TABLE_SCHEMA = '" . $db . "' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME, k.ORDINAL_POSITION");
        $grouped = [];
        foreach ($fks as $f) {
            $grouped[$f['tn'] . '.' . $f['cn']][] = $f;
        }
        foreach ($grouped as $key => $cols) {
            $on = implode(' AND ', array_map(static fn (array $f): string => 'c.`' . $f['cc'] . '` = p.`' . $f['rc'] . '`', $cols));
            $nn = implode(' AND ', array_map(static fn (array $f): string => 'c.`' . $f['cc'] . '` IS NOT NULL', $cols));
            $n  = (int) $this->one($snap, 'SELECT COUNT(*) FROM `' . $db . '`.`' . $cols[0]['tn'] . '` c LEFT JOIN `' . $db . '`.`' . $cols[0]['rt'] . '` p ON ' . $on . ' WHERE ' . $nn . ' AND p.`' . $cols[0]['rc'] . '` IS NULL');
            if ($n > 0) {
                $orphans[$key] = $n;
            }
        }
        $r['foreign_keys_checked'] = count($grouped);
        $r['fk_orphans']           = $orphans;
        $this->assertTrue('restore_fk_consistency', $orphans === [], count($grouped) . ' foreign keys checked; orphans: ' . json_encode($orphans));

        // duplicate references
        $dups = [];
        foreach ([['coin_purchase_requests', 'purchase_ref'], ['member_coin_ownership_transactions', 'transaction_ref'], ['member_coin_ownership_transactions', 'idempotency_key'], ['member_coin_locks', 'lock_ref'], ['member_profiles', 'member_id'], ['mining_wallet_ledger', 'idempotency_key']] as [$tb, $col]) {
            if (! in_array($tb, $this->tables($snap, $db), true)) {
                continue;
            }
            $n = count($this->q($snap, 'SELECT `' . $col . '` FROM `' . $db . '`.`' . $tb . '` WHERE `' . $col . '` IS NOT NULL GROUP BY `' . $col . '` HAVING COUNT(*) > 1'));
            if ($n > 0) {
                $dups[$tb . '.' . $col] = $n;
            }
        }
        $r['duplicate_references'] = $dups;
        $this->assertTrue('restore_no_duplicate_references', $dups === [], 'Duplicate financial references: ' . json_encode($dups));

        // ownership chain: cached balance == last ledger row
        $mismatch = $this->q($snap, 'SELECT b.member_id FROM `' . $db . '`.member_coin_balances b WHERE b.balance <> COALESCE((SELECT t.new_balance FROM `' . $db . '`.member_coin_ownership_transactions t WHERE t.member_user_id = b.member_user_id ORDER BY t.id DESC LIMIT 1), 0) OR b.balance <> b.available_balance + b.locked_balance + b.reserved_balance');
        $r['balance_ledger_mismatch'] = array_map(static fn (array $x): string => (string) $x['member_id'], $mismatch);
        $this->assertTrue('restore_balance_vs_ledger', $mismatch === [], 'Cached balances equal the last ledger row and total = available+locked+reserved.');

        // relation to current live state (freshness of the snapshot)
        $liveFp = $this->fingerprint($live, self::LIVE_DB);
        $same   = [];
        foreach (['members', 'approved_purchases', 'owned_total', 'available_total', 'locked_total', 'ownership_ledger_rows', 'locks_total', 'mining_ledger_rows', 'commission_ledger_rows'] as $k) {
            $same[$k] = ['snapshot' => $fp[$k], 'live' => $liveFp[$k], 'equal' => $fp[$k] === $liveFp[$k]];
        }
        $r['snapshot_vs_live_now'] = $same;
        $r['snapshot_matches_live_core'] = ! in_array(false, array_column($same, 'equal'), true);

        $full = $fp['table_count'] === 73 && $rowOk && $badTables === [] && $orphans === [] && $dups === [] && $mismatch === [];
        $r['classification'] = $full ? 'FULL_DATA_RESTORE_VERIFIED' : 'RESTORE_FAILED_VALIDATION';
        $r['scope_caveat']   = 'Verified for the LOCAL operational copy only. This is not proof that the file equals the DEPLOYED production host.';
        $this->assertTrue('full_data_restore_verified', $full, 'Classification ' . $r['classification'] . ' (schema AND data restored and validated).');

        return $r;
    }

    // ===================================================================== D. historical ownership rehearsal

    /** @return array<string, mixed> */
    protected function historicalAudit(mysqli $snap): array
    {
        $svc      = new MiningEligibleOwnershipSnapshotService();
        $members  = $this->q($snap, 'SELECT user_id, member_id, account_status, is_root_sponsor, activated_at FROM member_profiles ORDER BY id');
        $purchases = $this->q($snap, "SELECT purchase_ref, member_user_id, member_id, coin_quantity, reviewed_at, ownership_transaction_ref FROM coin_purchase_requests WHERE status = 'approved'");
        $policies = ['T-CALENDAR-HALFUP' => MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP'), 'T-NEXTDAY-DOWN' => MiningCalculationPolicy::fixture('T-NEXTDAY-DOWN')];
        $utc      = new \DateTimeZone('UTC');
        $tz       = $svc->businessTimezone();

        $dates = [];
        foreach ($purchases as $p) {
            $d = (new \DateTimeImmutable((string) $p['reviewed_at'], $utc))->setTimezone($tz);
            foreach ([-1, 0, 1] as $off) {
                $dates[] = $d->modify(($off >= 0 ? '+' : '') . $off . ' day')->format('Y-m-d');
            }
        }
        $dates = array_values(array_unique($dates));
        sort($dates);

        $rows = [];
        $blocked = 0;
        foreach ($members as $m) {
            if ((int) $m['is_root_sponsor'] === 1) {
                $rows[] = ['member_id' => $m['member_id'], 'note' => 'root sponsor — excluded from the mining population'];
                continue;
            }
            foreach ($dates as $date) {
                foreach ($policies as $pname => $policy) {
                    try {
                        $cut = $svc->cutoff($date, $policy, null, true);
                        $res = $svc->snapshotBatch([(int) $m['user_id']], $cut['cutoff_utc'], $policy)[(int) $m['user_id']];
                    } catch (Throwable $e) {
                        $rows[] = ['member_id' => $m['member_id'], 'business_date' => $date, 'policy' => $pname, 'status' => 'ERROR', 'reasons' => [$e->getMessage()]];
                        $blocked++;
                        continue;
                    }
                    if ($res['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED) {
                        $blocked++;
                    }
                    $rows[] = [
                        'member_id' => $m['member_id'], 'account_status' => $m['account_status'], 'business_date' => $date, 'policy' => $pname,
                        'cutoff_utc' => $cut['cutoff_utc'], 'incomplete_day' => $cut['incomplete_day'], 'status' => $res['status'],
                        'owned' => $res['owned'], 'available' => $res['available'], 'locked' => $res['locked'], 'reserved' => $res['reserved'], 'eligible' => $res['eligible'],
                        'evidence_ref' => $res['evidence_ref'], 'reasons' => $res['reasons'],
                        'illustrative_daily_at_3pct_calendar31_half_up' => MiningMath::dailyAmount($res['eligible'], '3', 31, 8, MiningMath::ROUND_HALF_UP),
                    ];
                }
            }
        }

        // ledger evidence per approved purchase
        $evidence = [];
        foreach ($purchases as $p) {
            $credit = $this->q($snap, "SELECT transaction_ref, quantity, created_at FROM member_coin_ownership_transactions WHERE purchase_ref = '" . $snap->real_escape_string((string) $p['purchase_ref']) . "' AND type = 'PURCHASE_CREDIT'");
            $evidence[] = [
                'purchase_ref' => $p['purchase_ref'], 'member_id' => $p['member_id'], 'quantity' => $p['coin_quantity'], 'approved_at_utc' => $p['reviewed_at'],
                'approved_business_date' => (new \DateTimeImmutable((string) $p['reviewed_at'], $utc))->setTimezone($tz)->format('Y-m-d H:i:s T'),
                'ledger_credit_rows' => count($credit), 'ledger_quantity_matches' => count($credit) === 1 && bccomp((string) $credit[0]['quantity'], (string) $p['coin_quantity'], 8) === 0,
                'ownership_ref_matches' => count($credit) === 1 && $credit[0]['transaction_ref'] === $p['ownership_transaction_ref'],
            ];
        }
        $missing = array_values(array_filter($evidence, static fn (array $e): bool => ! $e['ledger_quantity_matches'] || ! $e['ownership_ref_matches']));
        $lockMoves = (int) $this->one($snap, "SELECT COUNT(*) FROM member_coin_ownership_transactions WHERE type LIKE '%LOCK%'");
        $current  = $svc->snapshotBatch(array_map(static fn (array $m): int => (int) $m['user_id'], array_filter($members, static fn (array $m): bool => (int) $m['is_root_sponsor'] === 0)), '2999-01-01 00:00:00', $policies['T-CALENDAR-HALFUP']);

        $out = [
            'members_in_snapshot' => count($members),
            'non_root_members'    => count(array_filter($members, static fn (array $m): bool => (int) $m['is_root_sponsor'] === 0)),
            'business_dates'      => $dates,
            'rows'                => $rows,
            'ledger_evidence'     => $evidence,
            'missing_or_inconsistent_events' => $missing,
            'lock_movement_rows'  => $lockMoves,
            'blocked_rows'        => $blocked,
            'as_of_far_future_vs_cached_balance' => $current,
            'classification'      => ($blocked === 0 && $missing === []) ? 'HISTORICAL_OWNERSHIP_RECONSTRUCTED_FOR_SNAPSHOT_MEMBERS' : 'BLOCKED_HISTORICAL_DATA',
            'scope'               => 'Only the ' . count($members) . ' members present in the local snapshot. The member tree quoted for production (NGW000002.. NGW981235) is NOT in this data, so its historical ownership is NOT VERIFIED.',
            'dry_run_only'        => true,
        ];
        $this->assertTrue('historical_audit_no_blocked', $blocked === 0 && $missing === [], 'As-of reconstruction: blocked rows=' . $blocked . ', purchases with missing/inconsistent ledger evidence=' . count($missing) . '.');

        return $out;
    }

    // ===================================================================== E. LOCK1000 audit on the snapshot

    /** @return array<string, mixed> */
    protected function lock1000Audit(mysqli $snap): array
    {
        $p   = $snap->real_escape_string(self::PURCHASE);
        $row = $this->q($snap, "SELECT purchase_ref, member_user_id, member_id, coin_quantity, status, reviewed_at, ownership_transaction_ref FROM coin_purchase_requests WHERE purchase_ref = '" . $p . "'");
        $r   = ['purchase_ref' => self::PURCHASE, 'checks' => []];
        $add = function (string $k, bool $ok, string $d) use (&$r): void {
            $r['checks'][$k] = ['ok' => $ok, 'detail' => $d];
        };
        $add('purchase_exists', $row !== [], $row === [] ? 'purchase not found in the snapshot' : 'found');
        if ($row === []) {
            $r['classification'] = 'BLOCKED';
            $this->assertTrue('lock1000_purchase_found', false, 'LOCK1000 purchase not in snapshot.');

            return $r;
        }
        $row = $row[0];
        $uid = (int) $row['member_user_id'];
        $bal = $this->q($snap, 'SELECT balance, available_balance, locked_balance, reserved_balance FROM member_coin_balances WHERE member_user_id = ' . $uid)[0] ?? null;
        $add('purchase_approved', $row['status'] === 'approved', 'status=' . $row['status']);
        $add('member_matches', $row['member_id'] === self::MEMBER, 'member_id=' . $row['member_id']);
        $add('approval_timestamp_present', $row['reviewed_at'] !== null, 'reviewed_at=' . $row['reviewed_at'] . ' UTC');
        $credit = $this->q($snap, "SELECT quantity FROM member_coin_ownership_transactions WHERE purchase_ref = '" . $p . "' AND type = 'PURCHASE_CREDIT'");
        $add('ownership_credit_exists', count($credit) === 1, 'PURCHASE_CREDIT rows=' . count($credit));
        $add('quantity_matches', count($credit) === 1 && bccomp((string) $credit[0]['quantity'], (string) $row['coin_quantity'], 8) === 0, 'purchase qty=' . $row['coin_quantity']);
        $locks = (int) $this->one($snap, "SELECT COUNT(*) FROM member_coin_locks WHERE purchase_ref = '" . $p . "'");
        $add('mandatory_lock_missing', $locks === 0, 'locks for purchase=' . $locks);
        $reserved = $bal === null ? null : (string) $bal['reserved_balance'];
        $add('no_reservation', $reserved !== null && bccomp($reserved, '0', 8) === 0, 'reserved=' . $reserved);
        $prior = (int) $this->one($snap, "SELECT COUNT(*) FROM audit_logs WHERE action = 'coin.lock_mandatory_remediation_executed' AND entity_id = '" . $p . "'");
        $add('no_prior_remediation', $prior === 0, 'prior remediation audit rows=' . $prior);
        $add('invariant_total', $bal !== null && bccomp((string) $bal['balance'], bcadd(bcadd((string) $bal['available_balance'], (string) $bal['locked_balance'], 8), (string) $bal['reserved_balance'], 8), 8) === 0, 'total=' . ($bal['balance'] ?? '?') . ' available=' . ($bal['available_balance'] ?? '?') . ' locked=' . ($bal['locked_balance'] ?? '?'));
        $matures = (new \DateTimeImmutable((string) $row['reviewed_at'], new \DateTimeZone('UTC')))->modify('+1000 days')->format('Y-m-d H:i:s');
        $r['computed_maturity_utc'] = $matures;
        $r['balance_before'] = $bal;

        $cli = $this->spark(['locking:remediate-mandatory', '--purchase', self::PURCHASE, '--group', self::SNAP_GROUP, '--dry-run']);
        $r['cli_dry_run_exit'] = $cli['exit'];
        $r['cli_dry_run'] = preg_match('/^\{.*?^\}/ms', $cli['stdout'], $jm) === 1 ? json_decode($jm[0], true) : null;
        $r['cli_dry_run_stdout_excerpt'] = $r['cli_dry_run'] === null ? substr($cli['stdout'] . $cli['stderr'], 0, 600) : null;
        $add('cli_dry_run_ok', $cli['exit'] === 0 && is_array($r['cli_dry_run']), 'php spark locking:remediate-mandatory --group phase13c_snapshot --dry-run exit=' . $cli['exit']);
        $refuse = $this->spark(['locking:remediate-mandatory', '--purchase', self::PURCHASE, '--group', self::SNAP_GROUP, '--confirm', 'APPLY_STAGING']);
        $add('snapshot_apply_refused', $refuse['exit'] !== 0 && str_contains($refuse['stdout'] . $refuse['stderr'], 'read-only'), 'Apply against the snapshot group is refused (exit=' . $refuse['exit'] . ').');

        $all = ! in_array(false, array_column($r['checks'], 'ok'), true);
        $r['classification'] = $all ? 'LOCK1000_ELIGIBLE_FOR_REMEDIATION_ON_SNAPSHOT' : 'LOCK1000_NOT_ELIGIBLE_OR_BLOCKED';
        $this->assertTrue('lock1000_audit_checks', $all, 'LOCK1000 audit: ' . json_encode(array_map(static fn (array $c): bool => $c['ok'], $r['checks'])));

        return $r;
    }

    // ===================================================================== F. migration readiness (clone)

    /**
     * @param array<string, string> $baseSums
     * @return array<string, mixed>
     */
    protected function migrationReadiness(mysqli $admin, mysqli $clone, array $baseSums): array
    {
        $r   = ['database' => self::CLONE_DB];
        $before = array_map(static fn (array $x): string => (string) $x['version'], $this->q($clone, 'SELECT version FROM migrations ORDER BY id'));
        $origTables = $this->tables($clone, self::CLONE_DB);
        $ddlBefore  = [];
        foreach (array_diff($origTables, ['migrations']) as $t) {
            $ddlBefore[$t] = md5((string) (array_values($this->q($clone, 'SHOW CREATE TABLE `' . $t . '`')[0])[1] ?? ''));
        }
        $rowsBefore = [];
        foreach ($origTables as $t) {
            $rowsBefore[$t] = (int) $this->one($clone, 'SELECT COUNT(*) FROM `' . $t . '`');
        }

        // static scan: do the migrations alter existing tables?
        $alters = [];
        foreach (array_merge(glob(APPPATH . 'Database/Migrations/*993001*.php') ?: [], glob(APPPATH . 'Database/Migrations/*994001*.php') ?: []) as $f) {
            $src = (string) file_get_contents($f);
            if (preg_match('/ALTER\s+TABLE|modifyColumn|addColumn|dropColumn|dropKey|->dropTable\(\s*[\'"](?!financial_policy|mining_post)/i', $src)) {
                $alters[] = basename($f);
            }
        }
        $r['migrations_alter_existing_tables'] = $alters;
        $this->assertTrue('migrations_are_additive', $alters === [], 'Static scan: 993001/994001 add tables only (no ALTER/dropTable on existing tables).');

        $apply = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function () use ($before): array {
            $runner = service('migrations', null, null, false);
            $t0 = microtime(true);
            $runner->latest();

            return ['duration_s' => round(microtime(true) - $t0, 2)];
        });
        $after = array_map(static fn (array $x): string => (string) $x['version'], $this->q($clone, 'SELECT version FROM migrations ORDER BY id'));
        $newV  = array_values(array_diff($after, $before));
        sort($newV);
        $r['applied'] = ['previous_latest' => end($before), 'new_versions' => $newV, 'duration_s' => $apply['duration_s']];
        $this->assertTrue('migrations_apply_exact_set', $newV === self::MIGRATIONS, 'Applied on clone: ' . json_encode($newV));

        $newTables = array_values(array_diff($this->tables($clone, self::CLONE_DB), $origTables));
        sort($newTables);
        $r['new_tables'] = $newTables;
        $ddlAfter = [];
        foreach (array_diff($origTables, ['migrations']) as $t) {
            $ddlAfter[$t] = md5((string) (array_values($this->q($clone, 'SHOW CREATE TABLE `' . $t . '`')[0])[1] ?? ''));
        }
        $sumsAfter = $this->checksums($clone, self::CLONE_DB, $origTables);
        $changedDdl = array_keys(array_diff_assoc($ddlBefore, $ddlAfter));
        $changedData = array_keys(array_diff_assoc(array_diff_key($baseSums, ['migrations' => 1]), array_diff_key($sumsAfter, ['migrations' => 1])));
        $r['existing_tables_ddl_changed']  = $changedDdl;
        $r['existing_tables_data_changed'] = $changedData;
        $this->assertTrue('migrations_preserve_existing_data_and_schema', $changedDdl === [] && $changedData === [], 'All ' . count($origTables) . ' pre-existing tables: DDL and data identical after migration (excluding the migrations bookkeeping table).');

        $fkRows = $this->q($clone, "SELECT TABLE_NAME tn, CONSTRAINT_NAME cn, REFERENCED_TABLE_NAME rt FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = '" . self::CLONE_DB . "' AND TABLE_NAME IN ('" . implode("','", $newTables ?: ['-']) . "')");
        $idx    = [];
        foreach ($newTables as $t) {
            $idx[$t] = array_values(array_unique(array_map(static fn (array $x): string => (string) $x['Key_name'], $this->q($clone, 'SHOW INDEX FROM `' . $t . '`'))));
        }
        $r['new_table_foreign_keys'] = $fkRows;
        $r['new_table_indexes']      = $idx;

        // compatibility: hub + mining command + worksheet on the migrated clone
        $compat = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function (): array {
            $o = [];
            try {
                $w = service('miningPolicyWorksheetService', false)->build();
                $o['worksheet_builds'] = isset($w['decisions']) || isset($w['confirmed']) || is_array($w);
                $o['hub_tables_visible_to_worksheet'] = db_connect()->tableExists('financial_policy_versions');
            } catch (Throwable $e) {
                $o['worksheet_error'] = $e->getMessage();
            }

            return $o;
        });
        $mp = $this->spark(['mining:post', '--group', self::CLONE_GROUP, '--dry-run', '--date', 'yesterday']);
        $compat['mining_post_dry_run_exit'] = $mp['exit'];
        $compat['mining_post_dry_run_tail'] = trim(substr($mp['stdout'] . $mp['stderr'], -300));
        $compat['mining_post_expected']     = 'DRY_RUN reporting that no approved mining rate exists (a real run would be BLOCKED) — proves the command runs on the migrated restored schema without crashing or posting.';
        $r['compatibility'] = $compat;
        $this->assertTrue('migrated_clone_compat', ($compat['worksheet_builds'] ?? false) === true && ($compat['hub_tables_visible_to_worksheet'] ?? false) === true && in_array($mp['exit'], [0, 10, 20, 30], true) && str_contains($mp['stdout'], 'no approved mining rate'), 'Worksheet builds; hub tables present; mining:post dry-run on clone exit=' . $mp['exit'] . '.');

        // rollback test (down) then re-apply
        $prevBatch = (int) $this->one($clone, 'SELECT MAX(batch) - 1 FROM migrations');
        $roll = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function () use ($prevBatch): array {
            $runner = service('migrations', null, null, false);
            $runner->regress($prevBatch);

            return [];
        });
        $afterDown = array_map(static fn (array $x): string => (string) $x['version'], $this->q($clone, 'SELECT version FROM migrations ORDER BY id'));
        $leftTables = array_values(array_intersect($newTables, $this->tables($clone, self::CLONE_DB)));
        $sumsDown   = $this->checksums($clone, self::CLONE_DB, $origTables);
        $r['rollback'] = [
            'versions_after_down' => count($afterDown) === count($before) && $afterDown === $before,
            'new_tables_remaining' => $leftTables,
            'original_tables_identical_after_down' => array_diff_key($sumsDown, ['migrations' => 1]) === array_diff_key($baseSums, ['migrations' => 1]),
            'limit' => 'down() DROPs the new tables including any rows they contain: rollback is only data-safe BEFORE the scheduler/policies write to them (export first otherwise).',
        ];
        $this->assertTrue('migrations_down_clean', $r['rollback']['versions_after_down'] && $leftTables === [] && $r['rollback']['original_tables_identical_after_down'], 'down() removed only the new tables; originals identical to baseline.');

        $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function (): array {
            service('migrations', null, null, false)->latest();

            return [];
        });
        $reapplied = array_map(static fn (array $x): string => (string) $x['version'], $this->q($clone, 'SELECT version FROM migrations ORDER BY id'));
        $r['reapply_ok'] = count(array_diff($reapplied, $before)) === 2;
        $this->assertTrue('migrations_reapply_after_down', $r['reapply_ok'], 'Re-applied after down() on the clone.');

        $r['production_checklist'] = [
            '1. Take a fresh verified FULL-data backup of the production database; record sha256 and row counts.',
            '2. Maintenance window; keep every kill-switch FALSE; confirm no scheduler/cron entry exists.',
            '3. Run `php spark migrate` ONLY with a written production migration authorization; expected set = ' . implode(', ', self::MIGRATIONS) . ' (additive only).',
            '4. Verify: only those 2 versions added; 3 hub/run tables created; all pre-existing tables unchanged (CHECKSUM TABLE).',
            '5. Re-run this phase\'s read-only fingerprint; confirm balances/ledgers/members unchanged.',
            '6. If anything is wrong before any policy/run rows exist: `php spark migrate:rollback` is safe (drops only new empty tables). After rows exist: export first.',
        ];

        return $r;
    }

    // ===================================================================== G. LOCK1000 what-if on the clone

    /** @return array<string, mixed> */
    protected function lock1000Simulation(mysqli $clone): array
    {
        $db = self::CLONE_DB;
        $state = function () use ($clone, $db): array {
            $fp = $this->fingerprint($clone, $db);
            unset($fp['row_counts'], $fp['switches']);
            $b = $this->q($clone, 'SELECT balance, available_balance, locked_balance, reserved_balance FROM member_coin_balances b JOIN member_profiles m ON m.user_id = b.member_user_id WHERE m.member_id = \'' . self::MEMBER . '\'')[0] ?? null;
            $fp['member_balance'] = $b;
            $fp['inventory']      = $this->q($clone, 'SELECT available_balance, total_allocated FROM coin_inventory')[0] ?? null;
            $fp['inventory_txn_rows'] = (int) $this->one($clone, 'SELECT COUNT(*) FROM coin_inventory_transactions');
            $fp['purchase_credit_rows'] = (int) $this->one($clone, "SELECT COUNT(*) FROM member_coin_ownership_transactions WHERE type = 'PURCHASE_CREDIT'");
            $fp['withdrawal_or_sale_tables'] = (int) $this->one($clone, "SELECT COALESCE(SUM(1),0) FROM information_schema.tables WHERE table_schema = '" . $db . "' AND table_name LIKE '%sell%' AND table_rows > 0");

            return $fp;
        };

        $baseline = $state();
        $r = ['database' => $db, 'baseline' => $baseline];

        $dry = $this->spark(['locking:remediate-mandatory', '--purchase', self::PURCHASE, '--group', self::CLONE_GROUP, '--dry-run']);
        $r['dry_run_exit'] = $dry['exit'];
        $afterDry = $state();
        $this->assertTrue('simulation_dry_run_no_writes', $afterDry === $baseline, 'Dry-run on the clone changed nothing.');

        $apply = $this->spark(['locking:remediate-mandatory', '--purchase', self::PURCHASE, '--group', self::CLONE_GROUP, '--confirm', 'APPLY_STAGING']);
        $r['apply_exit']   = $apply['exit'];
        $r['apply_tail']   = $apply['exit'] === 0 ? 'ok' : trim(substr($apply['stdout'] . $apply['stderr'], -500));
        $after = $state();
        $r['after'] = $after;

        $proposed = ['available' => '0.00000000', 'locked' => '5.00000000', 'total' => '5.00000000'];
        $bal = $after['member_balance'];
        $actual = $bal === null ? null : ['available' => bcadd((string) $bal['available_balance'], '0', 8), 'locked' => bcadd((string) $bal['locked_balance'], '0', 8), 'total' => bcadd((string) $bal['balance'], '0', 8)];
        $before = $baseline['member_balance'];
        $r['reconciliation'] = [
            'before'     => $before === null ? null : ['available' => bcadd((string) $before['available_balance'], '0', 8), 'locked' => bcadd((string) $before['locked_balance'], '0', 8), 'total' => bcadd((string) $before['balance'], '0', 8)],
            'proposed'   => $proposed,
            'actual_simulated' => $actual,
            'difference_actual_vs_proposed' => $actual === null ? null : ['available' => bcsub($actual['available'], $proposed['available'], 8), 'locked' => bcsub($actual['locked'], $proposed['locked'], 8), 'total' => bcsub($actual['total'], $proposed['total'], 8)],
            'status'     => $actual === $proposed ? 'PASS (snapshot clone only — NOT a production correction)' : 'FAIL',
        ];
        $this->assertTrue('simulation_apply_ok', $apply['exit'] === 0 && $actual === $proposed, 'Clone after apply: ' . json_encode($actual) . ' vs proposed ' . json_encode($proposed));

        $noSide = $after['mining_wallet_total'] === $baseline['mining_wallet_total'] && $after['mining_ledger_rows'] === $baseline['mining_ledger_rows']
            && $after['commission_ledger_rows'] === $baseline['commission_ledger_rows'] && $after['inventory'] === $baseline['inventory']
            && $after['inventory_txn_rows'] === $baseline['inventory_txn_rows'] && $after['purchase_credit_rows'] === $baseline['purchase_credit_rows']
            && $after['owned_total'] === $baseline['owned_total'] && $after['members'] === $baseline['members'] && $after['approved_purchases'] === $baseline['approved_purchases'];
        $r['no_side_effects'] = $noSide;
        $r['ledger_rows_added'] = (int) $after['ownership_ledger_rows'] - (int) $baseline['ownership_ledger_rows'];
        $r['locks_added']       = (int) $after['locks_total'] - (int) $baseline['locks_total'];
        $this->assertTrue('simulation_no_mint_no_side_effects', $noSide && $r['locks_added'] === 1 && $r['ledger_rows_added'] === 1, 'No minting/duplicate credit, no mining/commission/inventory/sale effect; +1 lock, +' . $r['ledger_rows_added'] . ' ledger row.');

        $again = $this->spark(['locking:remediate-mandatory', '--purchase', self::PURCHASE, '--group', self::CLONE_GROUP, '--confirm', 'APPLY_STAGING']);
        $afterAgain = $state();
        $r['second_apply_exit'] = $again['exit'];
        $r['second_apply_idempotent'] = $afterAgain === $after;
        $this->assertTrue('simulation_second_apply_idempotent', $afterAgain === $after, 'Second apply is a no-op and changed nothing (exit=' . $again['exit'] . ').');

        return $r;
    }

    // ===================================================================== H. legacy commands

    /** @return array<string, mixed> */
    protected function legacyCommandSafety(mysqli $clone): array
    {
        $r = [];
        foreach (['MiningDaily', 'MiningReconcile'] as $cmd) {
            $src = (string) file_get_contents(APPPATH . 'Commands/' . $cmd . '.php');
            $r[$cmd] = [
                'labelled_legacy'      => str_contains($src, 'LEGACY'),
                'references_batch_poster' => str_contains($src, 'MiningBatchPostingService') || str_contains($src, 'postDay') || str_contains($src, 'creditDaily'),
                'points_to_safe_command' => str_contains($src, 'mining:post'),
                'writes_wallet_tables' => (bool) preg_match('/mining_wallet_ledger|mining_wallets|commission_wallet/', $src) && (bool) preg_match('/->(insert|update|delete)\(/', $src),
            ];
        }
        $env    = ['database.default.database' => self::CLONE_DB];
        $before = $this->fingerprint($clone, self::CLONE_DB);
        $daily  = $this->spark(['mining:daily', '--dry-run', '--no-persist', '--date', '2026-11-17'], $env);
        $recon  = $this->spark(['mining:reconcile'], $env);
        $after  = $this->fingerprint($clone, self::CLONE_DB);
        $r['mining_daily_run']     = ['exit' => $daily['exit'], 'legacy_banner' => str_contains($daily['stdout'], 'LEGACY')];
        $r['mining_reconcile_run'] = ['exit' => $recon['exit'], 'legacy_banner' => str_contains($recon['stdout'], 'LEGACY')];
        $r['wallet_state_unchanged'] = $before['mining_wallet_total'] === $after['mining_wallet_total'] && $before['mining_ledger_rows'] === $after['mining_ledger_rows'] && $before['commission_ledger_rows'] === $after['commission_ledger_rows'];
        $postAttempt = $this->spark(['mining:daily', '--post', '--no-persist'], $env);
        $r['post_flag_refused'] = $postAttempt['exit'] !== 0 || ! str_contains($postAttempt['stdout'], 'credited');
        $ok = $r['MiningDaily']['labelled_legacy'] && $r['MiningReconcile']['labelled_legacy'] && ! $r['MiningDaily']['references_batch_poster'] && ! $r['MiningDaily']['writes_wallet_tables'] && ! $r['MiningReconcile']['writes_wallet_tables']
            && $r['mining_daily_run']['legacy_banner'] && $r['mining_reconcile_run']['legacy_banner'] && $r['wallet_state_unchanged'];
        $this->assertTrue('legacy_commands_safe', $ok, 'mining:daily / mining:reconcile are labelled LEGACY, print banners, contain no posting code and left wallet/ledger state unchanged on the clone.');

        return $r;
    }

    // ===================================================================== I. monitoring (mock alerts)

    /** @return array<string, mixed> */
    protected function monitoring(): array
    {
        $file = WRITEPATH . 'phase13c-mock-alerts.log';
        @unlink($file);
        $captured = [];
        Events::on('mining_post_alert', static function (array $a) use (&$captured): void {
            $captured[] = $a['code'] ?? '?';
        });
        $svc = new MiningPostAlertService(null, $file);
        $scenarios = [
            'failed_run'               => [[], 'FAILED', 50, 'something broke', 'nonzero_exit'],
            'partial_run'              => [['status' => 'partial', 'reason' => 'x'], 'PARTIAL', 40, null, 'partial_run'],
            'missing_rate'             => [[], 'BLOCKED', 30, 'No approved mining rate for 2026-11', 'missing_rate'],
            'missing_evidence'         => [['blocked' => 3], 'PARTIAL', 40, null, 'blocked_historical_data'],
            'reconciliation_mismatch'  => [['reconcile_status' => 'MISMATCH'], 'PARTIAL', 40, null, 'reconciliation_failed'],
            'duplicate_invocation'     => [['status' => 'skipped_locked'], 'SKIPPED', 20, null, 'lock_contention'],
            'timeout'                  => [['interrupted' => 'timeout', 'status' => 'partial'], 'PARTIAL', 40, null, 'job_timeout'],
            'excessive_retry'          => [['retry_passes' => 99], 'POSTED', 0, null, 'excessive_retry'],
            'database_loss'            => [[], 'FAILED', 50, 'mysqli_sql_exception: Connection refused', 'database_connection_failure'],
        ];
        $results = [];
        foreach ($scenarios as $name => [$res, $status, $exit, $err, $expectCode]) {
            $codes = array_map(static fn (array $a): string => (string) $a['code'], $svc->evaluate($res, $status, $exit, $err));
            $results[$name] = ['expected' => $expectCode, 'raised' => $codes, 'ok' => in_array($expectCode, $codes, true)];
        }
        $missing = $svc->checkScheduledRun('2026-11-17');
        $results['missed_scheduled_run'] = ['expected' => 'missing_scheduled_run', 'raised' => [$missing['code']], 'ok' => $missing['code'] === 'missing_scheduled_run'];
        $allOk = ! in_array(false, array_column($results, 'ok'), true);
        $this->assertTrue('monitoring_mock_alerts', $allOk && $captured !== [], 'All ' . count($results) . ' failure scenarios raise their local alert; ' . count($captured) . ' events captured; nothing sent externally.');

        return [
            'scenarios'          => $results,
            'events_captured'    => count($captured),
            'delivery'           => 'LOCAL ONLY (writable/phase13c-mock-alerts.log + CI log + in-process event). No recipient configured or contacted.',
            'alert_destination'  => 'NOT CONFIGURED (BLOCKER) — recipients must be supplied by the business; none were invented.',
        ];
    }

    // ===================================================================== J. rollback proof

    /**
     * @param array<string, string> $baseSums
     * @return array<string, mixed>
     */
    protected function rollbackProof(mysqli $admin, array $baseSums): array
    {
        $res   = $this->restoreInto($admin, self::CLONE_DB, 'disposable clone (rollback proof)');
        $clone = $this->connect(self::CLONE_GROUP);
        $sums  = $this->checksums($clone, self::CLONE_DB, $this->tables($clone, self::CLONE_DB));
        $ok    = $res['exit'] === 0 && $sums === $baseSums;
        $this->assertTrue('rollback_restore_returns_baseline', $ok, 'Re-restoring the dump into the clone returns it to the exact baseline (' . count($sums) . ' tables identical) in ' . $res['duration_s'] . 's.');

        return ['restore_duration_s' => $res['duration_s'], 'clone_identical_to_baseline' => $sums === $baseSums, 'method' => 'drop clone tables (marker-guarded) + restore the same dump'];
    }

    // ===================================================================== K. live readiness + worksheet (read-only)

    /** @return array<string, mixed> */
    protected function liveReadiness(): array
    {
        $ws = service('miningPolicyWorksheetService', false);
        $w  = $ws->build();
        $md = $ws->toMarkdown($w);
        $path = ROOTPATH . 'docs/phase13c-mining-business-decision-matrix.md';
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        file_put_contents($path, $md);

        $ready = service('miningSchedulerReadinessService', false)->check();
        $this->assertTrue('scheduler_not_activated', $ready['activated'] === false, 'Scheduler readiness evaluated read-only; nothing activated.');

        $states = array_map(static fn (array $d): array => ['id' => $d['id'] ?? null, 'state' => $d['approval_state'] ?? null], array_values((array) ($w['decisions'] ?? [])));
        $unapproved = array_values(array_filter($states, static fn (array $s): bool => ($s['state'] ?? '') !== 'APPROVED_VERIFIED'));
        $this->assertTrue('no_policy_auto_approved', count($unapproved) === count($states) && $states !== [], count($unapproved) . ' of ' . count($states) . ' decisions are not approved (none auto-approved).');

        return [
            'database'           => (string) db_connect()->getDatabase(),
            'worksheet_decisions' => $states,
            'worksheet_blockers'  => $w['blockers'] ?? [],
            'matrix_written'      => 'docs/phase13c-mining-business-decision-matrix.md',
            'scheduler_readiness' => $ready,
        ];
    }
}
