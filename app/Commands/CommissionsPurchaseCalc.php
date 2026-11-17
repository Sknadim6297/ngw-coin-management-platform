<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Dry-run / persist purchase commission calculation. Never posts wallet credits.
 */
class CommissionsPurchaseCalc extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:purchase-calc';
    protected $description = 'Calculate purchase commissions (dry-run by default; no wallet credits).';
    protected $usage       = 'commissions:purchase-calc --purchase=REF [--persist]';
    protected $options     = [
        '--purchase' => 'Purchase reference (required)',
        '--persist'  => 'Persist calculation rows (still no wallet credits)',
    ];

    public function run(array $params)
    {
        if (CLI::getOption('post') || CLI::getOption('credit') || CLI::getOption('execute')) {
            CLI::error('Posting flags are not supported.');

            return EXIT_ERROR;
        }

        $purchase = CLI::getOption('purchase');
        if ($purchase === null || $purchase === true || $purchase === '') {
            CLI::error('--purchase=REF is required');

            return EXIT_ERROR;
        }
        $persist = (bool) CLI::getOption('persist');

        $result = service('commissionPurchaseEngineService')->calculateForPurchase((string) $purchase, $persist, null);
        CLI::write($result['posting_status'], 'red');
        CLI::write('assumptions=' . json_encode($result['assumptions']['unconfirmed'] ?? []));
        foreach ($result['lines'] as $line) {
            CLI::write(sprintf(
                'L%d beneficiary=%s rate=%s expected=%s status=%s',
                (int) $line['sponsor_level'],
                (string) ($line['beneficiary_member_id'] ?? '—'),
                (string) ($line['rate_percent'] ?? '—'),
                (string) $line['expected_amount_usdt'],
                (string) $line['status']
            ));
        }
        CLI::write('total_expected=' . $result['totals']['expected_amount_usdt']);
        CLI::write('No wallets were credited.', 'green');

        return EXIT_SUCCESS;
    }
}
