<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Renames generated member IDs from an old prefix (default CPM) to NGW.
 * Root sponsor IDs are left unchanged.
 *
 * Usage:
 *   php spark members:migrate-prefix
 *   php spark members:migrate-prefix --from=CPM --to=NGW
 */
class MigrateMemberIdPrefix extends BaseCommand
{
    protected $group       = 'Members';
    protected $name        = 'members:migrate-prefix';
    protected $description = 'Migrate member ID prefix (e.g. CPM → NGW) across related tables.';
    protected $usage       = 'members:migrate-prefix [--from=CPM] [--to=NGW]';
    protected $options     = [
        '--from' => 'Old prefix (default: CPM)',
        '--to'   => 'New prefix (default: platform.memberIdPrefix / NGW)',
    ];

    public function run(array $params)
    {
        $from = strtoupper((string) ($params['from'] ?? CLI::getOption('from') ?? 'CPM'));
        $to   = strtoupper((string) ($params['to'] ?? CLI::getOption('to') ?? config('Platform')->memberIdPrefix ?: 'NGW'));

        if ($from === '' || $to === '' || $from === $to) {
            CLI::error('Provide distinct --from and --to prefixes.');

            return;
        }

        if (! preg_match('/^[A-Z]{2,8}$/', $from) || ! preg_match('/^[A-Z]{2,8}$/', $to)) {
            CLI::error('Prefixes must be 2–8 uppercase letters.');

            return;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $tables = [
                'member_profiles'                    => ['member_id'],
                'sponsor_relationships'              => ['sponsor_member_id'],
                'coin_purchase_requests'             => ['member_id'],
                'member_coin_balances'               => ['member_id'],
                'member_coin_ownership_transactions' => ['member_id'],
                'usdt_deposit_requests'              => ['member_id'],
                'member_coin_locks'                  => ['member_id'],
            ];

            $total = 0;
            foreach ($tables as $table => $columns) {
                if (! $db->tableExists($table)) {
                    continue;
                }
                foreach ($columns as $column) {
                    $sql = "UPDATE {$table}
                            SET {$column} = CONCAT(?, SUBSTRING({$column}, ?))
                            WHERE {$column} LIKE ?";
                    $db->query($sql, [$to, strlen($from) + 1, $from . '%']);
                    $affected = $db->affectedRows();
                    $total += max(0, $affected);
                    CLI::write("{$table}.{$column}: {$affected} row(s)");
                }
            }

            // Auth usernames that mirrored old member IDs.
            if ($db->tableExists('users')) {
                $db->query(
                    "UPDATE users
                     SET username = CONCAT(?, SUBSTRING(username, ?))
                     WHERE username LIKE ?",
                    [$to, strlen($from) + 1, $from . '%']
                );
                CLI::write('users.username: ' . $db->affectedRows() . ' row(s)');
            }

            // Sequence row: move counter to new prefix.
            $oldSeq = $db->table('member_id_sequences')->where('prefix', $from)->get()->getRowArray();
            $newSeq = $db->table('member_id_sequences')->where('prefix', $to)->get()->getRowArray();
            $last   = (int) ($oldSeq['last_number'] ?? 0);

            if ($newSeq === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => $to,
                    'last_number' => $last,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
                CLI::write("Created sequence {$to} at {$last}");
            } else {
                $merged = max($last, (int) $newSeq['last_number']);
                $db->table('member_id_sequences')->where('prefix', $to)->update([
                    'last_number' => $merged,
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
                CLI::write("Updated sequence {$to} to {$merged}");
            }

            if ($oldSeq !== null) {
                $db->table('member_id_sequences')->where('prefix', $from)->delete();
                CLI::write("Removed old sequence {$from}");
            }

            // Keep Platform config aligned if .env not reloaded yet.
            config('Platform')->memberIdPrefix = $to;

            if ($db->transStatus() === false) {
                $db->transRollback();
                CLI::error('Migration failed; rolled back.');

                return;
            }

            $db->transCommit();
            CLI::write("Done. Prefix {$from} → {$to}. Touched ~{$total} column updates.", 'green');
            CLI::write('New registrations will use IDs like ' . $to . '000001');
        } catch (\Throwable $e) {
            $db->transRollback();
            CLI::error($e->getMessage());
        }
    }
}
