<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\FinancialGovernanceService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Read-only: shows the financial governance mode and whether a policy / rate / plan is eligible for single-admin
 * approval (terms, rule problems, governance trail). Never writes, approves, activates or toggles anything.
 */
class GovernanceStatus extends BaseCommand
{
    protected $group       = 'Finance';
    protected $name        = 'governance:status';
    protected $description = 'Read-only: financial governance mode and single-admin eligibility of a policy/rate/plan.';
    protected $usage       = 'governance:status [--type financial_policy|mining_rate|commission_plan --ref REF] [--group phase14]';
    protected $options     = [
        '--type'  => 'Subject type (financial_policy, mining_rate, commission_plan)',
        '--ref'   => 'Subject reference, e.g. FPV-MIN-20261118-6611BC',
        '--group' => 'Database group (default = live DB; read-only)',
    ];

    /** @return array<string, mixed> */
    public static function argSpec(): array
    {
        return [
            'values' => [
                'type'  => FinancialGovernanceService::SUBJECTS,
                'ref'   => '/^[A-Za-z0-9_-]{3,64}$/',
                'group' => CliArgs::DB_GROUPS,
            ],
            'flags' => [],
        ];
    }

    public function run(array $params)
    {
        $opt = CliArgs::guard($this->name, self::argSpec());
        if ($opt === null) {
            return 2;
        }
        if (isset($opt['ref']) !== isset($opt['type'])) {
            CLI::error('--type and --ref must be given together.');

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
            $gov = service('financialGovernanceService');
            $out = ['database' => (string) db_connect()->getDatabase(), 'read_only' => true] + $gov->status();

            if (isset($opt['ref'])) {
                $type  = (string) $opt['type'];
                $ref   = (string) $opt['ref'];
                $terms = $gov->terms($type, $ref);
                $out['subject'] = [
                    'type'          => $type,
                    'ref'           => $ref,
                    'status'        => $terms['status'],
                    'version'       => $terms['version'],
                    'created_by'    => $terms['created_by'],
                    'submitted_by'  => $terms['submitted_by'],
                    'approved_by'   => $terms['approved_by'],
                    'terms'         => $terms['terms'],
                    'requirements'  => $terms['requirements'],
                    'terms_hash'    => $terms['terms_hash'],
                    'rule_problems' => $terms['rule_problems'],
                    'single_admin_eligible' => $terms['rule_problems'] === [],
                    'history'       => array_map(static fn (array $h): array => [
                        'action' => $h['action'], 'mode' => $h['governance_mode'], 'actor' => (int) $h['actor_user_id'],
                        'version' => $h['subject_version'], 'ref' => $h['action_ref'], 'at' => $h['created_at'],
                    ], $gov->history($type, $ref)),
                ];
            }

            CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return 1;
        } finally {
            config('Database')->defaultGroup = $previous;
        }
    }
}
