<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Libraries\MiningCalculationPolicy;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 14 — Dual-control mining recovery assessment / proposal / Phase 13B execution.
 * Dry-run by default; never uses legacy mining:daily.
 */
class MiningRecover extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:recover';
    protected $description = 'Assess missing mining credits, create dual-control recovery proposal, or execute approved proposal via Phase 13B engine.';
    protected $usage       = 'mining:recover --date YYYY-MM-DD [--effective-from YYYY-MM-DD] [--group phase14] [--propose] [--approve MRP-...] [--execute MRP-...] [--confirm <phrase>] [--allow-production] [--actor N]';
    protected $options     = [
        '--date'             => 'IST business date YYYY-MM-DD',
        '--effective-from'   => 'Prospective policy start date YYYY-MM-DD (refuses dates before this)',
        '--group'            => 'Database group (default = live DB)',
        '--propose'          => 'Create draft dual-control mining recovery proposal (no credits)',
        '--approve'          => 'Approve proposal ref (requires actor != creator)',
        '--execute'          => 'Execute approved proposal ref via Phase 13B runner',
        '--confirm'          => 'APPLY_STAGING or I_AUTHORIZE_PRODUCTION_CHANGE',
        '--allow-production' => 'Required for live DB mutations',
        '--actor'            => 'Actor user id',
    ];

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, [
            'values' => [
                'date'           => 'date',
                'effective-from' => 'date',
                'group'          => CliArgs::DB_GROUPS,
                'approve'        => null,
                'execute'        => null,
                'confirm'        => null,
                'actor'          => 'int',
            ],
            'flags' => ['propose', 'allow-production'],
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

            $svc   = service('miningRecoveryService');
            $actor = (int) ($opt['actor'] ?? 0);

            if (isset($opt['approve'])) {
                if (in_array((string) db_connect()->getDatabase(), \App\Services\ProductionSafetyGate::liveDatabases(), true)) {
                    (new \App\Services\ProductionSafetyGate())->assertMutationAllowed(isset($opt['allow-production']), $opt['confirm'] ?? null, $group, 'mining recovery approval');
                }
                $out = $svc->approveProposal((string) $opt['approve'], $actor);
                CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return EXIT_SUCCESS;
            }

            if (isset($opt['execute'])) {
                $out = $svc->executeProposal((string) $opt['execute'], [
                    'group'            => $group,
                    'confirm'          => $opt['confirm'] ?? null,
                    'allow_production' => isset($opt['allow-production']),
                ], $actor);
                CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return EXIT_SUCCESS;
            }

            $date = (string) ($opt['date'] ?? service('miningPostingRunnerService')->resolveDate('yesterday'));
            $pol  = MiningCalculationPolicy::fromPlatform(config('Platform'));
            $eff  = isset($opt['effective-from']) ? (string) $opt['effective-from'] : null;

            if (isset($opt['propose'])) {
                if (in_array((string) db_connect()->getDatabase(), \App\Services\ProductionSafetyGate::liveDatabases(), true)) {
                    (new \App\Services\ProductionSafetyGate())->assertMutationAllowed(isset($opt['allow-production']), $opt['confirm'] ?? null, $group, 'mining recovery proposal');
                }
                $out = $svc->createProposal($date, $pol, $actor, 'reserve_inventory_tracked', $eff);
                CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return EXIT_SUCCESS;
            }

            $out = $svc->assessDate($date, $pol, $eff);
            CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        } finally {
            config('Database')->defaultGroup = $previous;
            Database::connect($previous, false);
        }
    }
}
