<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Alias-compatible dry-run (Phase 10C name). Delegates to mining daily engine.
 * Writes NO financial records. Optional calculation history only when --persist is set.
 */
class MiningDryRun extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:dry-run';
    protected $description = 'Read-only daily mining preview (no wallet credits).';
    protected $usage       = 'mining:dry-run [--date YYYY-MM-DD] [--limit 50] [--persist]';
    protected $options     = [
        '--date'    => 'Business date YYYY-MM-DD (Asia/Kolkata mining timezone when omitted)',
        '--limit'   => 'Max members to preview (default 50, max 2000)',
        '--persist' => 'Persist dry-run history tables (still no wallet credits)',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('mining:dry-run', ['values' => ['date' => 'date', 'limit' => 'int-positive'], 'flags' => ['persist']]) === null) {
            return 2;
        }

        $date = CLI::getOption('date');
        if ($date === null || $date === true || $date === '') {
            $date = service('miningEligibilityService')->businessDate();
        }
        $limit   = (int) (CLI::getOption('limit') ?: 50);
        $persist = (bool) CLI::getOption('persist');

        CLI::write('=== Mining Dry-Run (NO FINANCIAL WRITES) ===', 'yellow');
        CLI::write('Prefer: php spark mining:daily --dry-run --date=' . $date);

        $report = service('coinPlanAuditService')->miningDryRunPreview((string) $date, $limit);
        if ($persist) {
            service('miningDailyEngineService')->dryRun((string) $date, $limit, true, null);
        }

        CLI::write($report['posting_status'], 'red');
        CLI::write($report['formula_note'], 'yellow');

        if ($report['rate'] === null) {
            CLI::write('No approved mining rate for this date.', 'red');
        } else {
            CLI::write(
                'rate=' . ($report['rate']['rate_percent'] ?? '')
                . '% basis=' . ($report['rate']['rate_basis'] ?? '')
                . ' ref=' . ($report['rate']['rate_ref'] ?? ''),
                'green'
            );
        }

        CLI::newLine();
        CLI::write(str_pad('Date', 12) . str_pad('Member', 14) . str_pad('Eligible', 16)
            . str_pad('Rate%', 10) . str_pad('Expected', 16) . str_pad('Posted', 16) . 'Status');

        foreach ($report['rows'] as $row) {
            CLI::write(
                str_pad((string) $row['date'], 12)
                . str_pad((string) $row['member_id'], 14)
                . str_pad((string) $row['eligible_coins'], 16)
                . str_pad((string) ($row['rate_percent'] ?? '-'), 10)
                . str_pad((string) $row['expected_income'], 16)
                . str_pad((string) $row['posted_income'], 16)
                . (string) $row['status']
            );
        }

        CLI::newLine();
        CLI::write('Done. No wallets were credited.', 'green');

        return EXIT_SUCCESS;
    }
}
