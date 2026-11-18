<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;

/**
 * Phase 11A / Phase 14 unified mining eligibility.
 *
 * Policy total_owned_active (Coin Plan):
 * - Eligible coins = as-of owned balance (available + locked, subject to reserved/maturity policies).
 * - Member must be active.
 * - Mining start follows Platform.miningApprovalDayPolicy (exclude_approval_day = next Asia/Kolkata
 *   business date after purchase approval; full_day = legacy/test fixture including approval date).
 * - Multi-purchase cutoff: coins from any later purchase approved on/after the calculation business
 *   date under exclude_approval_day are excluded until the following Asia/Kolkata business date.
 * - Mining earnings / commission USDT are never counted as principal (no compounding).
 */
class MiningEligibilityService
{
    /** @deprecated Legacy Phase 7B policy — retained for historical rate rows only. */
    public const POLICY_AVAILABLE_ACTIVE = 'available_active_only';

    public const POLICY_TOTAL_OWNED_ACTIVE = 'total_owned_active';

    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null,
        private readonly ?MiningEligibleOwnershipSnapshotService $snapshots = null
    ) {
    }

    public function activePolicy(): string
    {
        return self::POLICY_TOTAL_OWNED_ACTIVE;
    }

    /**
     * Business date (Y-m-d) in mining timezone.
     */
    public function businessDate(?\DateTimeInterface $when = null): string
    {
        $tz = ($this->config ?? config('Platform'))->miningBusinessTimezone ?: 'Asia/Kolkata';
        $dt = $when instanceof \DateTimeInterface
            ? \DateTimeImmutable::createFromInterface($when)
            : new \DateTimeImmutable('now');

        return $dt->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
    }

    /**
     * Earliest approval business date among approved purchases for the member (Asia/Kolkata).
     */
    public function miningStartBusinessDate(int $memberUserId): ?string
    {
        $row = db_connect()->table('coin_purchase_requests')
            ->select('reviewed_at, updated_at, created_at')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'approved')
            ->orderBy('reviewed_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }

        $ts = (string) ($row['reviewed_at'] ?? $row['updated_at'] ?? $row['created_at'] ?? '');
        if ($ts === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($ts, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            return null;
        }

        return $this->businessDate($dt);
    }

    /**
     * First eligible mining business date in Asia/Kolkata under the configured approval-day policy.
     */
    public function effectiveStartBusinessDate(int $memberUserId): ?string
    {
        $approvalDate = $this->miningStartBusinessDate($memberUserId);
        if ($approvalDate === null) {
            return null;
        }

        $cfg       = $this->config ?? config('Platform');
        $policyDay = strtolower((string) $cfg->miningApprovalDayPolicy);
        if ($policyDay === MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY) {
            $tz = (string) ($cfg->miningBusinessTimezone ?: 'Asia/Kolkata');

            return (new \DateTimeImmutable($approvalDate, new \DateTimeZone($tz)))
                ->modify('+1 day')
                ->format('Y-m-d');
        }

        return $approvalDate;
    }

    /**
     * @param array<string, mixed>|null $rateConfig
     * @return array{
     *   member_user_id: int,
     *   member_id: ?string,
     *   calculation_date: string,
     *   total_owned: string,
     *   available: string,
     *   locked: string,
     *   eligible_coins: string,
     *   ineligible_coins: string,
     *   eligible: bool,
     *   reason_codes: list<string>,
     *   rate: ?array<string, mixed>,
     *   policy: string,
     *   mining_start_date: ?string,
     *   approval_business_date: ?string,
     *   assumptions: array<string, string>
     * }
     */
    public function evaluate(int $memberUserId, string $calculationDate, ?array $rateConfig = null): array
    {
        $cfg     = $this->config ?? config('Platform');
        $scale   = $this->scale();
        $policy  = self::POLICY_TOTAL_OWNED_ACTIVE;
        $calcPol = MiningCalculationPolicy::fromPlatform($cfg);
        $profile = $this->profiles->findByUserId($memberUserId);
        $summary = $this->ownership->summaryForUser($memberUserId);
        $reasons = [];
        $assumptions = [
            'approval_day_policy' => $calcPol->approvalDay,
            'month_day_divisor'   => $calcPol->divisorMode,
            'rounding_mode'       => $calcPol->rounding,
            'earning_asset'       => (string) $cfg->miningEarningAsset,
            'business_timezone'   => (string) $cfg->miningBusinessTimezone,
            'compounding_enabled' => 'false',
            'policy_version'      => $calcPol->version(),
            'label'               => 'OWNER_RULES_PENDING_FORMAL_ACTIVATION',
        ];

        if ($profile === null) {
            return [
                'member_user_id'         => $memberUserId,
                'member_id'              => null,
                'calculation_date'       => $calculationDate,
                'total_owned'            => MoneyMath::normalize('0', $scale),
                'available'              => MoneyMath::normalize('0', $scale),
                'locked'                 => MoneyMath::normalize('0', $scale),
                'eligible_coins'         => MoneyMath::normalize('0', $scale),
                'ineligible_coins'       => MoneyMath::normalize('0', $scale),
                'eligible'               => false,
                'reason_codes'           => ['member_not_found'],
                'rate'                   => $rateConfig,
                'policy'                 => $policy,
                'mining_start_date'      => null,
                'approval_business_date' => null,
                'assumptions'            => $assumptions,
            ];
        }

        if ($profile->isInactive()) {
            $reasons[] = 'member_inactive';
        }

        if ($rateConfig === null) {
            $reasons[] = 'rate_not_configured';
        } elseif (($rateConfig['status'] ?? '') !== 'approved') {
            $reasons[] = 'rate_not_approved';
        }

        $owned     = MoneyMath::normalize($summary['balance'], $scale);
        $available = MoneyMath::normalize($summary['available'], $scale);
        $locked    = MoneyMath::normalize($summary['locked'], $scale);
        $reserved  = MoneyMath::normalize((string) ($summary['reserved'] ?? '0'), $scale);

        if (MoneyMath::isNegative($owned, $scale)
            || MoneyMath::isNegative($available, $scale)
            || MoneyMath::isNegative($locked, $scale)
            || MoneyMath::isNegative($reserved, $scale)
        ) {
            $reasons[] = 'negative_balance';
        }

        $sumParts = MoneyMath::add(MoneyMath::add($available, $locked, $scale), $reserved, $scale);
        if (MoneyMath::compare($sumParts, $owned, $scale) !== 0) {
            $reasons[] = 'balance_invariant_broken';
        }

        $approvalDate = $this->miningStartBusinessDate($memberUserId);
        $compareStart = $this->effectiveStartBusinessDate($memberUserId);
        if ($approvalDate === null || $compareStart === null) {
            $reasons[] = 'no_approved_purchase';
        } else {
            if ($calcPol->approvalDay === MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY) {
                $reasons[] = 'exclude_approval_day_policy';
            } else {
                $reasons[] = 'full_day_approval_policy';
            }
            if (strcmp($calculationDate, $compareStart) < 0) {
                $reasons[] = 'before_mining_start';
            }
        }

        $eligibleCoins = MoneyMath::normalize('0', $scale);
        $isEligible    = false;

        $blocking = array_values(array_filter(
            $reasons,
            static fn (string $c): bool => ! in_array($c, [
                'exclude_approval_day_policy',
                'full_day_approval_policy',
            ], true)
        ));

        if ($profile->isActive()
            && $rateConfig !== null
            && ($rateConfig['status'] ?? '') === 'approved'
            && $approvalDate !== null
            && ! in_array('before_mining_start', $blocking, true)
            && ! in_array('negative_balance', $blocking, true)
            && ! in_array('balance_invariant_broken', $blocking, true)
            && MoneyMath::compare($owned, '0', $scale) === 1
        ) {
            $snapshotSvc = $this->snapshots ?? new MiningEligibleOwnershipSnapshotService($cfg);
            $cutoff      = $snapshotSvc->cutoff($calculationDate, $calcPol, null, true);
            $snapMap     = $snapshotSvc->snapshotBatch([$memberUserId], $cutoff['cutoff_utc'], $policyVersion = $calcPol);
            $snap        = $snapMap[$memberUserId] ?? null;

            if ($snap !== null && $snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_ELIGIBLE) {
                $asOf = MoneyMath::normalize((string) $snap['eligible'], $scale);
                if (MoneyMath::compare($asOf, '0', $scale) === 1) {
                    $eligibleCoins = $asOf;
                    $isEligible    = true;
                    $reasons[]     = 'eligible_total_owned';
                    if (MoneyMath::compare($asOf, $owned, $scale) < 0) {
                        $reasons[] = 'partial_approval_day_excluded';
                    }
                }
            } elseif ($snap !== null && $snap['status'] === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED) {
                $reasons[] = 'blocked_historical_data';
                foreach ((array) ($snap['reasons'] ?? []) as $br) {
                    $reasons[] = (string) $br;
                }
            } else {
                $reasons[] = 'no_eligible_ownership_as_of_snapshot';
            }
            unset($policyVersion);
        }

        $ineligible = MoneyMath::sub($owned, $eligibleCoins, $scale);
        if (MoneyMath::isNegative($ineligible, $scale)) {
            $ineligible = MoneyMath::normalize('0', $scale);
        }

        return [
            'member_user_id'         => $memberUserId,
            'member_id'              => $profile->member_id,
            'calculation_date'       => $calculationDate,
            'total_owned'            => $owned,
            'available'              => $available,
            'locked'                 => $locked,
            'eligible_coins'         => $eligibleCoins,
            'ineligible_coins'       => $ineligible,
            'eligible'               => $isEligible,
            'reason_codes'           => array_values(array_unique($reasons)),
            'rate'                   => $rateConfig,
            'policy'                 => $policy,
            'mining_start_date'      => $compareStart,
            'approval_business_date' => $approvalDate,
            'assumptions'            => $assumptions,
        ];
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
