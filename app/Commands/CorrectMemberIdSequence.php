<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\MemberIdSequenceCorrectionService;
use App\Services\ProductionSafetyGate;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 12D — Member ID sequence correction (NGW1234 / pad 4).
 *
 * Staging:
 *   php spark members:correct-sequence --db-group=phase12b --dry-run
 *   php spark members:correct-sequence --db-group=phase12b --confirm=APPLY_STAGING
 *
 * Production (requires separate explicit authorization):
 *   php spark members:correct-sequence --allow-production --confirm="I_AUTHORIZE_PRODUCTION_CHANGE"
 */
class CorrectMemberIdSequence extends BaseCommand
{
    protected $group       = 'Members';
    protected $name        = 'members:correct-sequence';
    protected $description = 'Preview/apply Member ID sequence correction (NGW1234+). Never renames existing IDs.';
    protected $usage       = 'members:correct-sequence [--dry-run] [--group phase12b] [--start 1234] [--padding 4] [--confirm ...] [--allow-production]';
    protected $options     = [
        '--dry-run'          => 'Preview only (default if --confirm omitted)',
        '--group'            => 'Database group (default|phase12b). Prefer phase12b.',
        '--start'            => 'Target start number (default 1234)',
        '--padding'          => 'Target padding (default 4)',
        '--confirm'          => 'APPLY_STAGING for staging apply, or I_AUTHORIZE_PRODUCTION_CHANGE for live',
        '--allow-production' => 'Required together with production confirm phrase',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('members:correct-sequence', ['values' => ['group' => ['default', 'phase12a', 'phase12b', 'phase12d', 'phase12f', 'phase13a', 'phase13b', 'phase13c'], 'start' => 'int-positive', 'padding' => 'int-positive', 'confirm' => null], 'flags' => ['dry-run', 'allow-production']]) === null) {
            return 2;
        }

        $group   = (string) (CLI::getOption('group') ?: ($params['group'] ?? 'default'));
        $start   = (int) (CLI::getOption('start') ?: MemberIdSequenceCorrectionService::TARGET_START);
        $padding = (int) (CLI::getOption('padding') ?: MemberIdSequenceCorrectionService::TARGET_PADDING);
        $confirm = CLI::getOption('confirm');
        $confirm = $confirm === true ? '' : ($confirm !== null ? (string) $confirm : null);
        $allowProd = (bool) CLI::getOption('allow-production');
        $dryRunFlag = CLI::getOption('dry-run') !== null;

        $dryRun = $dryRunFlag || $confirm === null || $confirm === '';

        CLI::write('=== members:correct-sequence ===', 'yellow');
        CLI::write('db-group=' . $group . ' dry_run=' . ($dryRun ? 'yes' : 'no'));

        $previous = config('Database')->defaultGroup;
        try {
            if ($group !== 'default') {
                config('Database')->defaultGroup = $group;
                Database::connect($group, false);
                db_connect($group, false);
            }

            $svc = service('memberIdSequenceCorrectionService');

            if ($dryRun) {
                $out = $svc->apply(true, false, null, $group, $start, $padding, 0);
            } else {
                if (in_array($group, ['phase12a', 'phase12b', 'phase12d', 'phase12f'], true)) {
                    if ($confirm !== 'APPLY_STAGING') {
                        CLI::error('Staging apply requires --confirm=APPLY_STAGING');

                        return EXIT_ERROR;
                    }
                    $out = $svc->apply(false, false, null, $group, $start, $padding, 0);
                } else {
                    $out = $svc->apply(false, $allowProd, $confirm, $group, $start, $padding, 0);
                }
            }

            CLI::write(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            CLI::write($dryRun ? 'DRY-RUN only — no sequence write.' : 'Sequence updated on ' . ($out['database'] ?? ''), 'green');
            CLI::write('Remember: update .env platform.memberIdStartNumber / memberIdPadding separately for production.');
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
