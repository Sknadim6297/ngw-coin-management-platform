<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;

/**
 * Phase 6: read-only member wallet reconciliation.
 * Detects cache/ledger/purchase/lock mismatches. Never auto-repairs balances.
 */
class WalletReconciliationService
{
    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   generated_at: string,
     *   member_user_id: ?int,
     *   member_id: ?string,
     *   summary: array<string, int>,
     *   findings: list<array<string, mixed>>,
     *   auto_repair: false
     * }
     */
    public function reconcile(?int $memberUserId = null, ?string $memberId = null): array
    {
        $findings = [];

        if ($memberUserId !== null || ($memberId !== null && trim($memberId) !== '')) {
            $findings = array_merge($findings, $this->reconcileMember($memberUserId, $memberId));
        } else {
            $findings = array_merge($findings, $this->scanCachedBalances(200));
            $findings = array_merge($findings, $this->missingPurchaseCredits(100));
            $findings = array_merge($findings, $this->activeLockMismatch(100));
            $findings = array_merge($findings, $this->unlockMismatch(100));
            $findings = array_merge($findings, $this->negativeBalances(100));
            $findings = array_merge($findings, $this->duplicatePurchaseCredits(100));
            $findings = array_merge($findings, $this->invalidSourceReferences(100));
            $findings = array_merge($findings, $this->crossMemberReferences(100));
        }

        $summary = [
            'total_findings' => count($findings),
            'critical'       => 0,
            'warning'        => 0,
            'info'           => 0,
        ];
        foreach ($findings as $f) {
            $sev = (string) ($f['severity'] ?? 'info');
            if (isset($summary[$sev])) {
                $summary[$sev]++;
            }
        }

        return [
            'generated_at'   => date('Y-m-d H:i:s'),
            'member_user_id' => $memberUserId,
            'member_id'      => $memberId,
            'summary'        => $summary,
            'findings'       => $findings,
            'auto_repair'    => false,
        ];
    }

    /**
     * Lightweight issue tally for admin dashboards. Bounded; never mutates.
     *
     * @return array{critical: int, warning: int, total: int}
     */
    public function issueCounts(int $limit = 100): array
    {
        $limit    = max(1, min(200, $limit));
        $findings = array_merge(
            $this->scanCachedBalances($limit),
            $this->missingPurchaseCredits($limit),
            $this->activeLockMismatch($limit),
            $this->unlockMismatch($limit),
            $this->negativeBalances($limit),
            $this->duplicatePurchaseCredits($limit),
            $this->invalidSourceReferences($limit),
            $this->crossMemberReferences($limit),
        );

        $critical = 0;
        $warning  = 0;
        foreach ($findings as $f) {
            $sev = (string) ($f['severity'] ?? 'info');
            if ($sev === 'critical') {
                $critical++;
            } elseif ($sev === 'warning') {
                $warning++;
            }
        }

        return [
            'critical' => $critical,
            'warning'  => $warning,
            'total'    => $critical + $warning,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reconcileMember(?int $memberUserId, ?string $memberId): array
    {
        $db  = db_connect();
        $out = [];

        if ($memberUserId === null && $memberId !== null) {
            $profile = $db->table('member_profiles')->where('member_id', strtoupper(trim($memberId)))->get()->getRowArray();
            if ($profile === null) {
                return [[
                    'code'      => 'member_not_found',
                    'severity'  => 'warning',
                    'message'   => 'Member not found for wallet reconciliation.',
                    'member_id' => $memberId,
                ]];
            }
            $memberUserId = (int) $profile['user_id'];
            $memberId     = (string) $profile['member_id'];
        }

        $cache  = $this->ownership->summaryForUser((int) $memberUserId);
        $replay = $this->replayLedger((int) $memberUserId);
        $scale  = $this->scale();

        $sum = MoneyMath::add($cache['available'], $cache['locked'], $scale);
        if (MoneyMath::compare($cache['balance'], $sum, $scale) !== 0) {
            $out[] = [
                'code'           => 'invariant_broken',
                'severity'       => 'critical',
                'message'        => 'Cached total owned != available + locked.',
                'member_user_id' => $memberUserId,
                'member_id'      => $cache['member_id'] ?? $memberId,
                'balance'        => $cache['balance'],
                'available'      => $cache['available'],
                'locked'         => $cache['locked'],
            ];
        }

        if (MoneyMath::compare($cache['balance'], $replay['balance'], $scale) !== 0
            || MoneyMath::compare($cache['available'], $replay['available'], $scale) !== 0
            || MoneyMath::compare($cache['locked'], $replay['locked'], $scale) !== 0
        ) {
            $out[] = [
                'code'           => 'cache_ledger_drift',
                'severity'       => 'critical',
                'message'        => 'Cached wallet balances do not match ownership ledger replay.',
                'member_user_id' => $memberUserId,
                'member_id'      => $cache['member_id'] ?? $memberId,
                'cached'         => $cache,
                'ledger_replay'  => $replay,
            ];
        }

        foreach (['balance', 'available', 'locked'] as $field) {
            if (MoneyMath::isNegative($cache[$field], $scale)) {
                $out[] = [
                    'code'           => 'negative_balance',
                    'severity'       => 'critical',
                    'message'        => 'Cached wallet balance field is negative.',
                    'member_user_id' => $memberUserId,
                    'field'          => $field,
                    'value'          => $cache[$field],
                ];
            }
        }

        $purchaseCredits = $db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS qty
             FROM member_coin_ownership_transactions
             WHERE member_user_id = ? AND type = ?',
            [$memberUserId, MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT]
        )->getRowArray();
        $approvedQty = $db->query(
            'SELECT COALESCE(SUM(coin_quantity), 0) AS qty
             FROM coin_purchase_requests
             WHERE member_user_id = ? AND status = ?',
            [$memberUserId, 'approved']
        )->getRowArray();

        $creditQty   = MoneyMath::normalize((string) ($purchaseCredits['qty'] ?? '0'), $scale);
        $approvedSum = MoneyMath::normalize((string) ($approvedQty['qty'] ?? '0'), $scale);
        if (MoneyMath::compare($creditQty, $approvedSum, $scale) !== 0) {
            $out[] = [
                'code'               => 'purchase_credit_mismatch',
                'severity'           => 'critical',
                'message'            => 'Approved purchase quantities do not match PURCHASE_CREDIT ledger totals.',
                'member_user_id'     => $memberUserId,
                'ledger_credits'     => $creditQty,
                'approved_purchases' => $approvedSum,
            ];
        }

        $activeLocks = $db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS qty
             FROM member_coin_locks
             WHERE member_user_id = ? AND status IN (?, ?)',
            [$memberUserId, 'active', 'matured']
        )->getRowArray();
        $activeLockQty = MoneyMath::normalize((string) ($activeLocks['qty'] ?? '0'), $scale);
        if (MoneyMath::compare($cache['locked'], $activeLockQty, $scale) !== 0) {
            $out[] = [
                'code'              => 'locked_balance_vs_active_locks',
                'severity'          => 'warning',
                'message'           => 'Cached locked balance differs from active/matured lock rows.',
                'member_user_id'    => $memberUserId,
                'cached_locked'     => $cache['locked'],
                'active_lock_total' => $activeLockQty,
            ];
        }

        $unlockLedger = $db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS qty
             FROM member_coin_ownership_transactions
             WHERE member_user_id = ? AND type = ?',
            [$memberUserId, MemberCoinOwnershipService::TYPE_UNLOCK]
        )->getRowArray();
        $unlockedLocks = $db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS qty
             FROM member_coin_locks
             WHERE member_user_id = ? AND status = ?',
            [$memberUserId, 'unlocked']
        )->getRowArray();
        $unlockQty  = MoneyMath::normalize((string) ($unlockLedger['qty'] ?? '0'), $scale);
        $releasedQty = MoneyMath::normalize((string) ($unlockedLocks['qty'] ?? '0'), $scale);
        if (MoneyMath::compare($unlockQty, $releasedQty, $scale) !== 0) {
            $out[] = [
                'code'            => 'unlock_mismatch',
                'severity'        => 'warning',
                'message'         => 'UNLOCK ledger quantity does not match unlocked lock rows.',
                'member_user_id'  => $memberUserId,
                'ledger_unlocks'  => $unlockQty,
                'released_locks'  => $releasedQty,
            ];
        }

        $dupCredits = $db->query(
            'SELECT purchase_ref, COUNT(*) AS cnt
             FROM member_coin_ownership_transactions
             WHERE member_user_id = ? AND type = ? AND purchase_ref IS NOT NULL AND purchase_ref <> \'\'
             GROUP BY purchase_ref
             HAVING COUNT(*) > 1',
            [$memberUserId, MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT]
        )->getResultArray();
        foreach ($dupCredits as $dup) {
            $out[] = [
                'code'           => 'duplicate_purchase_credit',
                'severity'       => 'critical',
                'message'        => 'Duplicate PURCHASE_CREDIT rows for the same purchase_ref.',
                'member_user_id' => $memberUserId,
                'purchase_ref'   => $dup['purchase_ref'],
                'count'          => (int) $dup['cnt'],
            ];
        }

        $out = array_merge(
            $out,
            $this->memberInvalidSourceReferences((int) $memberUserId),
            $this->memberCrossMemberReferences((int) $memberUserId)
        );

        if ($out === []) {
            $out[] = [
                'code'           => 'wallet_ok',
                'severity'       => 'info',
                'message'        => 'No wallet mismatches detected for this member.',
                'member_user_id' => $memberUserId,
                'member_id'      => $cache['member_id'] ?? $memberId,
                'balances'       => $cache,
            ];
        }

        return $out;
    }

    /**
     * @return array{balance: string, available: string, locked: string}
     */
    private function replayLedger(int $memberUserId): array
    {
        $scale     = $this->scale();
        $zero      = MoneyMath::normalize('0', $scale);
        $available = $zero;
        $locked    = $zero;
        $reserved  = $zero;

        $rows = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $qty  = MoneyMath::normalize((string) $row['quantity'], $scale);
            $type = (string) $row['type'];
            if ($type === MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT) {
                $available = MoneyMath::add($available, $qty, $scale);
            } elseif ($type === MemberCoinOwnershipService::TYPE_LOCK) {
                $available = MoneyMath::sub($available, $qty, $scale);
                $locked    = MoneyMath::add($locked, $qty, $scale);
            } elseif ($type === MemberCoinOwnershipService::TYPE_UNLOCK) {
                $locked    = MoneyMath::sub($locked, $qty, $scale);
                $available = MoneyMath::add($available, $qty, $scale);
            } elseif ($type === MemberCoinOwnershipService::TYPE_SALE_RESERVE) {
                $available = MoneyMath::sub($available, $qty, $scale);
                $reserved  = MoneyMath::add($reserved, $qty, $scale);
            } elseif ($type === MemberCoinOwnershipService::TYPE_SALE_RELEASE) {
                $reserved  = MoneyMath::sub($reserved, $qty, $scale);
                $available = MoneyMath::add($available, $qty, $scale);
            } elseif ($type === MemberCoinOwnershipService::TYPE_SALE_DEBIT) {
                $reserved = MoneyMath::sub($reserved, $qty, $scale);
            }
        }

        return [
            'balance'   => MoneyMath::add(MoneyMath::add($available, $locked, $scale), $reserved, $scale),
            'available' => $available,
            'locked'    => $locked,
            'reserved'  => $reserved,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanCachedBalances(int $limit): array
    {
        $scale = $this->scale();
        $out   = [];
        $rows  = db_connect()->table('member_coin_balances')
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $total     = MoneyMath::normalize((string) $row['balance'], $scale);
            $available = MoneyMath::normalize((string) ($row['available_balance'] ?? '0'), $scale);
            $locked    = MoneyMath::normalize((string) ($row['locked_balance'] ?? '0'), $scale);
            $reserved  = MoneyMath::normalize((string) ($row['reserved_balance'] ?? '0'), $scale);
            $sum       = MoneyMath::add(MoneyMath::add($available, $locked, $scale), $reserved, $scale);
            if (MoneyMath::compare($total, $sum, $scale) !== 0) {
                $out[] = [
                    'code'           => 'invariant_broken',
                    'severity'       => 'critical',
                    'message'        => 'Cached total owned != available + locked + reserved.',
                    'member_user_id' => (int) $row['member_user_id'],
                    'member_id'      => $row['member_id'],
                    'balance'        => $total,
                    'available'      => $available,
                    'locked'         => $locked,
                    'reserved'       => $reserved,
                ];
                continue;
            }

            $replay = $this->replayLedger((int) $row['member_user_id']);
            if (MoneyMath::compare($total, $replay['balance'], $scale) !== 0
                || MoneyMath::compare($available, $replay['available'], $scale) !== 0
                || MoneyMath::compare($locked, $replay['locked'], $scale) !== 0
                || MoneyMath::compare($reserved, (string) ($replay['reserved'] ?? '0'), $scale) !== 0
            ) {
                $out[] = [
                    'code'           => 'cache_ledger_drift',
                    'severity'       => 'critical',
                    'message'        => 'Cached wallet balances do not match ownership ledger replay.',
                    'member_user_id' => (int) $row['member_user_id'],
                    'member_id'      => $row['member_id'],
                    'cached'         => [
                        'balance'   => $total,
                        'available' => $available,
                        'locked'    => $locked,
                    ],
                    'ledger_replay'  => $replay,
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function missingPurchaseCredits(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT p.purchase_ref, p.member_user_id, p.member_id, p.coin_quantity
             FROM coin_purchase_requests p
             LEFT JOIN member_coin_ownership_transactions t
               ON t.purchase_ref = p.purchase_ref AND t.type = ?
             WHERE p.status = ? AND t.id IS NULL
             ORDER BY p.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT, 'approved']
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'missing_purchase_credit',
                'severity'       => 'critical',
                'message'        => 'Approved purchase has no ownership PURCHASE_CREDIT ledger row.',
                'purchase_ref'   => $row['purchase_ref'],
                'member_user_id' => (int) $row['member_user_id'],
                'member_id'      => $row['member_id'],
                'quantity'       => $row['coin_quantity'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activeLockMismatch(int $limit): array
    {
        $scale = $this->scale();
        $out   = [];
        $rows  = db_connect()->query(
            'SELECT b.member_user_id, b.member_id, b.locked_balance,
                    COALESCE(l.lock_qty, 0) AS lock_qty
             FROM member_coin_balances b
             LEFT JOIN (
                SELECT member_user_id, SUM(quantity) AS lock_qty
                FROM member_coin_locks
                WHERE status IN (\'active\', \'matured\')
                GROUP BY member_user_id
             ) l ON l.member_user_id = b.member_user_id
             ORDER BY b.id ASC
             LIMIT ' . (int) $limit
        )->getResultArray();

        foreach ($rows as $row) {
            $cached = MoneyMath::normalize((string) $row['locked_balance'], $scale);
            $locks  = MoneyMath::normalize((string) $row['lock_qty'], $scale);
            if (MoneyMath::compare($cached, $locks, $scale) === 0) {
                continue;
            }
            $out[] = [
                'code'              => 'locked_balance_vs_active_locks',
                'severity'          => 'warning',
                'message'           => 'Cached locked balance differs from active/matured lock rows.',
                'member_user_id'    => (int) $row['member_user_id'],
                'member_id'         => $row['member_id'],
                'cached_locked'     => $cached,
                'active_lock_total' => $locks,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unlockMismatch(int $limit): array
    {
        $scale = $this->scale();
        $rows  = db_connect()->query(
            'SELECT b.member_user_id, b.member_id,
                    COALESCE(u.unlock_qty, 0) AS unlock_qty,
                    COALESCE(l.released_qty, 0) AS released_qty
             FROM member_coin_balances b
             LEFT JOIN (
                SELECT member_user_id, SUM(quantity) AS unlock_qty
                FROM member_coin_ownership_transactions
                WHERE type = ?
                GROUP BY member_user_id
             ) u ON u.member_user_id = b.member_user_id
             LEFT JOIN (
                SELECT member_user_id, SUM(quantity) AS released_qty
                FROM member_coin_locks
                WHERE status = \'unlocked\'
                GROUP BY member_user_id
             ) l ON l.member_user_id = b.member_user_id
             ORDER BY b.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_UNLOCK]
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $unlock   = MoneyMath::normalize((string) $row['unlock_qty'], $scale);
            $released = MoneyMath::normalize((string) $row['released_qty'], $scale);
            if (MoneyMath::compare($unlock, $released, $scale) === 0) {
                continue;
            }
            $out[] = [
                'code'           => 'unlock_mismatch',
                'severity'       => 'warning',
                'message'        => 'UNLOCK ledger quantity does not match unlocked lock rows.',
                'member_user_id' => (int) $row['member_user_id'],
                'member_id'      => $row['member_id'],
                'ledger_unlocks' => $unlock,
                'released_locks' => $released,
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function negativeBalances(int $limit): array
    {
        $rows = db_connect()->table('member_coin_balances')
            ->groupStart()
                ->where('balance <', 0)
                ->orWhere('available_balance <', 0)
                ->orWhere('locked_balance <', 0)
            ->groupEnd()
            ->orderBy('id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'negative_balance',
                'severity'       => 'critical',
                'message'        => 'Cached wallet balance contains a negative value.',
                'member_user_id' => (int) $row['member_user_id'],
                'member_id'      => $row['member_id'],
                'balance'        => $row['balance'],
                'available'      => $row['available_balance'],
                'locked'         => $row['locked_balance'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function duplicatePurchaseCredits(int $limit): array
    {
        $rows = db_connect()->query(
            'SELECT purchase_ref, member_user_id, member_id, COUNT(*) AS cnt
             FROM member_coin_ownership_transactions
             WHERE type = ? AND purchase_ref IS NOT NULL AND purchase_ref <> \'\'
             GROUP BY purchase_ref, member_user_id, member_id
             HAVING COUNT(*) > 1
             ORDER BY cnt DESC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT]
        )->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'code'           => 'duplicate_purchase_credit',
                'severity'       => 'critical',
                'message'        => 'Duplicate PURCHASE_CREDIT rows for the same purchase_ref.',
                'purchase_ref'   => $row['purchase_ref'],
                'member_user_id' => (int) $row['member_user_id'],
                'member_id'      => $row['member_id'],
                'count'          => (int) $row['cnt'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function invalidSourceReferences(int $limit): array
    {
        $db  = db_connect();
        $out = [];

        $orphanPurchases = $db->query(
            'SELECT t.transaction_ref, t.purchase_ref, t.member_user_id, t.member_id
             FROM member_coin_ownership_transactions t
             LEFT JOIN coin_purchase_requests p ON p.purchase_ref = t.purchase_ref
             WHERE t.type = ? AND t.purchase_ref IS NOT NULL AND t.purchase_ref <> \'\' AND p.id IS NULL
             ORDER BY t.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT]
        )->getResultArray();
        foreach ($orphanPurchases as $row) {
            $out[] = [
                'code'            => 'invalid_purchase_ref',
                'severity'        => 'critical',
                'message'         => 'PURCHASE_CREDIT references a missing purchase request.',
                'transaction_ref' => $row['transaction_ref'],
                'purchase_ref'    => $row['purchase_ref'],
                'member_user_id'  => (int) $row['member_user_id'],
                'member_id'       => $row['member_id'],
            ];
        }

        $orphanLocks = $db->query(
            'SELECT t.transaction_ref, t.lock_ref, t.member_user_id, t.member_id, t.type
             FROM member_coin_ownership_transactions t
             LEFT JOIN member_coin_locks l ON l.lock_ref = t.lock_ref
             WHERE t.type IN (?, ?) AND t.lock_ref IS NOT NULL AND t.lock_ref <> \'\' AND l.id IS NULL
             ORDER BY t.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_LOCK, MemberCoinOwnershipService::TYPE_UNLOCK]
        )->getResultArray();
        foreach ($orphanLocks as $row) {
            $out[] = [
                'code'            => 'invalid_lock_ref',
                'severity'        => 'critical',
                'message'         => 'LOCK/UNLOCK references a missing member coin lock.',
                'transaction_ref' => $row['transaction_ref'],
                'lock_ref'        => $row['lock_ref'],
                'member_user_id'  => (int) $row['member_user_id'],
                'member_id'       => $row['member_id'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function crossMemberReferences(int $limit): array
    {
        $db  = db_connect();
        $out = [];

        $purchaseCross = $db->query(
            'SELECT t.transaction_ref, t.purchase_ref, t.member_user_id, p.member_user_id AS purchase_member_user_id
             FROM member_coin_ownership_transactions t
             INNER JOIN coin_purchase_requests p ON p.purchase_ref = t.purchase_ref
             WHERE t.type = ? AND t.member_user_id <> p.member_user_id
             ORDER BY t.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT]
        )->getResultArray();
        foreach ($purchaseCross as $row) {
            $out[] = [
                'code'                     => 'cross_member_purchase_ref',
                'severity'                 => 'critical',
                'message'                  => 'Ownership credit purchase_ref belongs to a different member.',
                'transaction_ref'          => $row['transaction_ref'],
                'purchase_ref'             => $row['purchase_ref'],
                'member_user_id'           => (int) $row['member_user_id'],
                'purchase_member_user_id'  => (int) $row['purchase_member_user_id'],
            ];
        }

        $lockCross = $db->query(
            'SELECT t.transaction_ref, t.lock_ref, t.member_user_id, l.member_user_id AS lock_member_user_id
             FROM member_coin_ownership_transactions t
             INNER JOIN member_coin_locks l ON l.lock_ref = t.lock_ref
             WHERE t.type IN (?, ?) AND t.member_user_id <> l.member_user_id
             ORDER BY t.id ASC
             LIMIT ' . (int) $limit,
            [MemberCoinOwnershipService::TYPE_LOCK, MemberCoinOwnershipService::TYPE_UNLOCK]
        )->getResultArray();
        foreach ($lockCross as $row) {
            $out[] = [
                'code'                 => 'cross_member_lock_ref',
                'severity'             => 'critical',
                'message'              => 'Ownership lock_ref belongs to a different member.',
                'transaction_ref'      => $row['transaction_ref'],
                'lock_ref'             => $row['lock_ref'],
                'member_user_id'       => (int) $row['member_user_id'],
                'lock_member_user_id'  => (int) $row['lock_member_user_id'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberInvalidSourceReferences(int $memberUserId): array
    {
        $all = $this->invalidSourceReferences(50);
        return array_values(array_filter(
            $all,
            static fn (array $f): bool => (int) ($f['member_user_id'] ?? 0) === $memberUserId
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function memberCrossMemberReferences(int $memberUserId): array
    {
        $all = $this->crossMemberReferences(50);
        return array_values(array_filter(
            $all,
            static fn (array $f): bool => (int) ($f['member_user_id'] ?? 0) === $memberUserId
        ));
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
