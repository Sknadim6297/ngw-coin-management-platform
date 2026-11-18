<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 11A daily mining dry-run (default). Never posts credits.
 */
class MiningDaily extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:daily';
    protected $description = 'LEGACY diagnostic dry-run (current balances, 2000 cap; never credits). Use mining:post --dry-run for the Phase 13B as-of preview.';
    protected $usage       = 'mining:daily [--dry-run] [--date YYYY-MM-DD] [--limit 200] [--no-persist]';
    protected $options     = [
        '--dry-run'    => 'Required safety flag (default behavior; posting never available here)',
        '--date'       => 'Business date YYYY-MM-DD (Asia/Kolkata when omitted uses mining timezone today)',
        '--limit'      => 'Max members (default 200)',
        '--no-persist' => 'Do not write dry-run history tables',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('mining:daily', ['values' => ['date' => 'date', 'limit' => 'int-positive'], 'flags' => ['dry-run', 'no-persist']]) === null) {
            return 2;
        }

        // Refuse any accidental post flag.
        if (CLI::getOption('post') || CLI::getOption('credit') || CLI::getOption('execute')) {
            CLI::error('Posting flags are not supported. mining:daily is dry-run only.');

            return EXIT_ERROR;
        }

        $tz   = (string) config('Platform')->miningBusinessTimezone;
        $date = CLI::getOption('date');
        if ($date === null || $date === true || $date === '') {
            $date = service('miningEligibilityService')->businessDate();
        }
        $limit    = (int) (CLI::getOption('limit') ?: 200);
        $persist  = CLI::getOption('no-persist') ? false : true;

        CLI::write('=== mining:daily DRY-RUN (LEGACY DIAGNOSTIC) ===', 'yellow');
        CLI::write('LEGACY: uses CURRENT member balances (not as-of history), a hard 2000-member scan cap and the older rounding path.', 'red');
        CLI::write('It can NEVER credit a wallet. For production-grade previews and posting use:  php spark mining:post --dry-run --date yesterday  (Phase 13B as-of engine).', 'red');
        CLI::write('timezone=' . $tz . ' date=' . $date);
        CLI::write('posting_enabled=' . (service('miningWalletService')->postingEnabled() ? 'true' : 'false'));

        $result = service('miningDailyEngineService')->dryRun((string) $date, $limit, $persist, null);
        CLI::write($result['posting_status'], 'red');
        CLI::write('formula=' . ($result['assumptions']['formula'] ?? ''));
        CLI::write('assumptions=' . json_encode($result['assumptions']['unconfirmed'] ?? []));

        if ($result['rate'] === null) {
            CLI::write('No approved monthly rate for this date.', 'red');
        } else {
            CLI::write(
                'rate=' . ($result['rate']['rate_percent'] ?? '') . '% basis=' . ($result['rate']['rate_basis'] ?? '')
                . ' ref=' . ($result['rate']['rate_ref'] ?? ''),
                'green'
            );
        }

        CLI::write(str_pad('Member', 14) . str_pad('Eligible', 16) . str_pad('Expected', 16) . str_pad('Posted', 16) . 'Status');
        foreach (array_slice($result['rows'], 0, 30) as $row) {
            CLI::write(
                str_pad((string) $row['member_id'], 14)
                . str_pad((string) $row['eligible_coins'], 16)
                . str_pad((string) $row['expected_amount'], 16)
                . str_pad((string) $row['posted_amount'], 16)
                . (string) $row['status']
            );
        }

        CLI::write('totals eligible_members=' . $result['totals']['eligible_count']
            . ' expected=' . $result['totals']['total_expected_amount']
            . ' posted=' . $result['totals']['total_posted_amount']);
        if (! empty($result['run_ref'])) {
            CLI::write('run_ref=' . $result['run_ref']);
        }
        CLI::write('No wallets were credited.', 'green');

        return EXIT_SUCCESS;
    }
}
