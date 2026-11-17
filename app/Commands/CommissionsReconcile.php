<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CommissionsReconcile extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:reconcile';
    protected $description = 'Read-only commission calculation vs ledger reconciliation.';
    protected $usage       = 'commissions:reconcile [--purchase=REF] [--limit=200]';
    protected $options     = [
        '--purchase' => 'Optional purchase_ref filter',
        '--limit'    => 'Max rows to scan',
    ];

    public function run(array $params)
    {
        $purchase = CLI::getOption('purchase');
        $limit    = (int) (CLI::getOption('limit') ?: 200);
        $purchase = ($purchase === null || $purchase === true || $purchase === '') ? null : (string) $purchase;

        $report = service('commissionPurchaseEngineService')->reconcile($purchase, $limit);
        CLI::write('mismatches=' . $report['summary']['mismatches'], 'yellow');
        foreach (array_slice($report['mismatches'], 0, 50) as $m) {
            CLI::write(json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        CLI::write($report['note'], 'yellow');

        return EXIT_SUCCESS;
    }
}
