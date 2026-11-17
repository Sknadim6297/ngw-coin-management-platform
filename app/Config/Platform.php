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

    public bool $commissionWithdrawalEnabled = false;

    public bool $commissionBlockchainPayoutEnabled = false;

    /**
     * Phase 11A mining kill-switches — fail-closed OFF until separately authorized.
     */
    public bool $miningPostingEnabled = false;

    public bool $miningP2pEnabled = false;

    /**
     * Mining business timezone for daily date boundaries (does not change global appTimezone).
     */
    public string $miningBusinessTimezone = 'Asia/Kolkata';

    /**
     * DRAFT assumption — monthly day divisor for dry-run only.
     * calendar_days = days in the rate's effective month; fixed_30 = always 30.
     * Live posting blocked until explicitly approved.
     */
    public string $miningMonthDayDivisorMode = 'calendar_days';

    /**
     * DRAFT assumption — approval-day mining policy for dry-run only.
     * full_day = eligibility includes approval business date; exclude_approval_day = starts next day.
     */
    public string $miningApprovalDayPolicy = 'full_day';

    /**
     * DRAFT assumption — mining earning asset denomination until business confirms.
     */
    public string $miningEarningAsset = 'COIN';

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
}
