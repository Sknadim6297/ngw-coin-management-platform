<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\MiningPostAlertService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 13B — read-only watchdog: did the scheduled mining day run complete? (missing-run / stale-run alert hook)
 * Exit 0 = COMPLETED; 1 = not completed / missing (alert written locally); 2 = usage error. Never writes ledger data.
 */
class MiningPostHealth extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:post-health';
    protected $description = 'Read-only check that the scheduled mining day run completed (alerts locally, no external notification).';
    protected $usage       = 'mining:post-health [--date yesterday|YYYY-MM-DD] [--group phase13b]';
    protected $options     = [
        '--date'  => 'Business date to check (default yesterday, Asia/Kolkata)',
        '--group' => 'Database group (default = live DB, read-only)',
    ];

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, [
            'values' => ['date' => 'date', 'group' => ['default', 'phase12a', 'phase12b', 'phase13a', 'phase13b', 'phase13c', 'phase14']],
        ]);
        if ($opt === null) {
            return 2;
        }

        $group    = (string) ($opt['group'] ?? 'default');
        $previous = config('Database')->defaultGroup;

        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }
            $date   = service('miningPostingRunnerService')->resolveDate($opt['date'] ?? 'yesterday');
            $result = (new MiningPostAlertService())->checkScheduledRun($date);
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['ok'] ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
        }
    }
}
