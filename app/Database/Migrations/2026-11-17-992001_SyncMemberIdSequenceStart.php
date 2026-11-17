<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use Config\Platform;

/**
 * Align member_id_sequences with NGW981234+ policy without rewriting existing IDs.
 */
class SyncMemberIdSequenceStart extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('member_id_sequences')) {
            return;
        }

        /** @var Platform $config */
        $config  = config('Platform');
        $prefix  = strtoupper(trim((string) $config->memberIdPrefix)) ?: 'NGW';
        $start   = max(1, (int) $config->memberIdStartNumber);
        $floor   = $start - 1;
        $now     = date('Y-m-d H:i:s');

        $maxExisting = 0;
        if ($this->db->tableExists('member_profiles')) {
            $rows = $this->db->table('member_profiles')
                ->select('member_id')
                ->like('member_id', $prefix, 'after')
                ->get()
                ->getResultArray();
            $quoted = preg_quote($prefix, '/');
            foreach ($rows as $row) {
                $id = (string) ($row['member_id'] ?? '');
                if (preg_match('/^' . $quoted . '(\d+)$/', $id, $m) === 1) {
                    $n = (int) $m[1];
                    if ($n > $maxExisting) {
                        $maxExisting = $n;
                    }
                }
            }
        }

        $target = max($floor, $maxExisting);

        $row = $this->db->table('member_id_sequences')->where('prefix', $prefix)->get()->getRowArray();
        if ($row === null) {
            $this->db->table('member_id_sequences')->insert([
                'prefix'      => $prefix,
                'last_number' => $target,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

            return;
        }

        $merged = max((int) $row['last_number'], $target);
        $this->db->table('member_id_sequences')->where('prefix', $prefix)->update([
            'last_number' => $merged,
            'updated_at'  => $now,
        ]);
    }

    public function down(): void
    {
        // Non-destructive: leave sequence at the synced high-water mark.
    }
}
