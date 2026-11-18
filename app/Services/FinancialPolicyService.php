<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningMath;
use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 12E — centralized financial business policy drafts / dual-control approval.
 * APPROVED/ACTIVE never flip production kill-switches or mutate live balances.
 */
class FinancialPolicyService
{
    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_ACTIVE           = 'active';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_SUPERSEDED       = 'superseded';

    public const DOMAIN_MINING      = 'mining';
    public const DOMAIN_COMMISSION  = 'commission';
    public const DOMAIN_P2P         = 'p2p';
    public const DOMAIN_WITHDRAWAL  = 'withdrawal';
    public const DOMAIN_SELLING     = 'selling';
    public const DOMAIN_MEMBER_ID   = 'member_id';
    public const DOMAIN_PURCHASE    = 'purchase_lock';
    public const DOMAIN_BLOCKCHAIN  = 'blockchain';

    /** @var list<string> */
    public const DOMAINS = [
        self::DOMAIN_MINING,
        self::DOMAIN_COMMISSION,
        self::DOMAIN_P2P,
        self::DOMAIN_WITHDRAWAL,
        self::DOMAIN_SELLING,
        self::DOMAIN_MEMBER_ID,
        self::DOMAIN_PURCHASE,
        self::DOMAIN_BLOCKCHAIN,
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ProductionSafetyGate $gate = new ProductionSafetyGate(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function domainCatalog(): array
    {
        return [
            self::DOMAIN_MINING => [
                'label' => 'Mining Policy',
                'manage_url' => site_url('admin/mining/rates'),
                'engine' => 'MiningDailyEngineService / MiningRateService',
            ],
            self::DOMAIN_COMMISSION => [
                'label' => 'Commission Policy',
                'manage_url' => site_url('admin/commissions/plans'),
                'engine' => 'CommissionPlanService / CommissionPurchaseEngineService',
            ],
            self::DOMAIN_P2P => [
                'label' => 'P2P Policy',
                'manage_url' => site_url('admin/mining'),
                'engine' => 'MiningP2pTransferService',
            ],
            self::DOMAIN_WITHDRAWAL => [
                'label' => 'Withdrawal Policy',
                'manage_url' => site_url('admin/withdrawals'),
                'engine' => 'CommissionWithdrawalService',
            ],
            self::DOMAIN_SELLING => [
                'label' => 'Coin Selling Policy',
                'manage_url' => site_url('admin/coin-selling'),
                'engine' => 'CoinSellingService',
            ],
            self::DOMAIN_MEMBER_ID => [
                'label' => 'Member ID Policy',
                'manage_url' => null,
                'engine' => 'MemberIdSequenceCorrectionService',
            ],
            self::DOMAIN_PURCHASE => [
                'label' => 'Purchase & Locking Policy',
                'manage_url' => site_url('admin/payment-details'),
                'engine' => 'CoinPurchaseService / CoinLockingService',
            ],
            self::DOMAIN_BLOCKCHAIN => [
                'label' => 'Blockchain Verification Policy',
                'manage_url' => site_url('admin/payment-details'),
                'engine' => 'EvmUsdtVerificationProvider',
            ],
        ];
    }

    /**
     * Current runtime / DB snapshot for a domain (read-only; unresolved marked null).
     *
     * @return array<string, mixed>
     */
    public function currentSnapshot(string $domain): array
    {
        $this->assertDomain($domain);
        $cfg = $this->config ?? config('Platform');

        return match ($domain) {
            self::DOMAIN_MINING => [
                'timezone' => (string) $cfg->miningBusinessTimezone,
                'month_day_divisor_mode' => (string) $cfg->miningMonthDayDivisorMode,
                'approval_day_policy' => (string) $cfg->miningApprovalDayPolicy,
                'earning_asset' => (string) $cfg->miningEarningAsset,
                'eligibility_policy' => 'total_owned_active',
                'locked_coins_included' => true,
                'monthly_percent' => null,
                'monthly_percent_status' => (string) $cfg->miningMonthlyPercentStatus,
                'day_divisor_status' => (string) $cfg->miningDayDivisorStatus,
                'denomination_status' => (string) $cfg->miningDenominationStatus,
                'approval_day_status' => (string) $cfg->miningApprovalDayPolicyStatus,
                'post_maturity_status' => (string) $cfg->miningPostMaturityEligibilityStatus,
                'post_sale_status' => (string) $cfg->miningPostSaleEligibilityStatus,
                'rounding_status' => (string) $cfg->miningRoundingPolicyStatus,
                'approved_rate_count' => $this->countApprovedMiningRates(),
                'posting_enabled' => (bool) $cfg->miningPostingEnabled,
                'illustrative_preview' => $this->miningPreview('100', '3', 'fixed_30'),
            ],
            self::DOMAIN_COMMISSION => [
                'target_l1' => '10.00000000',
                'target_l2' => '5.00000000',
                'target_l3' => '3.00000000',
                'calculation_base' => null,
                'approved_plan_count' => $this->countApprovedCommissionPlans(),
                'posting_enabled' => (bool) $cfg->commissionPostingEnabled,
                'illustrative_preview' => $this->commissionPreview('100.00000000', '10', '5', '3'),
            ],
            self::DOMAIN_P2P => [
                'internal_only' => true,
                'external_withdrawal_forbidden' => true,
                'min_transfer' => null,
                'max_transfer' => null,
                'daily_limit' => null,
                'fee' => null,
                'execution_enabled' => (bool) $cfg->miningP2pEnabled,
            ],
            self::DOMAIN_WITHDRAWAL => [
                'source_wallet' => 'posted_commission_only',
                'mining_wallet_withdrawable' => false,
                'network' => 'BEP20',
                'minimum' => $cfg->commissionWithdrawalMinimumUsdt,
                'fee' => $cfg->commissionWithdrawalFeeUsdt,
                'fee_policy_status' => (string) $cfg->commissionWithdrawalFeePolicyStatus,
                'execution_enabled' => (bool) $cfg->commissionWithdrawalEnabled,
                'blockchain_payout_enabled' => (bool) $cfg->commissionBlockchainPayoutEnabled,
            ],
            self::DOMAIN_SELLING => [
                'lock_days' => (int) $cfg->coinPlanLockDays,
                'fee' => $cfg->coinSellingFeeUsdt,
                'minimum_quantity' => $cfg->coinSellingMinimumQuantity,
                'fee_policy_status' => (string) $cfg->coinSellingFeePolicyStatus,
                'settlement_asset_status' => (string) $cfg->coinSellingSettlementAssetStatus,
                'counterparty_status' => (string) $cfg->coinSellingCounterpartyStatus,
                'post_sale_mining_status' => (string) $cfg->coinSellingPostSaleMiningPolicyStatus,
                'execution_enabled' => (bool) $cfg->coinSellingEnabled,
            ],
            self::DOMAIN_MEMBER_ID => service('memberIdSequenceCorrectionService')->preview('default', 1234, 4),
            self::DOMAIN_PURCHASE => [
                'free_registration' => true,
                'min_purchase_usdt' => service('coinPriceService')->getMinPurchaseUsdt(),
                'mandatory_lock_days' => (int) $cfg->coinPlanLockDays,
                'mandatory_lock_enabled' => (bool) $cfg->mandatoryPurchaseLockEnabled,
                'require_verified_deposit' => (bool) $cfg->requireVerifiedDepositForApproval,
                'historical_missing_lock' => $this->safeLockPreview('PUR-10D7174B7FF83054'),
            ],
            self::DOMAIN_BLOCKCHAIN => [
                'deposit_verifier' => (string) (config('Blockchain')->depositVerifier ?? 'not_configured'),
                'rpc_configured' => trim((string) (config('Blockchain')->evmRpcUrl ?? '')) !== '',
                'payment_readiness' => service('paymentDetailsService')->paymentReadiness(),
                'bep20' => $this->bep20NetworkSnapshot(),
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDraft(string $domain, string $title, array $payload, int $actorUserId, ?string $effectiveFrom = null, ?string $notes = null): array
    {
        $this->assertNotSupportView();
        $this->assertDomain($domain);
        $title = trim($title);
        if ($title === '' || strlen($title) > 160) {
            throw new InvalidArgumentException('Title is required (max 160).');
        }

        $payload = $this->normalizePayload($domain, $payload);
        $version = $this->nextVersionNo($domain);
        $ref     = 'FPV-' . strtoupper(substr($domain, 0, 3)) . '-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $now     = date('Y-m-d H:i:s');

        db_connect()->table('financial_policy_versions')->insert([
            'policy_ref'     => $ref,
            'domain'         => $domain,
            'version_no'     => $version,
            'title'          => $title,
            'status'         => self::STATUS_DRAFT,
            'payload_json'   => json_encode($payload, JSON_THROW_ON_ERROR),
            'baseline_json'  => json_encode($this->currentSnapshot($domain), JSON_THROW_ON_ERROR),
            'effective_from' => $this->normalizeDateOrNull($effectiveFrom),
            'notes'          => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'created_by'     => $actorUserId,
            'lock_version'   => 1,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);

        $this->recordEvent($ref, 'created', $actorUserId, null, self::STATUS_DRAFT, ['domain' => $domain]);
        $this->audit->log('financial_policy.draft_created', $actorUserId, 'financial_policy', $ref, [
            'domain' => $domain,
        ]);

        return $this->findByRef($ref) ?? ['policy_ref' => $ref];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateDraft(string $policyRef, array $payload, int $actorUserId, ?string $title = null, ?string $effectiveFrom = null, ?string $notes = null, ?int $expectedLock = null): array
    {
        $this->assertNotSupportView();
        $row = $this->require($policyRef);
        if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft policies can be edited.');
        }
        $this->assertLock($row, $expectedLock);

        $domain = (string) $row['domain'];
        $payload = $this->normalizePayload($domain, $payload);
        $now = date('Y-m-d H:i:s');
        $update = [
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'lock_version' => (int) $row['lock_version'] + 1,
            'updated_at'   => $now,
        ];
        if ($title !== null && trim($title) !== '') {
            $update['title'] = mb_substr(trim($title), 0, 160);
        }
        if ($effectiveFrom !== null) {
            $update['effective_from'] = $this->normalizeDateOrNull($effectiveFrom);
        }
        if ($notes !== null) {
            $update['notes'] = trim($notes) !== '' ? trim($notes) : null;
        }

        $ok = db_connect()->table('financial_policy_versions')
            ->where('policy_ref', $policyRef)
            ->where('lock_version', (int) $row['lock_version'])
            ->where('status', self::STATUS_DRAFT)
            ->update($update);
        if (! $ok || db_connect()->affectedRows() !== 1) {
            throw new RuntimeException('Draft update failed (concurrent edit). Reload and retry.');
        }

        $this->recordEvent($policyRef, 'updated', $actorUserId, self::STATUS_DRAFT, self::STATUS_DRAFT, null);
        $this->audit->log('financial_policy.draft_updated', $actorUserId, 'financial_policy', $policyRef, []);

        return $this->findByRef($policyRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function submitForApproval(string $policyRef, int $actorUserId, string $password): array
    {
        $this->assertNotSupportView();
        $this->assertPassword($actorUserId, $password);
        $row = $this->require($policyRef);
        if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only drafts can be submitted.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policyRef)->where('status', self::STATUS_DRAFT)->update([
            'status'       => self::STATUS_PENDING_APPROVAL,
            'submitted_by' => $actorUserId,
            'submitted_at' => $now,
            'lock_version' => (int) $row['lock_version'] + 1,
            'updated_at'   => $now,
        ]);
        if (db_connect()->affectedRows() !== 1) {
            throw new RuntimeException('Submit failed (race).');
        }

        $this->recordEvent($policyRef, 'submitted', $actorUserId, self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, null);
        $this->audit->log('financial_policy.submitted', $actorUserId, 'financial_policy', $policyRef, []);

        return $this->findByRef($policyRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(string $policyRef, int $actorUserId, string $password): array
    {
        $this->assertNotSupportView();
        $this->gate->assertKillSwitchesOff();
        $this->assertPassword($actorUserId, $password);
        $row = $this->require($policyRef);
        if (($row['status'] ?? '') !== self::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException('Only pending_approval policies can be approved.');
        }
        if ((int) ($row['created_by'] ?? 0) === $actorUserId || (int) ($row['submitted_by'] ?? 0) === $actorUserId) {
            throw new InvalidArgumentException('Self-approval is not allowed for financial policies. A different authorized approver is required.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->transStart();
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policyRef)->where('status', self::STATUS_PENDING_APPROVAL)->update([
            'status'       => self::STATUS_APPROVED,
            'approved_by'  => $actorUserId,
            'approved_at'  => $now,
            'lock_version' => (int) $row['lock_version'] + 1,
            'updated_at'   => $now,
        ]);
        if (db_connect()->affectedRows() !== 1) {
            db_connect()->transComplete();
            throw new RuntimeException('Approval failed (race).');
        }
        $this->recordEvent($policyRef, 'approved', $actorUserId, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, [
            'note' => 'Approved policy does NOT enable financial kill-switches.',
        ]);
        db_connect()->transComplete();
        if (! db_connect()->transStatus()) {
            throw new RuntimeException('Approval transaction failed.');
        }

        $this->audit->log('financial_policy.approved', $actorUserId, 'financial_policy', $policyRef, [
            'kill_switches_unchanged' => true,
        ]);

        return $this->findByRef($policyRef) ?? $row;
    }

    /**
     * Mark approved policy ACTIVE for documentation — still does not enable kill-switches.
     *
     * @return array<string, mixed>
     */
    public function activate(string $policyRef, int $actorUserId, string $password): array
    {
        $this->assertNotSupportView();
        $this->gate->assertKillSwitchesOff();
        $this->assertPassword($actorUserId, $password);
        $row = $this->require($policyRef);
        if (($row['status'] ?? '') !== self::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved policies can be marked active.');
        }
        if ((int) ($row['approved_by'] ?? 0) === $actorUserId) {
            throw new InvalidArgumentException('Activator must differ from approver (dual-control).');
        }

        $domain = (string) $row['domain'];
        $effectiveFrom = $this->normalizeDateOrNull($row['effective_from'] ?? null) ?? date('Y-m-d');
        $this->assertValidEffectiveWindow($effectiveFrom, $row['effective_to'] ?? null);

        $now = date('Y-m-d H:i:s');
        db_connect()->transStart();

        // Supersede prior active in same domain (only one ACTIVE at a time).
        $priors = db_connect()->table('financial_policy_versions')
            ->where('domain', $domain)
            ->where('status', self::STATUS_ACTIVE)
            ->get()->getResultArray();
        foreach ($priors as $prior) {
            db_connect()->table('financial_policy_versions')->where('policy_ref', $prior['policy_ref'])->update([
                'status'     => self::STATUS_SUPERSEDED,
                'updated_at' => $now,
            ]);
            $this->recordEvent((string) $prior['policy_ref'], 'superseded', $actorUserId, self::STATUS_ACTIVE, self::STATUS_SUPERSEDED, [
                'by' => $policyRef,
            ]);
        }

        db_connect()->table('financial_policy_versions')->where('policy_ref', $policyRef)->where('status', self::STATUS_APPROVED)->update([
            'status'        => self::STATUS_ACTIVE,
            'activated_by'  => $actorUserId,
            'activated_at'  => $now,
            'lock_version'  => (int) $row['lock_version'] + 1,
            'updated_at'    => $now,
        ]);
        if (db_connect()->affectedRows() !== 1) {
            db_connect()->transComplete();
            throw new RuntimeException('Activation failed (race).');
        }
        $this->recordEvent($policyRef, 'activated', $actorUserId, self::STATUS_APPROVED, self::STATUS_ACTIVE, [
            'note' => 'ACTIVE documents policy readiness only; kill-switches remain OFF.',
        ]);
        db_connect()->transComplete();

        $this->audit->log('financial_policy.activated', $actorUserId, 'financial_policy', $policyRef, [
            'kill_switches_unchanged' => true,
        ]);

        return $this->findByRef($policyRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $policyRef, int $actorUserId, string $reason, string $password): array
    {
        $this->assertNotSupportView();
        $this->assertPassword($actorUserId, $password);
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }
        $row = $this->require($policyRef);
        if (! in_array($row['status'] ?? '', [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Only draft/pending policies can be rejected.');
        }

        $now = date('Y-m-d H:i:s');
        $from = (string) $row['status'];
        db_connect()->table('financial_policy_versions')->where('policy_ref', $policyRef)->update([
            'status'           => self::STATUS_REJECTED,
            'rejected_by'      => $actorUserId,
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);
        $this->recordEvent($policyRef, 'rejected', $actorUserId, $from, self::STATUS_REJECTED, ['reason' => $reason]);
        $this->audit->log('financial_policy.rejected', $actorUserId, 'financial_policy', $policyRef, []);

        return $this->findByRef($policyRef) ?? $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByDomain(?string $domain = null, int $limit = 50): array
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            return [];
        }
        $b = db_connect()->table('financial_policy_versions')->orderBy('id', 'DESC')->limit(max(1, min(200, $limit)));
        if ($domain !== null && $domain !== '') {
            $this->assertDomain($domain);
            $b->where('domain', $domain);
        }

        return $b->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            return null;
        }
        $row = db_connect()->table('financial_policy_versions')->where('policy_ref', $ref)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['payload'] = $this->decodeJson($row['payload_json'] ?? null);
        $row['baseline'] = $this->decodeJson($row['baseline_json'] ?? null);
        $row['events'] = db_connect()->table('financial_policy_events')
            ->where('policy_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function readinessDashboard(): array
    {
        $switches = $this->gate->killSwitchSnapshot();
        $sections = [];
        foreach (self::DOMAINS as $domain) {
            $latestApproved = $this->latestStatus($domain, [self::STATUS_APPROVED, self::STATUS_ACTIVE]);
            $latestDraft = $this->latestStatus($domain, [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL]);
            $status = 'MISSING';
            if ($latestApproved !== null) {
                $status = strtoupper((string) $latestApproved['status']);
            } elseif ($latestDraft !== null) {
                $status = strtoupper((string) $latestDraft['status']);
            }
            // Enrich from engines for mining/commission
            if ($domain === self::DOMAIN_MINING && $this->countApprovedMiningRates() > 0 && $status === 'MISSING') {
                $status = 'ENGINE_RATE_EXISTS';
            }
            if ($domain === self::DOMAIN_COMMISSION && $this->countApprovedCommissionPlans() > 0 && $status === 'MISSING') {
                $status = 'ENGINE_PLAN_EXISTS';
            }
            $sections[$domain] = [
                'status' => $status,
                'latest_approved' => $latestApproved['policy_ref'] ?? null,
                'latest_draft' => $latestDraft['policy_ref'] ?? null,
                'snapshot' => $this->currentSnapshot($domain),
            ];
        }

        $memberId = $sections[self::DOMAIN_MEMBER_ID]['snapshot'] ?? [];
        $lock = $sections[self::DOMAIN_PURCHASE]['snapshot']['historical_missing_lock'] ?? [];
        $chain = $sections[self::DOMAIN_BLOCKCHAIN]['snapshot'] ?? [];

        return [
            'sections' => $sections,
            'summary' => [
                'mining' => $sections[self::DOMAIN_MINING]['status'],
                'commission' => $sections[self::DOMAIN_COMMISSION]['status'],
                'p2p' => $sections[self::DOMAIN_P2P]['status'],
                'withdrawal' => $sections[self::DOMAIN_WITHDRAWAL]['status'],
                'selling' => $sections[self::DOMAIN_SELLING]['status'],
                'member_id_correction' => ! empty($memberId['ready']) ? 'READY_FOR_APPROVAL' : 'PENDING',
                'historical_lock1000' => ! empty($lock['ready']) ? 'READY_FOR_APPROVAL' : 'PENDING',
                'blockchain_verifier' => (($chain['deposit_verifier'] ?? '') === 'evm_erc20' && ! empty($chain['rpc_configured']))
                    ? 'CONFIGURED'
                    : 'BLOCKED',
                'backup_restore' => 'NOT_VERIFIED_IN_12E',
                'scheduler' => 'BLOCKED',
                'production_security' => (ENVIRONMENT === 'production') ? 'READY' : 'BLOCKED',
            ],
            'kill_switches' => $switches,
            'all_kill_switches_off' => empty($switches['miningPostingEnabled'])
                && empty($switches['miningP2pEnabled'])
                && empty($switches['commissionPostingEnabled'])
                && empty($switches['commissionWithdrawalEnabled'])
                && empty($switches['commissionBlockchainPayoutEnabled'])
                && empty($switches['coinSellingEnabled']),
            'note' => 'ACTIVE/APPROVED policies never enable kill-switches automatically.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function miningPreview(string $ownedCoins, string $monthlyPercent, string $divisorMode, ?int $calendarDays = null, string $rounding = MiningMath::ROUND_HALF_UP): array
    {
        // Phase 13C: delegate to the exact Phase 13B arithmetic (MiningMath). The previous 16-dp factor + truncating
        // multiply under-reported the preview (1000 x 3% / 31 gave 0.96774193 instead of 0.96774194 under HALF_UP).
        $owned = MoneyMath::normalize($ownedCoins, 8);
        $pct   = MoneyMath::normalize($monthlyPercent, 8);
        if (! MoneyMath::isPositive($owned, 8) || ! MoneyMath::isPositive($pct, 8)) {
            throw new InvalidArgumentException('Owned coins and monthly percent must be positive.');
        }
        $divisorMode = strtolower(trim($divisorMode));
        if (! in_array($divisorMode, ['fixed_30', 'calendar_days'], true)) {
            throw new InvalidArgumentException('Divisor mode must be fixed_30 or calendar_days.');
        }
        $days = $divisorMode === 'fixed_30' ? 30 : max(28, min(31, (int) ($calendarDays ?? 30)));
        if (! in_array($rounding, MiningMath::roundingModes(), true)) {
            throw new InvalidArgumentException('Rounding must be half_up or down.');
        }
        $daily = MiningMath::dailyAmount($owned, $pct, $days, 8, $rounding);

        return [
            'owned_coins' => $owned,
            'monthly_percent' => $pct,
            'divisor_mode' => $divisorMode,
            'day_divisor' => $days,
            'rounding' => $rounding,
            'daily_earning' => $daily,
            'formula' => 'owned × (monthly%/100) ÷ day_divisor, rounded once to 8 dp (' . $rounding . ')',
            'illustrative_only' => true,
            'not_an_approval' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function commissionPreview(string $baseUsdt, string $l1, string $l2, string $l3): array
    {
        $base = MoneyMath::normalize($baseUsdt, 8);
        $a = MoneyMath::mul($base, MoneyMath::div(MoneyMath::normalize($l1, 8), '100', 16), 8);
        $b = MoneyMath::mul($base, MoneyMath::div(MoneyMath::normalize($l2, 8), '100', 16), 8);
        $c = MoneyMath::mul($base, MoneyMath::div(MoneyMath::normalize($l3, 8), '100', 16), 8);

        return [
            'base_usdt' => $base,
            'l1' => $a,
            'l2' => $b,
            'l3' => $c,
            'total' => MoneyMath::add(MoneyMath::add($a, $b, 8), $c, 8),
            'rates' => ['l1' => $l1, 'l2' => $l2, 'l3' => $l3],
            'illustrative_only' => true,
            'not_an_approval' => true,
        ];
    }

    private function assertDomain(string $domain): void
    {
        if (! in_array($domain, self::DOMAINS, true)) {
            throw new InvalidArgumentException('Unknown policy domain.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePayload(string $domain, array $payload): array
    {
        // Keep unresolved keys as null; never invent production amounts.
        $clean = [];
        foreach ($payload as $k => $v) {
            $key = is_string($k) ? trim($k) : '';
            if ($key === '' || strlen($key) > 64) {
                continue;
            }
            if ($v === '' || $v === 'unresolved' || $v === 'pending') {
                $clean[$key] = null;
            } else {
                $clean[$key] = $v;
            }
        }
        $clean['_domain'] = $domain;
        $clean['_phase'] = '12E';
        $clean['_does_not_enable_kill_switches'] = true;

        return $clean;
    }

    private function nextVersionNo(string $domain): int
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            throw new RuntimeException('financial_policy_versions table missing. Run migrations on an isolated DB first.');
        }
        $row = db_connect()->table('financial_policy_versions')->selectMax('version_no')->where('domain', $domain)->get()->getRowArray();

        return max(1, (int) ($row['version_no'] ?? 0) + 1);
    }

    /**
     * @return array<string, mixed>
     */
    private function require(string $ref): array
    {
        $row = $this->findByRef($ref);
        if ($row === null) {
            throw new InvalidArgumentException('Policy not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertLock(array $row, ?int $expected): void
    {
        if ($expected !== null && (int) ($row['lock_version'] ?? 0) !== $expected) {
            throw new RuntimeException('Optimistic lock mismatch. Reload the draft and retry.');
        }
    }

    private function assertPassword(int $actorUserId, string $password): void
    {
        $password = trim($password);
        if ($password === '') {
            throw new InvalidArgumentException('Admin password reauthentication is required.');
        }
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $actorUserId) {
            throw new InvalidArgumentException('Authenticated admin mismatch.');
        }
        $hash = (string) ($user->password_hash ?? '');
        if ($hash === '' || ! service('passwords')->verify($password, $hash)) {
            // Shield identities store secret separately in some setups
            $identity = db_connect()->table('auth_identities')
                ->where('user_id', $actorUserId)
                ->where('type', 'email_password')
                ->get()->getRowArray();
            $secret = (string) ($identity['secret2'] ?? $identity['secret'] ?? '');
            if ($secret === '' || ! service('passwords')->verify($password, $secret)) {
                throw new InvalidArgumentException('Password reauthentication failed.');
            }
        }
    }

    private function assertNotSupportView(): void
    {
        if (service('impersonationService')->activeContext() !== null) {
            throw new RuntimeException('Financial policies cannot be modified from Support View.');
        }
        if (! is_cli() && str_starts_with(uri_string(), 'support-view')) {
            throw new RuntimeException('Financial policies cannot be modified from Support View.');
        }
    }

    /**
     * @param list<string> $statuses
     * @return array<string, mixed>|null
     */
    private function latestStatus(string $domain, array $statuses): ?array
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            return null;
        }

        return db_connect()->table('financial_policy_versions')
            ->where('domain', $domain)
            ->whereIn('status', $statuses)
            ->orderBy('id', 'DESC')
            ->get()->getRowArray();
    }

    private function countApprovedMiningRates(): int
    {
        if (! db_connect()->tableExists('mining_rate_configs')) {
            return 0;
        }

        return (int) db_connect()->table('mining_rate_configs')->where('status', 'approved')->countAllResults();
    }

    private function countApprovedCommissionPlans(): int
    {
        if (! db_connect()->tableExists('commission_plans')) {
            return 0;
        }

        return (int) db_connect()->table('commission_plans')->where('status', 'approved')->countAllResults();
    }

    /**
     * @return array<string, mixed>
     */
    private function safeLockPreview(string $purchaseRef): array
    {
        try {
            return service('mandatoryLockRemediationService')->preview($purchaseRef, 'default');
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'error' => $e->getMessage(),
                'purchase_ref' => $purchaseRef,
            ];
        }
    }

    private function bep20NetworkSnapshot(): array
    {
        $row = service('usdtPaymentNetworkService')->findByKey('bep20');
        if ($row === null) {
            return ['configured' => false];
        }
        $wallet = (string) ($row['destination_wallet'] ?? '');
        $contract = (string) ($row['token_contract_address'] ?? '');
        $usableWallet = service('paymentDetailsService')->isUsableBep20Address($wallet);
        $usableContract = service('paymentDetailsService')->isUsableBep20Address($contract);

        return [
            'configured' => $usableWallet && $usableContract,
            'chain_id' => $row['chain_id'] ?? null,
            'destination_wallet_masked' => $usableWallet
                ? (substr($wallet, 0, 6) . '…' . substr($wallet, -4))
                : 'NOT_SET',
            'token_contract_masked' => $usableContract
                ? (substr($contract, 0, 6) . '…' . substr($contract, -4))
                : 'NOT_SET',
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'required_confirmations' => $row['required_confirmations'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function normalizeDateOrNull(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Effective date must be YYYY-MM-DD.');
        }

        return $date;
    }

    /**
     * Guard against invalid effective windows before marking ACTIVE.
     * Prior ACTIVE rows are superseded in the same transaction (one ACTIVE per domain).
     */
    private function assertValidEffectiveWindow(string $effectiveFrom, mixed $effectiveTo): void
    {
        $to = $this->normalizeDateOrNull(is_string($effectiveTo) ? $effectiveTo : null);
        if ($to !== null && $to < $effectiveFrom) {
            throw new InvalidArgumentException('effective_to cannot be before effective_from.');
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(string $ref, string $type, int $actor, ?string $from, ?string $to, ?array $payload): void
    {
        if (! db_connect()->tableExists('financial_policy_events')) {
            return;
        }
        db_connect()->table('financial_policy_events')->insert([
            'policy_ref'    => $ref,
            'event_type'    => $type,
            'actor_user_id' => $actor,
            'from_status'   => $from,
            'to_status'     => $to,
            'payload_json'  => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
