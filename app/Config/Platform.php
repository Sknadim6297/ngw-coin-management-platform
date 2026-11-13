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
    public string $memberIdPrefix = 'CPM';

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
     * BEP20 address pattern (0x + 40 hex chars).
     */
    public string $bep20Pattern = '/^0x[a-fA-F0-9]{40}$/';

    /**
     * Minimum member coin purchase amount in USDT.
     */
    public string $minPurchaseUsdt = '5.00000000';

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
}
