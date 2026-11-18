<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\MiningBlockedException;
use App\Libraries\MiningCalculationPolicy;
use Config\Platform;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Phase 13B — as-of-date eligible ownership, rebuilt from the append-only ownership ledger.
 *
 * Time model (never mixed):
 *  - transaction creation timestamp  = ledger `created_at` (UTC)
 *  - approval timestamp              = coin_purchase_requests.reviewed_at (UTC)
 *  - mining business date            = calendar date in the business timezone (Asia/Kolkata)
 *  - snapshot instant                = business-day end (approval day counts) or start (approval day excluded)
 *  - actual posting timestamp        = mining_wallet_ledger.created_at (when the credit is written)
 *
 * The snapshot uses ONLY ledger rows created strictly before the snapshot instant, so a backdated posting
 * run never sees later purchases, sales or lock movements. Every member's whole ledger chain is verified
 * (previous = prior new, total = available + locked + reserved, non-decreasing timestamps), cross-checked
 * against approved purchases and the cached balance row. Any gap returns BLOCKED_HISTORICAL_DATA for that
 * member with the exact evidence that is missing; balances are never guessed.
 */
class MiningEligibleOwnershipSnapshotService
{
    public const STATUS_ELIGIBLE = 'ELIGIBLE';
    public const STATUS_ZERO     = 'ZERO';
    public const STATUS_BLOCKED  = 'BLOCKED_HISTORICAL_DATA';

    private const SCALE = 8;

    public function __construct(private readonly ?Platform $config = null)
    {
    }

    public function businessTimezone(): DateTimeZone
    {
        $name = (string) (($this->config ?? config('Platform'))->miningBusinessTimezone ?: 'Asia/Kolkata');

        return new DateTimeZone($name);
    }

    /** UTC `Y-m-d H:i:s` of 00:00:00 of the business date in the business timezone. */
    public function dayStartUtc(string $businessDate): string
    {
        return $this->startImmutable($businessDate)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * @return array{cutoff_utc: string, incomplete_day: bool, basis: string}
     */
    public function cutoff(string $businessDate, MiningCalculationPolicy $policy, ?DateTimeImmutable $now = null, bool $allowIncompleteDay = false): array
    {
        $utc   = new DateTimeZone('UTC');
        $start = $this->startImmutable($businessDate);

        if ($policy->usesStartOfDaySnapshot()) {
            return ['cutoff_utc' => $start->setTimezone($utc)->format('Y-m-d H:i:s'), 'incomplete_day' => false, 'basis' => 'start_of_day'];
        }

        $end = $start->modify('+1 day');
        $now = ($now ?? new DateTimeImmutable('now', $utc))->setTimezone($utc);
        if ($end > $now) {
            if (! $allowIncompleteDay) {
                throw new MiningBlockedException(sprintf(
                    'Business date %s is not complete in %s yet (ends %s UTC). Refusing to snapshot a partial day.',
                    $businessDate,
                    $this->businessTimezone()->getName(),
                    $end->setTimezone($utc)->format('Y-m-d H:i:s')
                ));
            }

            // Ledger timestamps have 1-second resolution and the cutoff is exclusive: use the end of the current
            // second so rows written in the same second as the freeze are not ambiguous. The value is stored
            // with the day run, so every later recompute uses exactly this instant.
            return ['cutoff_utc' => $now->modify('+1 second')->format('Y-m-d H:i:s'), 'incomplete_day' => true, 'basis' => 'end_of_day_incomplete'];
        }

        return ['cutoff_utc' => $end->setTimezone($utc)->format('Y-m-d H:i:s'), 'incomplete_day' => false, 'basis' => 'end_of_day'];
    }

    /**
     * @param list<int> $userIds
     * @return array<int, array{
     *   status: string, eligible: string, owned: string, available: string, locked: string, reserved: string,
     *   evidence_ref: ?string, reasons: list<string>
     * }>
     */
    public function snapshotBatch(array $userIds, string $cutoffUtc, MiningCalculationPolicy $policy): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return [];
        }

        $db = db_connect();

        $ledger = [];
        foreach ($db->query(
            'SELECT id, transaction_ref, member_user_id, type, quantity, purchase_ref,
                    previous_balance, new_balance, previous_available, new_available,
                    previous_locked, new_locked, previous_reserved, new_reserved, created_at
               FROM member_coin_ownership_transactions
              WHERE member_user_id IN ?
              ORDER BY member_user_id ASC, id ASC',
            [$userIds]
        )->getResultArray() as $row) {
            $ledger[(int) $row['member_user_id']][] = $row;
        }

        $cached = [];
        foreach ($db->query(
            'SELECT member_user_id, balance, available_balance, locked_balance, reserved_balance
               FROM member_coin_balances WHERE member_user_id IN ?',
            [$userIds]
        )->getResultArray() as $row) {
            $cached[(int) $row['member_user_id']] = $row;
        }

        $purchases = [];
        foreach ($db->query(
            "SELECT purchase_ref, member_user_id, coin_quantity, reviewed_at
               FROM coin_purchase_requests WHERE member_user_id IN ? AND status = 'approved'",
            [$userIds]
        )->getResultArray() as $row) {
            $purchases[(int) $row['member_user_id']][(string) $row['purchase_ref']] = $row;
        }

        $matured = [];
        if ($policy->maturity === MiningCalculationPolicy::MATURITY_EXCLUDE_MATURED) {
            foreach ($db->query(
                'SELECT member_user_id, quantity FROM member_coin_locks
                  WHERE member_user_id IN ? AND locked_at < ? AND matures_at < ?
                    AND (unlocked_at IS NULL OR unlocked_at >= ?)
                    AND (cancelled_at IS NULL OR cancelled_at >= ?)',
                [$userIds, $cutoffUtc, $cutoffUtc, $cutoffUtc, $cutoffUtc]
            )->getResultArray() as $row) {
                $u           = (int) $row['member_user_id'];
                $matured[$u] = bcadd($matured[$u] ?? '0', $this->d((string) $row['quantity']), self::SCALE);
            }
        }

        $out = [];
        foreach ($userIds as $uid) {
            $out[$uid] = $this->evaluateMember(
                $ledger[$uid] ?? [],
                $cached[$uid] ?? null,
                $purchases[$uid] ?? [],
                $matured[$uid] ?? '0.00000000',
                $cutoffUtc,
                $policy
            );
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>>        $rows
     * @param array<string, mixed>|null         $cached
     * @param array<string, array<string, mixed>> $purchases
     * @return array<string, mixed>
     */
    private function evaluateMember(array $rows, ?array $cached, array $purchases, string $maturedLocked, string $cutoffUtc, MiningCalculationPolicy $policy): array
    {
        $zero = '0.00000000';
        $run  = ['t' => $zero, 'a' => $zero, 'l' => $zero, 'r' => $zero];
        $asof = $run;
        $err  = [];
        $evidence = null;
        $lastTs   = null;
        $creditRefs = [];

        foreach ($rows as $row) {
            $ref  = (string) $row['transaction_ref'];
            $type = (string) $row['type'];

            $legacy = $row['previous_available'] === null || $row['new_available'] === null;
            $pt     = $this->d((string) $row['previous_balance']);
            $nt     = $this->d((string) $row['new_balance']);
            if ($legacy) {
                if (in_array($type, ['LOCK', 'UNLOCK', 'SALE_RESERVE', 'SALE_RELEASE', 'SALE_DEBIT'], true)) {
                    $err[] = 'ledger_breakdown_missing:' . $ref;
                    break;
                }
                $prev = ['t' => $pt, 'a' => $pt, 'l' => $zero, 'r' => $zero];
                $new  = ['t' => $nt, 'a' => $nt, 'l' => $zero, 'r' => $zero];
            } else {
                $prev = [
                    't' => $pt,
                    'a' => $this->d((string) $row['previous_available']),
                    'l' => $this->d((string) ($row['previous_locked'] ?? '0')),
                    'r' => $this->d((string) ($row['previous_reserved'] ?? '0')),
                ];
                $new = [
                    't' => $nt,
                    'a' => $this->d((string) $row['new_available']),
                    'l' => $this->d((string) ($row['new_locked'] ?? '0')),
                    'r' => $this->d((string) ($row['new_reserved'] ?? '0')),
                ];
            }

            if ($prev !== $run) {
                $err[] = 'ledger_chain_break:' . $ref;
                break;
            }
            foreach ([$prev, $new] as $state) {
                $sum = bcadd(bcadd($state['a'], $state['l'], self::SCALE), $state['r'], self::SCALE);
                if (bccomp($sum, $state['t'], self::SCALE) !== 0 || bccomp($state['t'], '0', self::SCALE) < 0
                    || bccomp($state['a'], '0', self::SCALE) < 0 || bccomp($state['l'], '0', self::SCALE) < 0 || bccomp($state['r'], '0', self::SCALE) < 0) {
                    $err[] = 'ledger_invariant_violation:' . $ref;
                    break 2;
                }
            }
            if (in_array($type, ['LOCK', 'UNLOCK'], true) && bccomp($prev['t'], $new['t'], self::SCALE) !== 0) {
                $err[] = 'lock_movement_changed_total:' . $ref;
                break;
            }

            $createdAt = $row['created_at'] === null ? null : (string) $row['created_at'];
            if ($createdAt === null || ($lastTs !== null && $createdAt < $lastTs)) {
                $err[] = 'ledger_timestamp_missing_or_out_of_order:' . $ref;
                break;
            }
            $lastTs = $createdAt;

            if ($type === 'PURCHASE_CREDIT') {
                $creditRefs[(string) $row['purchase_ref']] = ['row' => $row, 'before_cutoff' => $createdAt < $cutoffUtc];
            }

            $run = $new;
            if ($createdAt < $cutoffUtc) {
                $asof     = $new;
                $evidence = $ref;
            }
        }

        if ($err === []) {
            foreach ($creditRefs as $purchaseRef => $info) {
                $purchase = $purchases[$purchaseRef] ?? null;
                if ($purchase === null) {
                    $err[] = 'credit_without_approved_purchase:' . $purchaseRef;
                    continue;
                }
                if (bccomp($this->d((string) $purchase['coin_quantity']), $this->d((string) $info['row']['quantity']), self::SCALE) !== 0) {
                    $err[] = 'credit_quantity_differs_from_purchase:' . $purchaseRef;
                }
                $reviewed = $purchase['reviewed_at'] === null ? null : (string) $purchase['reviewed_at'];
                if ($reviewed === null) {
                    $err[] = 'approved_purchase_missing_approval_time:' . $purchaseRef;
                } elseif (($reviewed < $cutoffUtc) !== $info['before_cutoff']) {
                    $err[] = 'approval_time_and_ledger_credit_straddle_snapshot:' . $purchaseRef;
                }
            }
            foreach ($purchases as $purchaseRef => $_) {
                if (! isset($creditRefs[$purchaseRef])) {
                    $err[] = 'approved_purchase_without_ledger_credit:' . $purchaseRef;
                }
            }
        }

        if ($err === []) {
            if ($cached === null) {
                if ($rows !== []) {
                    $err[] = 'ledger_without_cached_balance_row';
                }
            } else {
                $c = [
                    't' => $this->d((string) $cached['balance']),
                    'a' => $this->d((string) ($cached['available_balance'] ?? $cached['balance'])),
                    'l' => $this->d((string) ($cached['locked_balance'] ?? '0')),
                    'r' => $this->d((string) ($cached['reserved_balance'] ?? '0')),
                ];
                if ($rows === []) {
                    if (bccomp($c['t'], '0', self::SCALE) !== 0) {
                        $err[] = 'balance_without_ledger_evidence';
                    }
                } elseif ($c !== $run) {
                    $err[] = 'cached_balance_diverges_from_ledger';
                }
            }
        }

        if ($err !== []) {
            return $this->result(self::STATUS_BLOCKED, $zero, $asof, $evidence, $err);
        }

        $eligible = $asof['t'];
        if ($policy->reserved === MiningCalculationPolicy::RESERVED_EXCLUDE) {
            $eligible = bcsub($eligible, $asof['r'], self::SCALE);
        }
        if ($policy->maturity === MiningCalculationPolicy::MATURITY_EXCLUDE_MATURED) {
            if (bccomp($maturedLocked, $asof['l'], self::SCALE) > 0) {
                return $this->result(self::STATUS_BLOCKED, $zero, $asof, $evidence, ['lock_rows_exceed_ledger_locked_balance']);
            }
            $eligible = bcsub($eligible, $maturedLocked, self::SCALE);
        }
        if (bccomp($eligible, '0', self::SCALE) < 0) {
            return $this->result(self::STATUS_BLOCKED, $zero, $asof, $evidence, ['negative_eligible_ownership']);
        }

        if (bccomp($eligible, '0', self::SCALE) === 0) {
            return $this->result(self::STATUS_ZERO, $zero, $asof, $evidence, ['no_eligible_ownership_as_of_snapshot']);
        }

        return $this->result(self::STATUS_ELIGIBLE, $eligible, $asof, $evidence, []);
    }

    /**
     * @param array{t: string, a: string, l: string, r: string} $asof
     * @param list<string> $reasons
     * @return array<string, mixed>
     */
    private function result(string $status, string $eligible, array $asof, ?string $evidence, array $reasons): array
    {
        return [
            'status'       => $status,
            'eligible'     => $eligible,
            'owned'        => $asof['t'],
            'available'    => $asof['a'],
            'locked'       => $asof['l'],
            'reserved'     => $asof['r'],
            'evidence_ref' => $evidence,
            'reasons'      => $reasons,
        ];
    }

    private function d(string $value): string
    {
        return bcadd($value === '' ? '0' : $value, '0', self::SCALE);
    }

    private function startImmutable(string $businessDate): DateTimeImmutable
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $businessDate, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException('Business date must be a valid Y-m-d date.');
        }

        return new DateTimeImmutable($businessDate . ' 00:00:00', $this->businessTimezone());
    }
}
