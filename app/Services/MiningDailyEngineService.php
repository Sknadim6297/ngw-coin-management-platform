<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11A deterministic daily mining calculation / dry-run / reconcile.
 * Never posts wallet credits. Posting is a separate authorization-gated path.
 */
class MiningDailyEngineService
{
    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly MiningRateService $rates = new MiningRateService(),
        private readonly MiningEligibilityService $eligibility = new MiningEligibilityService(),
        private readonly MiningWalletService $wallets = new MiningWalletService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   business_date: string,
     *   timezone: string,
     *   rate: ?array<string, mixed>,
     *   assumptions: array<string, mixed>,
     *   posting_enabled: bool,
     *   rows: list<array<string, mixed>>,
     *   totals: array<string, mixed>,
     *   run_ref: ?string
     * }
     */
    public function dryRun(string $businessDate, int $limit = 200, bool $persistRun = true, ?int $actorUserId = null): array
    {
        $this->assertDate($businessDate);
        $cfg  = $this->config ?? config('Platform');
        $tz   = (string) $cfg->miningBusinessTimezone;
        $pol  = MiningCalculationPolicy::fromPlatform($cfg);
        $rate = $this->rates->approvedForDate($businessDate);
        $unconfirmed = [];
        foreach ([
            'monthly_percent'    => 'miningMonthlyPercentStatus',
            'day_divisor'        => 'miningDayDivisorStatus',
            'denomination'       => 'miningDenominationStatus',
            'approval_day'       => 'miningApprovalDayPolicyStatus',
            'locked_coins'       => 'miningLockedCoinEligibilityStatus',
            'post_maturity'      => 'miningPostMaturityEligibilityStatus',
            'post_sale'          => 'miningPostSaleEligibilityStatus',
            'rounding'           => 'miningRoundingPolicyStatus',
            'reward_supply'      => 'miningRewardSupplySourceStatus',
            'compounding_policy' => 'miningCompoundingPolicyStatus',
        ] as $key => $flag) {
            if (strtolower((string) ($cfg->{$flag} ?? '')) !== 'approved') {
                $unconfirmed[] = $key;
            }
        }
        $assumptions = [
            'label'                  => $unconfirmed === [] ? 'APPROVED_POLICY_DRY_RUN' : 'OWNER_RULES_PENDING_FORMAL_POLICY_ACTIVATION',
            'formula'                => 'as_of_eligible_owned × (monthly_rate/100) ÷ month_day_divisor (no compounding)',
            'policy_version'         => $pol->version(),
            'month_day_divisor_mode' => $pol->divisorMode,
            'approval_day_policy'    => $pol->approvalDay,
            'rounding_mode'          => $pol->rounding,
            'earning_asset'          => (string) $cfg->miningEarningAsset,
            'compounding_enabled'    => false,
            'posting'                => $this->wallets->postingEnabled() ? 'ENABLED' : 'OFF',
            'unconfirmed'            => $unconfirmed,
        ];

        $members = $this->profiles->excludeRootSponsors()
            ->orderBy('id', 'ASC')
            ->findAll(max(1, min(2000, $limit)));

        $rows = [];
        $eligibleCount = 0;
        $totalEligible = MoneyMath::normalize('0', 8);
        $totalExpected = MoneyMath::normalize('0', 8);
        $totalPosted   = MoneyMath::normalize('0', 8);

        foreach ($members as $profile) {
            $uid  = (int) $profile->user_id;
            $eval = $this->eligibility->evaluate($uid, $businessDate, $rate);
            $divisor = $rate !== null ? $this->rates->monthDayDivisor($businessDate, (string) ($rate['effective_month'] ?? ''), $pol->divisorMode) : null;
            $dailyFactor = null;
            $expected = MoneyMath::normalize('0', 8);
            if ($rate !== null && ! empty($eval['eligible'])) {
                $dailyFactor = $this->rates->dailyFactor($rate, $businessDate);
                $expected = $this->rates->previewAmount((string) $eval['eligible_coins'], $rate, $businessDate, $pol->divisorMode, $pol->rounding);
            }
            $posted = $this->wallets->postedAmountForDate($uid, $businessDate);

            $status = 'NOT_POSTED';
            if ($rate === null) {
                $status = 'NO_APPROVED_RATE';
            } elseif (empty($eval['eligible'])) {
                $status = 'INELIGIBLE';
            }

            if (! empty($eval['eligible'])) {
                $eligibleCount++;
                $totalEligible = MoneyMath::add($totalEligible, (string) $eval['eligible_coins'], 8);
                $totalExpected = MoneyMath::add($totalExpected, $expected, 8);
            }
            $totalPosted = MoneyMath::add($totalPosted, $posted, 8);

            $rows[] = [
                'member_id'            => (string) $profile->member_id,
                'member_user_id'       => $uid,
                'business_date'        => $businessDate,
                'eligible_coins'       => (string) $eval['eligible_coins'],
                'total_owned'          => (string) $eval['total_owned'],
                'available'            => (string) $eval['available'],
                'locked'               => (string) $eval['locked'],
                'monthly_rate_percent' => $rate['rate_percent'] ?? null,
                'day_divisor'          => $divisor,
                'daily_factor'         => $dailyFactor,
                'expected_amount'      => $expected,
                'posted_amount'        => $posted,
                'status'               => $status,
                'explanation'          => implode(',', $eval['reason_codes'] ?? []),
                'mining_start_date'    => $eval['mining_start_date'] ?? null,
            ];
        }

        $runRef = null;
        if ($persistRun && db_connect()->tableExists('mining_daily_runs')) {
            $runRef = 'MDR-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
            $now = date('Y-m-d H:i:s');
            db_connect()->table('mining_daily_runs')->insert([
                'run_ref'               => $runRef,
                'business_date'         => $businessDate,
                'run_type'              => 'dry_run',
                'status'                => 'completed',
                'member_count'          => count($rows),
                'eligible_count'        => $eligibleCount,
                'total_eligible_coins'  => $totalEligible,
                'total_expected_amount' => $totalExpected,
                'total_posted_amount'   => $totalPosted,
                'rate_ref'              => $rate['rate_ref'] ?? null,
                'assumptions_json'      => json_encode($assumptions, JSON_THROW_ON_ERROR),
                'started_at'            => $now,
                'finished_at'           => $now,
                'created_by'            => $actorUserId,
            ]);

            if (db_connect()->tableExists('mining_daily_calculations')) {
                foreach ($rows as $row) {
                    $idem = 'dry:' . $businessDate . ':' . $row['member_user_id'] . ':' . ($rate['rate_ref'] ?? 'none') . ':' . $runRef;
                    db_connect()->table('mining_daily_calculations')->insert([
                        'calc_ref'             => 'MDC-' . strtoupper(bin2hex(random_bytes(6))),
                        'run_ref'              => $runRef,
                        'member_user_id'       => $row['member_user_id'],
                        'member_id'            => $row['member_id'],
                        'business_date'        => $businessDate,
                        'eligible_coins'       => $row['eligible_coins'],
                        'monthly_rate_percent' => $row['monthly_rate_percent'],
                        'day_divisor'          => $row['day_divisor'] !== null ? (string) $row['day_divisor'] : null,
                        'daily_factor'         => $row['daily_factor'],
                        'expected_amount'      => $row['expected_amount'],
                        'posted_amount'        => $row['posted_amount'],
                        'asset_code'           => $this->wallets->assetCode(),
                        'rate_ref'             => $rate['rate_ref'] ?? null,
                        'status'               => $row['status'],
                        'explanation'          => substr((string) $row['explanation'], 0, 500),
                        'idempotency_key'      => $idem,
                        'created_at'           => $now,
                    ]);
                }
            }

            $this->audit->log('mining.daily_dry_run', $actorUserId ?? 0, 'mining_daily_run', $runRef, [
                'business_date' => $businessDate,
                'eligible'      => $eligibleCount,
            ]);
        }

        return [
            'business_date'   => $businessDate,
            'timezone'        => $tz,
            'rate'            => $rate,
            'assumptions'     => $assumptions,
            'posting_enabled' => $this->wallets->postingEnabled(),
            'rows'            => $rows,
            'totals'          => [
                'member_count'          => count($rows),
                'eligible_count'        => $eligibleCount,
                'total_eligible_coins'  => $totalEligible,
                'total_expected_amount' => $totalExpected,
                'total_posted_amount'   => $totalPosted,
            ],
            'run_ref' => $runRef,
            'posting_status' => 'DAILY MINING IS NOT POSTING REAL INCOME.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config ?? config('Platform');
        $last = null;
        if (db_connect()->tableExists('mining_daily_runs')) {
            $last = db_connect()->table('mining_daily_runs')
                ->orderBy('id', 'DESC')
                ->get()
                ->getRowArray();
        }

        return [
            'mining_posting_enabled'   => (bool) $cfg->miningPostingEnabled,
            'mining_p2p_enabled'       => (bool) $cfg->miningP2pEnabled,
            'commission_posting'       => (bool) $cfg->commissionPostingEnabled,
            'commission_withdrawals'   => (bool) $cfg->commissionWithdrawalEnabled,
            'blockchain_payouts'       => (bool) $cfg->commissionBlockchainPayoutEnabled,
            'mandatory_purchase_lock'  => (bool) $cfg->mandatoryPurchaseLockEnabled,
            'business_timezone'        => (string) $cfg->miningBusinessTimezone,
            'month_day_divisor_mode'   => (string) $cfg->miningMonthDayDivisorMode,
            'approval_day_policy'      => (string) $cfg->miningApprovalDayPolicy,
            'earning_asset'            => (string) $cfg->miningEarningAsset,
            'scheduler_configured'     => 'UNVERIFIED',
            'scheduler_activated'      => false,
            'last_run'                 => $last,
            'blockers'                 => [
                'Live posting blocked until formula assumptions are approved and miningPostingEnabled is authorized.',
                'Production cron/Scheduled Task not verified from codebase alone.',
            ],
        ];
    }

    /**
     * @return array{business_date: string, mismatches: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function reconcile(string $businessDate, int $limit = 200): array
    {
        $preview = $this->dryRun($businessDate, $limit, false);
        $mismatches = [];
        foreach ($preview['rows'] as $row) {
            $expected = (string) $row['expected_amount'];
            $posted   = (string) $row['posted_amount'];
            if (MoneyMath::compare($posted, '0', 8) === 1 && MoneyMath::compare($posted, $expected, 8) !== 0) {
                $mismatches[] = [
                    'type'      => 'amount_mismatch',
                    'member_id' => $row['member_id'],
                    'expected'  => $expected,
                    'posted'    => $posted,
                ];
            }
            if (MoneyMath::compare($posted, '0', 8) === 1 && ! $this->wallets->postingEnabled()) {
                $mismatches[] = [
                    'type'      => 'unexpected_credit_while_posting_off',
                    'member_id' => $row['member_id'],
                    'posted'    => $posted,
                ];
            }
            if (! empty($row['eligible_coins'])
                && MoneyMath::compare((string) $row['eligible_coins'], '0', 8) === 1
                && MoneyMath::compare($posted, '0', 8) === 0
                && $this->wallets->postingEnabled()
            ) {
                $mismatches[] = [
                    'type'      => 'missing_posting',
                    'member_id' => $row['member_id'],
                    'expected'  => $expected,
                ];
            }
        }

        return [
            'business_date' => $businessDate,
            'mismatches'    => $mismatches,
            'summary'       => [
                'rows'       => count($preview['rows']),
                'mismatches' => count($mismatches),
            ],
            'note' => 'Reconcile is read-only. Posting remains OFF in Phase 11A.',
        ];
    }

    /**
     * Post daily mining credits for eligible members.
     * Production remains blocked unless Financial Sandbox window is open.
     *
     * @param bool $continueOnError When true (scheduler), a failure for one member is recorded in
     *                              `errors` and the remaining members are still processed. Default
     *                              false keeps the original fail-fast behaviour.
     * @return array{business_date: string, credited: int, skipped: int, duplicates: int, rows: list<array<string, mixed>>, errors: list<array<string, mixed>>}
     */
    public function postDay(string $businessDate, int $limit = 200, ?int $actorUserId = null, bool $continueOnError = false): array
    {
        if (! $this->wallets->postingEnabled()) {
            throw new RuntimeException('Mining posting is disabled (kill-switch).');
        }

        $sandbox = service('financialSandboxService');
        if (! (bool) ($this->config ?? config('Platform'))->miningPostingEnabled) {
            $sandbox->assertActive('mining_post_day');
        }

        $this->assertDate($businessDate);
        $preview = $this->dryRun($businessDate, $limit, true, $actorUserId);
        $credited = 0;
        $skipped  = 0;
        $dupes    = 0;
        $outRows  = [];
        $errors   = [];

        foreach ($preview['rows'] as $row) {
            if (($row['status'] ?? '') !== 'NOT_POSTED' || empty($row['eligible_coins'])) {
                $skipped++;
                continue;
            }
            $expected = MoneyMath::normalize((string) ($row['expected_amount'] ?? '0'), 8);
            if (! MoneyMath::isPositive($expected, 8)) {
                $skipped++;
                continue;
            }

            $memberId = (string) $row['member_id'];

            try {
                if ($sandbox->isWindowOpen()) {
                    $sandbox->assertSyntheticMember($memberId, 'mining_post_day');
                }

                $idem = 'mining-daily:' . (int) $row['member_user_id'] . ':' . $businessDate;
                $before = $this->wallets->postedAmountForDate((int) $row['member_user_id'], $businessDate);
                $entry = $this->wallets->creditDailyMining((int) $row['member_user_id'], $memberId, [
                    'amount'          => $expected,
                    'business_date'   => $businessDate,
                    'idempotency_key' => $idem,
                    'rate_ref'        => $preview['rate']['rate_ref'] ?? null,
                    'created_by'      => $actorUserId,
                    'meta'            => [
                        'eligible_coins' => $row['eligible_coins'],
                        'sandbox'        => $sandbox->isWindowOpen(),
                    ],
                ]);
                $after = $this->wallets->postedAmountForDate((int) $row['member_user_id'], $businessDate);
            } catch (\Throwable $e) {
                if (! $continueOnError) {
                    throw $e;
                }
                $errors[] = [
                    'member_id' => $memberId,
                    'class'     => $e::class,
                    'message'   => $e->getMessage(),
                ];

                continue;
            }

            if (MoneyMath::compare($before, $after, 8) === 0) {
                $dupes++;
            } else {
                $credited++;
            }
            $outRows[] = [
                'member_id' => $memberId,
                'amount'    => $expected,
                'entry_ref' => $entry['entry_ref'] ?? null,
                'posted'    => $after,
            ];
        }

        return [
            'business_date' => $businessDate,
            'credited'      => $credited,
            'skipped'       => $skipped,
            'duplicates'    => $dupes,
            'rows'          => $outRows,
            'errors'        => $errors,
            'rate'          => $preview['rate'] ?? null,
        ];
    }

    private function assertDate(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Business date must be YYYY-MM-DD.');
        }
    }
}
