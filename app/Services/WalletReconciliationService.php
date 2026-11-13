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
     * @return list<array<string, mixed>>
     */
    private function reconcileMember(?int $memberUserId, ?string $memberId): array
    {
        $db = db_connect();
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

        $cache = $this->ownership->summaryForUser((int) $memberUserId);
        $replay = $this->replayLedger((int) $memberUserId);

        $scale = $this->scale();
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
                'code'            => 'purchase_credit_mismatch',
                'severity'        => 'critical',
                'message'         => 'Approved purchase quantities do not match PURCHASE_CREDIT ledger totals.',
                'member_user_id'  => $memberUserId,
                'ledger_credits'  => $creditQty,
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
        $scale = $this->scale();
        $zero  = MoneyMath::normalize('0', $scale);
        $available = $zero;
        $locked    = $zero;

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
            }
        }

        return [
            'balance'   => MoneyMath::add($available, $locked, $scale),
            'available' => $available,
            'locked'    => $locked,
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
            $sum       = MoneyMath::add($available, $locked, $scale);
            if (MoneyMath::compare($total, $sum, $scale) !== 0) {
                $out[] = [
                    'code'           => 'invariant_broken',
                    'severity'       => 'critical',
                    'message'        => 'Cached total owned != available + locked.',
                    'member_user_id' => (int) $row['member_user_id'],
                    'member_id'      => $row['member_id'],
                    'balance'        => $total,
                    'available'      => $available,
                    'locked'         => $locked,
                ];
                continue;
            }

            $replay = $this->replayLedger((int) $row['member_user_id']);
            if (MoneyMath::compare($total, $replay['balance'], $scale) !== 0
                || MoneyMath::compare($available, $replay['available'], $scale) !== 0
                || MoneyMath::compare($locked, $replay['locked'], $scale) !== 0
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

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
