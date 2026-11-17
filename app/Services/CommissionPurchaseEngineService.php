<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11B purchase-triggered commission calculation engine.
 * Separates eligibility → calculation → (future) posting.
 * Never credits commission wallets while kill-switch is OFF.
 *
 * DRAFT assumptions (pending business sign-off):
 * - Commission base = approved purchase total_usdt
 * - Rounding = BCMath truncate toward zero at usdtScale
 */
class CommissionPurchaseEngineService
{
    public const STATUS_CALCULATED   = 'CALCULATED_NOT_POSTED';
    public const STATUS_UNALLOCATED  = 'UNALLOCATED';
    public const STATUS_INELIGIBLE   = 'INELIGIBLE';
    public const STATUS_NO_PLAN      = 'NO_APPROVED_PLAN';
    public const STATUS_SKIPPED      = 'SKIPPED';

    public function __construct(
        private readonly CommissionPlanService $plans = new CommissionPlanService(),
        private readonly PurchaseSponsorSnapshotService $snapshots = new PurchaseSponsorSnapshotService(),
        private readonly CommissionWalletService $wallets = new CommissionWalletService(),
        private readonly CommissionSimulationService $simulation = new CommissionSimulationService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function draftAssumptions(): array
    {
        return [
            'label'            => 'DRAFT_ASSUMPTIONS_FOR_PREVIEW_ONLY',
            'commission_base'  => 'approved_purchase_total_usdt',
            'rounding'         => 'bcmath_truncate_toward_zero_at_usdt_scale',
            'missing_levels'   => 'recorded_as_unallocated_no_redistribution',
            'posting'          => 'OFF',
            'unconfirmed'      => [
                'commission_base_business_signoff',
                'rounding_policy_signoff',
            ],
        ];
    }

    /**
     * Deterministic calculate for one approved purchase (optionally persist).
     *
     * @return array{
     *   purchase_ref: string,
     *   business_date: string,
     *   plan: ?array<string, mixed>,
     *   assumptions: array<string, mixed>,
     *   lines: list<array<string, mixed>>,
     *   totals: array<string, string>,
     *   posting_enabled: bool,
     *   posting_status: string
     * }
     */
    public function calculateForPurchase(string $purchaseRef, bool $persist = true, ?int $actorUserId = null): array
    {
        $purchaseRef = trim($purchaseRef);
        $purchase = db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $purchaseRef)
            ->get()
            ->getRowArray();
        if ($purchase === null) {
            throw new InvalidArgumentException('Purchase not found.');
        }

        $assumptions = $this->draftAssumptions();
        $scale = $this->scale();
        $lines = [];

        if (($purchase['status'] ?? '') !== 'approved') {
            for ($level = 1; $level <= 3; $level++) {
                $lines[] = $this->lineSkeleton($purchase, $level, self::STATUS_INELIGIBLE, ['purchase_not_approved'], null, null, '0');
            }

            return $this->wrapResult($purchase, null, $assumptions, $lines, $persist, $actorUserId);
        }

        $cfg = $this->config ?? config('Platform');
        if ((bool) $cfg->requireVerifiedDepositForApproval
            && strtolower((string) ($purchase['payment_status'] ?? '')) !== 'verified'
        ) {
            // Soft signal — approval path usually already enforces this.
            $reasons = ['payment_not_verified'];
        } else {
            $reasons = [];
        }

        $businessDate = substr((string) ($purchase['reviewed_at'] ?? $purchase['requested_at'] ?? date('Y-m-d')), 0, 10);
        $plan = $this->plans->findApplicablePlan($businessDate);
        $base = MoneyMath::normalize((string) ($purchase['total_usdt'] ?? '0'), $scale);

        if ($plan === null || ($plan['status'] ?? '') !== CommissionPlanService::STATUS_APPROVED) {
            for ($level = 1; $level <= 3; $level++) {
                $lines[] = $this->lineSkeleton(
                    $purchase,
                    $level,
                    self::STATUS_NO_PLAN,
                    array_merge($reasons, ['no_approved_plan']),
                    null,
                    null,
                    '0',
                    $businessDate
                );
            }

            return $this->wrapResult($purchase, null, $assumptions, $lines, $persist, $actorUserId);
        }

        $trust = $this->snapshots->evaluateTrust($purchaseRef);
        $snapByLevel = [];
        foreach ($trust['levels'] as $lvl) {
            $snapByLevel[(int) $lvl['sponsor_level']] = $lvl;
        }

        $purchaserUid = (int) $purchase['member_user_id'];

        for ($level = 1; $level <= 3; $level++) {
            $percent = isset($plan['levels'][$level])
                ? MoneyMath::normalize((string) $plan['levels'][$level], $scale)
                : null;
            $snap = $snapByLevel[$level] ?? null;
            $lineReasons = $reasons;

            if ($percent === null) {
                $lines[] = $this->lineSkeleton(
                    $purchase,
                    $level,
                    self::STATUS_INELIGIBLE,
                    ['missing_plan_level'],
                    null,
                    null,
                    '0',
                    $businessDate,
                    $plan
                );
                continue;
            }

            if ($snap === null || ! empty($snap['is_missing']) || empty($snap['sponsor_user_id'])) {
                $lineReasons[] = 'missing_sponsor_level_' . $level;
                $lines[] = $this->lineSkeleton(
                    $purchase,
                    $level,
                    self::STATUS_UNALLOCATED,
                    $lineReasons,
                    $percent,
                    null,
                    '0',
                    $businessDate,
                    $plan
                );
                continue;
            }

            $beneficiaryUid = (int) $snap['sponsor_user_id'];
            $beneficiaryMid = (string) $snap['sponsor_member_id'];

            if ($beneficiaryUid === $purchaserUid) {
                $lineReasons[] = 'self_commission_blocked';
                $lines[] = $this->lineSkeleton(
                    $purchase,
                    $level,
                    self::STATUS_INELIGIBLE,
                    $lineReasons,
                    $percent,
                    ['user_id' => $beneficiaryUid, 'member_id' => $beneficiaryMid],
                    '0',
                    $businessDate,
                    $plan
                );
                continue;
            }

            $expected = $this->simulation->previewAmount($base, $percent);
            $lineReasons[] = 'eligible';
            $lines[] = $this->lineSkeleton(
                $purchase,
                $level,
                self::STATUS_CALCULATED,
                $lineReasons,
                $percent,
                ['user_id' => $beneficiaryUid, 'member_id' => $beneficiaryMid],
                $expected,
                $businessDate,
                $plan
            );
        }

        return $this->wrapResult($purchase, $plan, $assumptions, $lines, $persist, $actorUserId);
    }

    /**
     * After purchase approval — persist calc only; never posts.
     *
     * @return array<string, mixed>
     */
    public function onPurchaseApproved(string $purchaseRef, int $actorUserId): array
    {
        $result = $this->calculateForPurchase($purchaseRef, true, $actorUserId);
        $this->audit->log('commission.purchase_calculated', $actorUserId, 'coin_purchase_request', $purchaseRef, [
            'expected_total' => $result['totals']['expected_amount_usdt'],
            'posting'        => 'OFF',
            'line_count'     => count($result['lines']),
        ]);

        return $result;
    }

    /**
     * @return array{mismatches: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function reconcile(?string $purchaseRef = null, int $limit = 200): array
    {
        $db = db_connect();
        $mismatches = [];

        // Approved purchases missing calculations
        $sql = 'SELECT p.purchase_ref, p.member_id, p.total_usdt
                FROM coin_purchase_requests p
                LEFT JOIN commission_purchase_calculations c ON c.purchase_ref = p.purchase_ref
                WHERE p.status = ?
                  AND c.id IS NULL';
        $params = ['approved'];
        if ($purchaseRef !== null && $purchaseRef !== '') {
            $sql .= ' AND p.purchase_ref = ?';
            $params[] = $purchaseRef;
        }
        $sql .= ' ORDER BY p.id DESC LIMIT ' . (int) max(1, min(2000, $limit));
        foreach ($db->query($sql, $params)->getResultArray() as $row) {
            $mismatches[] = [
                'type'         => 'approved_purchase_missing_calculation',
                'purchase_ref' => $row['purchase_ref'],
                'member_id'    => $row['member_id'],
            ];
        }

        if ($db->tableExists('commission_purchase_calculations')) {
            $builder = $db->table('commission_purchase_calculations')->orderBy('id', 'DESC')->limit($limit);
            if ($purchaseRef) {
                $builder->where('purchase_ref', $purchaseRef);
            }
            foreach ($builder->get()->getResultArray() as $calc) {
                $posted = $this->postedAmountForCalc($calc);
                if (MoneyMath::compare($posted, '0', $this->scale()) === 1 && ! $this->wallets->postingEnabled()) {
                    $mismatches[] = [
                        'type'         => 'unexpected_credit_while_posting_off',
                        'purchase_ref' => $calc['purchase_ref'],
                        'level'        => (int) $calc['sponsor_level'],
                        'posted'       => $posted,
                    ];
                }
                if (($calc['status'] ?? '') === self::STATUS_CALCULATED
                    && MoneyMath::compare((string) $calc['expected_amount_usdt'], '0', $this->scale()) === 1
                    && MoneyMath::compare($posted, (string) $calc['expected_amount_usdt'], $this->scale()) !== 0
                    && $this->wallets->postingEnabled()
                ) {
                    $mismatches[] = [
                        'type'         => 'expected_vs_posted_mismatch',
                        'purchase_ref' => $calc['purchase_ref'],
                        'level'        => (int) $calc['sponsor_level'],
                        'expected'     => $calc['expected_amount_usdt'],
                        'posted'       => $posted,
                    ];
                }

                // Rate snapshot sanity vs plan (informational if plan still exists)
                if (! empty($calc['plan_ref']) && ($calc['status'] ?? '') === self::STATUS_CALCULATED) {
                    try {
                        $plan = $this->plans->findByRef((string) $calc['plan_ref']);
                        $lvl  = (int) $calc['sponsor_level'];
                        $pct  = $plan['levels'][$lvl] ?? null;
                        if ($pct !== null
                            && MoneyMath::compare(MoneyMath::normalize((string) $pct, 8), MoneyMath::normalize((string) $calc['rate_percent'], 8), 8) !== 0
                        ) {
                            // Historical rates are snapshotted — mismatch vs current plan row is OK if immutable.
                            // Only flag if approved plan level for that ref differs from stored snapshot (data corruption).
                            $mismatches[] = [
                                'type'         => 'rate_snapshot_differs_from_plan_row',
                                'purchase_ref' => $calc['purchase_ref'],
                                'level'        => $lvl,
                                'stored'       => $calc['rate_percent'],
                                'plan'         => $pct,
                                'note'         => 'Informational if plan was mutated incorrectly; approved plans should be immutable.',
                            ];
                        }
                    } catch (\Throwable) {
                        // plan missing — ignore
                    }
                }
            }

            $dupes = $db->query(
                'SELECT purchase_ref, sponsor_level, COUNT(*) AS c
                 FROM commission_purchase_calculations
                 GROUP BY purchase_ref, sponsor_level
                 HAVING c > 1'
            )->getResultArray();
            foreach ($dupes as $d) {
                $mismatches[] = [
                    'type'         => 'duplicate_calculation',
                    'purchase_ref' => $d['purchase_ref'],
                    'level'        => (int) $d['sponsor_level'],
                    'count'        => (int) $d['c'],
                ];
            }
        }

        // Wallet credits that look like commission while posting OFF
        if (! $this->wallets->postingEnabled() && $db->tableExists('commission_wallet_ledger_entries')) {
            $credits = $db->table('commission_wallet_ledger_entries')
                ->where('entry_type', 'commission_credit')
                ->limit(50)
                ->get()
                ->getResultArray();
            foreach ($credits as $c) {
                $mismatches[] = [
                    'type'      => 'unexpected_wallet_commission_credit',
                    'member_id' => $c['member_id'] ?? null,
                    'entry_ref' => $c['entry_ref'] ?? null,
                ];
            }
        }

        return [
            'mismatches' => $mismatches,
            'summary'    => [
                'mismatches' => count($mismatches),
            ],
            'note' => 'Reconcile is read-only. Commission posting remains OFF in Phase 11B.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config ?? config('Platform');
        $plan = $this->plans->findApplicablePlan(date('Y-m-d'));

        return [
            'commission_posting_enabled'   => (bool) $cfg->commissionPostingEnabled,
            'commission_withdrawal_enabled'=> (bool) $cfg->commissionWithdrawalEnabled,
            'blockchain_payouts'           => (bool) $cfg->commissionBlockchainPayoutEnabled,
            'mining_posting_enabled'       => (bool) $cfg->miningPostingEnabled,
            'mining_p2p_enabled'           => (bool) $cfg->miningP2pEnabled,
            'active_plan'                  => $plan,
            'default_display_rates'        => ['L1' => '10', 'L2' => '5', 'L3' => '3'],
            'assumptions'                  => $this->draftAssumptions(),
            'blockers'                     => [
                'Commission base (USDT purchase amount) pending business sign-off.',
                'Rounding policy pending business sign-off.',
                'Live posting blocked until commissionPostingEnabled is authorized.',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calculationsForPurchase(string $purchaseRef): array
    {
        if (! db_connect()->tableExists('commission_purchase_calculations')) {
            return [];
        }

        return db_connect()->table('commission_purchase_calculations')
            ->where('purchase_ref', $purchaseRef)
            ->orderBy('sponsor_level', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calculationsForBeneficiary(int $memberUserId, int $limit = 50): array
    {
        if (! db_connect()->tableExists('commission_purchase_calculations')) {
            return [];
        }

        return db_connect()->table('commission_purchase_calculations')
            ->where('beneficiary_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * Post calculated commission lines to commission wallets.
     * Production remains blocked unless Financial Sandbox window is open.
     *
     * @return array{purchase_ref: string, posted_lines: int, total_posted: string, duplicates: int, lines: list<array<string, mixed>>}
     */
    public function postPurchase(string $purchaseRef, ?int $actorUserId = null): array
    {
        if (! $this->wallets->postingEnabled()) {
            throw new RuntimeException('Commission posting is disabled (kill-switch).');
        }

        $sandbox = service('financialSandboxService');
        if (! (bool) ($this->config ?? config('Platform'))->commissionPostingEnabled) {
            $sandbox->assertActive('commission_post_purchase');
        }

        $purchaseRef = trim($purchaseRef);
        $calc = $this->calculateForPurchase($purchaseRef, true, $actorUserId);
        $scale = $this->scale();
        $postedLines = 0;
        $duplicates  = 0;
        $totalPosted = MoneyMath::normalize('0', $scale);
        $out         = [];

        foreach ($calc['lines'] as $line) {
            if (($line['status'] ?? '') !== self::STATUS_CALCULATED) {
                continue;
            }
            $uid = (int) ($line['beneficiary_user_id'] ?? 0);
            $mid = (string) ($line['beneficiary_member_id'] ?? '');
            $amt = MoneyMath::normalize((string) ($line['expected_amount_usdt'] ?? '0'), $scale);
            if ($uid <= 0 || $mid === '' || ! MoneyMath::isPositive($amt, $scale)) {
                continue;
            }
            if ($sandbox->isWindowOpen()) {
                $sandbox->assertSyntheticMember($mid, 'commission_post_purchase');
            }

            $level = (int) $line['sponsor_level'];
            $idem  = 'comm-post:' . $purchaseRef . ':L' . $level;
            $before = db_connect()->table('commission_wallet_ledger_entries')
                ->where('idempotency_key', $idem)
                ->countAllResults();

            $entry = $this->wallets->creditCommission($uid, $mid, [
                'amount'          => $amt,
                'reference_type'  => 'commission_purchase',
                'reference_id'    => $purchaseRef . ':L' . $level,
                'idempotency_key' => $idem,
                'created_by'      => $actorUserId,
                'notes'           => 'Phase12A sandbox commission post',
            ]);

            if ($before > 0) {
                $duplicates++;
            } else {
                $postedLines++;
            }
            $totalPosted = MoneyMath::add($totalPosted, $amt, $scale);

            if (db_connect()->tableExists('commission_purchase_calculations')) {
                db_connect()->table('commission_purchase_calculations')
                    ->where('purchase_ref', $purchaseRef)
                    ->where('sponsor_level', $level)
                    ->update([
                        'posted_amount_usdt' => $amt,
                        'status'             => 'POSTED',
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);
            }

            $out[] = [
                'level'     => $level,
                'member_id' => $mid,
                'amount'    => $amt,
                'entry_ref' => $entry['entry_ref'] ?? null,
            ];
        }

        return [
            'purchase_ref'  => $purchaseRef,
            'posted_lines'  => $postedLines,
            'duplicates'    => $duplicates,
            'total_posted'  => $totalPosted,
            'lines'         => $out,
        ];
    }

    /**
     * @param array<string, mixed> $purchase
     * @param list<string> $reasons
     * @param array{user_id:int,member_id:string}|null $beneficiary
     * @param array<string, mixed>|null $plan
     * @return array<string, mixed>
     */
    private function lineSkeleton(
        array $purchase,
        int $level,
        string $status,
        array $reasons,
        ?string $percent,
        ?array $beneficiary,
        string $expected,
        ?string $businessDate = null,
        ?array $plan = null
    ): array {
        $businessDate ??= substr((string) ($purchase['reviewed_at'] ?? $purchase['requested_at'] ?? date('Y-m-d')), 0, 10);

        return [
            'purchase_ref'           => (string) $purchase['purchase_ref'],
            'purchase_id'            => (int) ($purchase['id'] ?? 0),
            'purchaser_user_id'      => (int) $purchase['member_user_id'],
            'purchaser_member_id'    => (string) $purchase['member_id'],
            'sponsor_level'          => $level,
            'beneficiary_user_id'    => $beneficiary['user_id'] ?? null,
            'beneficiary_member_id'  => $beneficiary['member_id'] ?? null,
            'commission_base_usdt'   => MoneyMath::normalize((string) ($purchase['total_usdt'] ?? '0'), $this->scale()),
            'rate_percent'           => $percent,
            'expected_amount_usdt'   => MoneyMath::normalize($expected, $this->scale()),
            'posted_amount_usdt'     => '0.00000000',
            'plan_ref'               => $plan['plan_ref'] ?? null,
            'plan_effective_from'    => $plan['effective_from'] ?? null,
            'business_date'          => $businessDate,
            'status'                 => $status,
            'eligibility_status'     => $status === self::STATUS_CALCULATED ? 'eligible' : 'ineligible',
            'reason_codes'           => array_values(array_unique($reasons)),
        ];
    }

    /**
     * @param array<string, mixed> $purchase
     * @param array<string, mixed>|null $plan
     * @param array<string, mixed> $assumptions
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>
     */
    private function wrapResult(
        array $purchase,
        ?array $plan,
        array $assumptions,
        array $lines,
        bool $persist,
        ?int $actorUserId
    ): array {
        if ($persist && db_connect()->tableExists('commission_purchase_calculations')) {
            $this->persistLines($lines, $assumptions, $actorUserId);
        }

        $totalExpected = MoneyMath::normalize('0', $this->scale());
        $totalPosted   = MoneyMath::normalize('0', $this->scale());
        foreach ($lines as &$line) {
            $posted = $this->postedAmountForLine($line);
            $line['posted_amount_usdt'] = $posted;
            $totalExpected = MoneyMath::add($totalExpected, (string) $line['expected_amount_usdt'], $this->scale());
            $totalPosted   = MoneyMath::add($totalPosted, $posted, $this->scale());
        }
        unset($line);

        return [
            'purchase_ref'     => (string) $purchase['purchase_ref'],
            'business_date'    => $lines[0]['business_date'] ?? date('Y-m-d'),
            'plan'             => $plan,
            'assumptions'      => $assumptions,
            'lines'            => $lines,
            'totals'           => [
                'expected_amount_usdt' => $totalExpected,
                'posted_amount_usdt'   => $totalPosted,
            ],
            'posting_enabled'  => $this->wallets->postingEnabled(),
            'posting_status'   => 'COMMISSION POSTING IS NOT CREDITING WALLETS.',
        ];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param array<string, mixed> $assumptions
     */
    private function persistLines(array $lines, array $assumptions, ?int $actorUserId): void
    {
        $db  = db_connect();
        $now = date('Y-m-d H:i:s');
        foreach ($lines as $line) {
            $idem = 'cpc:' . $line['purchase_ref'] . ':L' . $line['sponsor_level'];
            $existing = $db->table('commission_purchase_calculations')
                ->where('idempotency_key', $idem)
                ->get()
                ->getRowArray();
            if ($existing !== null) {
                continue; // immutable once calculated
            }

            $db->table('commission_purchase_calculations')->insert([
                'calc_ref'               => 'CPC-' . strtoupper(bin2hex(random_bytes(6))),
                'purchase_ref'           => $line['purchase_ref'],
                'purchase_id'            => $line['purchase_id'] ?: null,
                'purchaser_user_id'      => $line['purchaser_user_id'],
                'purchaser_member_id'    => $line['purchaser_member_id'],
                'sponsor_level'          => $line['sponsor_level'],
                'beneficiary_user_id'    => $line['beneficiary_user_id'],
                'beneficiary_member_id'  => $line['beneficiary_member_id'],
                'commission_base_usdt'   => $line['commission_base_usdt'],
                'rate_percent'           => $line['rate_percent'],
                'expected_amount_usdt'   => $line['expected_amount_usdt'],
                'posted_amount_usdt'     => '0.00000000',
                'plan_ref'               => $line['plan_ref'],
                'plan_effective_from'    => $line['plan_effective_from'],
                'business_date'          => $line['business_date'],
                'status'                 => $line['status'],
                'eligibility_status'     => $line['eligibility_status'],
                'reason_codes'           => implode(',', $line['reason_codes']),
                'assumptions_json'       => json_encode($assumptions, JSON_THROW_ON_ERROR),
                'idempotency_key'        => $idem,
                'created_by'             => $actorUserId,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    private function postedAmountForLine(array $line): string
    {
        if (empty($line['beneficiary_user_id']) || empty($line['purchase_ref'])) {
            return MoneyMath::normalize('0', $this->scale());
        }

        if (! db_connect()->tableExists('commission_wallet_ledger_entries')) {
            return MoneyMath::normalize('0', $this->scale());
        }

        $row = db_connect()->table('commission_wallet_ledger_entries')
            ->selectSum('amount', 'qty')
            ->where('member_user_id', (int) $line['beneficiary_user_id'])
            ->where('direction', 'credit')
            ->where('entry_type', 'commission_credit')
            ->groupStart()
                ->where('reference_id', (string) $line['purchase_ref'])
                ->orLike('idempotency_key', 'commission:' . $line['purchase_ref'] . ':L' . $line['sponsor_level'], 'after')
            ->groupEnd()
            ->get()
            ->getRowArray();

        return MoneyMath::normalize((string) ($row['qty'] ?? '0'), $this->scale());
    }

    /**
     * @param array<string, mixed> $calc
     */
    private function postedAmountForCalc(array $calc): string
    {
        return $this->postedAmountForLine([
            'beneficiary_user_id' => $calc['beneficiary_user_id'] ?? null,
            'purchase_ref'        => $calc['purchase_ref'] ?? '',
            'sponsor_level'       => $calc['sponsor_level'] ?? 0,
        ]);
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale ?? 8;
    }
}
