<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class MiningStatus extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:status';
    protected $description = 'Show mining kill-switches, assumptions, and last dry-run.';
    protected $usage       = 'mining:status';

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('mining:status', []) === null) {
            return 2;
        }

        $status = service('miningDailyEngineService')->status();
        foreach ($status as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            CLI::write($k . '=' . (string) $v);
        }

        $missing = service('coinLockingService')->previewMissingMandatoryLocks(20);
        CLI::write('missing_mandatory_locks_sample=' . count($missing));

        return EXIT_SUCCESS;
    }
}
