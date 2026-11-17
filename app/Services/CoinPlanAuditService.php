<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;

/**
 * Phase 10C read-only Coin Plan compliance audit.
 * Never posts mining, commissions, P2P, or withdrawals.
 */
class CoinPlanAuditService
{
    public const CLIENT_LOCK_DAYS = 1000;

    /** @var list<array{level: int, percent: string}> */
    public const CLIENT_COMMISSION_RATES = [
        ['level' => 1, 'percent' => '10.00000000'],
        ['level' => 2, 'percent' => '5.00000000'],
        ['level' => 3, 'percent' => '3.00000000'],
    ];

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MiningRateService $miningRates = new MiningRateService(),
        private readonly MiningEligibilityService $miningEligibility = new MiningEligibilityService(),
        private readonly CommissionPlanService $commissionPlans = new CommissionPlanService(),
        private readonly CommissionSimulationService $commissionSim = new CommissionSimulationService(),
        private readonly CommissionWalletService $commissionWallets = new CommissionWalletService(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return list<array{
     *   rule: string,
     *   requirement: string,
     *   implementation: string,
     *   status: string,
     *   missing: string,
     *   evidence: string
     * }>
     */
    public function ruleMatrix(): array
    {
        $cfg = $this->config ?? config('Platform');
        $min = service('coinPriceService')->getMinPurchaseUsdt();
        $tz  = (string) config('App')->appTimezone;

        return [
            [
                'rule'           => '1. Free joining',
                'requirement'    => 'Joining is FREE; new member inactive until qualifying purchase.',
                'implementation' => 'RegistrationService creates inactive profiles with no fee.',
                'status'         => 'MATCHED',
                'missing'        => 'None for free join.',
                'evidence'       => 'app/Services/RegistrationService.php account_status=inactive',
            ],
            [
                'rule'           => '2. Min $5 purchase activates',
                'requirement'    => 'Member becomes ACTIVE after minimum $5 coin purchase approval.',
                'implementation' => 'Min USDT=' . $min . '; activation on first CoinPurchaseService::approveRequest(). Payment submit alone does not activate.',
                'status'         => MoneyMath::compare($min, '5.00000000', 8) === 0 ? 'MATCHED' : 'PARTIALLY MATCHED',
                'missing'        => 'Ensure coin_settings.min_purchase_usdt stays 5.00000000 in production.',
                'evidence'       => 'CoinPriceService::getMinPurchaseUsdt; CoinPurchaseService::approveRequest',
            ],
            [
                'rule'           => '3. Dynamic daily mining',
                'requirement'    => 'Daily mining income is dynamic and credited each day.',
                'implementation' => 'Phase 11A: total-owned eligibility + MiningDailyEngineService dry-run/status/reconcile; monthly rate ÷ configurable day divisor. DAILY MINING IS NOT POSTING REAL INCOME.',
                'status'         => 'PARTIALLY MATCHED',
                'missing'        => 'Formula sign-off (divisor, denomination, approval-day policy); authorize miningPostingEnabled; production scheduler.',
                'evidence'       => 'MiningDailyEngineService; MiningEligibilityService POLICY_TOTAL_OWNED_ACTIVE; Commands/MiningDaily.php',
            ],
            [
                'rule'           => '4. USDT QR + TX + admin validation',
                'requirement'    => 'Deposit via USDT QR + TX ID; Admin validates investment.',
                'implementation' => 'Payment Details BEP20/QR; member TX submit; manual/admin verify; purchase gate requireVerifiedDepositForApproval='
                    . ((bool) $cfg->requireVerifiedDepositForApproval ? 'true' : 'false'),
                'status'         => (bool) $cfg->requireVerifiedDepositForApproval ? 'MATCHED' : 'PARTIALLY MATCHED',
                'missing'        => 'Keep requireVerifiedDepositForApproval=true in production.',
                'evidence'       => 'PaymentDetailsService; UsdtDepositService; CoinPurchaseService::assertAndPreparePaymentForApproval',
            ],
            [
                'rule'           => '5. 1000-day lock + sell after maturity',
                'requirement'    => 'Purchased coins locked 1000 days; after maturity coins can be sold.',
                'implementation' => 'Phase 11A: approveRequest → lockMandatoryForPurchase (LOCK1000, early unlock forbidden). Coin selling still Coming Soon.',
                'status'         => (bool) $cfg->mandatoryPurchaseLockEnabled ? 'PARTIALLY MATCHED' : 'NOT IMPLEMENTED',
                'missing'        => 'Coin selling after maturity; historical backfill only after explicit approval.',
                'evidence'       => 'CoinPurchaseService::approveRequest + CoinLockingService::lockMandatoryForPurchase',
            ],
            [
                'rule'           => '6. L1/L2/L3 dynamic commission',
                'requirement'    => 'L1 10% / L2 5% / L3 3% on qualifying purchase; Admin-dynamic.',
                'implementation' => 'CommissionPlanService supports 1–3 levels; simulation on approved purchases; posting kill-switch OFF; rates not auto-seeded.',
                'status'         => 'PARTIALLY MATCHED',
                'missing'        => 'Approved default plan 10/5/3; purchase-triggered real posting when authorized.',
                'evidence'       => 'CommissionSimulationService; Platform::commissionPostingEnabled=false',
            ],
            [
                'rule'           => '7. Separate mining wallet + P2P only',
                'requirement'    => 'Mining income in separate mining wallet; no external withdraw; P2P only.',
                'implementation' => 'Phase 11A: mining_wallets + mining_wallet_ledger + MiningWalletService exist; posting/P2P kill-switches OFF; external withdraw always blocked.',
                'status'         => 'PARTIALLY MATCHED',
                'missing'        => 'Authorize posting; implement P2P transfer engine when approved.',
                'evidence'       => 'MiningWalletService; Platform::miningPostingEnabled=false; miningP2pEnabled=false',
            ],
            [
                'rule'           => '8. Commission-only USDT withdrawals',
                'requirement'    => 'Only level commission withdrawable; Admin manual payout with evidence.',
                'implementation' => 'Commission wallet exists; withdrawals architecture present but submit/approve blocked; no outgoing payout evidence workflow.',
                'status'         => 'PARTIALLY MATCHED',
                'missing'        => 'Enable path for submit/reserve/admin pay/TX evidence/PAID; keep mining non-withdrawable.',
                'evidence'       => 'CommissionWithdrawalService; commissionWithdrawalEnabled=false',
            ],
            [
                'rule'           => 'Business timezone',
                'requirement'    => 'Asia/Kolkata business dates for daily mining.',
                'implementation' => 'miningBusinessTimezone=' . (string) $cfg->miningBusinessTimezone . '; appTimezone=' . $tz,
                'status'         => ((string) $cfg->miningBusinessTimezone === 'Asia/Kolkata' ? 'MATCHED' : 'PARTIALLY MATCHED'),
                'missing'        => 'Keep Platform::miningBusinessTimezone=Asia/Kolkata for mining date boundaries.',
                'evidence'       => 'Platform::miningBusinessTimezone; MiningEligibilityService::businessDate',
            ],
        ];
    }

    /**
     * Read-only daily mining preview rows (no DB financial writes).
     *
     * @return array{
     *   date: string,
     *   timezone: string,
     *   rate: ?array<string, mixed>,
     *   formula_note: string,
     *   rows: list<array<string, mixed>>,
     *   posting_status: string
     * }
     */
    public function miningDryRunPreview(string $dateYmd, int $limit = 50): array
    {
        $engine = service('miningDailyEngineService')->dryRun($dateYmd, $limit, false);
        $rows   = [];
        foreach ($engine['rows'] as $row) {
            $profile = $this->profiles->findByUserId((int) $row['member_user_id']);
            $rows[]  = [
                'date'            => $dateYmd,
                'member_id'       => (string) $row['member_id'],
                'account_status'  => $profile !== null ? (string) $profile->account_status : '',
                'eligible_coins'  => (string) $row['eligible_coins'],
                'available'       => (string) $row['available'],
                'locked'          => (string) $row['locked'],
                'rate_percent'    => $row['monthly_rate_percent'],
                'rate_basis'      => $engine['rate']['rate_basis'] ?? null,
                'expected_income' => (string) $row['expected_amount'],
                'posted_income'   => (string) $row['posted_amount'],
                'status'          => (string) $row['status'],
            ];
        }

        return [
            'date'           => $dateYmd,
            'timezone'       => (string) ($engine['timezone'] ?? ''),
            'rate'           => $engine['rate'],
            'formula_note'   => 'DRAFT dry-run: total owned × (monthly_rate/100) ÷ month_day_divisor. Unconfirmed: divisor mode, COIN vs USDT, approval-day proration, post-maturity eligibility.',
            'rows'           => $rows,
            'posting_status' => (string) $engine['posting_status'],
            'assumptions'    => $engine['assumptions'] ?? [],
            'totals'         => $engine['totals'] ?? [],
        ];
    }

    /**
     * Illustrative commission math check for a purchase base (simulation formula only).
     *
     * @return list<array{level: int, percent: string, expected: string}>
     */
    public function illustrativeCommissionBreakdown(string $purchaseUsdt = '100.00000000'): array
    {
        $out = [];
        foreach (self::CLIENT_COMMISSION_RATES as $row) {
            $out[] = [
                'level'    => $row['level'],
                'percent'  => $row['percent'],
                'expected' => $this->commissionSim->previewAmount($purchaseUsdt, $row['percent']),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function runtimeFlags(): array
    {
        $cfg = $this->config ?? config('Platform');

        return [
            'min_purchase_usdt'                    => service('coinPriceService')->getMinPurchaseUsdt(),
            'require_verified_deposit_for_approval'=> (bool) $cfg->requireVerifiedDepositForApproval,
            'commission_posting_enabled'           => (bool) $cfg->commissionPostingEnabled,
            'commission_withdrawal_enabled'        => (bool) $cfg->commissionWithdrawalEnabled,
            'commission_blockchain_payout_enabled' => (bool) $cfg->commissionBlockchainPayoutEnabled,
            'commission_wallet_posting'            => $this->commissionWallets->postingEnabled(),
            'commission_wallet_withdrawal'         => $this->commissionWallets->withdrawalEnabled(),
            'mining_wallet_exists'                 => class_exists(MiningWalletService::class)
                && db_connect()->tableExists('mining_wallets'),
            'mining_posting_enabled'               => (bool) $cfg->miningPostingEnabled,
            'mining_p2p_enabled'                   => (bool) $cfg->miningP2pEnabled,
            'p2p_exists'                           => false,
            'coin_selling_exists'                  => false,
            'auto_lock_on_purchase'                => (bool) $cfg->mandatoryPurchaseLockEnabled,
            'client_lock_days'                     => self::CLIENT_LOCK_DAYS,
            'app_timezone'                         => (string) config('App')->appTimezone,
            'mining_business_timezone'             => (string) $cfg->miningBusinessTimezone,
            'approved_commission_plan'             => $this->commissionPlans->findApplicablePlan(date('Y-m-d')),
            'approved_mining_rate_today'           => $this->miningRates->approvedForDate(
                service('miningEligibilityService')->businessDate()
            ),
        ];
    }
}
