<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Platform extends BaseConfig
{
    public string $appName = 'Next Generation Wealth';

    /**
     * Configurable Root Sponsor Member ID used for public registration.
     */
    public string $rootSponsorId = 'ROOT0001';

    /**
     * Prefix for generated Member IDs. Override via .env platform.memberIdPrefix.
     */
    public string $memberIdPrefix = 'NGW';

    /**
     * Zero-padding length for the numeric portion of Member IDs.
     */
    public int $memberIdPadding = 6;

    /**
     * Minimum first numeric Member ID (NGW981234). Next IDs are sequential.
     * Generator also advances past the highest existing PREFIX+digits ID.
     * Override via .env platform.memberIdStartNumber.
     */
    public int $memberIdStartNumber = 981234;

    public string $adminEmail = '';

    public string $adminPassword = '';

    public string $adminName = 'Platform Admin';

    /**
     * Password reset token lifetime in seconds (1 hour).
     */
    public int $passwordResetTtl = 3600;

    /**
     * Pending email-change verification token lifetime in seconds (1 hour).
     */
    public int $emailChangeTtl = 3600;

    /**
     * Max email-change requests per member per rolling hour.
     */
    public int $emailChangeMaxPerHour = 3;

    /**
     * Days before a changed USDT destination may be used for withdrawals (when enabled).
     * Stored on profile for future activation; withdrawals remain disabled in Phase 10A.
     */
    public int $walletChangeCooldownDays = 24;

    /**
     * BEP20 address pattern (0x + 40 hex chars).
     */
    public string $bep20Pattern = '/^0x[a-fA-F0-9]{40}$/';

    /**
     * Minimum member coin purchase amount in USDT.
     * Runtime enforcement uses coin_settings.min_purchase_usdt (keep both aligned).
     */
    public string $minPurchaseUsdt = '5.00000000';

    /**
     * Client Coin Plan lock duration (days). Not auto-applied until an authorized activation phase
     * enables purchase-time locking. Admin plan form defaults to this value.
     */
    public int $coinPlanLockDays = 1000;

    /**
     * Maximum coin quantity per single purchase request.
     */
    public string $maxPurchaseQuantity = '1000000.00000000';

    /**
     * Soft cap on concurrent pending purchase requests per member.
     */
    public int $maxPendingPurchaseRequests = 5;

    /**
     * Display/storage scale for coin quantities.
     */
    public int $coinQuantityScale = 8;

    /**
     * Display/storage scale for USDT prices and amounts.
     */
    public int $usdtScale = 8;

    /**
     * When true, coin purchase approval requires a verified USDT deposit
     * covering the purchase total. Phase 3 tests disable this flag.
     * Production should keep this enabled.
     */
    public bool $requireVerifiedDepositForApproval = true;

    /**
     * Max evidence upload size in bytes (default 5 MB).
     */
    public int $depositEvidenceMaxBytes = 5242880;

    /**
     * Phase 7D kill-switches — all default OFF. Do not enable in production without a
     * separately approved activation phase. Approval of posting proposals never posts
     * while commissionPostingEnabled is false.
     */
    public bool $commissionPostingEnabled = false;

    /**
     * Phase 14 — purchase-approval auto-post remains fail-closed OFF even if commissionPostingEnabled is later
     * toggled, until the execution architecture and commission policies are separately approved.
     */
    public bool $commissionAutoPostOnApprovalEnabled = false;

    public string $commissionBasePolicyStatus = 'pending_business_decision';

    public string $commissionRoundingPolicyStatus = 'pending_business_decision';

    public string $commissionRootEligibilityPolicyStatus = 'pending_business_decision';

    public bool $commissionWithdrawalEnabled = false;

    public bool $commissionBlockchainPayoutEnabled = false;

    /**
     * Optional extra production database name(s), comma separated (env: platform.liveDatabase).
     * Additive only: can never remove a database from the hard-coded production list.
     */
    public string $liveDatabase = '';

    /**
     * Phase 11A mining kill-switches — fail-closed OFF until separately authorized.
     */
    public bool $miningPostingEnabled = false;

    public bool $miningP2pEnabled = false;

    /**
     * Phase 13A — scheduler kill-switch for `mining:post --scheduled`.
     * Fail-closed OFF. Even when ON, live posting also needs miningPostingEnabled,
     * all mining policy statuses = approved, --allow-production and the exact confirm phrase.
     * Never enable in production without a separate written authorization.
     */
    public bool $miningAutoPostEnabled = false;

    /** Max attempts for a transient failure (deadlock / lock wait / connection loss). */
    public int $miningPostMaxAttempts = 3;

    /** Base back-off in seconds between attempts (multiplied by attempt number). */
    public int $miningPostRetryDelaySeconds = 5;

    /** TTL of the per-business-date job lock (ops_job_locks). */
    public int $miningPostLockTtlSeconds = 1800;

    /**
     * Mining business timezone for daily date boundaries (does not change global appTimezone).
     */
    public string $miningBusinessTimezone = 'Asia/Kolkata';

    /**
     * Owner-approved rule: actual calendar days of the business month (calendar_days).
     * Formal versioned Hub/rate approval and kill-switch activation are still required before live posting.
     */
    public string $miningMonthDayDivisorMode = 'calendar_days';

    /**
     * Owner-approved rule: exclude_approval_day (approval day excluded; mining starts on the next
     * Asia/Kolkata business date after purchase approval).
     * Live posting remains blocked until formal Hub/rate approval and explicit switch activation.
     */
    public string $miningApprovalDayPolicy = 'exclude_approval_day';

    /**
     * Owner-approved rule: earning asset denomination is COIN (no compounding; mining wallet only).
     */
    public string $miningEarningAsset = 'COIN';

    /**
     * Phase 12D — explicit policy approval statuses (pending = not production-approved).
     * Do not treat draft Platform assumptions as approved business rules.
     */
    public string $miningMonthlyPercentStatus = 'pending_business_decision';

    public string $miningDayDivisorStatus = 'pending_business_decision';

    public string $miningDenominationStatus = 'pending_business_decision';

    public string $miningApprovalDayPolicyStatus = 'pending_business_decision';

    public string $miningLockedCoinEligibilityStatus = 'pending_business_decision';

    public string $miningPostMaturityEligibilityStatus = 'pending_business_decision';

    public string $miningPostSaleEligibilityStatus = 'pending_business_decision';

    public string $miningRoundingPolicyStatus = 'pending_business_decision';

    public string $miningRewardSupplySourceStatus = 'pending_business_decision';

    public string $miningCompoundingPolicyStatus = 'pending_business_decision';

    /**
     * Phase 13B — DRAFT calculation policies (none is a production approval; see
     * docs/phase13b-business-decisions-required.md). Defaults reproduce the pre-13B behaviour.
     *
     * miningRoundingMode:        down | half_up       (final amount only, never intermediate values)
     * miningReservedCoinPolicy:  include | exclude    (coins reserved for an open sale order)
     * miningMaturityPolicy:      include_all | exclude_matured_locked
     */
    public string $miningRoundingMode = 'down';

    public string $miningReservedCoinPolicy = 'include';

    public string $miningMaturityPolicy = 'include_all';

    /** Phase 13B — default keyset batch size (test default, not a business-approved value). */
    public int $miningPostBatchSize = 500;

    /** Phase 13B — wall-clock budget for one posting invocation; on expiry the run stops PARTIAL. */
    public int $miningPostMaxRuntimeSeconds = 3600;

    /** Phase 13B — alert thresholds (local log + event hook only; no external notification). */
    public int $miningPostAlertRetryThreshold = 2;

    public int $miningPostAlertRuntimeSeconds = 1800;

    /**
     * Financial Automation Control Center (`automation:run`, cron every ~10 minutes).
     * The ON/OFF switches are DB-backed (platform_settings); the env kill-switches above stay false.
     *
     * automationDailyRunTime:            HH:MM in miningBusinessTimezone after which the previous business date is due
     * automationHeartbeatMaxAgeSeconds:  cron is "working" only when `automation:run` ran within this window
     * automationMaxAttemptsPerDay:       attempts per job per business date before the job is left to an operator
     * automationRetryBackoffSeconds:     minimum pause between failed attempts of the same job/date
     */
    public string $automationDailyRunTime = '01:30';

    public int $automationHeartbeatMaxAgeSeconds = 1800;

    public int $automationMaxAttemptsPerDay = 3;

    public int $automationRetryBackoffSeconds = 900;

    /**
     * When true, purchase approval creates a mandatory 1000-day lock (Phase 11A).
     */
    public bool $mandatoryPurchaseLockEnabled = true;

    /**
     * Explicitly supported USDT withdrawal networks (address validation is network-specific).
     *
     * @var list<string>
     */
    public array $commissionWithdrawalNetworks = ['BEP20'];

    /**
     * Phase 11D — withdrawal fee / minimum are pending business decisions (null = not approved).
     * Do not invent fee or minimum amounts in UI or execution paths.
     */
    public ?string $commissionWithdrawalFeeUsdt = null;

    public ?string $commissionWithdrawalMinimumUsdt = null;

    public string $commissionWithdrawalFeePolicyStatus = 'pending_business_decision';

    /**
     * Phase 11E — coin selling execution kill-switch. Fail-closed OFF until authorized.
     */
    public bool $coinSellingEnabled = false;

    /**
     * Phase 11E unresolved business decisions (null / pending = not approved).
     */
    public ?string $coinSellingFeeUsdt = null;

    public ?string $coinSellingMinimumQuantity = null;

    public string $coinSellingFeePolicyStatus = 'pending_business_decision';

    public string $coinSellingSettlementAssetStatus = 'pending_business_decision';

    public string $coinSellingCounterpartyStatus = 'pending_business_decision';

    public string $coinSellingPostSaleMiningPolicyStatus = 'pending_business_decision';
}
