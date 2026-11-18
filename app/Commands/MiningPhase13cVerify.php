<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\Phase13CVerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Phase 13C — production-snapshot rehearsal / LOCK1000 audit / migration + scheduler readiness.
 * Mutates ONLY coin_platform_phase13c_snapshot and coin_platform_phase13c (marker-guarded). Live DB: SELECT-only account.
 */
class MiningPhase13cVerify extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:phase13c-verify';
    protected $description = 'Phase 13C snapshot rehearsal (isolated DBs only; live DB read-only fingerprint).';
    protected $usage       = 'mining:phase13c-verify';
    protected $options     = [];

    public function run(array $params)
    {
        if (CliArgs::guard($this->name, ['values' => [], 'flags' => []]) === null) {
            return 2;
        }

        CLI::write('=== Phase 13C snapshot rehearsal ===', 'yellow');
        CLI::write('Mutations: ' . Phase13CVerificationService::SNAP_DB . ' and ' . Phase13CVerificationService::CLONE_DB . ' only. Live ' . Phase13CVerificationService::LIVE_DB . ': SELECT-only account.');

        try {
            $report = (new Phase13CVerificationService())->run();
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $json = WRITEPATH . 'phase13c-validation-results.json';
        file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

        foreach ($report['checks'] as $c) {
            CLI::write(($c['ok'] ? '[PASS] ' : '[FAIL] ') . $c['id'] . ' — ' . $c['detail'], $c['ok'] ? 'green' : 'red');
        }
        if (isset($report['fatal'])) {
            CLI::error('FATAL: ' . $report['fatal']);
        }
        CLI::write('passed=' . $report['pass'] . ' failed=' . $report['fail'] . ' duration=' . $report['duration_s'] . 's');
        CLI::write('JSON: ' . $json);

        return $report['fail'] === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
