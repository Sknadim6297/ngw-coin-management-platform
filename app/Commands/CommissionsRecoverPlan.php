<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\ProductionSafetyGate;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 14 — Build append-only superseding commission calculation revisions for historical
 * NO_APPROVED_PLAN or pre-Phase-11B missing calculation purchases.
 * Dry-run by default; --persist writes ONLY to `commission_calculation_revisions`
 * (never modifies `commission_purchase_calculations` and never credits wallets).
 */
class CommissionsRecoverPlan extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:recover-plan';
    protected $description = 'Preview or persist append-only superseding calculation revisions for historical purchases without approved commission calculations.';
    protected $usage       = 'commissions:recover-plan [--plan CPL-...] [--purchase PUR-...] [--group phase14] [--persist] [--allow-production] [--confirm <phrase>] [--exclude-root] [--actor N]';
    protected $options     = [
        '--plan'             => 'Approved commission plan ref (required when persisting on a live database)',
        '--purchase'         => 'Filter to one purchase_ref',
        '--group'            => 'Database group (default = live DB)',
        '--persist'          => 'Persist append-only rows into commission_calculation_revisions (no wallet credit)',
        '--allow-production' => 'Required together with --confirm I_AUTHORIZE_PRODUCTION_CHANGE when persisting on a live DB',
        '--confirm'          => 'Production confirm phrase (I_AUTHORIZE_PRODUCTION_CHANGE) for --persist on live DB',
        '--exclude-root'     => 'Exclude ROOT0001 from commission eligibility',
        '--actor'            => 'Audit actor user id',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return [
            'values' => [
                'plan'     => null,
                'purchase' => null,
                'group'    => CliArgs::DB_GROUPS,
                'confirm'  => null,
                'actor'    => 'int',
            ],
            'flags' => ['persist', 'allow-production', 'exclude-root'],
        ];
    }

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, self::argSpec());
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

            if ($persist) {
                $info = (new ProductionSafetyGate())->assertMutationAllowed(
                    isset($opt['allow-production']),
                    isset($opt['confirm']) ? (string) $opt['confirm'] : null,
                    $group,
                    'commission superseding revision persistence'
                );
                if (! empty($info['is_live']) && empty($opt['plan'])) {
                    throw new \RuntimeException('Refusing --persist on live database without explicit approved --plan CPL-... reference.');
                }
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
