<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use mysqli;
use RuntimeException;
use Throwable;

/**
 * Phase 13C.1 — verification of the owner-proposed mining policy package.
 *
 * Reuses the Phase 13C isolation machinery. Production (`coin_platform`) is touched only through the temporary
 * SELECT-only account; every mutation (migrations, Hub DRAFTS, synthetic cohort, test rates, posting) targets the
 * disposable allowlisted clone `coin_platform_phase13c`. Nothing is approved, activated or deployed.
 */
class Phase13C1VerificationService extends Phase13CVerificationService
{
    private const POLICY = ['approval_day' => 'exclude_approval_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'half_up', 'reserved' => 'include', 'maturity' => 'include_all'];

    /** @var array<string, array<string, mixed>> */
    private array $cohort = [];
    private int $adminId  = 0;

    /** @return array<string, mixed> */
    public function runProposalVerification(): array
    {
        $started = microtime(true);
        $this->out = ['phase' => '13C.1', 'started_at' => gmdate('c'), 'notes' => 'Approval-package verification. Nothing approved, activated, migrated in production or deployed.'];
        $admin = null;

        try {
            $admin = $this->adminConnection();
            $this->out['preflight'] = $this->preflight($admin);
            $this->createLiveReadOnlyUser($admin);
            $live = $this->liveRoConnection();
            $this->out['live_fingerprint_before'] = $this->fingerprint($live, self::LIVE_DB);

            // ---- 1. source matrix: real 12 decisions on live (read-only)
            $this->out['live_matrix'] = $this->withDefault(self::LIVE_RO_GROUP, self::LIVE_DB, fn (): array => $this->liveMatrix());

            // ---- 2. proposals + pure previews
            $svc = new MiningProposalService();
            $this->out['proposals']       = $svc->proposals();
            $this->out['calendar_days']   = $svc->calendarDays();
            $this->out['economic_preview'] = $svc->economicPreview('3');
            $bad = array_filter($this->out['calendar_days'], static fn (array $r): bool => ! $r['ok']);
            $this->assertTrue('calendar_days_all_months', $bad === [], count($this->out['calendar_days']) . ' months (incl. Feb 28/29) match the engine divisor at first and last day.');
            $this->out['eligibility_rule'] = $this->ruleCases($svc);

            // ---- 3. isolated clone: restore, migrate, drafts, synthetic cohort, as-of, posting
            $this->out['clone_restore'] = $this->restoreInto($admin, self::CLONE_DB, 'disposable clone (13C.1)');
            $this->grantAccounts($admin);
            $clone = $this->connect(self::CLONE_GROUP);
            $this->adminId = (int) $this->one($clone, "SELECT user_id FROM member_profiles WHERE member_id = 'ROOT0001'");
            $this->out['clone_migration'] = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function (): array {
                service('migrations', null, null, false)->latest();

                return ['applied' => true];
            });
            $this->assertTrue('clone_has_hub_tables', in_array('financial_policy_versions', $this->tables($clone, self::CLONE_DB), true), 'Hub tables exist on the migrated clone.');

            $this->out['hub_drafts'] = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, fn (): array => $this->hubDrafts($clone, $svc));

            $this->seedCohort($clone);
            $this->out['as_of'] = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, fn (): array => $this->asOf());
            $this->out['posting'] = $this->posting($clone);

            // ---- 4. live unchanged
            $this->out['live_fingerprint_after'] = $this->fingerprint($live, self::LIVE_DB);
            $this->out['live_unchanged']         = $this->assertTrue('live_unchanged', $this->out['live_fingerprint_before'] === $this->out['live_fingerprint_after'], 'Live fingerprint identical before and after Phase 13C.1.');
        } catch (Throwable $e) {
            $this->out['fatal'] = get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
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

        return $this->out;
    }

    // ===================================================================== live matrix

    /** @return array<string, mixed> */
    protected function liveMatrix(): array
    {
        $w   = service('miningPolicyWorksheetService', false)->build();
        $ids = array_column($w['decisions'], 'id');
        $this->assertTrue('twelve_decision_ids', $ids === MiningProposalService::DECISION_IDS, 'Worksheet decision ids: ' . implode(',', $ids));
        $states = array_map(static fn (array $d): string => (string) $d['approval_state'], $w['decisions']);
        $this->assertTrue('live_all_pending', count(array_filter($states, static fn (string $s): bool => $s === 'PENDING_BUSINESS_APPROVAL')) === 12, 'All 12 live decisions are PENDING_BUSINESS_APPROVAL.');
        $rates = $w['rates']['rows'] ?? [];

        return [
            'decisions' => array_map(static fn (array $d): array => ['id' => $d['id'], 'decision' => $d['decision'], 'current_status' => $d['current_status'], 'platform_default_value' => $d['platform_default_value'], 'approval_state' => $d['approval_state']], array_values($w['decisions'])),
            'rates'     => array_map(static fn (array $r): array => ['rate_ref' => $r['rate_ref'], 'month' => $r['effective_month'], 'percent' => $r['rate_percent'], 'status' => $r['status']], $rates),
            'hub_table_present' => (bool) ($w['hub']['table_present'] ?? false),
        ];
    }

    // ===================================================================== rule cases (pure)

    /** @return array<string, mixed> */
    private function ruleCases(MiningProposalService $svc): array
    {
        $now = '2026-11-18 08:00:00';
        $cases = [
            'approval_2026-11-17_12:15_IST'      => ['2026-11-17 06:45:44', '2026-11-17', '2026-11-18'],
            'just_after_midnight_00:01_IST'      => ['2026-11-16 18:31:00', '2026-11-17', '2026-11-18'],
            'just_before_midnight_23:59_IST'     => ['2026-11-17 18:29:00', '2026-11-17', '2026-11-18'],
            'exact_midnight_IST'                 => ['2026-11-17 18:30:00', '2026-11-18', '2026-11-19'],
            'utc_17th_19:00_is_ist_18th_00:30'   => ['2026-11-17 19:00:00', '2026-11-18', '2026-11-19'],
            'month_end_31st_23:59_IST'           => ['2026-10-31 18:29:00', '2026-10-31', '2026-11-01'],
            'year_end'                           => ['2025-12-31 18:29:00', '2025-12-31', '2026-01-01'],
        ];
        $rows = [];
        $ok   = true;
        foreach ($cases as $name => [$ts, $bd, $first]) {
            $r = $svc->firstEligibleDate($ts, $now);
            $pass = $r['approval_business_date'] === $bd && $r['first_eligible_date'] === $first && $r['state'] === 'ELIGIBLE_FROM_FIRST_DATE';
            $ok   = $ok && $pass;
            $rows[$name] = ['approved_utc' => $ts] + $r + ['ok' => $pass];
        }
        $missing = $svc->firstEligibleDate(null, $now);
        $future  = $svc->firstEligibleDate('2027-01-15 04:30:00', $now);
        $rows['missing_timestamp'] = $missing + ['ok' => $missing['state'] === 'BLOCKED_MISSING_APPROVAL_TIMESTAMP'];
        $rows['future_dated']      = ['approved_utc' => '2027-01-15 04:30:00'] + $future + ['ok' => $future['state'] === 'FUTURE_DATED_APPROVAL'];
        $this->assertTrue('following_date_rule_cases', $ok && $rows['missing_timestamp']['ok'] && $rows['future_dated']['ok'], 'Following-business-date rule: ' . count($rows) . ' timestamp cases evaluated.');

        return $rows;
    }

    // ===================================================================== hub drafts (clone)

    /** @return array<string, mixed> */
    protected function hubDrafts(mysqli $clone, MiningProposalService $svc): array
    {
        $before = (int) $this->one($clone, "SELECT COUNT(*) FROM financial_policy_versions WHERE status IN ('approved','active')");
        $drafts = $svc->createHubDrafts($this->adminId, new FinancialPolicyService());
        $rows   = $this->q($clone, 'SELECT policy_ref, domain, status, version_no, created_by, effective_from, approved_by FROM financial_policy_versions ORDER BY id');
        $after  = (int) $this->one($clone, "SELECT COUNT(*) FROM financial_policy_versions WHERE status IN ('approved','active')");
        $allDraft = count($rows) === 4 && count(array_filter($rows, static fn (array $r): bool => $r['status'] === 'draft' && $r['approved_by'] === null && $r['effective_from'] === null)) === 4;
        $this->assertTrue('hub_four_drafts_unapproved', $allDraft && $before === 0 && $after === 0, '4 DRAFT hub policies created on the clone; approved/active before=' . $before . ' after=' . $after . '; effective date unset.');

        $w = service('miningPolicyWorksheetService', false)->build();
        $states = array_map(static fn (array $d): string => (string) $d['approval_state'], $w['decisions']);
        $this->assertTrue('worksheet_still_pending_after_drafts', count(array_filter($states, static fn (string $s): bool => $s === 'PENDING_BUSINESS_APPROVAL')) === 12, 'Drafts do not change any decision: all 12 remain PENDING_BUSINESS_APPROVAL.');

        $events = (int) $this->one($clone, "SELECT COUNT(*) FROM financial_policy_events WHERE event_type = 'created'");

        return ['drafts' => $drafts, 'rows' => $rows, 'created_events' => $events, 'dual_control' => 'Approval needs a different authorized admin with password re-authentication; self-approval is refused by FinancialPolicyService::approve (covered by Phase12EFinancialPolicyTest).', 'not_executed' => 'submit / approve / activate were deliberately NOT executed.'];
    }

    // ===================================================================== synthetic cohort

    /** @return list<array<string, mixed>> */
    private function members(): array
    {
        $P = static fn (string $ref, string $qty, ?string $ts): array => ['kind' => 'PURCHASE_CREDIT', 'qty' => $qty, 'ts' => $ts, 'purchase' => $ref];

        return [
            ['key' => 'M01_CONSTANT',    'ev' => [$P('PUR-C1-M01', '1000', '2026-10-10 04:30:00')]],
            ['key' => 'M02_MULTIPLE',    'ev' => [$P('PUR-C1-M02A', '600', '2026-10-10 04:30:00'), $P('PUR-C1-M02B', '400', '2026-10-30 18:00:00')]],
            ['key' => 'M03_LOCKED',      'ev' => [$P('PUR-C1-M03', '1000', '2026-10-10 04:30:00'), ['kind' => 'LOCK', 'qty' => '1000', 'ts' => '2026-10-10 04:30:01', 'lock' => 'LCK-C1-M03', 'lock_matures' => '2029-07-06 04:30:01']]],
            ['key' => 'M04_AFTER_MIDNIGHT', 'ev' => [$P('PUR-C1-M04', '500', '2026-10-30 18:35:00')]],
            ['key' => 'M05_PARTIAL_SALE', 'ev' => [$P('PUR-C1-M05', '1000', '2026-10-10 04:30:00'), ['kind' => 'SALE_RESERVE', 'qty' => '300', 'ts' => '2026-10-20 06:00:00'], ['kind' => 'SALE_DEBIT', 'qty' => '300', 'ts' => '2026-10-22 06:00:00']]],
            ['key' => 'M06_FULL_SALE',   'ev' => [$P('PUR-C1-M06', '1000', '2026-10-10 04:30:00'), ['kind' => 'SALE_RESERVE', 'qty' => '1000', 'ts' => '2026-10-24 06:00:00'], ['kind' => 'SALE_DEBIT', 'qty' => '1000', 'ts' => '2026-10-25 06:00:00']]],
            ['key' => 'M07_PENDING_SALE', 'ev' => [$P('PUR-C1-M07', '1000', '2026-10-10 04:30:00'), ['kind' => 'SALE_RESERVE', 'qty' => '200', 'ts' => '2026-10-28 06:00:00']]],
            ['key' => 'M08_INACTIVE',    'status' => 'inactive', 'ev' => [$P('PUR-C1-M08', '1000', '2026-10-10 04:30:00')]],
            ['key' => 'M09_NO_APPROVAL_TS', 'null_review' => true, 'ev' => [$P('PUR-C1-M09', '1000', '2026-10-10 04:30:00')]],
            ['key' => 'M10_FUTURE_APPROVAL', 'ev' => [$P('PUR-C1-M10', '700', '2027-01-15 04:30:00')]],
            ['key' => 'M11_MATURED_LOCK', 'ev' => [$P('PUR-C1-M11', '1000', '2023-12-10 04:30:00'), ['kind' => 'LOCK', 'qty' => '1000', 'ts' => '2023-12-10 04:30:01', 'lock' => 'LCK-C1-M11', 'lock_matures' => '2026-09-05 04:30:01']]],
        ];
    }

    private function seedCohort(mysqli $c): void
    {
        $c->query('SET FOREIGN_KEY_CHECKS = 0');
        $now = gmdate('Y-m-d H:i:s');
        $i   = 0;
        foreach ($this->members() as $m) {
            $i++;
            $uid = 910000 + $i;
            $mid = 'SYN13C1' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $status = $m['status'] ?? 'active';
            $this->ins($c, 'users', ['id' => $uid, 'username' => 'p13c1_' . $i, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->ins($c, 'member_profiles', ['user_id' => $uid, 'member_id' => $mid, 'member_name' => FinancialSandboxService::SYNTHETIC_NAME_PREFIX . ' ' . $m['key'], 'mobile' => '18' . str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'account_status' => $status, 'is_root_sponsor' => 0, 'created_at' => $now, 'updated_at' => $now]);
            $st = ['t' => '0', 'a' => '0', 'l' => '0', 'r' => '0'];
            $acq = '0';
            $n = 0;
            foreach ($m['ev'] as $e) {
                $n++;
                $prev = $st;
                $q = $e['qty'];
                switch ($e['kind']) {
                    case 'PURCHASE_CREDIT':
                        $st['t'] = bcadd($st['t'], $q, 8);
                        $st['a'] = bcadd($st['a'], $q, 8);
                        $acq     = bcadd($acq, $q, 8);
                        break;
                    case 'LOCK':
                        $st['a'] = bcsub($st['a'], $q, 8);
                        $st['l'] = bcadd($st['l'], $q, 8);
                        break;
                    case 'SALE_RESERVE':
                        $st['a'] = bcsub($st['a'], $q, 8);
                        $st['r'] = bcadd($st['r'], $q, 8);
                        break;
                    case 'SALE_DEBIT':
                        $st['r'] = bcsub($st['r'], $q, 8);
                        $st['t'] = bcsub($st['t'], $q, 8);
                        break;
                }
                $ts = $e['ts'];
                $f  = static fn (string $v): string => bcadd($v, '0', 8);
                $this->ins($c, 'member_coin_ownership_transactions', [
                    'transaction_ref' => 'OWN-C1-' . $i . '-' . $n, 'member_user_id' => $uid, 'member_id' => $mid, 'type' => $e['kind'],
                    'direction' => $e['kind'] === 'PURCHASE_CREDIT' ? 'credit' : 'debit', 'quantity' => $f($q),
                    'previous_balance' => $f($prev['t']), 'new_balance' => $f($st['t']), 'previous_available' => $f($prev['a']), 'new_available' => $f($st['a']),
                    'previous_locked' => $f($prev['l']), 'new_locked' => $f($st['l']), 'previous_reserved' => $f($prev['r']), 'new_reserved' => $f($st['r']),
                    'purchase_ref' => $e['purchase'] ?? null, 'lock_ref' => $e['lock'] ?? null, 'created_at' => $ts, 'updated_at' => $ts,
                ]);
                if ($e['kind'] === 'PURCHASE_CREDIT') {
                    $this->ins($c, 'coin_purchase_requests', [
                        'purchase_ref' => $e['purchase'], 'member_user_id' => $uid, 'member_id' => $mid, 'coin_quantity' => $f($q), 'price_usdt_snapshot' => '1.00000000', 'total_usdt' => $f($q),
                        'status' => 'approved', 'reviewed_by' => $this->adminId, 'requested_at' => $ts, 'reviewed_at' => ! empty($m['null_review']) ? null : $ts,
                        'idempotency_key' => 'c1-' . $e['purchase'], 'created_at' => $ts, 'updated_at' => $ts,
                    ]);
                }
                if ($e['kind'] === 'LOCK') {
                    $this->ins($c, 'member_coin_locks', [
                        'lock_ref' => $e['lock'], 'member_user_id' => $uid, 'member_id' => $mid, 'purchase_ref' => null, 'lock_kind' => 'voluntary', 'plan_code' => 'LOCK1000', 'plan_name' => 'LOCK1000',
                        'quantity' => $f($q), 'duration_days' => 1000, 'status' => 'active', 'locked_at' => $ts, 'matures_at' => $e['lock_matures'], 'idempotency_key' => 'c1-' . $e['lock'], 'created_at' => $ts, 'updated_at' => $ts,
                    ]);
                }
            }
            $this->ins($c, 'member_coin_balances', ['member_user_id' => $uid, 'member_id' => $mid, 'balance' => bcadd($st['t'], '0', 8), 'available_balance' => bcadd($st['a'], '0', 8), 'locked_balance' => bcadd($st['l'], '0', 8), 'reserved_balance' => bcadd($st['r'], '0', 8), 'total_acquired' => bcadd($acq, '0', 8), 'created_at' => $now, 'updated_at' => $now]);
            $this->cohort[$m['key']] = ['uid' => $uid, 'mid' => $mid, 'status' => $status];
        }
        $c->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->out['cohort'] = array_map(static fn (string $k, array $v): array => ['case' => $k] + $v, array_keys($this->cohort), array_values($this->cohort));
    }

    /** @param array<string, mixed> $row */
    private function ins(mysqli $c, string $table, array $row): void
    {
        $cols = implode(',', array_map(static fn (string $k): string => '`' . $k . '`', array_keys($row)));
        $vals = implode(',', array_map(static fn ($v): string => $v === null ? 'NULL' : "'" . $c->real_escape_string((string) $v) . "'", array_values($row)));
        $c->query('INSERT INTO `' . $table . '` (' . $cols . ') VALUES (' . $vals . ')');
    }

    // ===================================================================== as-of eligibility (real engine, clone)

    /** @return array<string, mixed> */
    protected function asOf(): array
    {
        $svc      = new MiningEligibleOwnershipSnapshotService();
        $proposed = MiningCalculationPolicy::fromArray(self::POLICY);
        $alts     = [
            'proposed'             => $proposed,
            'alt_full_day'         => MiningCalculationPolicy::fixture('T-CALENDAR-HALFUP'),
            'alt_reserved_excluded' => MiningCalculationPolicy::fixture('T-RESERVED-EXCL'),
            'alt_matured_excluded' => MiningCalculationPolicy::fixture('T-MATURED-EXCL'),
        ];
        $uids = array_column($this->cohort, 'uid');
        $expected = [ // proposed policy (following business date, reserved include, maturity include_all)
            '2026-10-30' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '600', 'M03_LOCKED' => '1000', 'M04_AFTER_MIDNIGHT' => '0', 'M05_PARTIAL_SALE' => '700', 'M06_FULL_SALE' => '0', 'M07_PENDING_SALE' => '1000', 'M08_INACTIVE' => '1000', 'M09_NO_APPROVAL_TS' => 'BLOCKED', 'M10_FUTURE_APPROVAL' => '0', 'M11_MATURED_LOCK' => '1000'],
            '2026-10-31' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '1000', 'M03_LOCKED' => '1000', 'M04_AFTER_MIDNIGHT' => '0', 'M05_PARTIAL_SALE' => '700', 'M06_FULL_SALE' => '0', 'M07_PENDING_SALE' => '1000', 'M08_INACTIVE' => '1000', 'M09_NO_APPROVAL_TS' => 'BLOCKED', 'M10_FUTURE_APPROVAL' => '0', 'M11_MATURED_LOCK' => '1000'],
            '2026-11-01' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '1000', 'M03_LOCKED' => '1000', 'M04_AFTER_MIDNIGHT' => '500', 'M05_PARTIAL_SALE' => '700', 'M06_FULL_SALE' => '0', 'M07_PENDING_SALE' => '1000', 'M08_INACTIVE' => '1000', 'M09_NO_APPROVAL_TS' => 'BLOCKED', 'M10_FUTURE_APPROVAL' => '0', 'M11_MATURED_LOCK' => '1000'],
        ];
        $rows = [];
        $allOk = true;
        foreach (array_keys($expected) as $date) {
            foreach ($alts as $name => $policy) {
                $cut = $svc->cutoff($date, $policy, null, true);
                $res = $svc->snapshotBatch($uids, $cut['cutoff_utc'], $policy);
                foreach ($this->cohort as $key => $mem) {
                    $r = $res[$mem['uid']];
                    $actual = $r['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED ? 'BLOCKED' : 'VALUE';
                    $row = ['policy' => $name, 'date' => $date, 'case' => $key, 'status' => $r['status'], 'eligible' => $r['eligible'], 'owned' => $r['owned'], 'locked' => $r['locked'], 'reserved' => $r['reserved'], 'reasons' => $r['reasons']];
                    if ($name === 'proposed') {
                        $row['expected'] = $expected[$date][$key];
                        $row['ok']       = $row['expected'] === 'BLOCKED' ? $actual === 'BLOCKED' : bccomp($r['eligible'], (string) $row['expected'], 8) === 0 && $r['status'] !== MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED;
                        $allOk           = $allOk && $row['ok'];
                    }
                    $rows[] = $row;
                }
            }
        }
        $this->assertTrue('as_of_matches_proposed_rule', $allOk, 'Real as-of engine under the proposed policy matches expected eligibility for 11 synthetic cases x 3 dates (following business date, locked included, sales, reservation, missing/future approval).');
        $lines = explode("\n", (string) file_get_contents(APPPATH . 'Services/MiningEligibleOwnershipSnapshotService.php'));
        $src   = implode("\n", array_filter($lines, static fn (string $l): bool => preg_match('/^\s*(\*|\/\/|\/\*)/', $l) !== 1));
        $this->assertTrue('eligibility_independent_of_mining_wallet', ! str_contains($src, 'mining_wallet'), 'Eligibility reads only ownership ledger / purchases / locks — never the Mining Wallet (so P2P and mining credits cannot change eligibility).');

        return ['rows' => $rows];
    }

    // ===================================================================== real posting on the clone

    /** @return array<string, mixed> */
    private function posting(mysqli $clone): array
    {
        // approve synthetic rates through the existing dual-control service (clone only)
        $approver = 900001;
        $this->ins($clone, 'users', ['id' => $approver, 'username' => 'p13c1_approver', 'active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $rates = $this->withDefault(self::CLONE_GROUP, self::CLONE_DB, function () use ($approver): array {
            $svc = service('miningRateService', false);
            $out = [];
            foreach (['2026-10' => '3.00000000', '2026-11' => '4.00000000'] as $month => $pct) {
                $d = $svc->createDraft(['effective_month' => $month, 'rate_percent' => $pct, 'rate_basis' => 'monthly', 'notes' => 'PHASE13C1 SYNTHETIC TEST RATE (isolated clone; not a business decision)'], $this->adminId);
                $selfBlocked = false;
                try {
                    $svc->approve($d['rate_ref'], $this->adminId);
                } catch (Throwable) {
                    $selfBlocked = true;
                }
                $svc->approve($d['rate_ref'], $approver);
                $out[$month] = ['rate_ref' => $d['rate_ref'], 'percent' => $pct, 'self_approval_blocked' => $selfBlocked];
            }

            return $out;
        });
        $this->assertTrue('rate_self_approval_blocked', ! in_array(false, array_column($rates, 'self_approval_blocked'), true), 'Creator cannot approve their own rate (existing dual control).');

        $env = [
            'platform_miningApprovalDayPolicy'     => self::POLICY['approval_day'],
            'platform_miningMonthDayDivisorMode'   => self::POLICY['divisor_mode'],
            'platform_miningRoundingMode'          => self::POLICY['rounding'],
            'platform_miningReservedCoinPolicy'    => self::POLICY['reserved'],
            'platform_miningMaturityPolicy'        => self::POLICY['maturity'],
            'platform_miningPostRetryDelaySeconds' => '0',
        ];
        $ownBefore = $this->ownershipHash($clone);
        $run = fn (string $date): array => $this->post($date, $env);
        $steps = [];
        foreach (['2026-10-31' => 'first run (later date first)', '2026-10-31#2' => 'duplicate re-run', '2026-10-30' => 'backdated run', '2026-11-01' => 'new month / new rate'] as $key => $label) {
            $date = explode('#', $key)[0];
            $r    = $run($date);
            $steps[$key] = ['label' => $label, 'exit' => $r['exit'], 'summary' => $r['json'] === null ? substr($r['raw'], -240) : array_intersect_key($r['json'], array_flip(['status', 'state', 'credited_total', 'credited', 'posted_total', 'skipped', 'blocked', 'reason', 'reconcile_status', 'run_ref']))];
        }
        $ownAfter = $this->ownershipHash($clone);

        // expected vs actual per (member, date)
        $expectedElig = [
            '2026-10-30' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '600', 'M03_LOCKED' => '1000', 'M05_PARTIAL_SALE' => '700', 'M07_PENDING_SALE' => '1000', 'M11_MATURED_LOCK' => '1000'],
            '2026-10-31' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '1000', 'M03_LOCKED' => '1000', 'M05_PARTIAL_SALE' => '700', 'M07_PENDING_SALE' => '1000', 'M11_MATURED_LOCK' => '1000'],
            '2026-11-01' => ['M01_CONSTANT' => '1000', 'M02_MULTIPLE' => '1000', 'M03_LOCKED' => '1000', 'M04_AFTER_MIDNIGHT' => '500', 'M05_PARTIAL_SALE' => '700', 'M07_PENDING_SALE' => '1000', 'M11_MATURED_LOCK' => '1000'],
        ];
        $pctFor = ['2026-10' => '3.00000000', '2026-11' => '4.00000000'];
        $actual = [];
        foreach ($this->q($clone, "SELECT member_id, business_date, SUM(amount) a, COUNT(*) n FROM mining_wallet_ledger WHERE direction = 'credit' GROUP BY member_id, business_date") as $r) {
            $actual[$r['member_id'] . '|' . $r['business_date']] = ['amount' => (string) $r['a'], 'rows' => (int) $r['n']];
        }
        $cmp = [];
        $allOk = true;
        foreach ($expectedElig as $date => $members) {
            $days = MiningMath::divisor('monthly', $date, null, MiningMath::DIVISOR_CALENDAR);
            foreach ($members as $key => $elig) {
                $mid = $this->cohort[$key]['mid'];
                $exp = $this->independent($elig . '.00000000', $pctFor[substr($date, 0, 7)], $days);
                $act = $actual[$mid . '|' . $date] ?? ['amount' => '0.00000000', 'rows' => 0];
                $ok  = bccomp($exp, $act['amount'], 8) === 0 && $act['rows'] === 1;
                $allOk = $allOk && $ok;
                $cmp[] = ['date' => $date, 'case' => $key, 'member_id' => $mid, 'eligible' => $elig, 'rate_percent' => $pctFor[substr($date, 0, 7)], 'divisor' => $days, 'expected_independent' => $exp, 'actual' => $act['amount'], 'ledger_rows' => $act['rows'], 'ok' => $ok];
            }
        }
        $this->assertTrue('posting_amounts_match_independent_math', $allOk, 'Real mining:post credits equal independent integer arithmetic for every eligible member/date (rate change Oct 3% -> Nov 4% included).');

        $excluded = [];
        foreach (['M04_AFTER_MIDNIGHT' => ['2026-10-30', '2026-10-31'], 'M06_FULL_SALE' => ['2026-10-30', '2026-10-31', '2026-11-01'], 'M08_INACTIVE' => ['2026-10-30', '2026-10-31', '2026-11-01'], 'M09_NO_APPROVAL_TS' => ['2026-10-30', '2026-10-31', '2026-11-01'], 'M10_FUTURE_APPROVAL' => ['2026-10-30', '2026-10-31', '2026-11-01']] as $key => $dates) {
            foreach ($dates as $d) {
                $excluded[] = ['case' => $key, 'date' => $d, 'credited' => isset($actual[$this->cohort[$key]['mid'] . '|' . $d])];
            }
        }
        $leak = array_filter($excluded, static fn (array $e): bool => $e['credited']);
        $this->assertTrue('posting_excludes_ineligible', $leak === [], 'No credit for: pre-eligibility day, full sale, inactive, missing approval timestamp, future-dated approval.');

        $dups = (int) $this->one($clone, "SELECT COUNT(*) FROM (SELECT member_id, business_date FROM mining_wallet_ledger WHERE direction = 'credit' GROUP BY member_id, business_date HAVING COUNT(*) > 1) x");
        $this->assertTrue('posting_no_duplicates', $dups === 0, 'Duplicate re-run created no second credit (duplicate groups=' . $dups . ').');
        $this->assertTrue('posting_ownership_unchanged', $ownBefore === $ownAfter, 'Ownership ledger/balances identical before and after posting: mining credits do NOT become eligible ownership (no compounding).');
        $walletTotal = (string) $this->one($clone, 'SELECT COALESCE(SUM(available_balance),0) FROM mining_wallets');
        $ledgerNet   = (string) $this->one($clone, "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount ELSE -amount END),0) FROM mining_wallet_ledger");
        $this->assertTrue('posting_wallet_equals_ledger', bccomp($walletTotal, $ledgerNet, 8) === 0, 'Mining Wallet total ' . $walletTotal . ' equals ledger net ' . $ledgerNet . '.');
        $assets = array_column($this->q($clone, 'SELECT DISTINCT asset_code FROM mining_wallets'), 'asset_code');
        $this->assertTrue('wallet_asset_single_coin', $assets === ['COIN'], 'Mining wallet assets: ' . json_encode($assets) . ' (no mixing).');
        $inv = $this->q($clone, 'SELECT available_balance, total_added, total_allocated FROM coin_inventory')[0] ?? null;

        return [
            'rates' => $rates, 'policy' => self::POLICY, 'steps' => $steps, 'comparison' => $cmp, 'excluded_checks' => $excluded,
            'wallet_total' => $walletTotal, 'ledger_net' => $ledgerNet, 'wallet_assets' => $assets, 'coin_inventory_after' => $inv,
            'inventory_untouched_by_mining' => 'coin_inventory unchanged by posting (mining never debits inventory) — see tokenomics.',
            'duplicate_credit_groups' => $dups, 'ownership_hash_before' => $ownBefore, 'ownership_hash_after' => $ownAfter,
        ];
    }

    private function ownershipHash(mysqli $c): string
    {
        $t = $this->checksums($c, self::CLONE_DB, ['member_coin_balances', 'member_coin_ownership_transactions', 'member_coin_locks', 'coin_purchase_requests']);

        return md5(json_encode($t) . (string) $this->one($c, 'SELECT COUNT(*) FROM member_coin_ownership_transactions'));
    }

    /** @return array{exit: int, json: ?array<string, mixed>, raw: string} */
    private function post(string $date, array $env): array
    {
        $r = $this->spark(['mining:post', '--group', self::CLONE_GROUP, '--date', $date, '--confirm', 'APPLY_STAGING', '--strict-exit'], $env);
        $a = strpos($r['stdout'], '{');
        $b = strrpos($r['stdout'], '}');
        $j = null;
        if ($a !== false && $b !== false && $b > $a) {
            $d = json_decode(substr($r['stdout'], $a, $b - $a + 1), true);
            $j = is_array($d) ? $d : null;
        }

        return ['exit' => $r['exit'], 'json' => $j, 'raw' => $r['stdout'] . $r['stderr']];
    }

    /** First-principles integer arithmetic (HALF_UP, 8 dp), independent of MiningMath. */
    private function independent(string $eligible, string $percent, int $divisor): string
    {
        $u = static function (string $v): string {
            [$i, $f] = array_pad(explode('.', $v, 2), 2, '');
            $s = ltrim($i . str_pad(substr($f, 0, 8), 8, '0'), '0');

            return $s === '' ? '0' : $s;
        };
        $num = bcmul(bcmul($u($eligible), $u($percent), 0), bcpow('10', '8', 0), 0);
        $den = bcmul(bcmul(bcpow('10', '16', 0), '100', 0), (string) $divisor, 0);
        $q   = bcdiv(bcadd(bcmul($num, '2', 0), $den, 0), bcmul($den, '2', 0), 0);
        $q   = str_pad($q, 9, '0', STR_PAD_LEFT);

        return substr($q, 0, -8) . '.' . substr($q, -8);
    }
}
