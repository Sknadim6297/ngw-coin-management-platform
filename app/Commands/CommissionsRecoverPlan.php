<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 14 — Build append-only superseding commission calculation revisions for historical NO_APPROVED_PLAN rows.
 * Dry-run by default; --persist writes ONLY to `commission_calculation_revisions` (never modifies `commission_purchase_calculations`).
 */
class CommissionsRecoverPlan extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:recover-plan';
    protected $description = 'Preview or persist append-only superseding calculation revisions for historical NO_APPROVED_PLAN purchases.';
    protected $usage       = 'commissions:recover-plan [--plan CPL-...] [--purchase PUR-...] [--group phase14] [--persist] [--exclude-root] [--actor N]';
    protected $options     = [
        '--plan'         => 'Approved commission plan ref (optional; defaults to applicable plan by purchase date)',
        '--purchase'     => 'Filter to one purchase_ref',
        '--group'        => 'Database group (default = live DB)',
        '--persist'      => 'Persist append-only rows into commission_calculation_revisions (no wallet credit)',
        '--exclude-root' => 'Exclude ROOT0001 from commission eligibility',
        '--actor'        => 'Audit actor user id',
    ];

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, [
            'values' => [
                'plan'     => null,
                'purchase' => null,
                'group'    => CliArgs::DB_GROUPS,
                'actor'    => 'int',
            ],
            'flags' => ['persist', 'exclude-root'],
        ]);
        if ($opt === null) {
            return 2;
        }

        $group    = (string) ($opt['group'] ?? 'default');
        $persist  = isset($opt['persist']);
        $previous = config('Database')->defaultGroup;

        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            if ($persist && \App\Services\ProductionSafetyGate::requiresProductionProtection((string) db_connect()->getDatabase())) {
                throw new \RuntimeException('Refusing --persist on live database without dedicated authorization workflow.');
            }

            $out = service('commissionPostingRunnerService')->generateSupersedingRevisions(
                isset($opt['plan']) ? (string) $opt['plan'] : null,
                $persist,
                ! isset($opt['exclude-root']),
                isset($opt['actor']) ? (int) $opt['actor'] : null,
                isset($opt['purchase']) ? (string) $opt['purchase'] : null
            );

            unset($out['lines']);
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
