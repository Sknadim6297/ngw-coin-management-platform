<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningMath;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Phase 13C.1 — owner-proposed mining policy values, economic previews and Hub draft payloads.
 *
 * Everything here is PROPOSED, never approved. The four proposals map onto the EXISTING decision identifiers and the
 * EXISTING platform/Hub enums (no new enum names). Nothing writes to a database unless createHubDrafts() is called,
 * which refuses any database that is not an allowlisted isolated one and only creates DRAFT rows (never submits,
 * approves or activates).
 */
class MiningProposalService
{
    public const STATE = 'PROPOSED_NOT_APPROVED';
    public const TZ    = 'Asia/Kolkata';

    /** Existing decision ids (MiningPolicyWorksheetService) in the order of the matrix. */
    public const DECISION_IDS = [
        'monthly_percent', 'day_divisor', 'denomination', 'approval_day', 'locked_coins', 'post_maturity',
        'post_sale', 'rounding', 'residual', 'effective_dates', 'scheduler_time', 'alert_destination',
    ];

    /** @return list<array<string, mixed>> */
    public function proposals(): array
    {
        return [
            [
                'decision_id'     => 'monthly_percent',
                'owner_proposal'  => '3.00000000 percent per month',
                'proposed_value'  => '3.00000000',
                'existing_enum'   => 'numeric percent (mining_rate_configs.rate_percent / Hub key monthly_percent)',
                'hub_payload'     => ['monthly_percent' => '3.00000000', 'rate_basis' => 'monthly', 'proposed_owner_label' => '3.00000000 percent per month'],
                'engine_note'     => 'Rate rows are month-granular: one approved rate per effective month, created by one admin and approved by another.',
                'state'           => self::STATE,
            ],
            [
                'decision_id'     => 'day_divisor',
                'owner_proposal'  => 'ACTUAL_CALENDAR_DAYS',
                'proposed_value'  => 'calendar_days',
                'existing_enum'   => MiningMath::DIVISOR_CALENDAR . ' (platform miningMonthDayDivisorMode; Hub key month_day_divisor_mode)',
                'hub_payload'     => ['month_day_divisor_mode' => 'calendar_days', 'proposed_owner_label' => 'ACTUAL_CALENDAR_DAYS'],
                'engine_note'     => 'ACTUAL_CALENDAR_DAYS is the owner wording for the existing enum calendar_days; no new enum is created.',
                'state'           => self::STATE,
            ],
            [
                'decision_id'     => 'denomination',
                'owner_proposal'  => 'COIN',
                'proposed_value'  => 'COIN',
                'existing_enum'   => 'COIN (platform miningEarningAsset; Hub key earning_asset)',
                'hub_payload'     => ['earning_asset' => 'COIN', 'proposed_owner_label' => 'COIN'],
                'engine_note'     => 'mining_wallets.asset_code already defaults to COIN. The supply source of the credited COIN is still unresolved (see tokenomics).',
                'state'           => self::STATE,
            ],
            [
                'decision_id'     => 'approval_day',
                'owner_proposal'  => 'FOLLOWING_BUSINESS_DATE',
                'proposed_value'  => 'exclude_approval_day',
                'existing_enum'   => 'exclude_approval_day (platform miningApprovalDayPolicy; Hub key approval_day_policy)',
                'hub_payload'     => ['approval_day_policy' => 'exclude_approval_day', 'proposed_owner_label' => 'FOLLOWING_BUSINESS_DATE'],
                'engine_note'     => 'FOLLOWING_BUSINESS_DATE is the owner wording for the existing enum exclude_approval_day (cutoff = start of the Asia/Kolkata business day).',
                'state'           => self::STATE,
            ],
        ];
    }

    /**
     * Calendar-day validation for every month using Asia/Kolkata dates.
     *
     * @return list<array<string, mixed>>
     */
    public function calendarDays(): array
    {
        $tz   = new DateTimeZone(self::TZ);
        $rows = [];
        foreach ([
            ['2026-01', 31], ['2027-02', 28], ['2028-02', 29], ['2026-03', 31], ['2026-04', 30], ['2026-05', 31], ['2026-06', 30],
            ['2026-07', 31], ['2026-08', 31], ['2026-09', 30], ['2026-10', 31], ['2026-11', 30], ['2026-12', 31],
        ] as [$ym, $expected]) {
            $first  = new DateTimeImmutable($ym . '-01 00:00:00', $tz);
            $last   = $first->modify('last day of this month');
            $days   = (int) $last->format('j');
            $engine = MiningMath::divisor('monthly', $last->format('Y-m-d'), null, MiningMath::DIVISOR_CALENDAR);
            $engineFirst = MiningMath::divisor('monthly', $first->format('Y-m-d'), null, MiningMath::DIVISOR_CALENDAR);
            $rows[] = [
                'month'            => $ym,
                'label'            => $first->format('F Y') . ($ym === '2028-02' ? ' (leap year)' : ($ym === '2027-02' ? ' (non-leap year)' : '')),
                'expected_days'    => $expected,
                'calendar_days'    => $days,
                'engine_divisor_first_day' => $engineFirst,
                'engine_divisor_last_day'  => $engine,
                'daily_1000_at_3pct_half_up' => MiningMath::dailyAmount('1000', '3', $engine, 8, MiningMath::ROUND_HALF_UP),
                'ok'               => $days === $expected && $engine === $expected && $engineFirst === $expected,
            ];
        }

        return $rows;
    }

    /**
     * Economic preview of a monthly percent (default 3). Exact bcmath; scenarios are labelled illustrative.
     *
     * @return array<string, mixed>
     */
    public function economicPreview(string $percent = '3'): array
    {
        $holdings = ['100', '500', '1000', '5000', '10000'];
        $rows     = [];
        foreach ($holdings as $h) {
            $row = ['eligible_coins' => $h . '.00000000'];
            foreach ([28, 29, 30, 31] as $d) {
                $row['daily_' . $d . '_days'] = [
                    'half_up' => MiningMath::dailyAmount($h, $percent, $d, 8, MiningMath::ROUND_HALF_UP),
                    'down'    => MiningMath::dailyAmount($h, $percent, $d, 8, MiningMath::ROUND_DOWN),
                ];
            }
            $monthly = bcdiv(bcmul($h, $percent, 12), '100', 8);
            $row['theoretical_monthly_issuance']    = $monthly;
            $row['annualized_simple_issuance']      = bcmul($monthly, '12', 8);
            $row['annual_simple_percent_of_holding'] = bcmul($percent, '12', 4);
            $row['compounding_hypothetical']        = $this->compounding($h, $percent);
            $row['lock_1000_day_simple_issuance']   = $this->horizon($h, $percent, 1000, '2026-11-18');
            $rows[] = $row;
        }

        return [
            'monthly_percent' => $percent,
            'holdings'        => $rows,
            'compounding_note' => 'Compounding is NOT enabled and not possible today: mining credits go to the Mining Wallet only and never enter the ownership ledger that determines eligibility. These figures show what would happen ONLY IF a future policy made mining credits eligible ownership.',
        ];
    }

    /** @return array<string, string> */
    private function compounding(string $holding, string $percent): array
    {
        // monthly compounding: credit at month end becomes eligible next month
        $m = $holding;
        $f = bcadd('1', bcdiv($percent, '100', 16), 16);
        for ($i = 0; $i < 12; $i++) {
            $m = bcmul($m, $f, 16);
        }
        // daily compounding over the real calendar of 2027 (each day earns percent/days_in_month of the running balance)
        $tz = new DateTimeZone(self::TZ);
        $d  = $holding;
        for ($month = 1; $month <= 12; $month++) {
            $days = (int) (new DateTimeImmutable(sprintf('2027-%02d-01', $month), $tz))->format('t');
            $step = bcadd('1', bcdiv(bcdiv($percent, '100', 16), (string) $days, 16), 16);
            for ($i = 0; $i < $days; $i++) {
                $d = bcmul($d, $step, 16);
            }
        }

        return [
            'after_12_months_monthly_compounding'      => bcadd($m, '0', 8),
            'extra_over_simple_monthly_compounding'    => bcsub(bcsub($m, $holding, 16), bcmul(bcdiv(bcmul($holding, $percent, 16), '100', 16), '12', 16), 8),
            'after_12_months_daily_compounding_2027'   => bcadd($d, '0', 8),
            'extra_over_simple_daily_compounding'      => bcsub(bcsub($d, $holding, 16), bcmul(bcdiv(bcmul($holding, $percent, 16), '100', 16), '12', 16), 8),
            'status'                                   => 'HYPOTHETICAL_NOT_ENABLED',
        ];
    }

    /** Sum of daily simple issuance over $days real calendar days from $startDate (exact; no rounding until the end). */
    private function horizon(string $holding, string $percent, int $days, string $startDate): string
    {
        $tz  = new DateTimeZone(self::TZ);
        $day = new DateTimeImmutable($startDate, $tz);
        $sum = '0';
        for ($i = 0; $i < $days; $i++) {
            $n   = (int) $day->format('t');
            $sum = bcadd($sum, bcdiv(bcmul($holding, bcdiv($percent, '100', 16), 16), (string) $n, 16), 16);
            $day = $day->modify('+1 day');
        }

        return bcadd($sum, '0', 8);
    }

    /**
     * Following-business-date eligibility for a single approval timestamp (pure rule; the engine's as-of snapshot is
     * verified separately against this function).
     *
     * @return array{state: string, approval_business_date: ?string, first_eligible_date: ?string, note: string}
     */
    public function firstEligibleDate(?string $approvedAtUtc, ?string $nowUtc = null): array
    {
        if ($approvedAtUtc === null || trim($approvedAtUtc) === '' || str_starts_with($approvedAtUtc, '0000')) {
            return ['state' => 'BLOCKED_MISSING_APPROVAL_TIMESTAMP', 'approval_business_date' => null, 'first_eligible_date' => null, 'note' => 'No eligibility is inferred without an approval timestamp.'];
        }
        $utc = new DateTimeZone('UTC');
        try {
            $at = new DateTimeImmutable($approvedAtUtc, $utc);
        } catch (\Exception) {
            throw new InvalidArgumentException('Invalid approval timestamp.');
        }
        $now = new DateTimeImmutable($nowUtc ?? 'now', $utc);
        $bd  = $at->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
        $first = (new DateTimeImmutable($bd, new DateTimeZone(self::TZ)))->modify('+1 day')->format('Y-m-d');

        return [
            'state'                  => $at > $now ? 'FUTURE_DATED_APPROVAL' : 'ELIGIBLE_FROM_FIRST_DATE',
            'approval_business_date' => $bd,
            'first_eligible_date'    => $first,
            'note'                   => $at > $now ? 'Approval is in the future; nothing accrues and the record needs review.' : 'Earns from the following Asia/Kolkata business date.',
        ];
    }

    /**
     * Create DRAFT Hub policies for the four proposals (isolated database only). Never submits/approves/activates.
     *
     * @return list<array<string, mixed>>
     */
    public function createHubDrafts(int $actorUserId, ?FinancialPolicyService $hub = null): array
    {
        service('financialSandboxService')->assertIsolatedDatabase();
        $hub ??= new FinancialPolicyService();
        $out = [];
        foreach ($this->proposals() as $p) {
            $payload = $p['hub_payload'] + ['proposal_state' => self::STATE, 'effective_date' => null, 'effective_date_note' => 'PENDING owner decision; no backdating.'];
            $row = $hub->createDraft(
                FinancialPolicyService::DOMAIN_MINING,
                'PROPOSAL (not approved): ' . $p['decision_id'] . ' = ' . $p['proposed_value'],
                $payload,
                $actorUserId,
                null,
                'Owner proposal recorded in Phase 13C.1. Requires separate dual-control approval; effective date not set.'
            );
            $out[] = ['decision_id' => $p['decision_id'], 'policy_ref' => $row['policy_ref'] ?? null, 'status' => $row['status'] ?? null, 'version_no' => $row['version_no'] ?? null];
        }

        return $out;
    }
}
