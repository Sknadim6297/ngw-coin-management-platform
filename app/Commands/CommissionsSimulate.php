<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 7C: commission simulation only. Always dry-run / non-payable / non-posted.
 * There is intentionally no --post or --credit option.
 */
class CommissionsSimulate extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:simulate';
    protected $description = 'Run purchase commission simulation (preview only; never credits wallets).';
    protected $usage       = 'commissions:simulate [--date=YYYY-MM-DD] [--limit=500] [--dry-run]';
    protected $options     = [
        '--date'    => 'Calculation date YYYY-MM-DD (default: today in app timezone)',
        '--limit'   => 'Max purchases per run (default 500, max 2000)',
        '--dry-run' => 'Explicit dry-run flag (default behavior; posting is never available)',
    ];

    public function run(array $params)
    {
        $date  = CLI::getOption('date') ?: date('Y-m-d');
        $limit = (int) (CLI::getOption('limit') ?: 500);

        CLI::write('Commission simulation starting (non-payable, non-posted).', 'yellow');
        CLI::write('date=' . $date . ' limit=' . $limit);

        try {
            $result = service('commissionSimulationService')->simulateDay((string) $date, null, $limit);
        } catch (\Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $run = $result['run'];
        CLI::write('run_ref=' . ($run['run_ref'] ?? ''), 'green');
        CLI::write('reused=' . ($result['reused'] ? 'yes' : 'no'));
        CLI::write('eligible_purchases=' . ($run['eligible_purchase_count'] ?? 0));
        CLI::write('total_preview_amount=' . ($run['total_preview_amount'] ?? '0'));
        CLI::write('level1_preview=' . ($run['level1_preview'] ?? '0'));
        CLI::write('level2_preview=' . ($run['level2_preview'] ?? '0'));
        CLI::write('level3_preview=' . ($run['level3_preview'] ?? '0'));
        CLI::write('is_payable=' . (string) ($run['is_payable'] ?? 0));
        CLI::write('is_posted=' . (string) ($run['is_posted'] ?? 0));
        CLI::write('NOTE: Actual commission posting is not implemented and cannot be enabled by this command.', 'yellow');

        return EXIT_SUCCESS;
    }
}
