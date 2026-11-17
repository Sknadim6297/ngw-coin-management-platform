<?php

declare(strict_types=1);

namespace App\Services;

use Config\Platform;
use RuntimeException;

/**
 * Concurrency-safe Member ID generator.
 *
 * Allocates PREFIX + zero-padded sequential numbers using a locked
 * member_id_sequences row. The next number is always:
 *   max(sequence.last_number, highest existing PREFIX numeric ID, startNumber - 1) + 1
 *
 * Production default: NGW981234, NGW981235, …
 * Existing Member IDs are never rewritten.
 */
class MemberIdGenerator
{
    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    public function previewNext(): string
    {
        $config  = $this->config ?? config('Platform');
        $prefix  = $this->prefix($config);
        $padding = $this->padding($config);
        $db      = db_connect();

        $row = $db->table('member_id_sequences')
            ->where('prefix', $prefix)
            ->get()
            ->getRowArray();

        $seqLast      = $row === null ? 0 : (int) $row['last_number'];
        $maxExisting  = $this->maxExistingNumber($db, $prefix);
        $floor        = max($seqLast, $maxExisting, $this->startNumber($config) - 1);
        $next         = $floor + 1;

        return $this->format($prefix, $next, $padding);
    }

    public function generate(): string
    {
        $config  = $this->config ?? config('Platform');
        $db      = db_connect();
        $prefix  = $this->prefix($config);
        $padding = $this->padding($config);
        $start   = $this->startNumber($config);

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

            $now = date('Y-m-d H:i:s');

            if ($row === null) {
                $db->table('member_id_sequences')->insert([
                    'prefix'      => $prefix,
                    'last_number' => 0,
                    'created_at'  => $now,
                    'updated_at'  => $now,
                ]);
                $row = $db->query(
                    'SELECT id, last_number FROM member_id_sequences WHERE prefix = ? FOR UPDATE',
                    [$prefix]
                )->getRowArray();
                if ($row === null) {
                    throw new RuntimeException('Unable to initialize Member ID sequence.');
                }
            }

            $maxExisting = $this->maxExistingNumber($db, $prefix);
            $floor       = max((int) $row['last_number'], $maxExisting, $start - 1);
            $number      = $floor + 1;

            $db->table('member_id_sequences')
                ->where('id', $row['id'])
                ->update([
                    'last_number' => $number,
                    'updated_at'  => $now,
                ]);

            $memberId = $this->format($prefix, $number, $padding);

            // Last-line guard against a concurrent manual insert colliding with the allocated ID.
            $collision = $db->table('member_profiles')
                ->where('member_id', $memberId)
                ->countAllResults();
            if ($collision > 0) {
                throw new RuntimeException('Member ID collision detected; retry registration.');
            }

            if ($startedHere) {
                if ($db->transStatus() === false) {
                    $db->transRollback();
                    throw new RuntimeException('Unable to generate Member ID.');
                }
                $db->transCommit();
            }

            return $memberId;
        } catch (\Throwable $e) {
            if ($startedHere && $db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Highest numeric suffix already stored for this prefix (0 when none).
     */
    public function maxExistingNumber(?object $db = null, ?string $prefix = null): int
    {
        $config = $this->config ?? config('Platform');
        $db ??= db_connect();
        $prefix ??= $this->prefix($config);

        $rows = $db->table('member_profiles')
            ->select('member_id')
            ->like('member_id', $prefix, 'after')
            ->get()
            ->getResultArray();

        $max    = 0;
        $quoted = preg_quote($prefix, '/');
        foreach ($rows as $row) {
            $id = (string) ($row['member_id'] ?? '');
            if (preg_match('/^' . $quoted . '(\d+)$/', $id, $m) === 1) {
                $n = (int) $m[1];
                if ($n > $max) {
                    $max = $n;
                }
            }
        }

        return $max;
    }

    private function prefix(Platform $config): string
    {
        $prefix = strtoupper(trim((string) $config->memberIdPrefix));

        return $prefix !== '' ? $prefix : 'NGW';
    }

    private function padding(Platform $config): int
    {
        return max(1, (int) $config->memberIdPadding);
    }

    private function startNumber(Platform $config): int
    {
        return max(1, (int) $config->memberIdStartNumber);
    }

    private function format(string $prefix, int $number, int $padding): string
    {
        return $prefix . str_pad((string) $number, $padding, '0', STR_PAD_LEFT);
    }
}
