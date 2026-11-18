<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\ProductionSafetyGate;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 12D — historical LOCK1000 remediation.
 *
 * Options are space-separated (CI4 does not parse "--opt=value"; the CliArgs guard refuses it).
 *
 * Dry-run (any DB, read-only projection):
 *   php spark locking:remediate-mandatory --purchase PUR-... --dry-run
 *
 * Staging / isolated execute:
 *   php spark locking:remediate-mandatory --group phase13c --purchase PUR-... --confirm APPLY_STAGING
 *
 * Production execute (separate written authorization only; NOT part of Phase 13C):
 *   php spark locking:remediate-mandatory --purchase PUR-... --allow-production --confirm I_AUTHORIZE_PRODUCTION_CHANGE
 */
class RemediateMandatoryLock extends BaseCommand
{
    protected $group       = 'Locking';
    protected $name        = 'locking:remediate-mandatory';
    protected $description = 'Dry-run / execute mandatory LOCK1000 remediation for one approved purchase.';
    protected $usage       = 'locking:remediate-mandatory --purchase PUR-... [--dry-run] [--group phase12b] [--confirm ...] [--allow-production]';
    protected $options     = [
        '--purchase'         => 'Purchase reference (required)',
        '--dry-run'          => 'Preview only (default if --confirm omitted)',
        '--group'            => 'Database group (default|phase12b)',
        '--confirm'          => 'APPLY_STAGING or I_AUTHORIZE_PRODUCTION_CHANGE',
        '--allow-production' => 'Required for live DB execution',
        '--actor'            => 'Audit actor user id (default 0 for CLI)',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('locking:remediate-mandatory', ['values' => ['purchase' => '/^[A-Za-z0-9_-]{3,40}$/', 'group' => ['default', 'phase12a', 'phase12b', 'phase12d', 'phase12f', 'phase13a', 'phase13b', 'phase13c', 'phase13c_snapshot'], 'confirm' => null, 'actor' => 'int'], 'flags' => ['dry-run', 'allow-production'], 'positionals' => 1]) === null) {
            return 2;
        }

        $purchaseRef = (string) (CLI::getOption('purchase') ?: ($params['purchase'] ?? $params[0] ?? ''));
        if ($purchaseRef === '') {
            CLI::error('--purchase is required');

            return EXIT_ERROR;
        }

        $group     = (string) (CLI::getOption('group') ?: 'default');
        $confirm   = CLI::getOption('confirm');
        $confirm   = $confirm === true ? '' : ($confirm !== null ? (string) $confirm : null);
        $allowProd = (bool) CLI::getOption('allow-production');
        $dryRunFlag = CLI::getOption('dry-run') !== null;
        $dryRun    = $dryRunFlag || $confirm === null || $confirm === '';
        $actor     = (int) (CLI::getOption('actor') ?: 0);

        // Phase 13C: the restored production-snapshot database is audit-only (its DB account is SELECT-only too).
        if ($group === 'phase13c_snapshot' && ! $dryRun) {
            CLI::error('Group phase13c_snapshot is read-only: only --dry-run is permitted. Apply on the disposable clone (--group phase13c).');

            return EXIT_ERROR;
        }

        CLI::write('=== locking:remediate-mandatory ===', 'yellow');
        CLI::write('purchase_ref=' . $purchaseRef . ' db-group=' . $group . ' dry_run=' . ($dryRun ? 'yes' : 'no'));

        $previous = config('Database')->defaultGroup;
        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            $svc = service('mandatoryLockRemediationService');

            if ($dryRun) {
                $out = $svc->execute($purchaseRef, true, false, null, $actor, $group);
            } elseif (in_array($group, ['phase12a', 'phase12b', 'phase12d', 'phase12f', 'phase13a', 'phase13b', 'phase13c'], true)) {
                if ($confirm !== 'APPLY_STAGING') {
                    CLI::error('Staging apply requires --confirm APPLY_STAGING');

                    return EXIT_ERROR;
                }
                $out = $svc->execute($purchaseRef, false, false, null, $actor, $group);
            } else {
                $out = $svc->execute($purchaseRef, false, $allowProd, $confirm, $actor, $group);
            }

            CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            CLI::write($dryRun ? 'DRY-RUN only — no lock created.' : 'Remediation executed on ' . ($out['database'] ?? ''), 'green');
            CLI::write('Kill-switches: ' . json_encode((new ProductionSafetyGate())->killSwitchSnapshot()));

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
