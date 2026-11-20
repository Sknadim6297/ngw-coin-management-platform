<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Financial Automation Control Center — the ONE cron entry point.
 *
 * Cron (every 10 minutes): "every-10-min" cd /home/wwwzeont/ngw.zodianwealth.info && php spark automation:run
 * (exact crontab line is in docs/phase14-production-activation-runbook.md).
 *
 * Always writes the cron heartbeat (that is how the admin page knows the server cron works). It posts nothing
 * unless the admin turned the Automatic Scheduler and the individual job switches ON AND every readiness check
 * passes again at run time. It cannot enable anything by itself and has no way to bypass a safety gate.
 *
 * Space-separated options only (`--group phase13b`).
 */
class AutomationRun extends BaseCommand
{
    public const EXIT_OK        = 0;
    public const EXIT_ATTENTION = 30;
    public const EXIT_ERROR     = 50;
    public const EXIT_USAGE     = 2;

    protected $group       = 'Automation';
    protected $name        = 'automation:run';
    protected $description = 'Cron tick for Financial Automation: heartbeat + due daily mining / commission jobs (only when switched ON in the admin panel).';
    protected $usage       = 'automation:run [--group phase13b]   (space-separated options only)';
    protected $options     = [
        '--group' => 'Database group (default = live DB; phase12b/phase13b/phase14 = isolated rehearsal DBs)',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return ['values' => ['group' => CliArgs::DB_GROUPS]];
    }

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, self::argSpec());
        if ($opt === null) {
            return self::EXIT_USAGE;
        }

        $group    = (string) ($opt['group'] ?? 'default');
        $previous = config('Database')->defaultGroup;
        $exit     = self::EXIT_OK;

        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            $result = service('financialAutomationRunnerService')->run(null, $group === 'default' ? null : $group);

            CLI::write('AUTOMATION_STATUS=' . strtoupper($result['status']) . ' — ' . $result['message'], $result['status'] === 'attention' ? 'red' : 'green');
            foreach ($result['jobs'] as $job => $info) {
                CLI::write(sprintf('  %-10s %-18s %s', $job, (string) $info['status'], (string) ($info['message'] ?? '')));
            }
            $exit = $result['status'] === 'attention' ? self::EXIT_ATTENTION : self::EXIT_OK;
        } catch (Throwable $e) {
            CLI::error('automation:run failed: ' . $e->getMessage());
            log_message('error', '[automation:run] {m}', ['m' => $e->getMessage()]);
            $exit = self::EXIT_ERROR;
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
        }

        return $exit;
    }
}
