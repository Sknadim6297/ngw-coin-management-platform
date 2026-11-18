<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * php spark coins:check-maturity [--limit 100]
 *
 * Marks eligible active locks as matured. No ROI or rewards.
 */
class CheckCoinLockMaturity extends BaseCommand
{
    protected $group       = 'Coins';
    protected $name        = 'coins:check-maturity';
    protected $description = 'Mark eligible active coin locks as matured (no rewards).';
    protected $usage       = 'coins:check-maturity [options]';
    protected $options     = [
        '--limit' => 'Max active locks to evaluate (default 100, max 500).',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('coins:check-maturity', ['values' => ['limit' => 'int-positive']]) === null) {
            return 2;
        }

        $limit  = (int) (CLI::getOption('limit') ?? ($params['limit'] ?? 100));
        $result = service('coinLockingService')->checkMaturity($limit);

        if ($result['skipped']) {
            CLI::write('SKIPPED: ' . ($result['reason'] ?? 'busy'), 'yellow');

            return EXIT_SUCCESS;
        }

        CLI::write('Coin lock maturity check completed.', 'green');
        CLI::write('scanned=' . $result['scanned']);
        CLI::write('matured=' . $result['matured']);
        CLI::write('NOTE: No ROI, interest, or coin rewards were generated.', 'yellow');

        return EXIT_SUCCESS;
    }
}
