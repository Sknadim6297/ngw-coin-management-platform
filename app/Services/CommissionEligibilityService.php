<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;

/**
 * Phase 7C read-only commission eligibility checks for approved purchases.
 * Never mutates wallets, ownership, or posts commissions.
 */
class CommissionEligibilityService
{
    public function __construct(
        private readonly CommissionPlanService $plans = new CommissionPlanService(),
        private readonly CommissionGenealogyService $genealogy = new CommissionGenealogyService()
    ) {
    }

    /**
     * Evaluate eligibility for one purchase against an applicable plan.
     *
     * @param array<string, mixed> $purchase
     * @param array<string, mixed>|null $plan
     * @return array{
     *   purchase_ref: string,
     *   purchaser_user_id: int,
     *   purchaser_member_id: string,
     *   purchase_amount: string,
     *   currency: string,
     *   plan_ref: ?string,
     *   purchase_eligible: bool,
     *   reason_codes: list<string>,
     *   levels: list<array{
     *     level: int,
     *     sponsor_user_id: ?int,
     *     sponsor_member_id: ?string,
     *     eligible: bool,
     *     reason_codes: list<string>,
     *     percent: ?string
     *   }>,
     *   chain_note: string
     * }
     */
    public function evaluatePurchase(array $purchase, ?array $plan = null): array
    {
        $purchaseRef = (string) ($purchase['purchase_ref'] ?? '');
        $purchaserId = (int) ($purchase['member_user_id'] ?? $purchase['user_id'] ?? 0);
        $memberId    = (string) ($purchase['member_id'] ?? '');
        $amount      = MoneyMath::normalize((string) ($purchase['total_usdt'] ?? '0'), 8);
        $currency    = strtoupper((string) ($purchase['currency'] ?? 'USDT'));
        $status      = (string) ($purchase['status'] ?? '');
        $payStatus   = (string) ($purchase['payment_status'] ?? '');
        $approvedAt  = (string) ($purchase['reviewed_at'] ?? $purchase['approved_at'] ?? $purchase['requested_at'] ?? $purchase['created_at'] ?? '');
        $purchaseDate = substr($approvedAt !== '' ? $approvedAt : date('Y-m-d'), 0, 10);

        $reasons = [];
        $levels  = [];

        if ($purchaseRef === '' || $purchaserId <= 0) {
            $reasons[] = 'purchase_invalid';
        }
        if ($status !== 'approved') {
            $reasons[] = 'purchase_not_approved';
        }
        if (! MoneyMath::isPositive($amount, 8)) {
            $reasons[] = 'purchase_amount_missing';
        }
        if ($currency !== 'USDT') {
            $reasons[] = 'currency_unsupported';
        }

        if ($plan === null) {
            $plan = $this->plans->findApplicablePlan($purchaseDate);
        }
        if ($plan === null) {
            $reasons[] = 'no_applicable_plan';
        }

        $planRef = $plan['plan_ref'] ?? null;
        $purchasePolicy = (string) ($plan['eligible_purchase_policy'] ?? '');
        $sponsorPolicy  = (string) ($plan['sponsor_eligibility_policy'] ?? '');

        if ($plan !== null) {
            if ($purchasePolicy === 'approved_verified_usdt' && $payStatus !== 'verified') {
                $reasons[] = 'payment_not_verified';
            } elseif ($purchasePolicy === 'approved_only') {
                // approved status already checked
            } elseif ($purchasePolicy === '' || ! in_array($purchasePolicy, CommissionPlanService::PURCHASE_POLICIES, true)) {
                $reasons[] = 'purchase_policy_unresolved';
            }

            if ($sponsorPolicy === 'unresolved' || $sponsorPolicy === '') {
                $reasons[] = 'sponsor_eligibility_unresolved';
            } elseif (! in_array($sponsorPolicy, CommissionPlanService::SPONSOR_POLICIES, true)) {
                $reasons[] = 'sponsor_policy_invalid';
            }
        }

        $purchaseEligible = $reasons === [];
        $planLevels = is_array($plan['levels'] ?? null) ? $plan['levels'] : [];
        $levelCount = (int) ($plan['level_count'] ?? 3);
        $chain = $this->genealogy->resolveUpline($purchaserId, $levelCount);

        $seenBeneficiaries = [];
        for ($level = 1; $level <= $levelCount; $level++) {
            $levelReasons = [];
            $percent      = isset($planLevels[$level]) ? MoneyMath::normalize((string) $planLevels[$level], 8) : null;
            $node         = null;
            foreach ($chain['chain'] as $c) {
                if ((int) $c['level'] === $level) {
                    $node = $c;
                    break;
                }
            }

            if (! $purchaseEligible) {
                $levelReasons = array_merge(['purchase_ineligible'], $reasons);
            }
            if ($percent === null) {
                $levelReasons[] = 'level_percent_missing';
            }
            if ($node === null) {
                $levelReasons[] = 'missing_sponsor_level_' . $level;
            } else {
                $uid = (int) $node['user_id'];
                if ($uid === $purchaserId) {
                    $levelReasons[] = 'self_referral';
                }
                if (isset($seenBeneficiaries[$uid])) {
                    $levelReasons[] = 'duplicate_beneficiary';
                }
                if (in_array('self_referral', $chain['reason_codes'], true)
                    || in_array('circular_sponsorship', $chain['reason_codes'], true)
                    || in_array('circular_or_duplicate_beneficiary', $chain['reason_codes'], true)) {
                    $levelReasons = array_merge($levelReasons, array_values(array_filter(
                        $chain['reason_codes'],
                        static fn (string $c): bool => str_contains($c, 'circular') || $c === 'self_referral'
                    )));
                }

                if ($sponsorPolicy === 'active_sponsors_only') {
                    if (($node['account_status'] ?? '') !== 'active') {
                        $levelReasons[] = 'sponsor_not_active';
                    }
                } elseif ($sponsorPolicy === 'unresolved') {
                    $levelReasons[] = 'sponsor_eligibility_unresolved';
                }

                $seenBeneficiaries[$uid] = true;
            }

            $levelReasons = array_values(array_unique($levelReasons));
            $levels[] = [
                'level'             => $level,
                'sponsor_user_id'   => $node['user_id'] ?? null,
                'sponsor_member_id' => $node['member_id'] ?? null,
                'eligible'          => $levelReasons === [],
                'reason_codes'      => $levelReasons,
                'percent'           => $percent,
            ];
        }

        return [
            'purchase_ref'         => $purchaseRef,
            'purchaser_user_id'    => $purchaserId,
            'purchaser_member_id'  => $memberId,
            'purchase_amount'      => $amount,
            'currency'             => $currency,
            'plan_ref'             => $planRef,
            'purchase_eligible'    => $purchaseEligible,
            'reason_codes'         => $reasons,
            'levels'               => $levels,
            'chain_note'           => (string) ($chain['snapshot_note'] ?? ''),
        ];
    }

    /**
     * Persist eligibility audit rows (append-only).
     *
     * @param array<string, mixed> $evaluation
     */
    public function recordAudit(array $evaluation, string $source = 'simulation'): void
    {
        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('commission_eligibility_audit_events')->insert([
            'purchase_ref'          => $evaluation['purchase_ref'],
            'plan_ref'              => $evaluation['plan_ref'],
            'level'                 => null,
            'beneficiary_member_id' => null,
            'eligible'              => ! empty($evaluation['purchase_eligible']) ? 1 : 0,
            'reason_codes_json'     => json_encode($evaluation['reason_codes'] ?? [], JSON_THROW_ON_ERROR),
            'source'                => $source,
            'created_at'            => $now,
        ]);
        foreach ($evaluation['levels'] ?? [] as $lvl) {
            $db->table('commission_eligibility_audit_events')->insert([
                'purchase_ref'          => $evaluation['purchase_ref'],
                'plan_ref'              => $evaluation['plan_ref'],
                'level'                 => (int) $lvl['level'],
                'beneficiary_member_id' => $lvl['sponsor_member_id'] ?? null,
                'eligible'              => ! empty($lvl['eligible']) ? 1 : 0,
                'reason_codes_json'     => json_encode($lvl['reason_codes'] ?? [], JSON_THROW_ON_ERROR),
                'source'                => $source,
                'created_at'            => $now,
            ]);
        }
    }
}
