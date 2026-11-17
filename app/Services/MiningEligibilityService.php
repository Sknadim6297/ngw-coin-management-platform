<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;

/**
 * Phase 7B: read-only mining eligibility.
 *
 * Policy (documented default until business expands rules):
 * - Eligible coins = available_balance only (locked coins excluded).
 * - Member must be active (first approved purchase activation).
 * - Balances come from ownership cache (fed only by approved purchase credits).
 * - No minimum hold-days rule exists in the codebase yet (reason: hold_days_not_configured).
 */
class MiningEligibilityService
{
    public const POLICY_AVAILABLE_ACTIVE = 'available_active_only';

    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
    ) {
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
     *   policy: string
     * }
     */
    public function evaluate(int $memberUserId, string $calculationDate, ?array $rateConfig = null): array
    {
        $scale   = $this->scale();
        $profile = $this->profiles->findByUserId($memberUserId);
        $summary = $this->ownership->summaryForUser($memberUserId);
        $reasons = [];

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
                'policy'            => self::POLICY_AVAILABLE_ACTIVE,
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

        // Locked coins are never eligible under default policy.
        if (MoneyMath::compare($locked, '0', $scale) === 1) {
            $reasons[] = 'locked_excluded';
        }

        // Hold-day rule not implemented in product docs — surface as informational when otherwise eligible.
        $reasons[] = 'hold_days_not_configured';

        $eligibleCoins = MoneyMath::normalize('0', $scale);
        $isEligible    = false;

        if ($profile->isActive()
            && $rateConfig !== null
            && ($rateConfig['status'] ?? '') === 'approved'
            && ! MoneyMath::isNegative($available, $scale)
            && MoneyMath::compare($available, '0', $scale) === 1
        ) {
            $eligibleCoins = $available;
            $isEligible    = true;
            // Remove blocking-only noise for eligible path; keep locked_excluded + hold_days as informational.
            $reasons = array_values(array_filter(
                $reasons,
                static fn (string $c): bool => in_array($c, ['locked_excluded', 'hold_days_not_configured'], true)
            ));
            if ($reasons === []) {
                $reasons[] = 'eligible_available_balance';
            } else {
                array_unshift($reasons, 'eligible_available_balance');
            }
        }

        $ineligible = MoneyMath::sub($owned, $eligibleCoins, $scale);
        if (MoneyMath::isNegative($ineligible, $scale)) {
            $ineligible = MoneyMath::normalize('0', $scale);
        }

        return [
            'member_user_id'   => $memberUserId,
            'member_id'        => $profile->member_id,
            'calculation_date' => $calculationDate,
            'total_owned'      => $owned,
            'available'        => $available,
            'locked'           => $locked,
            'eligible_coins'   => $eligibleCoins,
            'ineligible_coins' => $ineligible,
            'eligible'         => $isEligible,
            'reason_codes'     => $reasons,
            'rate'             => $rateConfig,
            'policy'           => self::POLICY_AVAILABLE_ACTIVE,
        ];
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
