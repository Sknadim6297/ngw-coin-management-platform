<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use Throwable;

/**
 * Phase 14 — Guarded, idempotent, resumable level commission posting command.
 * Dry-run by default; real execution requires sandbox/live gates & kill-switch authorization.
 */
class CommissionsPost extends BaseCommand
{
    public const EXIT_MAP = [
        'POSTED' => 0, 'DRY_RUN' => 10, 'SKIPPED' => 20, 'BLOCKED' => 30, 'PARTIAL' => 40, 'FAILED' => 50,
    ];

    public const EXIT_USAGE = 2;

    protected $group       = 'Commissions';
    protected $name        = 'commissions:post';
    protected $description = 'Post level commissions (guarded; dry-run default; idempotent; resumable batches).';
    protected $usage       = 'commissions:post [--group phase14] [--plan CPL-...] [--purchase PUR-...] [--confirm <phrase>] [--allow-production] [--dry-run] [--exclude-root] [--batch-size N] [--max-batches N] [--max-runtime SECONDS] [--resume BATCH_REF] [--strict-exit] [--actor N]';
    protected $options     = [
        '--group'            => 'Database group (default = live DB; phase12b/phase14 = isolated DBs)',
        '--plan'             => 'Filter / use approved plan ref',
        '--purchase'         => 'Post for a single approved purchase_ref',
        '--confirm'          => 'APPLY_STAGING (sandbox DB) or I_AUTHORIZE_PRODUCTION_CHANGE (live DB)',
        '--allow-production' => 'Required together with production confirm phrase for live DB',
        '--dry-run'          => 'Force read-only preview (default when --confirm omitted)',
        '--exclude-root'     => 'Exclude ROOT0001 from commission credits',
        '--batch-size'       => 'Items per batch (default 50)',
        '--max-batches'      => 'Stop after N batches (PARTIAL, resumable)',
        '--max-runtime'      => 'Wall-clock budget in seconds',
        '--resume'           => 'Resume a specific PARTIAL/FAILED batch_ref',
        '--strict-exit'      => 'Exit codes: 0 POSTED, 10 DRY_RUN, 20 SKIPPED, 30 BLOCKED, 40 PARTIAL, 50 FAILED, 2 usage',
        '--actor'            => 'Audit actor user id',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return [
            'values' => [
                'group'       => CliArgs::DB_GROUPS,
                'plan'        => null,
                'purchase'    => null,
                'confirm'     => null,
                'batch-size'  => 'int-positive',
                'max-batches' => 'int-positive',
                'max-runtime' => 'int-positive',
                'resume'      => null,
                'actor'       => 'int',
            ],
            'flags'     => ['allow-production', 'dry-run', 'exclude-root', 'strict-exit'],
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
        $forceDry  = isset($opt['dry-run']);
        $allowProd = isset($opt['allow-production']);
        $execute   = ! $forceDry && ($confirm !== null && $confirm !== '');

        $previous = config('Database')->defaultGroup;
        $result   = [];
        $error    = null;
        $status   = 'FAILED';

        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            $result = service('commissionPostingRunnerService')->run([
                'group'            => $group,
                'confirm'          => $confirm,
                'allow_production' => $allowProd,
                'execute'          => $execute,
                'purchase'         => $opt['purchase'] ?? null,
                'plan_ref'         => $opt['plan'] ?? null,
                'include_root'     => ! isset($opt['exclude-root']),
                'batch_size'       => isset($opt['batch-size']) ? (int) $opt['batch-size'] : 50,
                'max_batches'      => isset($opt['max-batches']) ? (int) $opt['max-batches'] : null,
                'max_runtime'      => isset($opt['max-runtime']) ? (int) $opt['max-runtime'] : null,
                'resume_batch_ref' => $opt['resume'] ?? null,
                'actor'            => (int) ($opt['actor'] ?? 0),
            ]);

            $rs = (string) ($result['status'] ?? '');
            $status = match (true) {
                $rs === 'dry_run'       => 'DRY_RUN',
                $rs === 'completed'     => 'POSTED',
                $rs === 'skipped_locked'=> 'SKIPPED',
                $rs === 'partial'       => 'PARTIAL',
                default                 => 'FAILED',
            };
        } catch (DatabaseException $e) {
            $status = 'FAILED';
            $error  = $e->getMessage();
        } catch (Throwable $e) {
            $status = 'BLOCKED';
            $error  = $e->getMessage();
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
        }

        if ($result !== []) {
            $print = $result;
            unset($print['items']);
            CLI::write(json_encode($print, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }
        $msg = $error ?? (string) ($result['message'] ?? '');
        if (in_array($status, ['POSTED', 'DRY_RUN', 'SKIPPED'], true)) {
            CLI::write($status . ' — ' . $msg, $status === 'POSTED' ? 'green' : 'yellow');
        } else {
            CLI::error($status . ' — ' . $msg);
        }

        $exit = $strict
            ? self::EXIT_MAP[$status]
            : (in_array($status, ['DRY_RUN', 'POSTED', 'SKIPPED'], true) ? EXIT_SUCCESS : EXIT_ERROR);

        CLI::write('COMMISSION_POST_STATUS=' . $status . ' EXIT_CODE=' . $exit);

        return $exit;
    }
}
