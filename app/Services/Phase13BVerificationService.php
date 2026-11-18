<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\CliArgs;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;
use Throwable;

/**
 * Phase 13B — isolated verification of historical as-of mining, precision, batching, scheduler reliability
 * and CLI syntax safety.
 *
 * Writes ONLY to coin_platform_phase13b (marker-table guarded; never drops a database it did not create) through a
 * dedicated MySQL account that is GRANTed on that schema alone. The live `coin_platform` database receives
 * SELECT-only fingerprint queries before and after. Real posting always runs through the real Spark command in
 * child processes; policy fixtures reach the child through environment variables of that child only
 * (never .env, never production configuration).
 */
class Phase13BVerificationService
{
    public const GROUP        = 'phase13b';
    public const DATABASE     = 'coin_platform_phase13b';
    public const MARKER_TABLE = 'phase13b_marker';

    public const D0 = '2026-10-14';
    public const D1 = '2026-10-15';
    public const D2 = '2026-10-16';
    public const D3 = '2026-10-17';

    private const RATE_PERCENT = '3.00000000';
    private const TZ_IST       = 'Asia/Kolkata';

    /** @var list<array{name: string, ok: bool, detail: string}> */
    private array $checks = [];

    /** @var array<string, array<string, mixed>> */
    private array $members = [];

    private int $adminUserId = 0;

    private int $approverUserId = 0;

    /**
     * @param array{perf?: bool, perf_sizes?: list<int>, skip_perf_batch_compare?: bool} $opts
     * @return array<string, mixed>
     */
    public function run(array $opts = []): array
    {
        @set_time_limit(0);
        $this->checks = [];
        $report = [
            'phase' => '13B', 'started_at' => date('c'), 'database' => self::DATABASE, 'success' => false, 'errors' => [],
            'timezone_note' => 'Ledger/approval timestamps are UTC; mining business date is Asia/Kolkata.',
        ];
        $previous = config('Database')->defaultGroup;

        try {
            $report['live_identity']        = $this->liveIdentity();
            $report['live_fingerprint_before'] = $report['live_identity']['inspectable'] ? $this->liveFingerprint() : null;

            $report['provision'] = $this->provisionDatabase();
            $this->switchToIsolated();
            $report['switches_before'] = service('financialSandboxService')->productionSwitchSnapshot();
            $this->check('production_switches_off_before', ! in_array(true, array_filter($report['switches_before'], 'is_bool'), true), json_encode($report['switches_before']));

            $this->bootstrap();
            $this->buildCohort();
            $report['cohort'] = array_map(static fn (array $m): array => ['case' => $m['case'], 'member_id' => $m['member_id'], 'story' => $m['story']], $this->members);

            $ownershipBeforePosting = $this->ownershipSnapshot();
            $report['cases']      = $this->caseStage();
            $report['policy_previews'] = $this->policyPreviews();
            $report['timezone']   = $this->timezoneStage();
            $report['precision']  = $this->precisionStage();
            $report['scheduler']  = $this->schedulerStage();
            $report['case_k']     = $this->caseKStage();
            $report['cli_audit']  = $this->cliAuditStage();

            $this->check('ownership_ledger_untouched_by_posting', $ownershipBeforePosting === $this->ownershipSnapshot(), 'member_coin_balances identical before/after all posting stages');
            $dupes = db_connect()->query("SELECT member_user_id, business_date, COUNT(*) c FROM mining_wallet_ledger WHERE entry_type='daily_mining_credit' GROUP BY member_user_id, business_date HAVING c > 1")->getResultArray();
            $this->check('zero_duplicate_credits_per_member_per_date', $dupes === [], 'duplicate groups=' . count($dupes));
            $walletBad = (int) db_connect()->query("SELECT COUNT(*) c FROM mining_wallets w JOIN (SELECT member_user_id, SUM(amount) s FROM mining_wallet_ledger WHERE direction='credit' GROUP BY member_user_id) l ON l.member_user_id = w.member_user_id WHERE w.total_credited <> l.s OR w.available_balance <> w.total_credited - w.total_debited")->getRow()->c;
            $this->check('wallet_balance_equals_ledger_for_every_member', $walletBad === 0, 'wallet/ledger mismatches=' . $walletBad);
            $report['ledger_totals'] = db_connect()->query("SELECT COUNT(*) AS rows_total, COALESCE(SUM(amount),0) AS sum_total FROM mining_wallet_ledger")->getRowArray();
            $report['switches_after'] = service('financialSandboxService')->productionSwitchSnapshot();
            $this->check('production_switches_off_after', ! in_array(true, array_filter($report['switches_after'], 'is_bool'), true), json_encode($report['switches_after']));

            if (! empty($opts['perf'])) {
                $report['performance'] = $this->performanceStage($opts['perf_sizes'] ?? [100, 2000, 2001, 5000, 10000], empty($opts['skip_perf_batch_compare']));
            }
        } catch (Throwable $e) {
            $report['errors'][] = ['class' => $e::class, 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()];
            $this->check('verification_completed_without_exception', false, $e->getMessage());
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
            service('financialSandboxService')->closeWindow();
        }

        try {
            if (($report['live_identity']['inspectable'] ?? false) === true) {
                $report['live_fingerprint_after'] = $this->liveFingerprint();
                $same = ($report['live_fingerprint_before'] ?? null) === $report['live_fingerprint_after'];
                $this->check('live_database_unchanged', $same, $same ? 'coin_platform fingerprint identical before and after' : 'FINGERPRINT CHANGED');
            } else {
                $this->check('live_database_unchanged', true, 'live inspection skipped: identity uncertain (' . ($report['live_identity']['reason'] ?? '?') . ')');
            }
        } catch (Throwable $e) {
            $this->check('live_database_unchanged', false, $e->getMessage());
        }

        $report['checks']      = $this->checks;
        $report['passed']      = count(array_filter($this->checks, static fn (array $c): bool => $c['ok']));
        $report['failed']      = count($this->checks) - $report['passed'];
        $report['success']     = $report['failed'] === 0 && $report['errors'] === [];
        $report['finished_at'] = date('c');

        return $report;
    }

    // ===================================================================== live (read-only)

    /** @return array<string, mixed> */
    private function liveIdentity(): array
    {
        $cfg  = config('Database')->default;
        $host = strtolower((string) ($cfg['hostname'] ?? ''));
        $local = in_array($host, ['localhost', '127.0.0.1', '::1'], true);
        $db   = Database::connect('default', false);
        $server = (string) ($db->query('SELECT @@hostname AS h')->getRow()->h ?? '');

        return [
            'configured_host' => $host,
            'configured_database' => (string) ($cfg['database'] ?? ''),
            'mysql_server_hostname' => $server,
            'is_local_host' => $local,
            'inspectable' => $local,
            'reason' => $local ? 'configured host is loopback (local MySQL); inspection is SELECT-only' : 'configured host is not loopback — production identity cannot be ruled out; inspection stopped',
        ];
    }

    /** @return array<string, mixed> SELECT-only fingerprint of the live database */
    private function liveFingerprint(): array
    {
        $db  = Database::connect('default', false);
        $one = static function (string $sql) use ($db): ?string {
            try {
                $r = $db->query($sql)->getRowArray();

                return $r === null ? null : (string) array_values($r)[0];
            } catch (Throwable) {
                return null;
            }
        };
        $cfg = config('Platform');

        return [
            'database'                   => (string) $db->getDatabase(),
            'member_count'               => $one('SELECT COUNT(*) FROM member_profiles'),
            'ownership_total'            => $one('SELECT COALESCE(SUM(balance),0) FROM member_coin_balances'),
            'ownership_ledger_rows'      => $one('SELECT COUNT(*) FROM member_coin_ownership_transactions'),
            'mining_wallet_total'        => $one('SELECT COALESCE(SUM(available_balance),0) FROM mining_wallets'),
            'mining_ledger_count'        => $one('SELECT COUNT(*) FROM mining_wallet_ledger'),
            'commission_ledger_count'    => $one('SELECT COUNT(*) FROM commission_wallet_ledger_entries'),
            'mandatory_lock_count'       => $one('SELECT COUNT(*) FROM member_coin_locks'),
            'member_id_sequences'        => $one("SELECT GROUP_CONCAT(CONCAT(prefix,':',last_number) ORDER BY prefix) FROM member_id_sequences"),
            'mining_post_day_runs_present' => $one("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('mining_post_day_runs','mining_post_members')"),
            'latest_migration'           => $one('SELECT MAX(version) FROM migrations'),
            'kill_switches'              => [
                'miningPostingEnabled' => (bool) $cfg->miningPostingEnabled,
                'miningAutoPostEnabled' => (bool) $cfg->miningAutoPostEnabled,
                'miningP2pEnabled' => (bool) $cfg->miningP2pEnabled,
                'commissionPostingEnabled' => (bool) $cfg->commissionPostingEnabled,
                'commissionWithdrawalEnabled' => (bool) $cfg->commissionWithdrawalEnabled,
                'commissionBlockchainPayoutEnabled' => (bool) $cfg->commissionBlockchainPayoutEnabled,
                'coinSellingEnabled' => (bool) $cfg->coinSellingEnabled,
            ],
        ];
    }

    // ===================================================================== isolated DB provisioning

    /** @return array<string, mixed> */
    private function provisionDatabase(): array
    {
        $isolated = config('Database')->{self::GROUP};
        if (($isolated['database'] ?? '') !== self::DATABASE) {
            throw new RuntimeException('Refusing: group ' . self::GROUP . ' must point at ' . self::DATABASE . '.');
        }
        $info = service('financialSandboxService')->inspectConnection(self::GROUP);
        if ($info['database'] !== self::DATABASE || ! $info['is_allowed'] || $info['is_forbidden']) {
            throw new RuntimeException('Refusing: ' . self::DATABASE . ' is not allowlisted.');
        }

        $admin = config('Database')->default; // administrative CREATE DATABASE / GRANT only; never used for mining writes.
        $c = @mysqli_connect((string) $admin['hostname'], (string) $admin['username'], (string) $admin['password'], '', (int) ($admin['port'] ?? 3306));
        if ($c === false) {
            throw new RuntimeException('Cannot connect to MySQL to provision the isolated database.');
        }
        $name   = $c->real_escape_string(self::DATABASE);
        $action = 'created';

        try {
            $exists = $c->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" . $name . "'")->num_rows > 0;
            if ($exists) {
                $tables = [];
                foreach ($c->query("SELECT table_name FROM information_schema.tables WHERE table_schema = '" . $name . "'") as $r) {
                    $tables[] = (string) $r['table_name'];
                }
                if (in_array(self::MARKER_TABLE, $tables, true)) {
                    $action = 'reused (marker present; tables are wiped before seeding)';
                } elseif ($tables === []) {
                    $action = 'reused (empty database)';
                } else {
                    throw new RuntimeException('Database ' . self::DATABASE . ' already exists, was not created by Phase 13B (no marker table) and is not empty. Refusing to drop or overwrite it without explicit confirmation.');
                }
            } else {
                $c->query('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
            }
            $c->query('CREATE TABLE IF NOT EXISTS `' . $name . '`.`' . self::MARKER_TABLE . '` (id INT PRIMARY KEY AUTO_INCREMENT, note VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL)');
            if ($c->query('SELECT COUNT(*) c FROM `' . $name . '`.`' . self::MARKER_TABLE . '`')->fetch_assoc()['c'] === '0') {
                $c->query("INSERT INTO `" . $name . "`.`" . self::MARKER_TABLE . "` (note, created_at) VALUES ('created by Phase 13B verification; safe to wipe', NOW())");
            }

            $user = $c->real_escape_string((string) $isolated['username']);
            $pass = $c->real_escape_string((string) $isolated['password']);
            $host = $c->real_escape_string((string) $isolated['hostname']);
            $grantee = "'" . $user . "'@'" . $host . "'";
            $dedicated = true;
            if ($user === 'root') {
                $dedicated = false;
            } else {
                $c->query('CREATE USER IF NOT EXISTS ' . $grantee . " IDENTIFIED BY '" . $pass . "'");
                if (! @$c->query('ALTER USER ' . $grantee . " IDENTIFIED BY '" . $pass . "'")) {
                    $c->query('SET PASSWORD FOR ' . $grantee . " = PASSWORD('" . $pass . "')");
                }
                $c->query('GRANT ALL PRIVILEGES ON `' . $name . '`.* TO ' . $grantee);
                $c->query('FLUSH PRIVILEGES');
            }
        } finally {
            $c->close();
        }

        return ['database' => self::DATABASE, 'action' => $action, 'dedicated_account' => $dedicated ? (string) $isolated['username'] . ' (GRANT only on ' . self::DATABASE . '.*)' : 'root (fallback; no dedicated account)'];
    }

    private function switchToIsolated(): void
    {
        config('Database')->defaultGroup = self::GROUP;
        Database::connect(self::GROUP, false);
        db_connect(self::GROUP, false);
        if ((string) db_connect()->getDatabase() !== self::DATABASE) {
            throw new RuntimeException('Failed to bind default connection to ' . self::DATABASE);
        }
    }

    private function wipeTables(): void
    {
        $db = db_connect();
        $db->resetDataCache();
        $tables = array_values(array_filter($db->listTables(), static fn (string $t): bool => $t !== self::MARKER_TABLE));
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($tables as $t) {
            $db->query('DROP TABLE IF EXISTS `' . $t . '`');
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->resetDataCache();
    }

    // ===================================================================== bootstrap / fixtures

    private function bootstrap(): void
    {
        $this->wipeTables();
        $sandbox = service('financialSandboxService');
        $sandbox->openWindow(300, self::GROUP);
        $sandbox->applySandboxPlatformOverrides();

        try {
            // Fresh (non-shared) runner: the shared one checks/creates the `migrations` table only once per process,
            // and wipeTables() drops it between performance populations.
            $migrate = service('migrations', null, null, false);
            $migrate->setNamespace(null);
            $migrate->latest();
            db_connect()->resetDataCache();
            Database::seeder()->call(\App\Database\Seeds\RootSponsorSeeder::class);

            $this->adminUserId = (int) ((new MemberProfileModel())->findByMemberId('ROOT0001')->user_id ?? 0);
            if ($this->adminUserId <= 0) {
                throw new RuntimeException('ROOT0001 missing after seed.');
            }
            $this->approverUserId = $this->createApprover();

            service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p13b price');
            service('coinInventoryService')->addInventory('1000000', $this->adminUserId, 'p13b stock', null, 'p13b-stock-' . bin2hex(random_bytes(3)));
            $db = db_connect();
            if ($db->table('member_id_sequences')->where('prefix', 'SYN')->get()->getRowArray() === null) {
                $now = date('Y-m-d H:i:s');
                $db->table('member_id_sequences')->insert(['prefix' => 'SYN', 'last_number' => 0, 'created_at' => $now, 'updated_at' => $now]);
            }

            $this->approveRatesForMonths(array_unique(array_merge(
                ['2026-10'],
                [$this->monthOf('yesterday'), $this->monthOf('today')]
            )));
        } finally {
            $sandbox->closeWindow();
        }
    }

    /** @param list<string> $months */
    private function approveRatesForMonths(array $months): void
    {
        foreach ($months as $month) {
            $draft = service('miningRateService')->createDraft([
                'effective_month' => $month,
                'rate_percent'    => self::RATE_PERCENT,
                'rate_basis'      => 'monthly',
                'notes'           => 'PHASE13B TEST-ONLY RATE (isolated DB; not a business-approved rate)',
            ], $this->adminUserId);
            service('miningRateService')->approve($draft['rate_ref'], $this->approverUserId);
        }
    }

    private function monthOf(string $when): string
    {
        return (new DateTimeImmutable($when, new DateTimeZone(self::TZ_IST)))->format('Y-m');
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p13b_approver', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'p13b.approver@sandbox.local', 'password' => 'ApproverPass123!']);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /** IST wall-clock string to UTC `Y-m-d H:i:s`. */
    private function ist(string $local): string
    {
        return (new DateTimeImmutable($local, new DateTimeZone(self::TZ_IST)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** @return array{user_id: int, member_id: string} */
    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32('p13b' . $suffix));
        $hex    = str_pad(substr(hash('sha256', 'p13b-' . $suffix . microtime(true)), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => FinancialSandboxService::SYNTHETIC_NAME_PREFIX . ' 13B-' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'p13b.' . strtolower($suffix) . '.' . bin2hex(random_bytes(2)) . '@sandbox.local',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ], null, 'admin');
        $memberId = (string) $result['member_id'];
        service('financialSandboxService')->assertSyntheticMember($memberId, 'p13b_register');

        return ['user_id' => (int) $result['user']->id, 'member_id' => $memberId];
    }

    /** Purchase approved through the real services, then every row it created is back-dated to $whenUtc. */
    private function buyAt(string $case, string $qty, bool $lock, string $whenUtc, ?string $maturesUtc = null): void
    {
        $m        = $this->members[$case];
        $db       = db_connect();
        $platform = config('Platform');
        $maxLedger = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM member_coin_ownership_transactions')->getRow()->m;
        $maxLock   = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM member_coin_locks')->getRow()->m;

        $platform->mandatoryPurchaseLockEnabled = $lock;
        try {
            $req = service('coinPurchaseService')->createRequest($m['user_id'], $qty, null, 'p13b-' . $case . '-' . bin2hex(random_bytes(3)));
            $ref = (string) $req['request']['purchase_ref'];
            service('coinPurchaseService')->approveRequest($ref, $this->adminUserId, true, 'p13b');
        } finally {
            $platform->mandatoryPurchaseLockEnabled = true;
        }

        $db->query('UPDATE member_coin_ownership_transactions SET created_at = ?, updated_at = ? WHERE id > ?', [$whenUtc, $whenUtc, $maxLedger]);
        $db->query('UPDATE member_coin_locks SET locked_at = ?, created_at = ?, updated_at = ?, matures_at = COALESCE(?, DATE_ADD(?, INTERVAL 1000 DAY)) WHERE id > ?', [$whenUtc, $whenUtc, $whenUtc, $maturesUtc, $whenUtc, $maxLock]);
        $db->query('UPDATE coin_purchase_requests SET requested_at = ?, reviewed_at = ?, created_at = ?, updated_at = ? WHERE purchase_ref = ?', [$whenUtc, $whenUtc, $whenUtc, $whenUtc, $ref]);
    }

    /** Run a ledger operation and back-date the rows it created. */
    private function ledgerOp(callable $fn, string $whenUtc): void
    {
        $db  = db_connect();
        $max = (int) $db->query('SELECT COALESCE(MAX(id),0) m FROM member_coin_ownership_transactions')->getRow()->m;
        $fn();
        $db->query('UPDATE member_coin_ownership_transactions SET created_at = ?, updated_at = ? WHERE id > ?', [$whenUtc, $whenUtc, $max]);
    }

    private function sell(string $case, string $qty, string $reserveUtc, ?string $debitUtc): void
    {
        $m   = $this->members[$case];
        $own = service('memberCoinOwnershipService');
        $ref = 'SELL-13B-' . strtoupper(bin2hex(random_bytes(4)));
        $this->ledgerOp(fn () => $own->reserveForSale($m['user_id'], $m['member_id'], $qty, $ref, $this->adminUserId), $reserveUtc);
        if ($debitUtc !== null) {
            $this->ledgerOp(fn () => $own->debitSale($m['user_id'], $m['member_id'], $qty, $ref, $this->adminUserId), $debitUtc);
        }
    }

    /**
     * Cohort cases A–M. Expected eligible coins per business date are fixed by the story (never read back from the code under test).
     */
    private function buildCohort(): void
    {
        $sandbox = service('financialSandboxService');
        $sandbox->openWindow(300, self::GROUP);
        try {
            $stories = [
                'A'  => ['story' => 'Buys 100 on 15 Oct, 50 more on 16 Oct. Mining for 15 Oct must use 100 (not today\'s 150).', 'exp' => [self::D0 => '0', self::D1 => '100', self::D2 => '150', self::D3 => '150']],
                'B'  => ['story' => '100 coins, fully LOCKED (mandatory lock).', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'C'  => ['story' => '40 available + 60 locked = 100 total.', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'D'  => ['story' => 'Registered member, no purchase.', 'exp' => [self::D0 => '0', self::D1 => '0', self::D2 => '0', self::D3 => '0']],
                'E'  => ['story' => '100 coins but account INACTIVE.', 'exp' => [self::D0 => '0', self::D1 => '0', self::D2 => '0', self::D3 => '0']],
                'F'  => ['story' => 'Purchases on different dates: 10 (10 Oct), 20 (12 Oct), 30 (17 Oct).', 'exp' => [self::D0 => '30', self::D1 => '30', self::D2 => '30', self::D3 => '60']],
                'G'  => ['story' => 'Partial sale BEFORE the business date: 100 bought, 30 sold 12 Oct.', 'exp' => [self::D0 => '70', self::D1 => '70', self::D2 => '70', self::D3 => '70']],
                'H'  => ['story' => 'Partial sale AFTER 15 Oct: 100 bought, 30 sold 17 Oct.', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '70']],
                'H2' => ['story' => 'Sale reserved 14 Oct, settled 17 Oct (reserved-coin policy probe).', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '70']],
                'I'  => ['story' => 'Lock movement only: 100 available, moved to locked 14 Oct — total unchanged.', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'J'  => ['story' => 'Backdated posting after later transactions: 100 (10 Oct), +200 (16 Oct), sale 50 (17 Oct).', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '300', self::D3 => '250']],
                'K'  => ['story' => 'Missing ledger evidence (tampered in the Case K stage only).', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'L1' => ['story' => 'Approved 00:30 IST on 15 Oct (= 14 Oct 19:00 UTC; UTC date differs from IST date).', 'exp' => [self::D0 => '0', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'L2' => ['story' => 'Approved 23:59:59 IST on 15 Oct (= 15 Oct 18:29:59 UTC).', 'exp' => [self::D0 => '0', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
                'L3' => ['story' => 'Approved 00:00:00 IST on 16 Oct (= 15 Oct 18:30:00 UTC; UTC date is still 15 Oct).', 'exp' => [self::D0 => '0', self::D1 => '0', self::D2 => '100', self::D3 => '100']],
                'M'  => ['story' => '100 locked; lock matured 12 Oct (maturity-policy probe).', 'exp' => [self::D0 => '100', self::D1 => '100', self::D2 => '100', self::D3 => '100']],
            ];
            foreach ($stories as $case => $spec) {
                $this->members[$case] = $this->register(strtolower($case)) + ['case' => $case, 'story' => $spec['story'], 'exp' => $spec['exp']];
            }

            $oct10 = $this->ist('2026-10-10 10:00:00');
            $this->buyAt('A', '100', false, $this->ist('2026-10-15 11:00:00'));
            $this->buyAt('A', '50', false, $this->ist('2026-10-16 10:00:00'));
            $this->buyAt('B', '100', true, $oct10);
            $this->buyAt('C', '40', false, $oct10);
            $this->buyAt('C', '60', true, $this->ist('2026-10-11 10:00:00'));
            $this->buyAt('E', '100', false, $oct10);
            db_connect()->table('member_profiles')->where('user_id', $this->members['E']['user_id'])->update(['account_status' => 'inactive']);
            $this->buyAt('F', '10', false, $oct10);
            $this->buyAt('F', '20', false, $this->ist('2026-10-12 10:00:00'));
            $this->buyAt('F', '30', false, $this->ist('2026-10-17 10:00:00'));
            $this->buyAt('G', '100', false, $oct10);
            $this->sell('G', '30', $this->ist('2026-10-12 10:00:00'), $this->ist('2026-10-12 10:05:00'));
            $this->buyAt('H', '100', false, $oct10);
            $this->sell('H', '30', $this->ist('2026-10-17 11:00:00'), $this->ist('2026-10-17 11:05:00'));
            $this->buyAt('H2', '100', false, $oct10);
            $this->sell('H2', '30', $this->ist('2026-10-14 12:00:00'), $this->ist('2026-10-17 11:00:00'));
            $this->buyAt('I', '100', false, $oct10);
            $mI = $this->members['I'];
            $this->ledgerOp(fn () => service('memberCoinOwnershipService')->applyLock($mI['user_id'], $mI['member_id'], '100', 'LOCK-13B-I', $this->adminUserId), $this->ist('2026-10-14 12:00:00'));
            $this->buyAt('J', '100', false, $oct10);
            $this->buyAt('J', '200', false, $this->ist('2026-10-16 10:00:00'));
            $this->sell('J', '50', $this->ist('2026-10-17 12:00:00'), $this->ist('2026-10-17 12:05:00'));
            $this->buyAt('K', '100', false, $oct10);
            $this->buyAt('L1', '100', false, '2026-10-14 19:00:00');
            $this->buyAt('L2', '100', false, '2026-10-15 18:29:59');
            $this->buyAt('L3', '100', false, '2026-10-15 18:30:00');
            $this->buyAt('M', '100', true, $this->ist('2026-10-10 10:00:00'), $this->ist('2026-10-12 12:00:00'));
        } finally {
            $sandbox->closeWindow();
        }

        $this->check('cohort_built', count($this->members) === 16, count($this->members) . ' synthetic members (cases A–M)');
        foreach ($this->members as $case => $m) {
            $sum = service('memberCoinOwnershipService')->summaryForUser($m['user_id']);
            $this->members[$case]['final_owned'] = (string) $sum['balance'];
        }
    }

    // ===================================================================== spark children

    /**
     * @param list<string>          $args    mining:post args (space-separated form)
     * @param array<string, string> $env
     * @return array{exit: int, json: ?array<string, mixed>, raw: string, seconds: float}
     */
    private function spark(string $command, array $args, array $env = []): array
    {
        $cmd  = array_merge([PHP_BINARY, ROOTPATH . 'spark', $command], $args);
        $envp = array_merge(getenv(), $env);
        $t0   = microtime(true);
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH, $envp);
        if (! is_resource($proc)) {
            throw new RuntimeException('Cannot start spark child process.');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        $json = null;
        $a = strpos($stdout, '{');
        $b = strrpos($stdout, '}');
        if ($a !== false && $b !== false && $b > $a) {
            $d    = json_decode(substr($stdout, $a, $b - $a + 1), true);
            $json = is_array($d) ? $d : null;
        }

        return ['exit' => $exit, 'json' => $json, 'raw' => $stdout . $stderr, 'seconds' => round(microtime(true) - $t0, 2)];
    }

    /** @return array<string, string> child-process environment for a policy fixture */
    private function policyEnv(string $fixture): array
    {
        $p = MiningCalculationPolicy::fixture($fixture);

        return [
            'platform_miningApprovalDayPolicy'  => $p->approvalDay,
            'platform_miningMonthDayDivisorMode' => $p->divisorMode,
            'platform_miningRoundingMode'       => $p->rounding,
            'platform_miningReservedCoinPolicy' => $p->reserved,
            'platform_miningMaturityPolicy'     => $p->maturity,
            'platform_miningPostRetryDelaySeconds' => '0',
        ];
    }

    /** @param list<string> $extra @return array{exit: int, json: ?array<string, mixed>, raw: string, seconds: float} */
    private function post(string $date, string $fixture, array $extra = [], array $env = []): array
    {
        return $this->spark('mining:post', array_merge(['--group', self::GROUP, '--date', $date, '--confirm', 'APPLY_STAGING', '--strict-exit'], $extra), $this->policyEnv($fixture) + $env);
    }

    // ===================================================================== independent math

    /** First-principles integer arithmetic (no MiningMath): returns amount at `$scale` places. */
    private function independentAmount(string $eligible, string $percent, int $divisor, int $scale, string $mode): string
    {
        $toUnits = static function (string $v): string {
            [$i, $f] = array_pad(explode('.', $v, 2), 2, '');
            $f = str_pad(substr($f, 0, 8), 8, '0');
            $s = ltrim($i . $f, '0');

            return $s === '' ? '0' : $s;
        };
        $num = bcmul(bcmul($toUnits($eligible), $toUnits($percent), 0), bcpow('10', (string) $scale, 0), 0);
        $den = bcmul(bcmul(bcpow('10', '16', 0), '100', 0), (string) $divisor, 0);
        $q   = $mode === 'half_up'
            ? bcdiv(bcadd(bcmul($num, '2', 0), $den, 0), bcmul($den, '2', 0), 0)
            : bcdiv($num, $den, 0);
        $q   = str_pad($q, $scale + 1, '0', STR_PAD_LEFT);

        return $scale === 0 ? $q : substr($q, 0, -$scale) . '.' . substr($q, -$scale);
    }

    // ===================================================================== stage: historical cases A–M

    /** @return array<string, mixed> */
    private function caseStage(): array
    {
        $order = [self::D3, self::D1, self::D2, self::D0]; // later date first: proves back-dated runs ignore later transactions
        $runs  = [];
        foreach ($order as $date) {
            $r = $this->post($date, 'T-CALENDAR-HALFUP');
            $runs[$date] = [
                'exit' => $r['exit'], 'status' => $r['json']['status'] ?? null, 'state' => $r['json']['state'] ?? null,
                'credited' => $r['json']['credited'] ?? null, 'credited_total' => $r['json']['credited_total'] ?? null,
                'failed' => $r['json']['failed'] ?? null, 'blocked' => $r['json']['blocked'] ?? null,
                'reconcile' => $r['json']['reconcile_status'] ?? null, 'cutoff_utc' => $r['json']['cutoff_utc'] ?? null,
                'policy_version' => $r['json']['policy_version'] ?? null, 'seconds' => $r['seconds'],
            ];
            $this->check('case_run_' . $date . '_posted_and_reconciled', $r['exit'] === 0 && ($r['json']['state'] ?? '') === 'COMPLETED' && ($r['json']['reconcile_status'] ?? '') === 'OK', 'exit=' . $r['exit'] . ' state=' . ($r['json']['state'] ?? 'n/a') . ' reconcile=' . ($r['json']['reconcile_status'] ?? 'n/a'));
        }

        $db   = db_connect();
        $rows = [];
        $fail = 0;
        foreach ($this->members as $case => $m) {
            $expectedWallet = '0.00000000';
            $actualWallet   = (string) ($db->query('SELECT COALESCE(SUM(amount),0) s FROM mining_wallet_ledger WHERE member_user_id = ?', [$m['user_id']])->getRow()->s);
            $caseRows = [];
            foreach ([self::D0, self::D1, self::D2, self::D3] as $date) {
                $eligible = $m['exp'][$date];
                $divisor  = 31;
                $expected = bccomp($eligible, '0', 8) === 0 ? '0.00000000' : $this->independentAmount($eligible, self::RATE_PERCENT, $divisor, 8, 'half_up');
                $expectedWallet = bcadd($expectedWallet, $expected, 8);
                $led = $db->query("SELECT entry_ref, amount FROM mining_wallet_ledger WHERE member_user_id = ? AND business_date = ? AND entry_type = 'daily_mining_credit'", [$m['user_id'], $date])->getRowArray();
                $pm  = $db->query('SELECT status, eligible_coins, reason FROM mining_post_members WHERE business_date = ? AND member_user_id = ?', [$date, $m['user_id']])->getRowArray();
                $actual = $led !== null ? bcadd((string) $led['amount'], '0', 8) : '0.00000000';
                $recordedEligible = $pm !== null ? bcadd((string) $pm['eligible_coins'], '0', 8) : null;
                $ok = bccomp($actual, $expected, 8) === 0 && $recordedEligible !== null && bccomp($recordedEligible, bcadd($eligible, '0', 8), 8) === 0;
                if (! $ok) {
                    $fail++;
                }
                $caseRows[] = [
                    'case' => $case, 'member_id' => $m['member_id'], 'business_date' => $date,
                    'eligible_expected' => bcadd($eligible, '0', 8), 'eligible_recorded' => $recordedEligible,
                    'monthly_rate_percent' => self::RATE_PERCENT, 'divisor' => $divisor,
                    'expected_daily_income' => $expected, 'actual_daily_income' => $actual,
                    'ledger_ref' => $led['entry_ref'] ?? null, 'member_run_status' => $pm['status'] ?? null, 'member_run_reason' => $pm['reason'] ?? null,
                    'result' => $ok ? 'PASS' : 'FAIL',
                ];
            }
            $walletOk = bccomp($expectedWallet, $actualWallet, 8) === 0 && bccomp($expectedWallet, bcadd((string) service('miningWalletService')->balanceFor($m['user_id'])['available_balance'], '0', 8), 8) === 0;
            if (! $walletOk) {
                $fail++;
            }
            foreach ($caseRows as $i => $cr) {
                $caseRows[$i]['wallet_balance_expected'] = $expectedWallet;
                $caseRows[$i]['wallet_balance_actual']   = bcadd((string) service('miningWalletService')->balanceFor($m['user_id'])['available_balance'], '0', 8);
                $caseRows[$i]['wallet_result']           = $walletOk ? 'PASS' : 'FAIL';
            }
            $rows = array_merge($rows, $caseRows);
            $this->check('case_' . $case . '_expected_equals_actual', ! in_array('FAIL', array_column($caseRows, 'result'), true) && $walletOk, $m['member_id'] . ' wallet expected ' . $expectedWallet . ' actual ' . $actualWallet);
        }

        // Case J: D1 was posted AFTER D3 (later transactions existed) and must not see them.
        $jD1 = $db->query('SELECT eligible_coins FROM mining_post_members WHERE business_date = ? AND member_user_id = ?', [self::D1, $this->members['J']['user_id']])->getRow();
        $this->check('case_J_backdated_posting_ignores_later_transactions', $jD1 !== null && bccomp((string) $jD1->eligible_coins, '100', 8) === 0, 'D1 posted after D3; J eligible on D1=' . ($jD1->eligible_coins ?? 'n/a') . ' (today\'s balance is 250)');

        return ['runs' => $runs, 'rows' => $rows, 'failures' => $fail, 'run_order' => $order];
    }

    /** Policy comparisons computed read-only with the snapshot service (nothing posted). @return array<string, mixed> */
    private function policyPreviews(): array
    {
        $snap = new MiningEligibleOwnershipSnapshotService();
        $ids  = array_map(static fn (array $m): int => $m['user_id'], $this->members);
        $byUser = [];
        foreach ($this->members as $c => $m) {
            $byUser[$m['user_id']] = $c;
        }
        $out = [];
        // [case, business date, expected eligible] — start-of-day policy drops coins approved on the business date itself.
        $probe = [
            'T-RESERVED-EXCL' => [['H2', self::D1, '70'], ['H', self::D3, '70'], ['B', self::D1, '100']],
            'T-MATURED-EXCL'  => [['M', self::D1, '0'], ['B', self::D1, '100']],
            'T-NEXTDAY-DOWN'  => [['L1', self::D1, '0'], ['L1', self::D2, '100'], ['L2', self::D1, '0'], ['L3', self::D2, '0'], ['L3', self::D3, '100'], ['A', self::D1, '0'], ['A', self::D2, '100'], ['A', self::D3, '150'], ['B', self::D1, '100']],
        ];
        foreach ($probe as $fixture => $cases) {
            $policy = MiningCalculationPolicy::fixture($fixture);
            foreach ($cases as [$case, $date, $expected]) {
                $cut = $snap->cutoff($date, $policy, null, false)['cutoff_utc'];
                $r   = $snap->snapshotBatch([$this->members[$case]['user_id']], $cut, $policy)[$this->members[$case]['user_id']];
                $ok  = bccomp($r['eligible'], bcadd($expected, '0', 8), 8) === 0 && $r['status'] !== MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED;
                $out[] = ['policy' => $fixture, 'case' => $case, 'business_date' => $date, 'cutoff_utc' => $cut, 'eligible_expected' => bcadd($expected, '0', 8), 'eligible_actual' => $r['eligible'], 'owned' => $r['owned'], 'locked' => $r['locked'], 'reserved' => $r['reserved'], 'result' => $ok ? 'PASS' : 'FAIL'];
                $this->check('policy_' . $fixture . '_' . $case . '_' . $date, $ok, 'expected ' . $expected . ' actual ' . $r['eligible']);
            }
        }

        // L-case under the default full-day policy for the exact IST/UTC boundary seconds.
        return ['rows' => $out, 'note' => 'Read-only snapshot comparisons; no policy is selected for production.'];
    }

    /** @return array<string, mixed> */
    private function timezoneStage(): array
    {
        $snap = new MiningEligibleOwnershipSnapshotService();
        $zones = ['UTC', 'America/New_York', 'Pacific/Auckland', 'Asia/Tokyo', 'Europe/London', 'Asia/Kolkata'];
        $orig = date_default_timezone_get();
        $res  = [];
        try {
            foreach ($zones as $z) {
                date_default_timezone_set($z);
                $res[$z] = ['start_utc_15_oct' => $snap->dayStartUtc(self::D1), 'start_utc_16_oct' => $snap->dayStartUtc(self::D2)];
            }
        } finally {
            date_default_timezone_set($orig);
        }
        $unique = array_unique(array_map('json_encode', $res));
        $this->check('business_date_boundary_independent_of_process_timezone', count($unique) === 1 && $res['UTC']['start_utc_15_oct'] === '2026-10-14 18:30:00', 'IST midnight of 15 Oct = ' . $res['UTC']['start_utc_15_oct'] . ' UTC under ' . count($zones) . ' PHP timezones');

        return ['zones' => $res, 'ist_midnight_15_oct_utc' => $res['UTC']['start_utc_15_oct']];
    }

    // ===================================================================== stage: precision

    /** @return array<string, mixed> */
    private function precisionStage(): array
    {
        $rates = new MiningRateService();
        $cases = [
            ['Feb 28 days (2026-02-10)',        '1000', '3', '2026-02-10', 'calendar_days', 'half_up'],
            ['Feb 29 days leap (2028-02-10)',   '1000', '3', '2028-02-10', 'calendar_days', 'half_up'],
            ['Apr 30 days (2026-04-10)',        '1000', '3', '2026-04-10', 'calendar_days', 'half_up'],
            ['Jan 31 days (2026-01-10)',        '1000', '3', '2026-01-10', 'calendar_days', 'half_up'],
            ['Jan 31 days DOWN',                '1000', '3', '2026-01-10', 'calendar_days', 'down'],
            ['Fixed-30 in 31-day month',        '1000', '3', '2026-01-10', 'fixed_30',      'half_up'],
            ['Dec 31 (Dec rate month)',         '1000', '3', '2026-12-31', 'calendar_days', 'half_up'],
            ['Jan 1 (Jan rate month)',          '1000', '3', '2027-01-01', 'calendar_days', 'half_up'],
            ['Smallest balance 0.00000001',     '0.00000001', '3', '2026-10-15', 'calendar_days', 'half_up'],
            ['Small balance 0.5',               '0.5',  '3', '2026-10-15', 'calendar_days', 'down'],
            ['Large balance',                   '999999999999.99999999', '3', '2026-10-15', 'calendar_days', 'half_up'],
            ['Large balance DOWN',              '999999999999.99999999', '3', '2026-10-15', 'calendar_days', 'down'],
            ['Fractional balance + fractional rate', '123.45678901', '2.75', '2026-10-15', 'calendar_days', 'half_up'],
            ['Tiny fractional rate 0.00000005%', '1000', '0.00000005', '2026-10-15', 'fixed_30', 'half_up'],
            ['Exact half-unit boundary HALF_UP', '0.00015', '0.1', '2026-10-15', 'fixed_30', 'half_up'],
            ['Exact half-unit boundary DOWN',    '0.00015', '0.1', '2026-10-15', 'fixed_30', 'down'],
            ['Zero rate',                       '1000', '0', '2026-10-15', 'calendar_days', 'half_up'],
            ['Max rate 100%',                   '1000', '100', '2026-10-15', 'calendar_days', 'half_up'],
            ['Illustration 1000 x 3% / 31 HALF_UP', '1000', '3', '2026-10-15', 'calendar_days', 'half_up'],
        ];
        $rows = [];
        foreach ($cases as [$label, $eligible, $pct, $date, $divMode, $mode]) {
            $rate    = ['rate_percent' => bcadd($pct, '0', 8), 'rate_basis' => 'monthly', 'precision_scale' => 8, 'effective_month' => substr($date, 0, 7)];
            $div     = MiningMath::divisor('monthly', $date, $rate['effective_month'], $divMode);
            $engine  = $rates->previewAmount(bcadd($eligible, '0', 8), $rate, $date, $divMode, $mode);
            $indep   = $this->independentAmount($eligible, $rate['rate_percent'], $div, 8, $mode);
            $repeat  = [$rates->previewAmount(bcadd($eligible, '0', 8), $rate, $date, $divMode, $mode), $rates->previewAmount(bcadd($eligible, '0', 8), $rate, $date, $divMode, $mode)];
            $ok      = $engine === $indep && $repeat[0] === $engine && $repeat[1] === $engine;
            $rows[]  = ['case' => $label, 'eligible' => bcadd($eligible, '0', 8), 'rate_percent' => $rate['rate_percent'], 'date' => $date, 'divisor_mode' => $divMode, 'divisor' => $div, 'rounding' => $mode, 'engine' => $engine, 'independent' => $indep, 'deterministic' => $repeat[0] === $repeat[1], 'result' => $ok ? 'PASS' : 'FAIL'];
            $this->check('precision_' . preg_replace('/\W+/', '_', strtolower($label)), $ok, $engine . ' vs independent ' . $indep);
        }

        $illus = $rates->previewAmount('1000.00000000', ['rate_percent' => '3.00000000', 'rate_basis' => 'monthly', 'precision_scale' => 8, 'effective_month' => '2026-10'], '2026-10-15', 'calendar_days', 'half_up');
        $this->check('illustration_0_96774194_calendar_half_up', $illus === '0.96774194', $illus);
        $fixed = $rates->previewAmount('1000.00000000', ['rate_percent' => '3.00000000', 'rate_basis' => 'monthly', 'precision_scale' => 8, 'effective_month' => '2026-10'], '2026-10-15', 'fixed_30', 'half_up');
        $this->check('illustration_1_00000000_fixed_30', $fixed === '1.00000000', $fixed);

        // Invalid inputs fail closed.
        $invalid = [];
        foreach ([['-1', '3'], ['abc', '3'], ['1000', '-3'], ['1000', '100.00000001'], ['1000', 'x'], ['1000', '']] as [$e, $p]) {
            try {
                MiningMath::dailyAmount($e, $p, 31, 8, 'half_up');
                $invalid[] = ['eligible' => $e, 'percent' => $p, 'rejected' => false];
            } catch (\InvalidArgumentException) {
                $invalid[] = ['eligible' => $e, 'percent' => $p, 'rejected' => true];
            }
        }
        $this->check('invalid_rate_and_balance_inputs_rejected', ! in_array(false, array_column($invalid, 'rejected'), true), json_encode($invalid));
        try {
            MiningMath::dailyAmount('1', '1', 31, 8, 'banker');
            $badMode = false;
        } catch (\InvalidArgumentException) {
            $badMode = true;
        }
        $this->check('unknown_rounding_mode_rejected', $badMode, 'banker rounding is not a configured policy');

        // Comparative preview: policy choice impact for one month of the cohort's 15 Oct eligible coins.
        $cohortEligible = '0.00000000';
        foreach ($this->members as $m) {
            $cohortEligible = bcadd($cohortEligible, $m['exp'][self::D1], 8);
        }
        $cmp = [];
        foreach ([['calendar_days', 'half_up'], ['calendar_days', 'down'], ['fixed_30', 'half_up'], ['fixed_30', 'down']] as [$dm, $rm]) {
            $cmp[] = [
                'divisor_mode' => $dm, 'rounding' => $rm,
                'per_1000_coins_31_day_month' => MiningMath::dailyAmount('1000', '3', MiningMath::divisor('monthly', '2026-10-15', '2026-10', $dm), 8, $rm),
                'per_1000_coins_30_day_month' => MiningMath::dailyAmount('1000', '3', MiningMath::divisor('monthly', '2026-11-15', '2026-11', $dm), 8, $rm),
                'per_1000_coins_28_day_month' => MiningMath::dailyAmount('1000', '3', MiningMath::divisor('monthly', '2026-02-15', '2026-02', $dm), 8, $rm),
                'cohort_total_15_oct' => MiningMath::dailyAmount($cohortEligible, '3', MiningMath::divisor('monthly', '2026-10-15', '2026-10', $dm), 8, $rm),
            ];
        }
        $monthSum = [];
        foreach (['fixed_30', 'calendar_days'] as $dm) {
            $sum = '0.00000000';
            for ($d = 1; $d <= 31; $d++) {
                $sum = bcadd($sum, MiningMath::dailyAmount('1000', '3', MiningMath::divisor('monthly', sprintf('2026-10-%02d', $d), '2026-10', $dm), 8, 'half_up'), 8);
            }
            $monthSum[$dm] = $sum;
        }

        return ['rows' => $rows, 'invalid_inputs' => $invalid, 'comparative_previews' => $cmp, 'month_total_per_1000_coins_oct_31_days' => $monthSum,
            'month_total_note' => 'Fixed-30 pays 31 x 1.00000000 = 31.00000000 in a 31-day month (3.1% instead of 3%); calendar-days pays ~30.00000000. No residual redistribution / final-day adjustment is applied.'];
    }

    // ===================================================================== stage: scheduler simulations

    /** @return array<string, mixed> */
    private function schedulerStage(): array
    {
        $sim   = [];
        $db    = db_connect();
        $add   = function (string $name, string $expected, array $r, bool $ok, string $note = '') use (&$sim): void {
            $sim[] = ['scenario' => $name, 'expected' => $expected, 'exit' => $r['exit'] ?? null, 'scheduler_status' => $this->statusOf($r), 'credited' => $r['json']['credited'] ?? null, 'duplicates' => $r['json']['duplicates'] ?? null, 'state' => $r['json']['state'] ?? null, 'result' => $ok ? 'PASS' : 'FAIL', 'note' => $note];
            $this->check('scheduler_' . $name, $ok, $expected . ' | exit=' . ($r['exit'] ?? 'n/a') . ' status=' . $this->statusOf($r) . ' ' . $note);
        };
        $eligibleMembers = 0;
        foreach ($this->members as $m) {
            if ($m['case'] !== 'D' && $m['case'] !== 'E') {
                $eligibleMembers++;
            }
        }

        // 1-3: scheduled yesterday, repeat, manual same date
        $y  = (new DateTimeImmutable('yesterday', new DateTimeZone(self::TZ_IST)))->format('Y-m-d');
        $s1 = $this->spark('mining:post', ['--group', self::GROUP, '--scheduled', '--strict-exit', '--confirm', 'APPLY_STAGING'], $this->policyEnv('T-CALENDAR-HALFUP'));
        $add('scheduled_yesterday_posts', 'POSTED exit 0, credited=' . $eligibleMembers . ' for ' . $y, $s1, $s1['exit'] === 0 && ($s1['json']['business_date'] ?? '') === $y && (int) ($s1['json']['credited'] ?? -1) === $eligibleMembers && ($s1['json']['reconcile_status'] ?? '') === 'OK');
        $s2 = $this->spark('mining:post', ['--group', self::GROUP, '--scheduled', '--strict-exit', '--confirm', 'APPLY_STAGING'], $this->policyEnv('T-CALENDAR-HALFUP'));
        $add('scheduled_repeat_zero_new_credits', 'POSTED exit 0, credited_now=0, duplicates=' . $eligibleMembers, $s2, $s2['exit'] === 0 && (int) ($s2['json']['credited'] ?? -1) === 0 && (int) ($s2['json']['duplicates'] ?? -1) === $eligibleMembers);
        $s3 = $this->post($y, 'T-CALENDAR-HALFUP');
        $add('manual_same_date_zero_new_credits', 'POSTED exit 0, credited_now=0', $s3, $s3['exit'] === 0 && (int) ($s3['json']['credited'] ?? -1) === 0);

        // 4: concurrent invocation (job lock held by another scheduler)
        $date4 = '2026-10-18';
        $locks = new OpsJobLockService();
        $locks->acquire('mining_post:' . $date4, 'simulated-other-scheduler', 600);
        $s4 = $this->post($date4, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $locks->release('mining_post:' . $date4, 'simulated-other-scheduler');
        $dayRows = (int) $db->table('mining_post_day_runs')->where('business_date', $date4)->countAllResults();
        $add('concurrent_invocation_skipped', 'SKIPPED exit 20, no day run, no credits', $s4, $s4['exit'] === 20 && $dayRows === 0 && (int) $db->table('mining_wallet_ledger')->where('business_date', $date4)->countAllResults() === 0);

        // 5-6: interrupted batch, then resume
        $s5 = $this->post($date4, 'T-CALENDAR-HALFUP', ['--scheduled', '--batch-size', '3', '--max-batches', '1']);
        $partialCredits = (int) $db->table('mining_wallet_ledger')->where('business_date', $date4)->countAllResults();
        $add('interrupted_batch_is_partial', 'PARTIAL exit 40, <= 3 credits, pending > 0', $s5, $s5['exit'] === 40 && ($s5['json']['state'] ?? '') === 'PARTIAL' && $partialCredits <= 3 && (int) ($s5['json']['pending'] ?? 0) > 0, 'credits so far=' . $partialCredits);
        $s6 = $this->post($date4, 'T-CALENDAR-HALFUP', ['--scheduled', '--batch-size', '3']);
        $totalCredits = (int) $db->table('mining_wallet_ledger')->where('business_date', $date4)->countAllResults();
        $dup = (int) $db->query("SELECT COUNT(*) c FROM (SELECT member_user_id FROM mining_wallet_ledger WHERE business_date = ? GROUP BY member_user_id HAVING COUNT(*) > 1) x", [$date4])->getRow()->c;
        $add('resume_completes_without_duplicates_or_skips', 'POSTED exit 0, total credits=' . $eligibleMembers . ', no duplicate', $s6, $s6['exit'] === 0 && $totalCredits === $eligibleMembers && $dup === 0 && (int) ($s6['json']['credited'] ?? -1) === $eligibleMembers - $partialCredits, 'credited_now=' . ($s6['json']['credited'] ?? 'n/a') . ' total=' . $totalCredits);

        // 7: DB connection failure
        $s7 = $this->post('2026-10-19', 'T-CALENDAR-HALFUP', ['--scheduled'], ['PHASE13B_DB_PASS' => 'definitely-wrong-password']);
        $add('db_connection_failure_fails_closed', 'FAILED exit 50, nothing written', $s7, $s7['exit'] === 50 && (int) $db->table('mining_wallet_ledger')->where('business_date', '2026-10-19')->countAllResults() === 0);

        // 8: missing approved rate
        $s8 = $this->post('2025-01-10', 'T-CALENDAR-HALFUP', ['--scheduled']);
        $add('missing_approved_rate_blocked', 'BLOCKED exit 30', $s8, $s8['exit'] === 30);

        // 9-10: disabled switches (live DB group: gates refuse before any query)
        $live = $this->liveFingerprint()['kill_switches'] ?? [];
        if (($live['miningPostingEnabled'] ?? true) === false && ($live['miningAutoPostEnabled'] ?? true) === false) {
            $s10 = $this->spark('mining:post', ['--scheduled', '--strict-exit']);
            $add('disabled_auto_switch_skips', 'SKIPPED exit 20 (live gates; miningAutoPostEnabled=false)', $s10, $s10['exit'] === 20);
            $s9 = $this->spark('mining:post', ['--allow-production', '--confirm', 'I_AUTHORIZE_PRODUCTION_CHANGE', '--strict-exit']);
            $add('disabled_posting_switch_blocks', 'BLOCKED exit 30 (miningPostingEnabled=false)', $s9, $s9['exit'] === 30);
        } else {
            $this->check('scheduler_switch_scenarios_skipped_for_safety', false, 'live kill-switch is not OFF — scenarios not executed');
        }
        $s10b = $this->spark('mining:post', ['--group', self::GROUP, '--scheduled', '--strict-exit']);
        $add('sandbox_scheduled_without_confirmation_skips', 'SKIPPED exit 20 (not authorized)', $s10b, $s10b['exit'] === 20);

        // 11: historical ownership mismatch after posting (ledger evidence shifted past the cutoff)
        $b = $this->members['B']['user_id'];
        $orig = $db->query('SELECT id, created_at FROM member_coin_ownership_transactions WHERE member_user_id = ? ORDER BY id', [$b])->getResultArray();
        $db->query("UPDATE member_coin_ownership_transactions SET created_at = '2026-11-30 00:00:00' WHERE member_user_id = ?", [$b]);
        $s11 = $this->post(self::D3, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $types = array_column((array) ($s11['json']['mismatches'] ?? []), 'type');
        $add('historical_ownership_mismatch_detected', 'PARTIAL exit 40, mismatch historical_ownership_mismatch', $s11, $s11['exit'] === 40 && in_array('historical_ownership_mismatch', $types, true) && ($s11['json']['reconcile_status'] ?? '') === 'MISMATCH');
        foreach ($orig as $o) {
            $db->query('UPDATE member_coin_ownership_transactions SET created_at = ? WHERE id = ?', [$o['created_at'], $o['id']]);
        }
        $s11b = $this->post(self::D3, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $add('historical_mismatch_clears_after_evidence_restored', 'POSTED exit 0 after restore', $s11b, $s11b['exit'] === 0 && ($s11b['json']['reconcile_status'] ?? '') === 'OK');

        // 12: reconciliation mismatch (ledger amount altered)
        $db->query("UPDATE mining_wallet_ledger SET amount = amount + 0.00000001 WHERE business_date = ? AND member_user_id = ?", [self::D3, $b]);
        $s12 = $this->post(self::D3, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $types12 = array_column((array) ($s12['json']['mismatches'] ?? []), 'type');
        $add('reconciliation_mismatch_detected', 'PARTIAL exit 40, ledger/run mismatch reported', $s12, $s12['exit'] === 40 && $types12 !== [] && ($s12['json']['reconcile_status'] ?? '') === 'MISMATCH', 'types=' . implode(',', $types12));
        $db->query("UPDATE mining_wallet_ledger SET amount = amount - 0.00000001 WHERE business_date = ? AND member_user_id = ?", [self::D3, $b]);
        $s12b = $this->post(self::D3, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $add('reconciliation_clears_after_restore', 'POSTED exit 0 after restore', $s12b, $s12b['exit'] === 0 && ($s12b['json']['reconcile_status'] ?? '') === 'OK');

        // 13: policy changed between interruption and resume
        $s13 = $this->post('2026-10-19', 'T-CALENDAR-HALFUP', ['--scheduled', '--batch-size', '3', '--max-batches', '1']);
        $s13b = $this->post('2026-10-19', 'T-FIXED30-HALFUP', ['--scheduled']);
        $add('policy_change_on_resume_blocked', 'BLOCKED exit 30 (frozen policy differs)', $s13b, $s13['exit'] === 40 && $s13b['exit'] === 30 && str_contains((string) $s13b['raw'], 'Policy changed'));
        $s13c = $this->post('2026-10-19', 'T-CALENDAR-HALFUP', ['--scheduled']);
        $add('resume_with_original_policy_completes', 'POSTED exit 0', $s13c, $s13c['exit'] === 0 && ($s13c['json']['state'] ?? '') === 'COMPLETED');

        // Incomplete business day on a sandbox DB needs the explicit flag; live never allows it.
        $today = (new DateTimeImmutable('today', new DateTimeZone(self::TZ_IST)))->format('Y-m-d');
        $s14 = $this->post($today, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $add('incomplete_business_day_blocked_without_flag', 'BLOCKED exit 30', $s14, $s14['exit'] === 30);
        $s15 = $this->spark('mining:post', ['--group', self::GROUP, '--date', $today, '--dry-run', '--strict-exit'], $this->policyEnv('T-CALENDAR-HALFUP'));
        $add('dry_run_never_counted_as_posting', 'DRY_RUN exit 10, no ledger row for today', $s15, $s15['exit'] === 10 && (int) $db->table('mining_wallet_ledger')->where('business_date', $today)->countAllResults() === 0 && (int) $db->table('mining_post_day_runs')->where('business_date', $today)->countAllResults() === 0);

        $health = (new MiningPostAlertService(null, sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'p13b-alerts-' . bin2hex(random_bytes(3)) . '.log'));
        $okRun   = $health->checkScheduledRun(self::D3);
        $missing = $health->checkScheduledRun('2026-09-01');
        $this->check('watchdog_detects_completed_and_missing_runs', $okRun['ok'] === true && $missing['ok'] === false && $missing['code'] === 'missing_scheduled_run', 'completed=' . json_encode($okRun['ok']) . ' missing_code=' . $missing['code']);

        return ['scenarios' => $sim, 'ledger_log_tail' => $this->logTail()];
    }

    /** @param array<string, mixed> $r */
    private function statusOf(array $r): string
    {
        if (preg_match('/SCHEDULER_STATUS=([A-Z_]+)/', (string) ($r['raw'] ?? ''), $m) === 1) {
            return $m[1];
        }

        return 'n/a';
    }

    private function dayRunUnfinished(string $date): bool
    {
        $row = db_connect()->table('mining_post_day_runs')->where('business_date', $date)->get()->getRowArray();

        return is_array($row) && array_key_exists('finished_at', $row) && $row['finished_at'] === null && $row['state'] !== 'COMPLETED';
    }

    // ===================================================================== stage: case K

    /** @return array<string, mixed> */
    private function caseKStage(): array
    {
        $db   = db_connect();
        $date = '2026-10-20';
        $k    = $this->members['K'];
        $rows = $db->table('member_coin_ownership_transactions')->where('member_user_id', $k['user_id'])->get()->getResultArray();
        $db->query('DELETE FROM member_coin_ownership_transactions WHERE member_user_id = ?', [$k['user_id']]);

        $first = $this->post($date, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $pm    = $db->query('SELECT status, reason, eligible_coins FROM mining_post_members WHERE business_date = ? AND member_user_id = ?', [$date, $k['user_id']])->getRowArray();
        $credits = (int) $db->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults();
        $kCredit = (int) $db->table('mining_wallet_ledger')->where('business_date', $date)->where('member_user_id', $k['user_id'])->countAllResults();
        $this->check('case_K_missing_evidence_blocks_member_and_day_is_partial', $first['exit'] === 40 && ($first['json']['state'] ?? '') === 'PARTIAL' && ($pm['status'] ?? '') === 'BLOCKED' && $kCredit === 0 && (int) ($first['json']['blocked'] ?? 0) === 1, 'exit=' . $first['exit'] . ' K status=' . ($pm['status'] ?? 'n/a') . ' reason=' . ($pm['reason'] ?? ''));
        $this->check('case_K_other_members_still_credited', $credits === 13, 'credits=' . $credits . ' (13 eligible members besides K)');
        $this->check('case_K_day_never_marked_complete', ($first['json']['state'] ?? '') !== 'COMPLETED' && $this->dayRunUnfinished($date), 'finished_at is NULL while a member is BLOCKED');

        $db->table('member_coin_ownership_transactions')->insertBatch($rows);
        $second = $this->post($date, 'T-CALENDAR-HALFUP', ['--scheduled']);
        $kLedger = $db->query("SELECT amount FROM mining_wallet_ledger WHERE business_date = ? AND member_user_id = ?", [$date, $k['user_id']])->getRowArray();
        $expected = $this->independentAmount('100', self::RATE_PERCENT, 31, 8, 'half_up');
        $this->check('case_K_evidence_restored_resume_credits_member_once', $second['exit'] === 0 && ($second['json']['state'] ?? '') === 'COMPLETED' && $kLedger !== null && bcadd((string) $kLedger['amount'], '0', 8) === $expected && (int) ($second['json']['credited'] ?? -1) === 1, 'resume credited_now=' . ($second['json']['credited'] ?? 'n/a') . ' amount=' . ($kLedger['amount'] ?? 'n/a'));

        return [
            'business_date' => $date, 'member_id' => $k['member_id'],
            'first_run' => ['exit' => $first['exit'], 'state' => $first['json']['state'] ?? null, 'blocked' => $first['json']['blocked'] ?? null, 'member_status' => $pm['status'] ?? null, 'member_reason' => $pm['reason'] ?? null],
            'second_run' => ['exit' => $second['exit'], 'state' => $second['json']['state'] ?? null, 'credited_now' => $second['json']['credited'] ?? null],
            'missing_evidence_reported' => $pm['reason'] ?? null,
        ];
    }

    // ===================================================================== stage: CLI audit

    /** @return array<string, mixed> */
    private function cliAuditStage(): array
    {
        $commands = [
            ['mining:post', 'date'], ['mining:daily', 'date'], ['mining:dry-run', 'date'], ['mining:reconcile', 'date'], ['mining:simulate', 'date'],
            ['mining:status', null], ['mining:post-verify', null], ['mining:p2p-reconcile', 'limit'], ['coins:selling-reconcile', 'limit'],
            ['members:correct-sequence', 'group'], ['locking:remediate-mandatory', 'group'], ['financial:staging-e2e', 'seconds'],
            ['financial:test-window', 'seconds'], ['coins:check-maturity', 'limit'], ['payment:deliver-alerts', 'limit'], ['payment:integrity-monitor', 'limit'],
        ];
        $rows = [];
        foreach ($commands as [$cmd, $opt]) {
            $unknown = $this->spark($cmd, ['--definitely-unknown-flag']);
            $equals  = $opt !== null ? $this->spark($cmd, ['--' . $opt . '=' . ($opt === 'group' ? self::GROUP : ($opt === 'date' ? '2026-10-15' : '5'))]) : null;
            $missing = $opt !== null ? $this->spark($cmd, ['--' . $opt]) : null;
            $ok = $unknown['exit'] === 2 && ($equals === null || $equals['exit'] === 2) && ($missing === null || $missing['exit'] === 2);
            $rows[] = ['command' => $cmd, 'unknown_option_exit' => $unknown['exit'], 'equals_syntax_exit' => $equals['exit'] ?? null, 'missing_value_exit' => $missing['exit'] ?? null, 'result' => $ok ? 'PASS' : 'FAIL'];
            $this->check('cli_' . str_replace([':', '-'], '_', $cmd) . '_rejects_malformed_syntax', $ok, 'unknown=' . $unknown['exit'] . ' equals=' . ($equals['exit'] ?? '-') . ' missing=' . ($missing['exit'] ?? '-'));
        }

        $matrix = [
            'equals_group'          => [['--group=phase13b'], 'equals'],
            'unsupported_group'     => [['--group', 'coin_platform'], 'Invalid value'],
            'unsupported_group_tests' => [['--group', 'tests'], 'Invalid value'],
            'missing_value'         => [['--group'], 'requires a value'],
            'flag_given_value_is_positional' => [['--scheduled', 'now'], 'Unexpected argument'],
            'duplicate_group'       => [['--group', 'phase13b', '--group', 'phase13a'], 'more than once'],
            'conflicting'           => [['--dry-run', '--confirm', 'APPLY_STAGING'], 'Conflicting'],
            'short_option'          => [['-g', 'phase13b'], 'Short option'],
            'bad_limit'             => [['--limit', 'abc'], 'positive whole number'],
            'bad_date'              => [['--date', '15-10-2026'], 'must be today'],
        ];
        $matrixRows = [];
        foreach ($matrix as $name => [$args, $needle]) {
            $p  = CliArgs::parse($args, \App\Commands\MiningPost::argSpec());
            $ok = $p['errors'] !== [] && str_contains(implode(' ', $p['errors']), $needle === 'equals' ? 'Unsupported syntax' : $needle);
            $matrixRows[] = ['case' => $name, 'args' => implode(' ', $args), 'errors' => $p['errors'], 'result' => $ok ? 'PASS' : 'FAIL'];
            $this->check('cli_parser_' . $name, $ok, implode(' | ', $p['errors']));
        }
        $valid = CliArgs::parse(['--group', 'phase13b', '--date', 'yesterday', '--scheduled', '--strict-exit', '--confirm', 'APPLY_STAGING', '--batch-size', '250'], \App\Commands\MiningPost::argSpec());
        $this->check('cli_parser_valid_command_accepted', $valid['errors'] === [] && $valid['options']['group'] === 'phase13b', 'options=' . json_encode($valid['options']));

        $dry = $this->spark('mining:post', ['--group', self::GROUP, '--date', self::D1]);
        $this->check('cli_valid_dry_run_preserved', $dry['exit'] === 0 && ($dry['json']['status'] ?? '') === 'dry_run', 'exit=' . $dry['exit'] . ' status=' . ($dry['json']['status'] ?? 'n/a'));
        $unknownGroup = $this->spark('mining:post', ['--group', 'definitely_not_a_group']);
        $this->check('cli_unknown_group_never_falls_back_to_default_db', $unknownGroup['exit'] === 2 && ! str_contains((string) $unknownGroup['raw'], 'coin_platform'), 'exit=' . $unknownGroup['exit']);

        return ['commands' => $rows, 'parser_matrix' => $matrixRows];
    }

    // ===================================================================== stage: performance

    /**
     * @param list<int> $sizes
     * @return array<string, mixed>
     */
    private function performanceStage(array $sizes, bool $batchCompare): array
    {
        $out = ['sizes' => [], 'batch_size_comparison' => [], 'resume' => null, 'note' => 'Measured on this machine (local MySQL + PHP CLI child processes). Synthetic data.'];
        foreach ($sizes as $n) {
            $out['sizes'][(string) $n] = $this->performanceRun($n, 500, '2026-10-17');
        }
        if ($batchCompare) {
            $this->seedBulk(2000);
            foreach ([100, 250, 500, 1000] as $i => $b) {
                $date = sprintf('2026-10-%02d', 17 + $i);
                $out['batch_size_comparison'][(string) $b] = $this->measure('mining:post', $date, $b);
            }
        }
        // Resume: interrupt a 5000-member run after 3 batches and measure recovery.
        $this->seedBulk(5000);
        $date = '2026-10-17';
        $a = $this->post($date, 'T-CALENDAR-HALFUP', ['--batch-size', '500', '--max-batches', '3']);
        $b = $this->post($date, 'T-CALENDAR-HALFUP', ['--batch-size', '500']);
        $dup = (int) db_connect()->query("SELECT COUNT(*) c FROM (SELECT member_user_id FROM mining_wallet_ledger WHERE business_date = ? GROUP BY member_user_id HAVING COUNT(*) > 1) x", [$date])->getRow()->c;
        $ledger = (int) db_connect()->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults();
        $out['resume'] = [
            'members' => 5000, 'interrupted_run' => ['exit' => $a['exit'], 'state' => $a['json']['state'] ?? null, 'credited_now' => $a['json']['credited'] ?? null, 'seconds' => $a['seconds']],
            'recovery_run' => ['exit' => $b['exit'], 'state' => $b['json']['state'] ?? null, 'credited_now' => $b['json']['credited'] ?? null, 'seconds' => $b['seconds']],
            'total_credits' => $ledger, 'duplicate_groups' => $dup,
        ];
        $this->check('perf_resume_5000_no_duplicates_no_skips', $a['exit'] === 40 && $b['exit'] === 0 && $dup === 0 && $ledger === 5000 && (int) ($a['json']['credited'] ?? 0) + (int) ($b['json']['credited'] ?? 0) === 5000, 'ledger=' . $ledger . ' dupes=' . $dup);

        return $out;
    }

    /** @return array<string, mixed> */
    private function performanceRun(int $n, int $batch, string $date): array
    {
        $this->seedBulk($n);
        $first  = $this->measure('mining:post', $date, $batch);
        $second = $this->measure('mining:post', $date, $batch);
        $db     = db_connect();
        $ledger = (int) $db->table('mining_wallet_ledger')->where('business_date', $date)->countAllResults();
        $sum    = (string) $db->query('SELECT COALESCE(SUM(amount),0) s FROM mining_wallet_ledger WHERE business_date = ?', [$date])->getRow()->s;
        $dup    = (int) $db->query("SELECT COUNT(*) c FROM (SELECT member_user_id FROM mining_wallet_ledger WHERE business_date = ? GROUP BY member_user_id HAVING COUNT(*) > 1) x", [$date])->getRow()->c;
        $expectedSum = '0.00000000';
        for ($i = 1; $i <= $n; $i++) {
            $expectedSum = bcadd($expectedSum, $this->independentAmount((string) $this->bulkQty($i), self::RATE_PERCENT, 31, 8, 'half_up'), 8);
        }
        $ok = $first['exit'] === 0 && $ledger === $n && $dup === 0 && bcadd($sum, '0', 8) === $expectedSum && (int) ($second['credited'] ?? -1) === 0;
        $this->check('perf_' . $n . '_members_posted_reconciled_idempotent', $ok, 'ledger=' . $ledger . ' sum=' . $sum . ' expected=' . $expectedSum . ' dupes=' . $dup . ' repeat_credited=' . ($second['credited'] ?? 'n/a'));

        return ['members' => $n, 'batch_size' => $batch, 'first_run' => $first, 'repeat_run' => $second, 'ledger_rows' => $ledger, 'ledger_sum' => $sum, 'expected_sum' => $expectedSum, 'duplicate_groups' => $dup, 'result' => $ok ? 'PASS' : 'FAIL'];
    }

    /** @return array<string, mixed> */
    private function measure(string $command, string $date, int $batch): array
    {
        $r = $this->post($date, 'T-CALENDAR-HALFUP', ['--batch-size', (string) $batch]);
        $j = $r['json'] ?? [];

        return [
            'exit' => $r['exit'], 'state' => $j['state'] ?? null, 'wall_seconds' => $r['seconds'], 'duration_ms' => $j['duration_ms'] ?? null,
            'peak_memory_mb' => $j['peak_memory_mb'] ?? null, 'queries' => $j['queries'] ?? null, 'batches' => $j['batches_done'] ?? null,
            'batch_size' => $batch, 'avg_batch_ms' => (isset($j['duration_ms'], $j['batches_done']) && (int) $j['batches_done'] > 0) ? round($j['duration_ms'] / $j['batches_done'], 1) : null,
            'credited' => $j['credited'] ?? null, 'duplicates' => $j['duplicates'] ?? null, 'retry_passes' => $j['retry_passes'] ?? null,
            'reconcile' => $j['reconcile_status'] ?? null,
        ];
    }

    private function bulkQty(int $i): int
    {
        return (($i % 7) + 1) * 10;
    }

    /** Bulk synthetic population: fresh database, N active members with real-looking ledger chains (set-based SQL). */
    private function seedBulk(int $n): void
    {
        $this->bootstrap();
        $db   = db_connect();
        $ts   = $this->ist('2026-10-10 10:00:00');
        $now  = date('Y-m-d H:i:s');
        $base = 100000;
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        for ($from = 1; $from <= $n; $from += 1000) {
            $users = $profiles = $purchases = $ledger = $balances = [];
            for ($i = $from; $i < $from + 1000 && $i <= $n; $i++) {
                $uid = $base + $i;
                $mid = 'SYN' . str_pad((string) $i, 7, '0', STR_PAD_LEFT);
                $qty = $this->bulkQty($i) . '.00000000';
                $locked = $i % 2 === 0;
                $users[]    = ['id' => $uid, 'username' => 'bulk' . $i, 'active' => 1, 'created_at' => $ts, 'updated_at' => $ts];
                $profiles[] = ['user_id' => $uid, 'member_id' => $mid, 'member_name' => FinancialSandboxService::SYNTHETIC_NAME_PREFIX . ' bulk ' . $i, 'mobile' => '19' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'account_status' => 'active', 'is_root_sponsor' => 0, 'created_at' => $ts, 'updated_at' => $ts];
                $pref = 'PUR-B' . str_pad((string) $i, 9, '0', STR_PAD_LEFT);
                $purchases[] = ['purchase_ref' => $pref, 'member_user_id' => $uid, 'member_id' => $mid, 'coin_quantity' => $qty, 'price_usdt_snapshot' => '1.00000000', 'total_usdt' => $qty, 'status' => 'approved', 'reviewed_by' => $this->adminUserId, 'requested_at' => $ts, 'reviewed_at' => $ts, 'idempotency_key' => 'bulk-' . $i, 'created_at' => $ts, 'updated_at' => $ts];
                $ledger[] = ['transaction_ref' => 'OWN-B' . str_pad((string) $i, 10, '0', STR_PAD_LEFT) . 'C', 'member_user_id' => $uid, 'member_id' => $mid, 'type' => 'PURCHASE_CREDIT', 'direction' => 'credit', 'quantity' => $qty, 'previous_balance' => '0.00000000', 'new_balance' => $qty, 'previous_available' => '0.00000000', 'new_available' => $qty, 'previous_locked' => '0.00000000', 'new_locked' => '0.00000000', 'previous_reserved' => '0.00000000', 'new_reserved' => '0.00000000', 'purchase_ref' => $pref, 'lock_ref' => null, 'created_at' => $ts, 'updated_at' => $ts];
                if ($locked) {
                    $ledger[] = ['transaction_ref' => 'OWN-B' . str_pad((string) $i, 10, '0', STR_PAD_LEFT) . 'L', 'member_user_id' => $uid, 'member_id' => $mid, 'type' => 'LOCK', 'direction' => 'debit', 'quantity' => $qty, 'previous_balance' => $qty, 'new_balance' => $qty, 'previous_available' => $qty, 'new_available' => '0.00000000', 'previous_locked' => '0.00000000', 'new_locked' => $qty, 'previous_reserved' => '0.00000000', 'new_reserved' => '0.00000000', 'purchase_ref' => null, 'lock_ref' => 'LCK-B' . $i, 'created_at' => $ts, 'updated_at' => $ts];
                }
                $balances[] = ['member_user_id' => $uid, 'member_id' => $mid, 'balance' => $qty, 'available_balance' => $locked ? '0.00000000' : $qty, 'locked_balance' => $locked ? $qty : '0.00000000', 'reserved_balance' => '0.00000000', 'total_acquired' => $qty, 'created_at' => $ts, 'updated_at' => $ts];
            }
            $db->table('users')->insertBatch($users);
            $db->table('member_profiles')->insertBatch($profiles);
            $db->table('coin_purchase_requests')->insertBatch($purchases);
            $db->table('member_coin_ownership_transactions')->insertBatch($ledger);
            $db->table('member_coin_balances')->insertBatch($balances);
        }
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        unset($now);
    }

    // ===================================================================== helpers

    /** @return array<string, string> */
    private function ownershipSnapshot(): array
    {
        $out = [];
        foreach (db_connect()->table('member_coin_balances')->orderBy('member_id')->get()->getResultArray() as $r) {
            $out[(string) $r['member_id']] = $r['balance'] . '|' . $r['available_balance'] . '|' . $r['locked_balance'] . '|' . $r['reserved_balance'];
        }

        return $out;
    }

    /** @return list<string> */
    private function logTail(): array
    {
        $file = WRITEPATH . 'logs/mining-post.log';
        if (! is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_slice($lines, -6);
    }

    private function check(string $name, bool $ok, string $detail): void
    {
        $this->checks[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    }
}
