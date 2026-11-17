<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Phase 10C: print Coin Plan compliance matrix (read-only).
 */
class CoinPlanAudit extends BaseCommand
{
    protected $group       = 'Audit';
    protected $name        = 'coinplan:audit';
    protected $description = 'Print client Coin Plan vs implementation matrix (read-only).';
    protected $usage       = 'coinplan:audit';

    public function run(array $params)
    {
        $svc = service('coinPlanAuditService');
        CLI::write('=== NGW Coin Plan Audit (read-only) ===', 'yellow');

        foreach ($svc->ruleMatrix() as $row) {
            CLI::newLine();
            CLI::write('[' . $row['status'] . '] ' . $row['rule'], $row['status'] === 'MATCHED' ? 'green' : 'yellow');
            CLI::write('  Requirement: ' . $row['requirement']);
            CLI::write('  Current:     ' . $row['implementation']);
            CLI::write('  Missing:     ' . $row['missing']);
            CLI::write('  Evidence:    ' . $row['evidence']);
        }

        CLI::newLine();
        CLI::write('=== Runtime flags ===', 'yellow');
        foreach ($svc->runtimeFlags() as $k => $v) {
            if (is_array($v)) {
                $v = $v === [] || $v === null ? 'null' : json_encode($v, JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            CLI::write($k . '=' . (string) $v);
        }

        CLI::newLine();
        CLI::write('Illustrative commission on 100 USDT base:', 'yellow');
        foreach ($svc->illustrativeCommissionBreakdown('100.00000000') as $line) {
            CLI::write('  L' . $line['level'] . ' @ ' . $line['percent'] . '% => ' . $line['expected'] . ' USDT');
        }

        CLI::newLine();
        CLI::write('FINAL: Software does NOT match ALL 8 client Coin Plan rules while mining/commission posting/P2P/selling/auto-lock remain incomplete or disabled.', 'red');

        return EXIT_SUCCESS;
    }
}
