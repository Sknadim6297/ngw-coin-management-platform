<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 11C read-only mining P2P reconciliation.
 */
class MiningP2pReconcile extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:p2p-reconcile';
    protected $description = 'Read-only reconcile mining P2P transfers vs mining wallet ledger.';
    protected $usage       = 'mining:p2p-reconcile [--limit 200]';
    protected $options     = [
        '--limit' => 'Max transfer rows to inspect',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('mining:p2p-reconcile', ['values' => ['limit' => 'int-positive']]) === null) {
            return 2;
        }

        $limit  = (int) (CLI::getOption('limit') ?: 200);
        $report = service('miningP2pTransferService')->reconcile($limit);

        CLI::write('completed=' . ($report['summary']['completed'] ?? 0));
        CLI::write('failed=' . ($report['summary']['failed'] ?? 0));
        CLI::write('volume=' . ($report['summary']['volume'] ?? '0'));
        CLI::write('mismatches=' . ($report['summary']['mismatches'] ?? 0));
        foreach (array_slice($report['mismatches'], 0, 50) as $m) {
            CLI::write(json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        CLI::write($report['note'], 'yellow');

        return EXIT_SUCCESS;
    }
}
