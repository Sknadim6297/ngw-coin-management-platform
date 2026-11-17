<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MiningReconcile extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:reconcile';
    protected $description = 'Read-only reconcile expected dry-run vs posted mining ledger.';
    protected $usage       = 'mining:reconcile [--date=YYYY-MM-DD] [--limit=200]';
    protected $options     = [
        '--date'  => 'Business date YYYY-MM-DD',
        '--limit' => 'Max members',
    ];

    public function run(array $params)
    {
        $date = CLI::getOption('date');
        if ($date === null || $date === true || $date === '') {
            $date = service('miningEligibilityService')->businessDate();
        }
        $limit = (int) (CLI::getOption('limit') ?: 200);
        $report = service('miningDailyEngineService')->reconcile((string) $date, $limit);
        CLI::write('business_date=' . $report['business_date']);
        CLI::write('mismatches=' . $report['summary']['mismatches']);
        foreach (array_slice($report['mismatches'], 0, 50) as $m) {
            CLI::write(json_encode($m, JSON_UNESCAPED_SLASHES));
        }
        CLI::write($report['note'], 'yellow');

        return EXIT_SUCCESS;
    }
}
