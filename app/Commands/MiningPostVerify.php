<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Phase 13A — end-to-end verification of `mining:post` on coin_platform_phase13a ONLY.
 * Recreates the isolated DB, seeds synthetic members, runs the real Spark command, and writes a report.
 */
class MiningPostVerify extends BaseCommand
{
    protected $group       = 'Mining';
    protected $name        = 'mining:post-verify';
    protected $description = 'Isolated E2E test of mining:post (coin_platform_phase13a only; live DB read-only fingerprint).';
    protected $usage       = 'mining:post-verify';
    protected $options     = [];

    public function run(array $params)
    {
        if (\App\Libraries\CliArgs::guard('mining:post-verify', []) === null) {
            return 2;
        }

        CLI::write('=== Phase 13A mining:post isolated verification ===', 'yellow');
        CLI::write('Target DB: coin_platform_phase13a (recreated). Live coin_platform is only fingerprinted read-only.');

        try {
            $report = (new \App\Services\MiningPostVerificationService())->run();
        } catch (Throwable $e) {
            CLI::error($e->getMessage());

            return EXIT_ERROR;
        }

        $json = WRITEPATH . 'phase13a-mining-post-report.json';
        file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->writeMarkdown($report);

        foreach ($report['checks'] as $c) {
            CLI::write(($c['ok'] ? '[PASS] ' : '[FAIL] ') . $c['name'] . ' — ' . $c['detail'], $c['ok'] ? 'green' : 'red');
        }
        CLI::write('passed=' . $report['passed'] . ' failed=' . $report['failed']);
        CLI::write('JSON: ' . $json);
        CLI::write('Markdown: docs/phase13a-mining-post-report.md');

        return $report['success'] ? EXIT_SUCCESS : EXIT_ERROR;
    }

    /**
     * @param array<string, mixed> $r
     */
    private function writeMarkdown(array $r): void
    {
        $L   = [];
        $L[] = '# Phase 13A — `mining:post` isolated test report';
        $L[] = '';
        $L[] = 'Generated: ' . ($r['finished_at'] ?? date('c')) . '  ';
        $L[] = 'Isolated DB: `' . ($r['database'] ?? '') . '` — **Overall: ' . (! empty($r['success']) ? 'PASS' : 'FAIL') . '** (' . ($r['passed'] ?? 0) . ' passed / ' . ($r['failed'] ?? 0) . ' failed)';
        $L[] = '';
        $L[] = '## 1. Setup';
        $L[] = '';
        $L[] = '- Business dates: yesterday=`' . ($r['business_dates']['yesterday'] ?? '') . '` today=`' . ($r['business_dates']['today'] ?? '') . '`';
        $L[] = '- Test-only approved monthly rate: **' . ($r['rate_percent'] ?? '') . '%** (dual control; created by admin, approved by a different approver; isolated DB only)';
        $L[] = '- Production kill-switches before: `' . json_encode($r['switches_before'] ?? []) . '`';
        $L[] = '- Production kill-switches after: `' . json_encode($r['switches_after'] ?? []) . '`';
        $L[] = '';
        $L[] = '## 2. Expected vs actual credits and wallet balances';
        $L[] = '';
        $L[] = '| Member | Kind | Owned | Locked | Available | D1 expected | D1 actual | D2 expected | D2 actual | Wallet balance | Ledger rows |';
        $L[] = '|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|';
        foreach ($r['comparison'] ?? [] as $row) {
            $L[] = '| ' . implode(' | ', [
                $row['member'], $row['kind'], $row['owned'], $row['locked'], $row['available'],
                $row['d1_expected'], $row['d1_actual'], $row['d2_expected'], $row['d2_actual'],
                $row['wallet_balance'], $row['ledger_rows'],
            ]) . ' |';
        }
        $t = $r['totals'] ?? [];
        $L[] = '| **Total** | | | | | **' . ($t['d1_expected'] ?? '') . '** | **' . ($t['d1_actual'] ?? '') . '** | **' . ($t['d2_expected'] ?? '') . '** | **' . ($t['d2_actual'] ?? '') . '** | **' . ($t['wallet_balances'] ?? '') . '** | |';
        $L[] = '';
        $L[] = '## 3. Scheduler executions (real `php spark mining:post`)';
        $L[] = '';
        $L[] = '| Run | Exit | Status | Credited | Duplicates | Attempts | Expected total | Posted total | Mismatches |';
        $L[] = '|---|---:|---|---:|---:|---:|---:|---:|---:|';
        foreach ($r['runs'] ?? [] as $name => $run) {
            $L[] = '| ' . $name . ' | ' . $run['exit'] . ' | ' . ($run['status'] ?? '') . ' | ' . ($run['credited'] ?? '') . ' | ' . ($run['duplicates'] ?? '') . ' | ' . ($run['attempts'] ?? '') . ' | ' . ($run['expected_total'] ?? '') . ' | ' . ($run['posted_total'] ?? '') . ' | ' . ($run['mismatches'] ?? '') . ' |';
        }
        $L[] = '';
        $L[] = '## 4. Safety scenarios';
        $L[] = '';
        $L[] = '| Scenario | Exit | Status / message |';
        $L[] = '|---|---:|---|';
        foreach ($r['scenarios'] ?? [] as $name => $s) {
            if (! is_array($s) || ! array_key_exists('exit', $s)) {
                continue;
            }
            $msg = str_replace(['|', "\n", "\r"], ['/', ' ', ' '], (string) ($s['status'] ?? '') . ' ' . (string) ($s['message'] ?? ''));
            $L[] = '| ' . $name . ' | ' . $s['exit'] . ' | ' . trim($msg) . ' |';
        }
        $L[] = '';
        $L[] = '## 5. Reconciliation';
        $L[] = '';
        $L[] = '```json';
        $L[] = json_encode($r['reconciliation'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $L[] = '```';
        $L[] = '';
        $L[] = '- Duplicate credit groups: `' . count($r['duplicate_credit_groups'] ?? []) . '`';
        $L[] = '- Ledger vs wallet mismatches: `' . count($r['ledger_vs_wallet']['mismatches'] ?? []) . '`';
        $L[] = '';
        $L[] = '## 6. Run history (`mining_daily_runs`, run_type=post)';
        $L[] = '';
        $L[] = '| Run ref | Date | Status | Members | Eligible | Expected | Posted |';
        $L[] = '|---|---|---|---:|---:|---:|---:|';
        foreach ($r['run_history'] ?? [] as $h) {
            $L[] = '| ' . $h['run_ref'] . ' | ' . $h['business_date'] . ' | ' . $h['status'] . ' | ' . $h['member_count'] . ' | ' . $h['eligible_count'] . ' | ' . $h['total_expected_amount'] . ' | ' . $h['total_posted_amount'] . ' |';
        }
        $L[] = '';
        $L[] = '## 7. Live DB untouched (read-only fingerprint)';
        $L[] = '';
        $L[] = '- Before: `' . json_encode($r['live_baseline_before'] ?? []) . '`';
        $L[] = '- After: `' . json_encode($r['live_baseline_after'] ?? []) . '`';
        $L[] = '';
        $L[] = '## 8. Precision finding (informational)';
        $L[] = '';
        $L[] = '```json';
        $L[] = json_encode($r['precision_note'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $L[] = '```';
        $L[] = '';
        $L[] = '## 9. All checks';
        $L[] = '';
        $L[] = '| Result | Check | Detail |';
        $L[] = '|---|---|---|';
        foreach ($r['checks'] ?? [] as $c) {
            $L[] = '| ' . ($c['ok'] ? 'PASS' : '**FAIL**') . ' | `' . $c['name'] . '` | ' . str_replace('|', '/', (string) $c['detail']) . ' |';
        }
        if (! empty($r['errors'])) {
            $L[] = '';
            $L[] = '## Errors';
            foreach ($r['errors'] as $e) {
                $L[] = '- `' . $e['class'] . '`: ' . $e['message'] . ' (' . $e['file'] . ':' . $e['line'] . ')';
            }
        }
        $L[] = '';

        file_put_contents(ROOTPATH . 'docs' . DIRECTORY_SEPARATOR . 'phase13a-mining-post-report.md', implode("\n", $L));
    }
}
