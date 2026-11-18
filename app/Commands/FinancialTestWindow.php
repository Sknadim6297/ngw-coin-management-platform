<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Throwable;

/**
 * Phase 12A — isolated 120-second financial engine test window.
 *
 * Refuses production / coin_platform. Uses coin_platform_phase12a only.
 * Production kill-switches remain OFF; sandbox gate authorizes synthetic ops.
 */
class FinancialTestWindow extends BaseCommand
{
    protected $group       = 'Finance';
    protected $name        = 'financial:test-window';
    protected $description = 'Run a time-boxed financial engine test on coin_platform_phase12a only.';
    protected $usage       = 'financial:test-window [--seconds 120] [--fresh]';
    protected $options     = [
        '--seconds' => 'Authorization window seconds (1–300, default 120)',
        '--fresh'   => 'DROP and recreate coin_platform_phase12a before running',
    ];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('financial:test-window', ['values' => ['seconds' => 'int-positive'], 'flags' => ['fresh']]) === null) {
            return 2;
        }

        $seconds = (int) (CLI::getOption('seconds') ?: ($params['seconds'] ?? 120));
        $fresh   = CLI::getOption('fresh') !== null || array_key_exists('fresh', $params);

        $sandbox = service('financialSandboxService');
        $info    = $sandbox->inspectConnection('phase12a');

        CLI::write('=== Phase 12A Financial Test Window ===');
        CLI::write('Target DB: ' . $info['database'] . ' @ ' . $info['hostname']);
        CLI::write('Forbidden live DB coin_platform will not be used.');

        if ($info['is_forbidden'] || ! $info['is_allowed']) {
            CLI::error('Refused: target database is not allowlisted.');

            return EXIT_ERROR;
        }

        try {
            if ($fresh) {
                $this->recreateDatabase((string) $info['database']);
                CLI::write('Recreated database ' . $info['database'], 'green');
            } else {
                $this->ensureDatabaseExists((string) $info['database']);
            }

            $report = service('financialTestWindowService')->run($seconds);
            $path   = WRITEPATH . 'phase12a-test-window-report.json';
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->writeMarkdownReport($report);

            CLI::write('success=' . ($report['success'] ? 'true' : 'false'));
            CLI::write('production_switches_ok=' . ($report['production_switches_ok'] ? 'true' : 'false'));
            CLI::write('JSON report: ' . $path);
            CLI::write('Markdown: docs/phase12a-financial-generation-test-report.md');

            if (! empty($report['errors'])) {
                foreach ($report['errors'] as $err) {
                    CLI::error(($err['class'] ?? 'Error') . ': ' . ($err['message'] ?? ''));
                    CLI::write(($err['file'] ?? '') . ':' . ($err['line'] ?? ''), 'yellow');
                }
            }

            return ! empty($report['success']) ? EXIT_SUCCESS : EXIT_ERROR;
        } catch (Throwable $e) {
            CLI::error($e->getMessage());
            CLI::write($e->getFile() . ':' . $e->getLine(), 'yellow');

            return EXIT_ERROR;
        } finally {
            // Restore default group so CLI process does not leave spark on phase12a.
            config('Database')->defaultGroup = 'default';
            Database::connect('default', false);
            service('financialSandboxService')->closeWindow();
        }
    }

    private function ensureDatabaseExists(string $name): void
    {
        $c = mysqli_connect('localhost', 'root', '');
        if ($c === false) {
            throw new \RuntimeException('Cannot connect to MySQL to ensure sandbox DB.');
        }
        $c->query('CREATE DATABASE IF NOT EXISTS `' . $c->real_escape_string($name) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->close();
    }

    private function recreateDatabase(string $name): void
    {
        $c = mysqli_connect('localhost', 'root', '');
        if ($c === false) {
            throw new \RuntimeException('Cannot connect to MySQL to recreate sandbox DB.');
        }
        $safe = $c->real_escape_string($name);
        $c->query('DROP DATABASE IF EXISTS `' . $safe . '`');
        $c->query('CREATE DATABASE `' . $safe . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        $c->close();
    }

    /**
     * @param array<string, mixed> $report
     */
    private function writeMarkdownReport(array $report): void
    {
        $steps = $report['steps'] ?? [];
        $comm  = $steps['commission'] ?? [];
        $mine  = $steps['mining'] ?? [];
        $p2p   = $steps['p2p'] ?? [];
        $wd    = $steps['withdrawal'] ?? [];
        $sell  = $steps['selling'] ?? [];
        $recon = $report['reconciliation'] ?? [];
        $env   = $report['environment'] ?? [];

        $lines = [];
        $lines[] = '# Phase 12A — Financial Generation Test Report';
        $lines[] = '';
        $lines[] = 'Generated: ' . ($report['finished_at'] ?? date('c'));
        $lines[] = '';
        $lines[] = '## 1. Environment and database isolation';
        $lines[] = '';
        $lines[] = '- Live/default app DB inspected earlier: `coin_platform` (contains real member NGW000001) — **NOT USED**';
        $lines[] = '- Isolated test DB: `' . ($env['database'] ?? 'coin_platform_phase12a') . '` @ `' . ($env['hostname'] ?? '') . '`';
        $lines[] = '- Sandbox window seconds: ' . ($report['seconds_authorized'] ?? 120);
        $lines[] = '- Window closed after run: ' . (! empty($report['window_closed']) ? 'yes' : 'no');
        $lines[] = '';
        $lines[] = '## 2. Audit findings fixed or still pending';
        $lines[] = '';
        foreach ($report['audit_findings'] ?? [] as $f) {
            $lines[] = '- **' . ($f['status'] ?? '') . '**: ' . ($f['finding'] ?? '');
            $lines[] = '  - Proposal: ' . ($f['proposal'] ?? '');
        }
        $lines[] = '';
        $lines[] = '## 3–4. Mining calculation / wallet credit';
        $lines[] = '';
        $lines[] = '- Assumptions: `' . json_encode($mine['assumptions'] ?? [], JSON_UNESCAPED_SLASHES) . '`';
        $lines[] = '- Business date: `' . ($mine['business_date'] ?? '') . '`';
        $lines[] = '- Buyer owned (includes locked): `' . ($mine['buyer_owned'] ?? '') . '` locked=`' . ($mine['buyer_locked'] ?? '') . '`';
        $lines[] = '- Expected buyer credit: `' . ($mine['expected_buyer'] ?? '') . '`';
        $lines[] = '- Actual buyer posted: `' . ($mine['actual_buyer_posted'] ?? '') . '`';
        $lines[] = '- Math OK: ' . (! empty($mine['math_ok']) ? 'YES' : 'NO');
        $lines[] = '- Idempotent second run OK: ' . (! empty($mine['idempotent_ok']) ? 'YES' : 'NO');
        $lines[] = '';
        $lines[] = '## 5–6. L1/L2/L3 commission / wallet credit';
        $lines[] = '';
        $lines[] = '- Expected: L1=10, L2=5, L3=3, total=18 USDT on 100 USDT base';
        $lines[] = '- Actual by level: `' . json_encode($comm['actual_by_level'] ?? [], JSON_UNESCAPED_SLASHES) . '`';
        $lines[] = '- Total posted: `' . ($comm['total_posted'] ?? '') . '`';
        $lines[] = '- Math OK: ' . (! empty($comm['math_ok']) ? 'YES' : 'NO');
        $lines[] = '- Purchase lock created: ' . (! empty($comm['lock_ok']) ? 'YES (`' . ($comm['lock_ref'] ?? '') . '`)' : 'NO');
        $lines[] = '';
        $lines[] = '## 7. Duplicate generation results';
        $lines[] = '';
        $lines[] = '- Commission second pass new posts: `' . ($comm['second_new_posts'] ?? '') . '` duplicates=`' . ($comm['second_duplicates'] ?? '') . '`';
        $lines[] = '- Mining second pass credited: `' . ($mine['second_credited'] ?? '') . '` duplicates=`' . ($mine['second_duplicates'] ?? '') . '`';
        $lines[] = '- Commission idempotent OK: ' . (! empty($comm['idempotent_ok']) ? 'YES' : 'NO');
        $lines[] = '- Mining idempotent OK: ' . (! empty($mine['idempotent_ok']) ? 'YES' : 'NO');
        $lines[] = '';
        $lines[] = '## 8. P2P transfer results';
        $lines[] = '';
        $lines[] = '- Transfer ref: `' . ($p2p['transfer_ref'] ?? '') . '`';
        $lines[] = '- Debit OK: ' . (! empty($p2p['debit_ok']) ? 'YES' : 'NO');
        $lines[] = '- Credit OK: ' . (! empty($p2p['credit_ok']) ? 'YES' : 'NO');
        $lines[] = '- Duplicate blocked: ' . (! empty($p2p['duplicate_blocked']) ? 'YES' : 'NO');
        $lines[] = '- Reconcile mismatches: `' . ($p2p['reconcile_mismatches'] ?? '') . '`';
        $lines[] = '';
        $lines[] = '## 9. Withdrawal workflow results';
        $lines[] = '';
        $lines[] = '- Submit: `' . ($wd['submit_status'] ?? '') . '`';
        $lines[] = '- Reject/release: `' . ($wd['reject_status'] ?? '') . '`';
        $lines[] = '- Approve: `' . ($wd['approve_status'] ?? '') . '`';
        $lines[] = '- Evidence recorded: `' . ($wd['evidence_ref'] ?? '') . '`';
        $lines[] = '- Real USDT sent: **no**';
        $lines[] = '- Live PAID marking: skipped by design';
        $lines[] = '';
        $lines[] = '## 10. Coin selling results';
        $lines[] = '';
        $lines[] = '- Sell ref/status: `' . ($sell['sell_ref'] ?? '') . '` / `' . ($sell['status'] ?? '') . '`';
        $lines[] = '- Ownership debit OK: ' . (! empty($sell['debit_ok']) ? 'YES' : 'NO');
        $lines[] = '- Duplicate blocked: ' . (! empty($sell['duplicate_blocked']) ? 'YES' : 'NO');
        $lines[] = '- Real member coins: **no** (synthetic SYN* only)';
        $lines[] = '';
        $lines[] = '## 11. Ledger reconciliation';
        $lines[] = '';
        $lines[] = '```json';
        $lines[] = json_encode($recon, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '## 12. Errors';
        $lines[] = '';
        if (empty($report['errors'])) {
            $lines[] = '- None';
        } else {
            foreach ($report['errors'] as $err) {
                $lines[] = '- `' . ($err['class'] ?? '') . '`: ' . ($err['message'] ?? '');
                $lines[] = '  - ' . ($err['file'] ?? '') . ':' . ($err['line'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = '## 13. Production blockers';
        $lines[] = '';
        $lines[] = '- Mining/commission/P2P/withdrawal/selling production kill-switches must remain OFF';
        $lines[] = '- Live DB historical missing LOCK1000 for PUR-10D7174B7FF… still needs controlled remediation approval';
        $lines[] = '- Mining formula + commission plan not production-approved';
        $lines[] = '- Member ID start NGW1234 vs NGW981234 awaiting business confirmation';
        $lines[] = '- Blockchain verifier / SMTP / HTTPS production hardening incomplete';
        $lines[] = '';
        $lines[] = '## 14. Financial switch states before / after';
        $lines[] = '';
        $lines[] = '### Before';
        $lines[] = '```json';
        $lines[] = json_encode($report['switches_before'] ?? [], JSON_PRETTY_PRINT);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '### After';
        $lines[] = '```json';
        $lines[] = json_encode($report['switches_after'] ?? [], JSON_PRETTY_PRINT);
        $lines[] = '```';
        $lines[] = '';
        $lines[] = 'Production switches remain OFF: ' . (! empty($report['production_switches_ok']) ? '**YES**' : '**NO**');
        $lines[] = '';
        $lines[] = '## 15. Exact next steps';
        $lines[] = '';
        $lines[] = '1. Review this report and JSON artifact under `writable/phase12a-test-window-report.json`';
        $lines[] = '2. Decide Member ID policy (NGW981234 vs NGW1234) explicitly';
        $lines[] = '3. Approve controlled remediation for live missing LOCK1000 (do not silent-fix)';
        $lines[] = '4. Approve mining formula + seed production commission plan only after business sign-off';
        $lines[] = '5. Keep all production kill-switches OFF until a separate activation phase';
        $lines[] = '';
        $lines[] = '## Classification';
        $lines[] = '';
        $lines[] = '| Item | Status |';
        $lines[] = '|---|---|';
        $lines[] = '| Executable sandbox posting code | Implemented |';
        $lines[] = '| Tested successfully in isolation | ' . (! empty($report['success']) ? 'YES' : 'NO / see errors') . ' |';
        $lines[] = '| Production approved | NO |';
        $lines[] = '| Production enabled | NO |';
        $lines[] = '';
        $lines[] = 'Overall run success: **' . (! empty($report['success']) ? 'PASS' : 'FAIL') . '**';
        $lines[] = '';

        $dir = ROOTPATH . 'docs';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'phase12a-financial-generation-test-report.md', implode("\n", $lines));
    }
}
