<?php

declare(strict_types=1);

namespace App\Commands;

use App\Libraries\CliArgs;
use App\Services\Phase13C1VerificationService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Phase 13C.1 — verification of the owner-proposed mining policy package.
 * Mutates ONLY the disposable clone coin_platform_phase13c (marker-guarded). Live DB: SELECT-only account.
 */
class MiningPhase13c1Verify extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:phase13c1-verify';
    protected $description = 'Phase 13C.1 policy-proposal verification (isolated clone only; live DB read-only fingerprint).';
    protected $usage       = 'mining:phase13c1-verify';
    protected $options     = [];

    public function run(array $params)
    {
        if (CliArgs::guard($this->name, ['values' => [], 'flags' => []]) === null) {
            return 2;
        }

        CLI::write('=== Phase 13C.1 proposal verification ===', 'yellow');
        try {
            $report = (new Phase13C1VerificationService())->runProposalVerification();
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $json = WRITEPATH . 'phase13c1-validation-results.json';
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
