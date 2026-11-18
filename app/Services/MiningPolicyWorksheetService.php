<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningMath;
use Config\Platform;
use Throwable;

/**
 * Phase 13C — Mining Business Decision Worksheet (READ-ONLY).
 *
 * Separates, for every mining decision: confirmed requirements, technical options/recommendations (never an approval),
 * the CURRENT platform status flag, and the APPROVED value — which is reported only when it can be verified from an
 * actual approved/active Financial Policy Hub record (+ the platform flag being "approved"). A configuration default is
 * never treated as an approval. Nothing here writes to the database or changes any switch.
 */
class MiningPolicyWorksheetService
{
    public const PENDING = 'PENDING_BUSINESS_APPROVAL';

    /** decision id => [label, platform flag property, platform value property|null, hub payload keys] */
    private const FLAG_DECISIONS = [
        'monthly_percent'     => ['Monthly mining percentage',                 'miningMonthlyPercentStatus',          null,                        ['monthly_percent', 'rate_percent']],
        'day_divisor'         => ['Daily divisor (fixed 30 vs calendar days)',  'miningDayDivisorStatus',              'miningMonthDayDivisorMode', ['month_day_divisor_mode', 'divisor_mode']],
        'denomination'        => ['Mining denomination (COIN vs USDT)',        'miningDenominationStatus',            'miningEarningAsset',        ['earning_asset', 'denomination']],
        'approval_day'        => ['Approval-day eligibility',                  'miningApprovalDayPolicyStatus',       'miningApprovalDayPolicy',   ['approval_day_policy']],
        'locked_coins'        => ['Locked coin eligibility',                   'miningLockedCoinEligibilityStatus',   null,                        ['locked_coins_included', 'locked_coin_eligibility']],
        'post_maturity'       => ['Mining after LOCK1000 maturity / unlock',   'miningPostMaturityEligibilityStatus', 'miningMaturityPolicy',      ['post_maturity_eligibility', 'maturity_policy']],
        'post_sale'           => ['Mining after sale / during reservation',    'miningPostSaleEligibilityStatus',     'miningReservedCoinPolicy',  ['post_sale_eligibility', 'reserved_coin_policy']],
        'rounding'            => ['Rounding policy',                           'miningRoundingPolicyStatus',          'miningRoundingMode',        ['rounding_mode', 'rounding']],
    ];

    public function __construct(private readonly ?Platform $config = null)
    {
    }

    /** @return array<string, mixed> */
    public function build(): array
    {
        $cfg   = $this->config ?? config('Platform');
        $hub   = $this->hubRecords();
        $rates = $this->rateRecords();

        $decisions = [];
        foreach ($this->decisionCatalog($cfg) as $id => $d) {
            $decisions[] = $this->resolve($id, $d, $cfg, $hub);
        }

        $pending = array_values(array_filter($decisions, static fn (array $d): bool => $d['approval_state'] !== 'APPROVED_VERIFIED'));

        return [
            'generated_at'       => date('c'),
            'database'           => (string) db_connect()->getDatabase(),
            'confirmed'          => $this->confirmedRequirements(),
            'decisions'          => $decisions,
            'pending_count'      => count($pending),
            'approved_count'     => count($decisions) - count($pending),
            'rates'              => $rates,
            'hub'                => ['table_present' => $hub['table_present'], 'approved_policy_count' => count($hub['approved'])],
            'previews'           => $this->previews(),
            'denomination'       => $this->denominationImpact(),
            'blockers'           => $this->blockers($decisions, $rates, $hub),
            'kill_switches'      => service('financialSandboxService')->productionSwitchSnapshot(),
            'note'               => 'Technical recommendations are NOT business approval. Previews are illustrative and approve nothing.',
        ];
    }

    /** @return list<array{id: string, statement: string, source: string}> */
    public function confirmedRequirements(): array
    {
        return [
            ['id' => 'C1', 'statement' => 'Mining income is calculated daily.', 'source' => 'Business (confirmed)'],
            ['id' => 'C2', 'statement' => 'Mining rate is configured monthly by Admin (dual control, no self-approval).', 'source' => 'Business (confirmed); MiningRateService'],
            ['id' => 'C3', 'statement' => 'Mining is based on eligible total owned coins.', 'source' => 'Business (confirmed); MiningEligibleOwnershipSnapshotService'],
            ['id' => 'C4', 'statement' => 'Eligible locked coins are included in total owned coins.', 'source' => 'Business (confirmed)'],
            ['id' => 'C5', 'statement' => 'Mining Wallet is separate from the Commission Wallet.', 'source' => 'Business (confirmed)'],
            ['id' => 'C6', 'statement' => 'Mining Wallet funds cannot be externally withdrawn.', 'source' => 'Business (confirmed)'],
            ['id' => 'C7', 'statement' => 'Mining Wallet supports internal P2P only when separately enabled.', 'source' => 'Business (confirmed); miningP2pEnabled=false'],
            ['id' => 'C8', 'statement' => 'Calculation uses Asia/Kolkata business dates.', 'source' => 'Business (confirmed)'],
            ['id' => 'C9', 'statement' => 'No duplicate credit for the same member and business date.', 'source' => 'Business (confirmed); idempotency key + unique ledger index'],
            ['id' => 'C10', 'statement' => 'Financial posting requires approved policies and explicit activation.', 'source' => 'Business (confirmed); Phase 13A/13B gates'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function decisionCatalog(Platform $cfg): array
    {
        $c = [];
        $c['monthly_percent'] = [
            'options' => 'Any percent > 0 and <= 100 per effective month (draft -> different approver). The 3% used in 13A/13B tests is test-only.',
            'recommended' => 'No technical default exists; the business must supply the percentage.',
            'financial' => 'Linear: income = owned x percent / 100 / divisor. Doubling the percent doubles every daily credit.',
            'historical' => 'A rate applies to the month it is approved for; back-dated posting uses the rate of the business month.',
        ];
        $c['day_divisor'] = [
            'options' => 'fixed_30 | calendar_days (28/29/30/31 of the rate month)',
            'recommended' => 'calendar_days (monthly total equals the stated monthly percent in every month). Not an approval.',
            'financial' => 'fixed_30 pays 31/30 of the monthly percent in 31-day months and 28/30 in February.',
            'historical' => 'Changing it after posting does not alter posted days; the day run freezes the policy version.',
        ];
        $c['denomination'] = [
            'options' => 'COIN | USDT',
            'recommended' => 'COIN (the Mining Wallet ledger asset is single-asset today); USDT needs an approved conversion policy first.',
            'financial' => 'COIN: income is a coin quantity. USDT: needs a defined conversion (price source, time, rounding) — none is approved.',
            'historical' => 'A denomination change must not re-denominate existing mining ledger rows.',
        ];
        $c['approval_day'] = [
            'options' => 'full_day (earns on approval business date) | exclude_approval_day (earns from the next business date)',
            'recommended' => 'full_day matches the existing Phase 13A tests. Not an approval.',
            'financial' => 'Exclude-day delays the first credit by exactly one business day for every new purchase.',
            'historical' => 'Cutoff = end of the business day (full_day) or its start (exclude_approval_day) in Asia/Kolkata.',
        ];
        $c['locked_coins'] = [
            'options' => 'include (CONFIRMED business requirement C4)',
            'recommended' => 'include — already confirmed; the flag still needs a formal approval record.',
            'financial' => 'Locked coins earn the same as available coins.',
            'historical' => 'As-of locked/available split is rebuilt from the ownership ledger.',
        ];
        $c['post_maturity'] = [
            'options' => 'include_all (keep mining after LOCK1000 maturity/unlock) | exclude_matured_locked',
            'recommended' => 'No default recommended; the engine implements both. Do not assume matured coins stop mining.',
            'financial' => 'exclude_matured_locked removes matured-lock coins from the eligible quantity from the maturity day.',
            'historical' => 'Maturity is evaluated as-of the business date from lock records.',
        ];
        $c['post_sale'] = [
            'options' => 'reserved coins: include | exclude. Sold coins: stop at the ledger SALE_DEBIT time (engine behaviour).',
            'recommended' => 'No default recommended; do not assume sold coins keep mining.',
            'financial' => 'Reservation include keeps earning until settlement; exclude stops at SALE_RESERVE.',
            'historical' => 'Partial/full sale and settlement are applied from the dated ownership events only.',
        ];
        $c['rounding'] = [
            'options' => 'half_up | down (once, at 8 dp, per member per day). Residual carry-forward and final-day adjustment are NOT implemented.',
            'recommended' => 'half_up gives the smallest per-day error; the sum of daily roundings can still differ from the exact month by a few 1e-8.',
            'financial' => 'See the rounding comparison preview for the exact mathematical difference.',
            'historical' => 'Posted rows keep the rounding in force when posted (policy version is stored per day run).',
        ];
        $c['residual'] = [
            'options' => 'none (independent daily rounding) | residual carry-forward | final-day adjustment',
            'recommended' => 'Keep independent daily rounding; any adjustment rule needs explicit approval and new code + tests.',
            'financial' => 'Non-zero only at the 1e-8 level per member per month.',
            'historical' => 'A final-day adjustment would make a day depend on earlier days (breaks per-day idempotent recompute).',
        ];
        $c['effective_dates'] = [
            'options' => 'Rate: effective month (start = first day, end = last day of that month). Hub policies: effective_from / effective_to date window.',
            'recommended' => 'Month-granular rates are what the engine supports; sub-month windows are not supported.',
            'financial' => 'One approved rate per month (overlap refused).',
            'historical' => 'Backdated posting uses the approved rate of the business month.',
        ];
        $c['scheduler_time'] = [
            'options' => 'Post the previous completed Asia/Kolkata business day shortly after midnight IST.',
            'recommended' => '00:20 IST (as in the Phase 13A/13B examples) with a later health check. Not an approval.',
            'financial' => 'None directly; timing affects when members see credits.',
            'historical' => 'Dates are business dates, not run dates.',
        ];
        $c['alert_destination'] = [
            'options' => 'Email | webhook | Admin notification system (recipients/URLs must be supplied by the business).',
            'recommended' => 'At least one channel with a named on-call owner.',
            'financial' => 'None; a missed day is only noticed if an alert exists.',
            'historical' => 'Not applicable.',
        ];

        $out = [];
        foreach (self::FLAG_DECISIONS as $id => $meta) {
            $out[$id] = $c[$id] + ['label' => $meta[0], 'flag' => $meta[1], 'value_prop' => $meta[2], 'hub_keys' => $meta[3]];
        }
        $out['residual']          = $c['residual'] + ['label' => 'Residual / final-day adjustment', 'flag' => null, 'value_prop' => null, 'hub_keys' => ['residual_policy']];
        $out['effective_dates']   = $c['effective_dates'] + ['label' => 'Effective dates & policy versions', 'flag' => null, 'value_prop' => null, 'hub_keys' => []];
        $out['scheduler_time']    = $c['scheduler_time'] + ['label' => 'Scheduler run time', 'flag' => null, 'value_prop' => null, 'hub_keys' => ['scheduler_time']];
        $out['alert_destination'] = $c['alert_destination'] + ['label' => 'Alert destination', 'flag' => null, 'value_prop' => null, 'hub_keys' => ['alert_destination']];

        return $out;
    }

    /**
     * @param array<string, mixed> $d
     * @param array<string, mixed> $hub
     * @return array<string, mixed>
     */
    private function resolve(string $id, array $d, Platform $cfg, array $hub): array
    {
        $flag       = $d['flag'] !== null ? strtolower((string) $cfg->{$d['flag']}) : null;
        $current    = $d['value_prop'] !== null ? (string) $cfg->{$d['value_prop']} : null;
        $record     = null;
        $hubValue   = null;
        foreach ($hub['approved'] as $row) {
            foreach ($d['hub_keys'] as $key) {
                if (array_key_exists($key, $row['payload']) && $row['payload'][$key] !== null) {
                    $record   = $row;
                    $hubValue = $row['payload'][$key];
                    break 2;
                }
            }
        }

        $state = 'PENDING_BUSINESS_APPROVAL';
        if ($flag === 'approved' && $record !== null) {
            $state = 'APPROVED_VERIFIED';
        } elseif ($flag === 'approved' && $record === null && $d['flag'] !== null) {
            $state = 'FLAG_APPROVED_WITHOUT_POLICY_RECORD';
        } elseif ($record !== null) {
            $state = 'POLICY_RECORD_APPROVED_FLAG_NOT_APPROVED';
        }

        // Phase 13C.1: the Hub does not validate payload values. An approved record whose value is not a canonical
        // engine enum (e.g. the owner label ACTUAL_CALENDAR_DAYS instead of calendar_days) must never count as approved.
        if ($record !== null && $state === 'APPROVED_VERIFIED' && ! self::valueIsCanonical($id, $hubValue)) {
            $state = 'POLICY_VALUE_INVALID';
        }

        $verified = $state === 'APPROVED_VERIFIED';

        return [
            'id'                      => $id,
            'decision'                => $d['label'],
            'current_status'          => $flag ?? 'no_status_flag',
            'platform_default_value'  => $current,
            'available_options'       => $d['options'],
            'recommended_default'     => $d['recommended'],
            'financial_impact'        => $d['financial'],
            'historical_impact'       => $d['historical'],
            'approval_required'       => 'Business owner + dual-control (creator != approver) in the Financial Policy Hub, then platform status flag set to "approved" by an authorized person',
            'approval_state'          => $state,
            'approved_value'          => $verified ? (is_scalar($hubValue) ? (string) $hubValue : json_encode($hubValue)) : self::PENDING,
            'effective_date'          => $verified ? ($record['effective_from'] ?? null) : null,
            'policy_ref'              => $verified ? ($record['policy_ref'] ?? null) : null,
            'policy_version'          => $verified ? ($record['version_no'] ?? null) : null,
            'approved_by'             => $verified ? ($record['approved_by'] ?? null) : null,
        ];
    }

    /** Canonical enum values accepted by the posting engine (unknown decisions: no restriction). */
    public static function valueIsCanonical(string $id, mixed $value): bool
    {
        $enum = [
            'day_divisor'   => ['calendar_days', 'fixed_30'],
            'denomination'  => ['COIN', 'USDT'],
            'approval_day'  => ['full_day', 'exclude_approval_day'],
            'post_maturity' => ['include_all', 'exclude_matured_locked'],
            'post_sale'     => ['include', 'exclude'],
            'rounding'      => ['half_up', 'down'],
        ];
        if (isset($enum[$id])) {
            return is_string($value) && in_array($value, $enum[$id], true);
        }
        if ($id === 'monthly_percent') {
            return (is_string($value) || is_int($value)) && preg_match('/^\d{1,3}(\.\d{1,8})?$/', (string) $value) === 1 && bccomp((string) $value, '0', 8) > 0 && bccomp((string) $value, '100', 8) <= 0;
        }

        return true;
    }

    /** @return array{table_present: bool, approved: list<array<string, mixed>>} */
    private function hubRecords(): array
    {
        $out = ['table_present' => false, 'approved' => []];
        try {
            $db = db_connect();
            if (! $db->tableExists('financial_policy_versions')) {
                return $out;
            }
            $out['table_present'] = true;
            $rows = $db->table('financial_policy_versions')
                ->where('domain', FinancialPolicyService::DOMAIN_MINING)
                ->whereIn('status', [FinancialPolicyService::STATUS_APPROVED, FinancialPolicyService::STATUS_ACTIVE])
                ->orderBy('id', 'DESC')
                ->get()->getResultArray();
            foreach ($rows as $r) {
                $payload = json_decode((string) ($r['payload_json'] ?? ''), true);
                $r['payload'] = is_array($payload) ? $payload : [];
                $out['approved'][] = $r;
            }
        } catch (Throwable) {
            // read-only worksheet must never fail the page
        }

        return $out;
    }

    /** @return array{table_present: bool, rows: list<array<string, mixed>>} */
    private function rateRecords(): array
    {
        $out = ['table_present' => false, 'rows' => []];
        try {
            $db = db_connect();
            if (! $db->tableExists('mining_rate_configs')) {
                return $out;
            }
            $out['table_present'] = true;
            $rows = $db->table('mining_rate_configs')->orderBy('effective_month', 'ASC')->orderBy('id', 'ASC')->get()->getResultArray();
            $versionByMonth = [];
            foreach ($rows as $r) {
                $month = (string) $r['effective_month'];
                $versionByMonth[$month] = ($versionByMonth[$month] ?? 0) + 1;
                $first = $month . '-01';
                $out['rows'][] = [
                    'rate_ref'        => $r['rate_ref'],
                    'effective_month' => $month,
                    'effective_start' => $first,
                    'effective_end'   => date('Y-m-t', (int) strtotime($first)),
                    'version_in_month' => $versionByMonth[$month],
                    'rate_percent'    => $r['rate_percent'],
                    'rate_basis'      => $r['rate_basis'],
                    'status'          => $r['status'],
                    'created_by'      => $r['created_by'],
                    'reviewed_approved_by' => $r['approved_by'],
                    'approved_at'     => $r['approved_at'],
                    'self_approved'   => $r['approved_by'] !== null && (int) $r['approved_by'] === (int) $r['created_by'],
                    'looks_test_only' => stripos((string) ($r['notes'] ?? ''), 'test') !== false,
                ];
            }
        } catch (Throwable) {
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function previews(): array
    {
        $owned = '1000';
        $pct   = '3';

        $divisor = [];
        foreach ([28 => 'February (common year)', 29 => 'February (leap year)', 30 => 'April / June / Sept / Nov', 31 => 'January / March / ... / December'] as $days => $label) {
            $row = ['days_in_month' => $days, 'month_example' => $label];
            foreach ([MiningMath::ROUND_HALF_UP, MiningMath::ROUND_DOWN] as $mode) {
                $fixed = MiningMath::dailyAmount($owned, $pct, 30, 8, $mode);
                $cal   = MiningMath::dailyAmount($owned, $pct, $days, 8, $mode);
                $row[$mode] = [
                    'fixed_30_daily'        => $fixed,
                    'calendar_daily'        => $cal,
                    'fixed_30_month_total'  => bcmul($fixed, (string) $days, 8),
                    'calendar_month_total'  => bcmul($cal, (string) $days, 8),
                ];
            }
            $row['exact_monthly_income'] = bcdiv(bcmul($owned, $pct, 12), '100', 8);
            $divisor[] = $row;
        }

        // Rounding / residual comparison for a 31-day month (exact math, bcmath only).
        $days   = 31;
        $exact  = bcdiv(bcmul($owned, $pct, 40), '100', 40);                         // exact monthly income
        $perDay = bcdiv($exact, (string) $days, 40);
        $rounding = [];
        foreach ([MiningMath::ROUND_HALF_UP, MiningMath::ROUND_DOWN] as $mode) {
            $daily = MiningMath::dailyAmount($owned, $pct, $days, 8, $mode);
            $sumIndependent = bcmul($daily, (string) $days, 8);

            // Illustrative alternative A: residual carry-forward (NOT implemented): pay the rounded cumulative exact amount minus what was already paid.
            $paid = '0';
            for ($d = 1; $d <= $days; $d++) {
                $cum  = bcmul($perDay, (string) $d, 40);
                $due  = MiningMath::round($cum, 8, $mode);
                $paid = $due;
            }
            // Illustrative alternative B: final-day adjustment (NOT implemented): last day = exact monthly - sum(first 30 days).
            $first30 = bcmul($daily, (string) ($days - 1), 8);
            $final   = MiningMath::round($exact, 8, $mode);
            $lastDay = bcsub($final, $first30, 8);

            $rounding[$mode] = [
                'daily_credit'                          => $daily,
                'independent_daily_month_total'         => $sumIndependent,
                'exact_monthly_income'                  => bcadd($exact, '0', 12),
                'independent_vs_exact_difference'       => bcsub($sumIndependent, bcadd($exact, '0', 12), 12),
                'carry_forward_month_total'             => $paid,
                'carry_forward_vs_exact_difference'     => bcsub($paid, bcadd($exact, '0', 12), 12),
                'final_day_adjustment_last_day_credit'  => $lastDay,
                'final_day_adjustment_month_total'      => bcadd($first30, $lastDay, 8),
                'final_day_vs_normal_day_difference'    => bcsub($lastDay, $daily, 8),
            ];
        }

        $rateExamples = [];
        foreach (['1', '2', '3', '5'] as $r) {
            $rateExamples[] = [
                'monthly_percent' => $r,
                'owned_coins'     => $owned,
                'fixed_30_half_up' => MiningMath::dailyAmount($owned, $r, 30, 8, MiningMath::ROUND_HALF_UP),
                'calendar_31_half_up' => MiningMath::dailyAmount($owned, $r, 31, 8, MiningMath::ROUND_HALF_UP),
                'label'           => 'ILLUSTRATIVE ONLY — not a production rate',
            ];
        }

        return [
            'assumptions'      => 'Owned coins = 1000; monthly rate = 3% unless stated; HALF_UP/DOWN shown side by side. Illustrative only; approves nothing.',
            'divisor_matrix'   => $divisor,
            'rounding_matrix_31_day_month' => $rounding,
            'rate_examples'    => $rateExamples,
            'spec_example'     => [
                'fixed_30'   => MiningMath::dailyAmount($owned, $pct, 30, 8, MiningMath::ROUND_HALF_UP),
                'calendar_31' => MiningMath::dailyAmount($owned, $pct, 31, 8, MiningMath::ROUND_HALF_UP),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function denominationImpact(): array
    {
        return [
            'decision_status' => self::PENDING,
            'current_platform_value' => (string) (($this->config ?? config('Platform'))->miningEarningAsset),
            'note' => 'miningEarningAsset=COIN is a platform default, NOT an approval.',
            'if_coin' => [
                'wallet'   => 'mining_wallets.available_balance holds a COIN quantity (8 dp). Ledger asset = COIN (mining_wallet_ledger.asset).',
                'ledger'   => 'daily_mining_credit rows carry the coin amount; no price is involved.',
                'precision'=> '8 dp (MiningMath, rounded once).',
                'p2p'      => 'Internal Mining-Wallet P2P moves COIN between Mining Wallets only (still disabled).',
                'reconciliation' => 'expected COIN (frozen per member) = posted COIN; wallet = ledger sum.',
                'dashboard'=> 'Member dashboard shows COIN balances/earnings.',
                'reports'  => 'Financial reports total COIN; no USDT conversion line.',
            ],
            'if_usdt' => [
                'definition_required' => 'A conversion policy: price source (Dynamic Coin Price vs fixed), snapshot time (credit date vs month start), rounding, and handling of price changes. NONE is approved; the coin market price is NOT reused.',
                'wallet'   => 'Needs a USDT-denominated Mining Wallet balance or a second asset column; the current Mining Wallet is single-asset (COIN). Compatibility work required — not done silently.',
                'ledger'   => 'USDT and COIN balances must never be mixed in one ledger; a separate asset code per row/ledger.',
                'precision'=> 'USDT scale (8 dp in this platform) applied after conversion — two rounding steps unless defined otherwise.',
                'p2p'      => 'P2P policy must state the transferable asset.',
                'reconciliation' => 'Must reconcile coins x price = USDT credited, per member per day, with the stored price snapshot.',
                'dashboard'=> 'Dashboard and reports need USDT labels/formatting.',
                'reports'  => 'Reports need the conversion price snapshot to be auditable.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $decisions
     * @param array<string, mixed>       $rates
     * @param array<string, mixed>       $hub
     * @return list<array{id: string, blocker: string, status: string}>
     */
    private function blockers(array $decisions, array $rates, array $hub): array
    {
        $b = [];
        foreach ($decisions as $d) {
            if ($d['approval_state'] !== 'APPROVED_VERIFIED') {
                $b[] = ['id' => 'POLICY-' . strtoupper($d['id']), 'blocker' => $d['decision'] . ' is not approved (' . $d['approval_state'] . ').', 'status' => 'OPEN'];
            }
        }
        $approvedRates = array_filter($rates['rows'], static fn (array $r): bool => $r['status'] === 'approved' && ! $r['looks_test_only']);
        if ($approvedRates === []) {
            $b[] = ['id' => 'RATE-APPROVED', 'blocker' => 'No approved, non-test monthly mining rate exists in this database.', 'status' => 'OPEN'];
        }
        if (! $hub['table_present']) {
            $b[] = ['id' => 'HUB-MIGRATION', 'blocker' => 'financial_policy_versions table is absent (migration 993001 not applied to this database).', 'status' => 'OPEN'];
        }

        return $b;
    }

    /** @param array<string, mixed> $w */
    public function toMarkdown(array $w): string
    {
        $esc = static fn (mixed $v): string => str_replace(["\n", '|'], [' ', '/'], (string) ($v ?? ''));
        $L   = [];
        $L[] = '# Phase 13C — Mining business decision matrix';
        $L[] = '';
        $L[] = 'Generated: ' . $w['generated_at'] . ' from database `' . $w['database'] . '` (read-only). Single source: `MiningPolicyWorksheetService`.';
        $L[] = '';
        $L[] = '**A technical recommendation is not business approval.** `Approved Value` is filled only when an approved/active Financial Policy Hub record exists AND the platform status flag is `approved`; otherwise the item is `' . self::PENDING . '`.';
        $L[] = '';
        $L[] = '## 1. Confirmed business requirements';
        $L[] = '';
        foreach ($w['confirmed'] as $c) {
            $L[] = '- **' . $c['id'] . '** ' . $c['statement'] . ' _(' . $c['source'] . ')_';
        }
        $L[] = '';
        $L[] = '## 2. Decision matrix (' . $w['approved_count'] . ' verified approved, ' . $w['pending_count'] . ' not approved)';
        $L[] = '';
        $L[] = '| Decision | Current Status | Available Options | Recommended Technical Default | Financial Impact | Historical Data Impact | Approval Required | Approved Value | Effective Date |';
        $L[] = '|---|---|---|---|---|---|---|---|---|';
        foreach ($w['decisions'] as $d) {
            $L[] = '| ' . implode(' | ', [
                $esc($d['decision']), '`' . $esc($d['current_status']) . '`' . ($d['platform_default_value'] !== null ? ' (default value `' . $esc($d['platform_default_value']) . '`)' : ''),
                $esc($d['available_options']), $esc($d['recommended_default']), $esc($d['financial_impact']), $esc($d['historical_impact']),
                $esc($d['approval_required']), '**' . $esc($d['approved_value']) . '**' . ($d['approval_state'] !== 'APPROVED_VERIFIED' ? ' (' . $d['approval_state'] . ')' : ''), $esc($d['effective_date'] ?? '—'),
            ]) . ' |';
        }
        $L[] = '';
        $L[] = '## 3. Monthly rate records (actual rows)';
        $L[] = '';
        if (empty($w['rates']['rows'])) {
            $L[] = 'No rate rows exist in this database (' . ($w['rates']['table_present'] ? 'table present, empty' : 'table absent') . '). **PENDING_BUSINESS_APPROVAL**.';
        } else {
            $L[] = '| Rate ref | Month | Start | End | Version | Percent | Status | Created by | Approved by | Approved at | Test-only? |';
            $L[] = '|---|---|---|---|---:|---:|---|---|---|---|---|';
            foreach ($w['rates']['rows'] as $r) {
                $L[] = '| ' . implode(' | ', [$r['rate_ref'], $r['effective_month'], $r['effective_start'], $r['effective_end'], $r['version_in_month'], $r['rate_percent'], $r['status'], $r['created_by'], $r['reviewed_approved_by'] ?? '—', $r['approved_at'] ?? '—', $r['looks_test_only'] ? 'yes' : 'no']) . ' |';
            }
        }
        $L[] = '';
        $L[] = 'Rate workflow facts (verified in `MiningRateService`): draft -> approve by a **different** user (self-approval refused), one approved rate per effective month (overlap refused), approved rates are immutable, month granularity (start = first day, end = last day of the month). Sub-month effective windows are **not supported**; if the business needs them it is a new decision.';
        $L[] = '';
        $L[] = '## 4. Illustrative previews (1000 coins, 3 % monthly — NOT approved)';
        $L[] = '';
        $L[] = '### Divisor and rounding (daily credit / month total)';
        $L[] = '';
        $L[] = '| Days in month | Exact monthly income | Fixed-30 HALF_UP daily | Calendar HALF_UP daily | Fixed-30 HALF_UP month total | Calendar HALF_UP month total | Calendar DOWN daily | Calendar DOWN month total |';
        $L[] = '|---:|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($w['previews']['divisor_matrix'] as $r) {
            $L[] = '| ' . implode(' | ', [$r['days_in_month'], $r['exact_monthly_income'], $r['half_up']['fixed_30_daily'], $r['half_up']['calendar_daily'], $r['half_up']['fixed_30_month_total'], $r['half_up']['calendar_month_total'], $r['down']['calendar_daily'], $r['down']['calendar_month_total']]) . ' |';
        }
        $L[] = '';
        $L[] = '### Residual handling, 31-day month (exact difference vs exact monthly income)';
        $L[] = '';
        $L[] = '| Rounding | Independent daily total | Diff vs exact | Carry-forward total (not implemented) | Diff vs exact | Final-day adjustment: last-day credit (not implemented) | Last day minus normal day |';
        $L[] = '|---|---:|---:|---:|---:|---:|---:|';
        foreach ($w['previews']['rounding_matrix_31_day_month'] as $mode => $r) {
            $L[] = '| ' . implode(' | ', [$mode, $r['independent_daily_month_total'], $r['independent_vs_exact_difference'], $r['carry_forward_month_total'], $r['carry_forward_vs_exact_difference'], $r['final_day_adjustment_last_day_credit'], $r['final_day_vs_normal_day_difference']]) . ' |';
        }
        $L[] = '';
        $L[] = 'Spec example: fixed-30 = `' . $w['previews']['spec_example']['fixed_30'] . '`, 31-day calendar = `' . $w['previews']['spec_example']['calendar_31'] . '` (HALF_UP, illustrative).';
        $L[] = '';
        $L[] = '## 5. Denomination (COIN vs USDT) impact';
        $L[] = '';
        foreach (['if_coin' => 'If COIN', 'if_usdt' => 'If USDT'] as $k => $title) {
            $L[] = '**' . $title . '**';
            $L[] = '';
            foreach ($w['denomination'][$k] as $area => $text) {
                $L[] = '- *' . $area . ':* ' . $text;
            }
            $L[] = '';
        }
        $L[] = '## 6. Open blockers from the worksheet';
        $L[] = '';
        foreach ($w['blockers'] as $b) {
            $L[] = '- `' . $b['id'] . '` — ' . $b['blocker'];
        }
        $L[] = '';

        return implode("\n", $L);
    }
}
