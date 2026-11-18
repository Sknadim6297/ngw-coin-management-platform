<?php

declare(strict_types=1);

namespace App\Commands;

use App\Exceptions\MiningBlockedException;
use App\Libraries\CliArgs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use Throwable;

/**
 * Phase 13A/13B — guarded daily mining posting (the only scheduler entry that can credit mining).
 *
 * Default = DRY-RUN. Real posting is refused unless every gate passes:
 *   Sandbox DB : --group phase13a|phase13b|phase13c --confirm APPLY_STAGING
 *   Live DB    : Platform.miningPostingEnabled + (--scheduled => miningAutoPostEnabled)
 *                + all mining policies "approved" + --allow-production
 *                + --confirm "I_AUTHORIZE_PRODUCTION_CHANGE"
 *
 * SYNTAX: options are space-separated ("--group phase13b"). "--group=phase13b" is REFUSED (the CI4 CLI would
 * silently drop it and fall back to the live database). Unknown options, missing values, duplicates,
 * stray arguments and unsupported database groups are refused too (see App\Libraries\CliArgs).
 *
 * Scheduler status (printed as SCHEDULER_STATUS=...): DRY_RUN | POSTED | SKIPPED | BLOCKED | PARTIAL | FAILED.
 * With --strict-exit the exit code carries it: 0 POSTED, 10 DRY_RUN, 20 SKIPPED, 30 BLOCKED, 40 PARTIAL,
 * 50 FAILED, 2 usage error. Without it the legacy 13A mapping applies (0 for dry_run/completed/skipped, else 1).
 *
 * Examples:
 *   php spark mining:post --group phase13b --date yesterday                          (dry-run)
 *   php spark mining:post --group phase13b --date 2026-10-15 --confirm APPLY_STAGING
 *   php spark mining:post --group phase13b --scheduled --strict-exit --confirm APPLY_STAGING   (cron/Task entry)
 */
class MiningPost extends BaseCommand
{
    public const EXIT_MAP = [
        'POSTED' => 0, 'DRY_RUN' => 10, 'SKIPPED' => 20, 'BLOCKED' => 30, 'PARTIAL' => 40, 'FAILED' => 50,
    ];

    public const EXIT_USAGE = 2;

    protected $group       = 'Mining';
    protected $name        = 'mining:post';
    protected $description = 'Post daily mining income (guarded; dry-run by default; idempotent; as-of ownership; resumable batches).';
    protected $usage       = 'mining:post [--date yesterday|today|YYYY-MM-DD] [--group phase13b] [--confirm <phrase>] [--allow-production] [--scheduled] [--dry-run] [--strict-exit] [--limit N] [--batch-size N] [--max-batches N] [--max-runtime SECONDS] [--allow-incomplete-day] [--actor N]   (space-separated options only)';
    protected $options     = [
        '--date'             => 'Business date: yesterday (default) | today | YYYY-MM-DD (never future)',
        '--group'            => 'Database group (default = live DB → refused unless fully authorized; phase13a/phase13b = isolated test DBs)',
        '--confirm'          => 'APPLY_STAGING (sandbox DB) or I_AUTHORIZE_PRODUCTION_CHANGE (live DB)',
        '--allow-production' => 'Required together with the production confirm phrase for the live DB',
        '--scheduled'        => 'Scheduler mode: SKIPPED while miningAutoPostEnabled=false',
        '--dry-run'          => 'Force preview only (no wallet credit, nothing persisted)',
        '--strict-exit'      => 'Scheduler exit codes: 0 POSTED, 10 DRY_RUN, 20 SKIPPED, 30 BLOCKED, 40 PARTIAL, 50 FAILED, 2 usage',
        '--limit'            => 'Optional hard cap: refuse a run whose member population exceeds N (no default cap since Phase 13B)',
        '--batch-size'       => 'Members per keyset batch (default Platform.miningPostBatchSize; test value, not an approved default)',
        '--max-batches'      => 'Stop after N batches this invocation (run ends PARTIAL; resume by re-running)',
        '--max-runtime'      => 'Wall-clock budget in seconds for this invocation (run ends PARTIAL on expiry)',
        '--allow-incomplete-day' => 'Sandbox DBs only: snapshot a business date that is not over yet (never allowed on the live DB)',
        '--actor'            => 'Audit actor user id (default 0 = system)',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return [
            'values' => [
                'date'         => 'date',
                'group'        => ['default', 'phase12a', 'phase12b', 'phase13a', 'phase13b', 'phase13c', 'phase14'],
                'confirm'      => null,
                'limit'        => 'int-positive',
                'batch-size'   => 'int-positive',
                'max-batches'  => 'int-positive',
                'max-runtime'  => 'int-positive',
                'actor'        => 'int',
            ],
            'flags'     => ['allow-production', 'scheduled', 'dry-run', 'strict-exit', 'allow-incomplete-day'],
            'conflicts' => [['dry-run', 'confirm']],
        ];
    }

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, self::argSpec());
        if ($opt === null) {
            return self::EXIT_USAGE;
        }

        $strict    = isset($opt['strict-exit']);
        $group     = (string) ($opt['group'] ?? 'default');
        $confirm   = isset($opt['confirm']) ? (string) $opt['confirm'] : null;
        $scheduled = isset($opt['scheduled']);
        $forceDry  = isset($opt['dry-run']);
        $allowProd = isset($opt['allow-production']);
        $execute   = ! $forceDry && ($scheduled || ($confirm !== null && $confirm !== ''));

        $previous = config('Database')->defaultGroup;
        $result   = [];
        $error    = null;
        $status   = 'FAILED';

        try {
            // Switch BEFORE any service/model is created so every connection binds to the target DB.
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            CLI::write('=== mining:post ===', 'yellow');

            $result = service('miningPostingRunnerService')->run([
                'date'                 => $opt['date'] ?? null,
                'limit'                => isset($opt['limit']) ? (int) $opt['limit'] : null,
                'batch_size'           => isset($opt['batch-size']) ? (int) $opt['batch-size'] : null,
                'max_batches'          => isset($opt['max-batches']) ? (int) $opt['max-batches'] : null,
                'max_runtime'          => isset($opt['max-runtime']) ? (int) $opt['max-runtime'] : null,
                'allow_incomplete_day' => isset($opt['allow-incomplete-day']),
                'group'                => $group,
                'confirm'              => $confirm,
                'allow_production'     => $allowProd,
                'scheduled'            => $scheduled,
                'execute'              => $execute,
                'actor'                => (int) ($opt['actor'] ?? 0),
            ]);

            $runStatus = (string) ($result['status'] ?? '');
            $status    = match (true) {
                $runStatus === 'dry_run'  => 'DRY_RUN',
                $runStatus === 'completed' => 'POSTED',
                str_starts_with($runStatus, 'skipped_') => 'SKIPPED',
                $runStatus === 'partial' => 'PARTIAL',
                default => 'FAILED',
            };
        } catch (MiningBlockedException $e) {
            $status = 'BLOCKED';
            $error  = $e->getMessage();
        } catch (DatabaseException $e) {
            $status = 'FAILED';
            $error  = $e->getMessage();
        } catch (Throwable $e) {
            // Gate refusals (kill-switch, confirmation, isolation) and bad input are BLOCKED, never "posted".
            $status = str_contains(strtolower($e::class), 'database') ? 'FAILED' : 'BLOCKED';
            $error  = $e->getMessage();
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
        }

        if ($result !== []) {
            CLI::write(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $message = $error ?? (string) ($result['message'] ?? '');

        if ($status === 'POSTED' || $status === 'DRY_RUN' || $status === 'SKIPPED') {
            CLI::write($status . ' — ' . $message, $status === 'POSTED' ? 'green' : 'yellow');
        } else {
            CLI::error($status . ' — ' . $message);
        }

        $exit = $strict
            ? self::EXIT_MAP[$status]
            : (in_array($status, ['DRY_RUN', 'POSTED', 'SKIPPED'], true) ? EXIT_SUCCESS : EXIT_ERROR);

        CLI::write('SCHEDULER_STATUS=' . $status . ' EXIT_CODE=' . $exit);

        try {
            service('miningPostingRunnerService')->logCommandExit($result + ['scheduled' => $scheduled, 'execute' => $execute], $status, $exit, $error);
            if ($execute || $scheduled) {
                (new \App\Services\MiningPostAlertService())->evaluate($result, $status, $strict ? $exit : (self::EXIT_MAP[$status] ?? 1), $error);
            }
        } catch (Throwable $e) {
            CLI::error('[mining:post] logging/alert hook failed: ' . $e->getMessage());
        }

        return $exit;
    }
}
