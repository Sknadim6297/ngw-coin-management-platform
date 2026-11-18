<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark payment:deliver-alerts [--limit 25]
 */
class DeliverFinancialAlerts extends BaseCommand
{
    protected $group       = 'Payment';
    protected $name        = 'payment:deliver-alerts';
    protected $description = 'Process financial alert email outbox (no-op unless SMTP + recipients configured).';
    protected $usage       = 'payment:deliver-alerts [options]';
    protected $options     = [
        '--limit' => 'Max outbox rows to process (default 25).',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('payment:deliver-alerts', ['values' => ['limit' => 'int-positive']]) === null) {
            return 2;
        }

        $limit  = (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 25));
        $result = service('financialNotificationService')->processPending($limit);

        if (! $result['configured']) {
            CLI::write('SKIPPED: email delivery not configured (financeNotifications.emailEnabled / recipients / SMTP).', 'yellow');

            return EXIT_SUCCESS;
        }

        if (! empty($result['lock_busy'])) {
            CLI::write('SKIPPED: another deliver-alerts run holds the lock.', 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write('Outbox processed=' . $result['processed'] . ' sent=' . $result['sent'] . ' failed=' . $result['failed'], 'green');

        return EXIT_SUCCESS;
    }
}
