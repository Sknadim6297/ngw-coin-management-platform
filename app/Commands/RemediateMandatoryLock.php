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
 * Dry-run (any DB, read-only projection):
 *   php spark locking:remediate-mandatory --purchase-ref=PUR-... --dry-run
 *
 * Staging execute:
 *   php spark locking:remediate-mandatory --db-group=phase12b --purchase-ref=PUR-... --confirm=APPLY_STAGING
 *
 * Production execute (separate authorization only):
 *   php spark locking:remediate-mandatory --purchase-ref=PUR-... --allow-production --confirm="I_AUTHORIZE_PRODUCTION_CHANGE"
 */
class RemediateMandatoryLock extends BaseCommand
{
    protected $group       = 'Locking';
    protected $name        = 'locking:remediate-mandatory';
    protected $description = 'Dry-run / execute mandatory LOCK1000 remediation for one approved purchase.';
    protected $usage       = 'locking:remediate-mandatory --purchase=PUR-... [--dry-run] [--group=phase12b] [--confirm=...] [--allow-production]';
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
            } elseif (in_array($group, ['phase12a', 'phase12b', 'phase12d'], true)) {
                if ($confirm !== 'APPLY_STAGING') {
                    CLI::error('Staging apply requires --confirm=APPLY_STAGING');

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
