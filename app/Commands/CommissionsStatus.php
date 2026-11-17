<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CommissionsStatus extends BaseCommand
{
    protected $group       = 'Commissions';
    protected $name        = 'commissions:status';
    protected $description = 'Show commission kill-switches, active plan, and draft assumptions.';
    protected $usage       = 'commissions:status';

    public function run(array $params)
    {
        $status = service('commissionPurchaseEngineService')->status();
        foreach ($status as $k => $v) {
            if (is_array($v)) {
                $v = json_encode($v, JSON_UNESCAPED_SLASHES);
            } elseif (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            CLI::write($k . '=' . (string) $v);
        }

        return EXIT_SUCCESS;
    }
}
