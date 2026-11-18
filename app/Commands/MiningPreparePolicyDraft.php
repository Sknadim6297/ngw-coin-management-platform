<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\MiningProposalService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Preview or persist a versioned DRAFT mining policy (and optional draft 3% monthly rate)
 * reflecting the owner-approved mining rules. Never submits, approves, activates, or toggles switches.
 */
class MiningPreparePolicyDraft extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:prepare-policy-draft';
    protected $description = 'Preview or persist a DRAFT versioned mining policy (never auto-activates or enables switches).';
    protected $usage       = 'mining:prepare-policy-draft [--effective-from YYYY-MM-DD] [--group phase14] [--persist --actor N] [--allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE] [--no-rate-draft]';
    protected $options     = [
        '--effective-from'   => 'Proposed effective YYYY-MM-DD date (optional; never backdates without approval)',
        '--group'            => 'Database group (default = live DB)',
        '--persist'          => 'Persist status=draft row into financial_policy_versions (requires Phase 12E migration)',
        '--actor'            => 'Admin user ID creating the draft (required with --persist)',
        '--no-rate-draft'    => 'Do not also create a draft 3% monthly row in mining_rate_configs',
        '--allow-production' => 'Required together with --confirm on a live/protected database',
        '--confirm'          => 'Production confirm phrase (I_AUTHORIZE_PRODUCTION_CHANGE) for --persist on live DB',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return [
            'values' => [
                'effective-from' => '/^\d{4}-\d{2}-\d{2}$/',
                'group'          => CliArgs::DB_GROUPS,
                'actor'          => 'int-positive',
                'confirm'        => null,
            ],
            'flags' => ['persist', 'no-rate-draft', 'allow-production'],
        ];
    }

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, self::argSpec());
        if ($opt === null) {
            return 2;
        }

        $group         = (string) ($opt['group'] ?? 'default');
        $persist       = isset($opt['persist']);
        $effectiveFrom = isset($opt['effective-from']) ? (string) $opt['effective-from'] : null;
        $previous      = config('Database')->defaultGroup;

        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            $svc = new MiningProposalService();
            if (! $persist) {
                $payload = $svc->ownerApprovedMiningPolicyPayload($effectiveFrom);
                CLI::write(json_encode([
                    'mode'             => 'dry_run',
                    'database'         => (string) db_connect()->getDatabase(),
                    'status'           => 'PREVIEW_ONLY_NOTHING_WRITTEN',
                    'proposed_payload' => $payload,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return EXIT_SUCCESS;
            }

            $out = $svc->prepareVersionedMiningDraft(
                (int) ($opt['actor'] ?? 0),
                $effectiveFrom,
                ! isset($opt['no-rate-draft']),
                $group,
                isset($opt['allow-production']),
                isset($opt['confirm']) ? (string) $opt['confirm'] : null
            );

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
