<?php

declare(strict_types=1);

namespace App\Services;

use Config\Platform;
use RuntimeException;

/**
 * Concurrency-safe Member ID generator using a locked sequence row.
 */
class MemberIdGenerator
{
    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    public function previewNext(): string
    {
        $config = $this->config ?? config('Platform');
        $db     = db_connect();

        $row = $db->table('member_id_sequences')
            ->where('prefix', $config->memberIdPrefix)
            ->get()
            ->getRowArray();

        $next = $row === null ? 1 : ((int) $row['last_number'] + 1);

        return $this->format($config->memberIdPrefix, $next, $config->memberIdPadding);
    }

    public function generate(): string
    {
        $config = $this->config ?? config('Platform');
        $db     = db_connect();
        $prefix = $config->memberIdPrefix;

        // Avoid nested commits when called inside RegistrationService transaction.
        $startedHere = $db->transDepth === 0;
        if ($startedHere) {
            $db->transBegin();
        }

        try {
            $row = $db->query(
                'SELECT id, last_number FROM member_id_sequences WHERE prefix = ? FOR UPDATE',
                [$prefix]
            )->getRowArray();

            if ($row === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => $prefix,
                    'last_number' => 1,
                    'created_at'  => date('Y-m-d H:i:s'),
                    'updated_at'  => date('Y-m-d H:i:s'),
                ]);
                $number = 1;
            } else {
                $number = (int) $row['last_number'] + 1;
                $db->table('member_id_sequences')
                    ->where('id', $row['id'])
                    ->update([
                        'last_number' => $number,
                        'updated_at'  => date('Y-m-d H:i:s'),
                    ]);
            }

            if ($startedHere) {
                if ($db->transStatus() === false) {
                    $db->transRollback();
                    throw new RuntimeException('Unable to generate Member ID.');
                }
                $db->transCommit();
            }

            return $this->format($prefix, $number, $config->memberIdPadding);
        } catch (\Throwable $e) {
            if ($startedHere && $db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    private function format(string $prefix, int $number, int $padding): string
    {
        return $prefix . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }
}
