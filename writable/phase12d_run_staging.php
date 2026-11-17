<?php

declare(strict_types=1);

/**
 * Phase 12D staging runner — avoids PowerShell option stripping.
 * Does NOT touch coin_platform balances for lock remediation on live.
 */

define('ROOTPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
chdir(ROOTPATH);

passthru('php spark members:correct-sequence --group phase12b --dry-run', $e1);
echo "\nEXIT_MEMBER_DRY={$e1}\n";

passthru('php spark members:correct-sequence --group phase12b --confirm APPLY_STAGING', $e2);
echo "\nEXIT_MEMBER_APPLY={$e2}\n";

passthru('php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --dry-run', $e3);
echo "\nEXIT_LOCK_PROD_DRY={$e3}\n";

// Refuse live apply without phrase (must fail)
passthru('php spark locking:remediate-mandatory --purchase PUR-10D7174B7FF83054 --confirm APPLY_STAGING', $e4);
echo "\nEXIT_LOCK_LIVE_REFUSE_EXPECT_FAIL={$e4}\n";

passthru('php spark mining:status', $e5);
echo "\nEXIT_MINING_STATUS={$e5}\n";
