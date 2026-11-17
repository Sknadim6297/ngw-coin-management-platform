<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;

/**
 * Phase 11A mining eligibility.
 *
 * Policy total_owned_active (Coin Plan):
 * - Eligible coins = TOTAL OWNED (available + locked). Locked coins remain eligible.
 * - Member must be active.
 * - Mining start uses purchase approval business date policy (DRAFT: full_day / exclude_approval_day).
 * - Balances come from ownership cache (approved purchase credits only).
 * - Mining earnings / commission USDT are never counted as principal.
 */
class MiningEligibilityService
{
    /** @deprecated Legacy Phase 7B policy — retained for historical rate rows only. */
    public const POLICY_AVAILABLE_ACTIVE = 'available_active_only';

    public const POLICY_TOTAL_OWNED_ACTIVE = 'total_owned_active';

    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
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
     * Earliest approval business date among approved purchases for the member.
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
     *   assumptions: array<string, string>
     * }
     */
    public function evaluate(int $memberUserId, string $calculationDate, ?array $rateConfig = null): array
    {
        $cfg     = $this->config ?? config('Platform');
        $scale   = $this->scale();
        $policy  = self::POLICY_TOTAL_OWNED_ACTIVE;
        $profile = $this->profiles->findByUserId($memberUserId);
        $summary = $this->ownership->summaryForUser($memberUserId);
        $reasons = [];
        $assumptions = [
            'approval_day_policy' => (string) $cfg->miningApprovalDayPolicy,
            'month_day_divisor'   => (string) $cfg->miningMonthDayDivisorMode,
            'earning_asset'       => (string) $cfg->miningEarningAsset,
            'business_timezone'   => (string) $cfg->miningBusinessTimezone,
            'label'               => 'DRAFT_ASSUMPTIONS_FOR_DRY_RUN_ONLY',
        ];

        if ($profile === null) {
            return [
                'member_user_id'    => $memberUserId,
                'member_id'         => null,
                'calculation_date'  => $calculationDate,
                'total_owned'       => MoneyMath::normalize('0', $scale),
                'available'         => MoneyMath::normalize('0', $scale),
                'locked'            => MoneyMath::normalize('0', $scale),
                'eligible_coins'    => MoneyMath::normalize('0', $scale),
                'ineligible_coins'  => MoneyMath::normalize('0', $scale),
                'eligible'          => false,
                'reason_codes'      => ['member_not_found'],
                'rate'              => $rateConfig,
                'policy'            => $policy,
                'mining_start_date' => null,
                'assumptions'       => $assumptions,
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

        if (MoneyMath::isNegative($owned, $scale) || MoneyMath::isNegative($available, $scale) || MoneyMath::isNegative($locked, $scale)) {
            $reasons[] = 'negative_balance';
        }

        $sumParts = MoneyMath::add($available, $locked, $scale);
        if (MoneyMath::compare($sumParts, $owned, $scale) !== 0) {
            $reasons[] = 'balance_invariant_broken';
        }

        $startDate = $this->miningStartBusinessDate($memberUserId);
        if ($startDate === null) {
            $reasons[] = 'no_approved_purchase';
        } else {
            $policyDay = strtolower((string) $cfg->miningApprovalDayPolicy);
            $compareStart = $startDate;
            if ($policyDay === 'exclude_approval_day') {
                $compareStart = (new \DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d');
                $reasons[] = 'draft_exclude_approval_day';
            } else {
                $reasons[] = 'draft_full_day_approval_policy';
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
                'draft_full_day_approval_policy',
                'draft_exclude_approval_day',
            ], true)
        ));

        if ($profile->isActive()
            && $rateConfig !== null
            && ($rateConfig['status'] ?? '') === 'approved'
            && $startDate !== null
            && ! in_array('before_mining_start', $blocking, true)
            && ! in_array('negative_balance', $blocking, true)
            && ! in_array('balance_invariant_broken', $blocking, true)
            && MoneyMath::compare($owned, '0', $scale) === 1
        ) {
            $eligibleCoins = $owned;
            $isEligible    = true;
            $reasons[]     = 'eligible_total_owned';
        }

        $ineligible = MoneyMath::sub($owned, $eligibleCoins, $scale);
        if (MoneyMath::isNegative($ineligible, $scale)) {
            $ineligible = MoneyMath::normalize('0', $scale);
        }

        return [
            'member_user_id'    => $memberUserId,
            'member_id'         => $profile->member_id,
            'calculation_date'  => $calculationDate,
            'total_owned'       => $owned,
            'available'         => $available,
            'locked'            => $locked,
            'eligible_coins'    => $eligibleCoins,
            'ineligible_coins'  => $ineligible,
            'eligible'          => $isEligible,
            'reason_codes'      => array_values(array_unique($reasons)),
            'rate'              => $rateConfig,
            'policy'            => $policy,
            'mining_start_date' => $startDate,
            'assumptions'       => $assumptions,
        ];
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
