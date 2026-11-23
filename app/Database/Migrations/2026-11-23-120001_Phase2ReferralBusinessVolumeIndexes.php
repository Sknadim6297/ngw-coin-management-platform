<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Phase 2 — additive indexes for level-wise referral business volume queries.
 */
class Phase2ReferralBusinessVolumeIndexes extends Migration
{
    public function up(): void
    {
        if (! $this->db->tableExists('purchase_sponsor_snapshots')) {
            return;
        }

        $keys = $this->db->getIndexData('purchase_sponsor_snapshots');
        $names = [];
        foreach ($keys as $key) {
            $names[] = strtolower((string) ($key->name ?? ''));
        }

        if (! in_array('pss_sponsor_level_missing', $names, true)) {
            $this->db->query(
                'CREATE INDEX pss_sponsor_level_missing ON purchase_sponsor_snapshots (sponsor_user_id, sponsor_level, is_missing)'
            );
        }
        if (! in_array('pss_sponsor_purchaser', $names, true)) {
            $this->db->query(
                'CREATE INDEX pss_sponsor_purchaser ON purchase_sponsor_snapshots (sponsor_user_id, purchaser_user_id, sponsor_level)'
            );
        }

        if ($this->db->tableExists('coin_purchase_requests')) {
            $pKeys = $this->db->getIndexData('coin_purchase_requests');
            $pNames = [];
            foreach ($pKeys as $key) {
                $pNames[] = strtolower((string) ($key->name ?? ''));
            }
            if (! in_array('cpr_status_payment_reviewed', $pNames, true)) {
                $this->db->query(
                    'CREATE INDEX cpr_status_payment_reviewed ON coin_purchase_requests (status, payment_status, reviewed_at)'
                );
            }
        }
    }

    public function down(): void
    {
        if ($this->db->tableExists('purchase_sponsor_snapshots')) {
            try {
                $this->db->query('DROP INDEX pss_sponsor_level_missing ON purchase_sponsor_snapshots');
            } catch (\Throwable) {
            }
            try {
                $this->db->query('DROP INDEX pss_sponsor_purchaser ON purchase_sponsor_snapshots');
            } catch (\Throwable) {
            }
        }
        if ($this->db->tableExists('coin_purchase_requests')) {
            try {
                $this->db->query('DROP INDEX cpr_status_payment_reviewed ON coin_purchase_requests');
            } catch (\Throwable) {
            }
        }
    }
}
