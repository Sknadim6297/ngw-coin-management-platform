<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7D: immutable purchase-time sponsor snapshots (L1–L3).
 * Captured inside purchase approval. Never silently backfills older purchases.
 */
class PurchaseSponsorSnapshotService
{
    public const STATUS_CAPTURED   = 'CAPTURED';
    public const STATUS_UNAVAILABLE = 'HISTORICAL_SNAPSHOT_UNAVAILABLE';

    public const SOURCE_PURCHASE_APPROVAL = 'purchase_approval';

    public function __construct(
        private readonly CommissionGenealogyService $genealogy = new CommissionGenealogyService(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * Capture L1–L3 snapshots for an approved purchase. Idempotent per purchase_ref+level.
     * Must be called inside the purchase approval DB transaction when possible.
     *
     * @param array<string, mixed> $purchase Row from coin_purchase_requests
     * @return list<array<string, mixed>>
     */
    public function captureForApprovedPurchase(array $purchase, int $actorUserId): array
    {
        $purchaseId   = (int) ($purchase['id'] ?? 0);
        $purchaseRef  = (string) ($purchase['purchase_ref'] ?? '');
        $purchaserUid = (int) ($purchase['member_user_id'] ?? 0);
        $purchaserMid = (string) ($purchase['member_id'] ?? '');

        if ($purchaseId <= 0 || $purchaseRef === '' || $purchaserUid <= 0 || $purchaserMid === '') {
            throw new InvalidArgumentException('Purchase identity incomplete for sponsor snapshot.');
        }

        $existing = db_connect()->table('purchase_sponsor_snapshots')
            ->where('purchase_ref', $purchaseRef)
            ->countAllResults();
        if ($existing > 0) {
            return $this->snapshotsForPurchase($purchaseRef);
        }

        $chain   = $this->genealogy->resolveUpline($purchaserUid, 3);
        $now     = date('Y-m-d H:i:s');
        $db      = db_connect();
        $rowsOut = [];

        $byLevel = [];
        foreach ($chain['chain'] as $node) {
            $byLevel[(int) $node['level']] = $node;
        }

        $globalReasons = $chain['reason_codes'] ?? [];
        $hasCycle = in_array('self_referral', $globalReasons, true)
            || in_array('circular_sponsorship', $globalReasons, true)
            || in_array('circular_or_duplicate_beneficiary', $globalReasons, true);

        for ($level = 1; $level <= 3; $level++) {
            $node    = $byLevel[$level] ?? null;
            $missing = $node === null || $hasCycle;
            $reasons = [];
            if ($hasCycle) {
                $reasons = array_values(array_filter(
                    $globalReasons,
                    static fn (string $c): bool => str_contains($c, 'circular') || $c === 'self_referral'
                ));
            }
            if ($node === null) {
                $reasons[] = 'missing_level_' . $level;
            }

            $relId = null;
            if ($node !== null && ! $hasCycle) {
                $edge = $db->table('sponsor_relationships')
                    ->select('id')
                    ->where('member_user_id', $level === 1 ? $purchaserUid : (int) ($byLevel[$level - 1]['user_id'] ?? 0))
                    ->where('sponsor_user_id', (int) $node['user_id'])
                    ->get()
                    ->getRowArray();
                $relId = $edge !== null ? (int) $edge['id'] : null;
            }

            $row = [
                'purchase_id'              => $purchaseId,
                'purchase_ref'             => $purchaseRef,
                'purchaser_user_id'        => $purchaserUid,
                'purchaser_member_id'      => $purchaserMid,
                'sponsor_level'            => $level,
                'sponsor_user_id'          => $missing ? null : (int) $node['user_id'],
                'sponsor_member_id'        => $missing ? null : (string) $node['member_id'],
                'sponsor_relationship_id'  => $missing ? null : $relId,
                'is_missing'               => $missing ? 1 : 0,
                'snapshot_status'          => self::STATUS_CAPTURED,
                'snapshot_source'          => self::SOURCE_PURCHASE_APPROVAL,
                'reason_codes_json'        => $reasons === [] ? null : json_encode($reasons, JSON_THROW_ON_ERROR),
                'snapshot_timestamp'       => $now,
                'created_at'               => $now,
            ];

            // For cycle cases still store explicit missing rows for remaining levels.
            if ($hasCycle) {
                $row['sponsor_user_id']         = null;
                $row['sponsor_member_id']       = null;
                $row['sponsor_relationship_id'] = null;
                $row['is_missing']              = 1;
            }

            $db->table('purchase_sponsor_snapshots')->insert($row);
            $rowsOut[] = $row;
        }

        $this->audit->log('commission.sponsor_snapshot_captured', $actorUserId, 'coin_purchase_request', $purchaseRef, [
            'levels'   => 3,
            'has_cycle'=> $hasCycle,
        ]);

        return $rowsOut;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshotsForPurchase(string $purchaseRef): array
    {
        return db_connect()->table('purchase_sponsor_snapshots')
            ->where('purchase_ref', $purchaseRef)
            ->orderBy('sponsor_level', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * Trusted snapshots exist only when CAPTURED rows are present for the purchase.
     * Pre-7D purchases without rows are HISTORICAL_SNAPSHOT_UNAVAILABLE.
     *
     * @return array{trusted: bool, status: string, levels: list<array<string, mixed>>, reason_codes: list<string>}
     */
    public function evaluateTrust(string $purchaseRef): array
    {
        $rows = $this->snapshotsForPurchase($purchaseRef);
        if ($rows === []) {
            return [
                'trusted'      => false,
                'status'       => self::STATUS_UNAVAILABLE,
                'levels'       => [],
                'reason_codes' => ['historical_snapshot_unavailable'],
            ];
        }

        $reasons = [];
        $usable  = [];
        foreach ($rows as $row) {
            if (($row['snapshot_status'] ?? '') !== self::STATUS_CAPTURED) {
                $reasons[] = 'snapshot_status_not_captured';
            }
            if (! empty($row['is_missing'])) {
                $reasons[] = 'missing_level_' . (int) $row['sponsor_level'];
            } else {
                $usable[] = $row;
            }
            if (! empty($row['reason_codes_json'])) {
                $decoded = json_decode((string) $row['reason_codes_json'], true);
                if (is_array($decoded)) {
                    $reasons = array_merge($reasons, $decoded);
                }
            }
        }

        $hasAnySponsor = $usable !== [];
        $hasCycle = in_array('self_referral', $reasons, true)
            || in_array('circular_sponsorship', $reasons, true)
            || in_array('circular_or_duplicate_beneficiary', $reasons, true);

        return [
            'trusted'      => $hasAnySponsor && ! $hasCycle,
            'status'       => $hasCycle ? 'INVALID_GENEALOGY' : self::STATUS_CAPTURED,
            'levels'       => $rows,
            'reason_codes' => array_values(array_unique($reasons)),
        ];
    }

    /**
     * There is no mutate API. Explicit guard for callers/tests.
     */
    public function rejectMutationAttempt(string $purchaseRef): never
    {
        throw new RuntimeException('Purchase sponsor snapshots are immutable (' . $purchaseRef . ').');
    }
}
