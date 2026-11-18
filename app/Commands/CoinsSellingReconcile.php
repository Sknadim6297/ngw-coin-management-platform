<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CoinsSellingReconcile extends BaseCommand
{
    protected $group       = 'Coins';
    protected $name        = 'coins:selling-reconcile';
    protected $description = 'Read-only reconcile coin selling requests vs ownership ledger.';
    protected $usage       = 'coins:selling-reconcile [--limit 200]';
    protected $options     = [
        '--limit' => 'Max sell rows to inspect',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('coins:selling-reconcile', ['values' => ['limit' => 'int-positive']]) === null) {
            return 2;
        }

        $limit  = (int) (CLI::getOption('limit') ?: 200);
        $report = service('coinSellingReconciliationService')->run($limit);
        CLI::write('rows=' . ($report['summary']['rows'] ?? 0));
        CLI::write('mismatches=' . ($report['summary']['mismatches'] ?? 0));
        foreach (array_slice($report['mismatches'], 0, 50) as $m) {
            CLI::write(json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        CLI::write($report['note'], 'yellow');

        return EXIT_SUCCESS;
    }
}
