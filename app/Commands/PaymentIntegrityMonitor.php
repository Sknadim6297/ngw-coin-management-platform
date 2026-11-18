<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\PaymentIntegrityMonitorService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark payment:integrity-monitor [--limit 50]
 *
 * Read-only financial integrity scan. Never mutates ledgers.
 */
class PaymentIntegrityMonitor extends BaseCommand
{
    protected $group       = 'Payment';
    protected $name        = 'payment:integrity-monitor';
    protected $description = 'Run bounded payment integrity monitoring and raise internal alerts (no ledger mutation).';
    protected $usage       = 'payment:integrity-monitor [options]';
    protected $options     = [
        '--limit' => 'Max flagged/failure rows to scan per category (default 50, max 500).',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('payment:integrity-monitor', ['values' => ['limit' => 'int-positive']]) === null) {
            return 2;
        }

        $limit = (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 50));
        $svc   = service('paymentIntegrityMonitorService');
        /** @var PaymentIntegrityMonitorService $svc */
        $result = $svc->run($limit);

        if ($result['skipped']) {
            CLI::write('SKIPPED: ' . ($result['reason'] ?? 'busy'), 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write('Payment integrity monitor completed.', 'green');
        CLI::write('checked_deposits=' . $result['checked_deposits']);
        CLI::write('findings=' . $result['findings']);
        CLI::write('alerts_raised=' . $result['alerts_raised']);
        foreach ($result['redacted_log'] as $line) {
            CLI::write('[log] ' . $line);
        }
        CLI::write('NOTE: No financial ledger mutations were performed.', 'yellow');

        return EXIT_SUCCESS;
    }
}
