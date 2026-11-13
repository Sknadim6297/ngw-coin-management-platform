<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Simple DB-backed concurrency lock for CLI monitors.
 */
class OpsJobLockService
{
    public function acquire(string $lockName, string $owner, int $ttlSeconds = 900): bool
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        $exp = date('Y-m-d H:i:s', time() + max(30, $ttlSeconds));

        $db->transStart();

        $row = $db->query(
            'SELECT * FROM ops_job_locks WHERE lock_name = ? FOR UPDATE',
            [$lockName]
        )->getRowArray();

        if ($row === null) {
            $db->table('ops_job_locks')->insert([
                'lock_name'  => $lockName,
                'locked_by'  => $owner,
                'locked_at'  => $now,
                'expires_at' => $exp,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $db->transComplete();

            return (bool) $db->transStatus();
        }

        $expired = $row['expires_at'] === null || strtotime((string) $row['expires_at']) <= time();
        if (! $expired && (string) $row['locked_by'] !== $owner) {
            $db->transComplete();

            return false;
        }

        $db->table('ops_job_locks')->where('id', (int) $row['id'])->update([
            'locked_by'  => $owner,
            'locked_at'  => $now,
            'expires_at' => $exp,
            'updated_at' => $now,
        ]);
        $db->transComplete();

        return (bool) $db->transStatus();
    }

    public function release(string $lockName, string $owner): void
    {
        db_connect()->table('ops_job_locks')
            ->where('lock_name', $lockName)
            ->groupStart()
                ->where('locked_by', $owner)
                ->orWhere('locked_by', null)
            ->groupEnd()
            ->update([
                'locked_by'  => null,
                'locked_at'  => null,
                'expires_at' => null,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
