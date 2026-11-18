<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\Phase14VerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 14 — Execute Phases 2–7 isolated staging backup/restore, dual-control policy rehearsal,
 * mining/commission recovery, and automation checks without touching live ng_database or coin_platform.
 */
class Phase14Verify extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'phase14:verify';
    protected $description = 'Run Phase 14 isolated backup, restore, recovery rehearsal, and automation checks.';
    protected $usage       = 'phase14:verify';

    public function run(array $params)
    {
        if (CliArgs::guard($this->name, []) === null) {
            return 2;
        }

        $report = (new Phase14VerificationService())->run();
        $out    = WRITEPATH . 'phase14-validation-results.json';
        file_put_contents($out, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($report['checks'] as $c) {
            CLI::write(sprintf('[%s] %s — %s', $c['ok'] ? 'PASS' : 'FAIL', $c['id'], $c['detail']), $c['ok'] ? 'green' : 'red');
        }
        CLI::write(sprintf('Summary: pass=%d fail=%d duration=%ss -> %s', $report['pass'], $report['fail'], $report['duration_s'], $out), $report['fail'] === 0 ? 'green' : 'red');

        return $report['fail'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
