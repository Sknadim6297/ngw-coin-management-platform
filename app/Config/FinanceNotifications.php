<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Optional financial alert email delivery (Phase 4E).
 * Emails are never sent unless enabled AND recipients AND SMTP are configured.
 */
class FinanceNotifications extends BaseConfig
{
    /**
     * Master switch — default off.
     */
    public bool $emailEnabled = false;

    /**
     * Comma-separated recipient emails (env: financeNotifications.recipients).
     */
    public string $recipients = '';

    /**
     * Minimum severity to notify: critical|warning|info
     */
    public string $minSeverity = 'critical';

    public int $maxAttempts = 5;

    public int $retryBackoffSeconds = 120;

    public int $retryMaxBackoffSeconds = 3600;

    public int $deliveryBatchSize = 25;
}
