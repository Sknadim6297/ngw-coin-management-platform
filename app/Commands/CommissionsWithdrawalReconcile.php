<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 11D read-only commission withdrawal reconciliation.
 */
class CommissionsWithdrawalReconcile extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:withdrawal-reconcile';
    protected $description = 'Read-only reconcile commission withdrawals vs ledger and payout evidence.';
    protected $usage       = 'commissions:withdrawal-reconcile [--limit=200]';
    protected $options     = [
        '--limit' => 'Max withdrawal rows to inspect',
    ];

    public function run(array $params)
    {
        $limit  = (int) (CLI::getOption('limit') ?: 200);
        $report = service('commissionWithdrawalReconciliationService')->run($limit);

        CLI::write('rows=' . ($report['summary']['rows'] ?? 0));
        CLI::write('mismatches=' . ($report['summary']['mismatches'] ?? 0));
        foreach (array_slice($report['mismatches'], 0, 50) as $m) {
            CLI::write(json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        CLI::write($report['note'], 'yellow');

        return EXIT_SUCCESS;
    }
}
