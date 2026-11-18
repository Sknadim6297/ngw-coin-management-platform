<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 12B — production-like staging E2E on coin_platform_phase12b only.
 */
class FinancialStagingE2E extends BaseCommand
{
    protected $group       = 'Finance';
    protected $name        = 'financial:staging-e2e';
    protected $description = 'Phase 12B staging end-to-end financial workflow test (isolated DB only).';
    protected $usage       = 'financial:staging-e2e [--seconds 180] [--fresh]';
    protected $options     = [
        '--seconds' => 'Sandbox authorization window seconds (default 180)',
        '--fresh'   => 'DROP and recreate coin_platform_phase12b before running',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('financial:staging-e2e', ['values' => ['seconds' => 'int-positive'], 'flags' => ['fresh']]) === null) {
            return 2;
        }

        $seconds = (int) (CLI::getOption('seconds') ?: ($params['seconds'] ?? 180));
        $fresh   = CLI::getOption('fresh') !== null || array_key_exists('fresh', $params);

        CLI::write('=== Phase 12B Staging E2E ===');
        CLI::write('Target: coin_platform_phase12b (never coin_platform)');

        try {
            $proof = service('stagingIsolationService')->proveIsolation();
            foreach ($proof['checks'] as $c) {
                CLI::write(($c['ok'] ? '[OK] ' : '[FAIL] ') . $c['name'] . ' — ' . $c['detail']);
            }
            if (! $proof['ok']) {
                CLI::error('Isolation proof failed. STOP.');

                return EXIT_ERROR;
            }

            if ($fresh) {
                $this->recreateDatabase('coin_platform_phase12b');
                CLI::write('Recreated coin_platform_phase12b', 'green');
            } else {
                $this->ensureDatabase('coin_platform_phase12b');
            }

            $report = service('phase12BStagingE2EService')->run($seconds);
            $jsonPath = WRITEPATH . 'phase12b-staging-test-results.json';
            file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->writeMarkdown($report);

            CLI::write('success=' . (! empty($report['success']) ? 'true' : 'false'));
            CLI::write('production_switches_ok=' . (! empty($report['production_switches_ok']) ? 'true' : 'false'));
            foreach ($report['classifications'] ?? [] as $letter => $status) {
                CLI::write($letter . '=' . $status);
            }
            if (! empty($report['errors'])) {
                foreach ($report['errors'] as $err) {
                    CLI::error(($err['class'] ?? 'Error') . ': ' . ($err['message'] ?? ''));
                }
            }
            CLI::write('JSON: ' . $jsonPath);
            CLI::write('Markdown: docs/phase12b-staging-e2e-report.md');

            return ! empty($report['success']) ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            CLI::write($e->getFile() . ':' . $e->getLine(), 'yellow');

            return EXIT_ERROR;
        } finally {
            config('Database')->defaultGroup = 'default';
            Database::connect('default', false);
            service('financialSandboxService')->closeWindow();
        }
    }

    private function ensureDatabase(string $name): void
    {
        $c = mysqli_connect('localhost', 'root', '');
        if ($c === false) {
            throw new \RuntimeException('MySQL connect failed for staging DB create.');
        }
        $c->query('CREATE DATABASE IF NOT EXISTS `' . $c->real_escape_string($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->close();
    }

    private function recreateDatabase(string $name): void
    {
        $c = mysqli_connect('localhost', 'root', '');
        if ($c === false) {
            throw new \RuntimeException('MySQL connect failed for staging DB recreate.');
        }
        $safe = $c->real_escape_string($name);
        $c->query('DROP DATABASE IF EXISTS `' . $safe . '`');
        $c->query('CREATE DATABASE `' . $safe . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->close();
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeMarkdown(array $report): void
    {
        $s = $report['sections'] ?? [];
        $c = $report['classifications'] ?? [];
        $lines = [];
        $lines[] = '# Phase 12B — Staging End-to-End Financial Test Report';
        $lines[] = '';
        $lines[] = 'Run ID: `' . ($report['run_id'] ?? '') . '`';
        $lines[] = 'Finished: ' . ($report['finished_at'] ?? '');
        $lines[] = 'Overall success: **' . (! empty($report['success']) ? 'PASS' : 'FAIL') . '**';
        $lines[] = '';
        $lines[] = '## Classification summary';
        $lines[] = '';
        $lines[] = '| Section | Status |';
        $lines[] = '|---|---|';
        foreach ($c as $letter => $status) {
            $lines[] = '| ' . $letter . ' | ' . $status . ' |';
        }
        $lines[] = '';

        $titles = [
            'A' => 'Environment Isolation',
            'B' => 'Test Member Registration',
            'C' => 'Member ID Sequence',
            'D' => 'Purchase and Activation',
            'E' => 'Mandatory LOCK1000',
            'F' => 'Mining Posting',
            'G' => 'L1/L2/L3 Commission Posting',
            'H' => 'Mining P2P',
            'I' => 'Commission Withdrawal',
            'J' => 'Coin Selling',
            'K' => 'Admin Panel',
            'L' => 'Member Panel',
            'M' => 'Support View',
            'N' => 'Failure and Recovery',
            'O' => 'Financial Reconciliation',
            'P' => 'Security Findings',
            'Q' => 'Bugs Fixed',
            'R' => 'Remaining Business Decisions',
            'S' => 'Production Activation Blockers',
            'T' => 'Kill-Switch States Before and After',
        ];
        $keyMap = [
            'A' => 'A_environment_isolation',
            'B' => 'B_registration',
            'C' => 'C_member_id',
            'D' => 'D_purchase',
            'E' => 'E_lock1000',
            'F' => 'F_mining',
            'G' => 'G_commission',
            'H' => 'H_p2p',
            'I' => 'I_withdrawal',
            'J' => 'J_selling',
            'K' => 'K_admin_panel',
            'L' => 'L_member_panel',
            'M' => 'M_support_view',
            'N' => 'N_failure',
            'O' => 'O_reconcile',
            'P' => 'P_security',
            'Q' => 'Q_bugs_fixed',
            'R' => 'R_business_decisions',
            'S' => 'S_activation_blockers',
            'T' => 'T_kill_switches',
        ];

        foreach ($titles as $letter => $title) {
            $lines[] = '## ' . $letter . '. ' . $title;
            $lines[] = '';
            $lines[] = 'Status: **' . ($c[$letter] ?? 'NOT TESTED') . '**';
            $lines[] = '';
            $payload = $s[$keyMap[$letter]] ?? [];
            $lines[] = '```json';
            $lines[] = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            $lines[] = '```';
            $lines[] = '';
        }

        $lines[] = '## Final verdict answers';
        $lines[] = '';
        $lines[] = '1. Staging isolation verified? **' . (($c['A'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '2. Complete purchase-to-earning workflow pass? **' . ((($c['D'] ?? '') === 'PASS' && ($c['G'] ?? '') === 'PASS' && ($c['F'] ?? '') === 'PASS') ? 'YES' : 'NO') . '**';
        $lines[] = '3. Mining credits staging wallets? **' . (($c['F'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '4. Commission credits staging wallets? **' . (($c['G'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '5. P2P transfers posted mining correctly? **' . (($c['H'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '6. Withdrawal reservation + mock settlement? **' . (($c['I'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '7. Matured coin selling works? **' . (($c['J'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '8. Ledgers reconciled? **' . (($c['O'] ?? '') === 'PASS' ? 'YES' : 'NO') . '**';
        $lines[] = '9. Production financial switches still OFF? **' . (! empty($report['production_switches_ok']) ? 'YES' : 'NO') . '**';
        $lines[] = '10. Remaining before production activation: see sections R and S.';
        $lines[] = '';
        $lines[] = 'DO NOT DEPLOY. DO NOT ENABLE PRODUCTION FINANCIAL SWITCHES.';
        $lines[] = '';

        $dir = ROOTPATH . 'docs';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'phase12b-staging-e2e-report.md', implode("\n", $lines));
    }
}
