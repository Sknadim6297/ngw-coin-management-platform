<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningMath;
use App\Libraries\MoneyMath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7B mining rate configuration with dual-control approval.
 * Approved historical rates are immutable. Never posts mining rewards.
 */
class MiningRateService
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISABLED = 'disabled';

    public const BASES = ['daily', 'monthly', 'annual'];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @param array{effective_month: string, rate_percent: string, rate_basis?: string, notes?: string} $data
     * @return array<string, mixed>
     */
    public function createDraft(array $data, int $actorUserId): array
    {
        $month = $this->normalizeMonth($data['effective_month'] ?? '');
        $basis = strtolower(trim((string) ($data['rate_basis'] ?? 'monthly')));
        if (! in_array($basis, self::BASES, true)) {
            throw new InvalidArgumentException('Rate basis must be daily, monthly, or annual.');
        }

        $percent = MoneyMath::normalize((string) ($data['rate_percent'] ?? ''), 8);
        if (! MoneyMath::isPositive($percent, 8) || MoneyMath::compare($percent, '100', 8) === 1) {
            throw new InvalidArgumentException('Rate percent must be greater than 0 and at most 100.');
        }

        $ref = 'MRC-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');

        db_connect()->table('mining_rate_configs')->insert([
            'rate_ref'            => $ref,
            'effective_month'     => $month,
            'rate_percent'        => $percent,
            'rate_basis'          => $basis,
            'precision_scale'     => 8,
            'eligibility_policy'  => MiningEligibilityService::POLICY_TOTAL_OWNED_ACTIVE,
            'status'              => self::STATUS_DRAFT,
            'created_by'          => $actorUserId,
            'notes'               => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $this->recordEvent($ref, 'rate.created_draft', $actorUserId, null, self::STATUS_DRAFT, [
            'effective_month' => $month,
            'rate_percent'    => $percent,
            'rate_basis'      => $basis,
        ]);

        $this->audit->log('mining.rate_draft_created', $actorUserId, 'mining_rate', $ref, [
            'effective_month' => $month,
        ]);

        return $this->findByRef($ref);
    }

    /**
     * Self-approval is refused under dual_control. Under single_admin governance the designated administrator may
     * approve their own 3% monthly rate with $singleAdmin (password + review_ref, terms_hash, confirm,
     * confirmed_terms, acknowledge) validated by FinancialGovernanceService.
     *
     * @param array<string, mixed>|null $singleAdmin
     * @return array<string, mixed>
     */
    public function approve(string $rateRef, int $approverUserId, ?array $singleAdmin = null): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM mining_rate_configs WHERE rate_ref = ? FOR UPDATE',
                [$rateRef]
            )->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Rate configuration not found.');
            }
            if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
                throw new InvalidArgumentException('Only draft rates can be approved.');
            }
            $governance = service('financialGovernanceService');
            $auth       = null;
            if ((int) $row['created_by'] === $approverUserId) {
                if (! $governance->isSingleAdmin()) {
                    throw new InvalidArgumentException('Self-approval of mining rates is not allowed.');
                }
                $governance->assertKillSwitchesOff();
                $auth = $governance->authorize(FinancialGovernanceService::SUBJECT_RATE, $rateRef, FinancialGovernanceService::INTENT_APPROVE, $approverUserId, (string) ($singleAdmin['password'] ?? ''), $singleAdmin ?? []);
            }
            $mode = $auth !== null ? FinancialGovernanceService::MODE_SINGLE_ADMIN : FinancialGovernanceService::MODE_DUAL_CONTROL;

            $overlap = $db->table('mining_rate_configs')
                ->where('status', self::STATUS_APPROVED)
                ->where('effective_month', $row['effective_month'])
                ->where('rate_ref !=', $rateRef)
                ->countAllResults();
            if ($overlap > 0) {
                throw new InvalidArgumentException('An approved rate already exists for this effective month.');
            }

            $now = date('Y-m-d H:i:s');
            $db->table('mining_rate_configs')->where('rate_ref', $rateRef)->where('status', self::STATUS_DRAFT)->update([
                'status'      => self::STATUS_APPROVED,
                'approved_by' => $approverUserId,
                'approved_at' => $now,
                'updated_at'  => $now,
            ]);
            if ($db->affectedRows() !== 1) {
                throw new RuntimeException('Rate approval failed (race or invalid state).');
            }

            $this->recordEvent($rateRef, 'rate.approved', $approverUserId, self::STATUS_DRAFT, self::STATUS_APPROVED, [
                'governance_mode' => $mode,
                'review_ref'      => $auth['review_ref'] ?? null,
            ]);
            $governance->record(FinancialGovernanceService::SUBJECT_RATE, $rateRef, FinancialGovernanceService::INTENT_APPROVE, $approverUserId, $auth);
            $this->audit->log('mining.rate_approved', $approverUserId, 'mining_rate', $rateRef, [
                'effective_month' => $row['effective_month'],
                'rate_percent'    => $row['rate_percent'],
                'governance_mode' => $mode,
                'review_ref'      => $auth['review_ref'] ?? null,
            ]);

            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return $this->findByRef($rateRef);
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $rateRef, int $actorUserId): array
    {
        $row = $this->findByRef($rateRef);
        if (($row['status'] ?? '') === self::STATUS_DISABLED) {
            return $row;
        }
        if (($row['status'] ?? '') === self::STATUS_APPROVED) {
            // Disable is allowed; fields remain immutable except status.
        } elseif (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Rate cannot be disabled from current status.');
        }

        $from = $row['status'];
        $now  = date('Y-m-d H:i:s');
        db_connect()->table('mining_rate_configs')->where('rate_ref', $rateRef)->update([
            'status'      => self::STATUS_DISABLED,
            'disabled_by' => $actorUserId,
            'disabled_at' => $now,
            'updated_at'  => $now,
        ]);
        $this->recordEvent($rateRef, 'rate.disabled', $actorUserId, $from, self::STATUS_DISABLED);
        $this->audit->log('mining.rate_disabled', $actorUserId, 'mining_rate', $rateRef, []);

        return $this->findByRef($rateRef);
    }

    /**
     * Approved rates cannot silently change percent/basis/month.
     */
    public function assertImmutable(string $rateRef): void
    {
        $row = $this->findByRef($rateRef);
        if (($row['status'] ?? '') === self::STATUS_APPROVED) {
            throw new InvalidArgumentException('Approved historical rates are immutable. Create a new draft instead.');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function approvedForDate(string $dateYmd): ?array
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
            throw new InvalidArgumentException('Calculation date must be YYYY-MM-DD.');
        }
        $month = substr($dateYmd, 0, 7);

        $row = db_connect()->table('mining_rate_configs')
            ->where('status', self::STATUS_APPROVED)
            ->where('effective_month', $month)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function findByRef(string $rateRef): array
    {
        $row = db_connect()->table('mining_rate_configs')->where('rate_ref', $rateRef)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Rate configuration not found.');
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRates(?string $status = null, int $limit = 100): array
    {
        $builder = db_connect()->table('mining_rate_configs')->orderBy('id', 'DESC')->limit($limit);
        if ($status !== null && $status !== '') {
            $builder->where('status', $status);
        }

        return $builder->get()->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsFor(string $rateRef): array
    {
        return db_connect()->table('mining_rate_approval_events')
            ->where('rate_ref', $rateRef)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function pendingApprovalCount(): int
    {
        return (int) db_connect()->table('mining_rate_configs')
            ->where('status', self::STATUS_DRAFT)
            ->countAllResults();
    }

    /**
     * Convert configured rate into a daily factor applied to eligible coins.
     *
     * Conventions:
     * - daily: percent/100 per calendar day
     * - monthly: (percent/100) / approved monthly day divisor (DRAFT config)
     * - annual: (percent/100) / 365 (fixed; non-leap convention for determinism)
     * Rounding: BCMath scale truncation toward zero at coinQuantityScale.
     *
     * Coin Plan uses MONTHLY rates. Do not treat monthly % as a daily %.
     */
    public function dailyFactor(array $rate, string $calculationDate): string
    {
        $scale   = (int) ($rate['precision_scale'] ?? 8);
        $percent = MoneyMath::normalize((string) $rate['rate_percent'], $scale);
        $basis   = (string) $rate['rate_basis'];
        $ratio   = MoneyMath::div($percent, '100', $scale + 4);

        if ($basis === 'daily') {
            return MoneyMath::normalize($ratio, $scale + 4);
        }
        if ($basis === 'monthly') {
            $days = (string) $this->monthDayDivisor($calculationDate, (string) ($rate['effective_month'] ?? substr($calculationDate, 0, 7)));

            return MoneyMath::div($ratio, $days, $scale + 4);
        }
        if ($basis === 'annual') {
            return MoneyMath::div($ratio, '365', $scale + 4);
        }

        throw new InvalidArgumentException('Unsupported rate basis.');
    }

    /**
     * DRAFT-approved monthly divisor selection for dry-run / future posting.
     */
    public function monthDayDivisor(string $calculationDate, ?string $effectiveMonth = null, ?string $divisorMode = null): int
    {
        $mode = strtolower((string) ($divisorMode ?? config('Platform')->miningMonthDayDivisorMode ?? 'calendar_days'));
        if ($mode === 'fixed_30') {
            return 30;
        }

        $ym = $effectiveMonth !== null && preg_match('/^\d{4}-\d{2}$/', $effectiveMonth)
            ? $effectiveMonth
            : substr($calculationDate, 0, 7);

        return $this->daysInMonth($ym);
    }

    /**
     * Daily amount for $eligibleCoins. Phase 13B: evaluated exactly (bcmath, 40 dp intermediate) and
     * rounded once at the end. `dailyFactor()` is informational only and is NOT used here, because its
     * 12-dp value used to be truncated again at 8 dp (Phase 13A finding).
     *
     * Divisor and rounding come from the Platform draft policy unless overridden (batch posting passes the
     * frozen run policy explicitly so a mid-run config change cannot alter amounts).
     */
    public function previewAmount(
        string $eligibleCoins,
        array $rate,
        string $calculationDate,
        ?string $divisorMode = null,
        ?string $roundingMode = null
    ): string {
        $cfg     = config('Platform');
        $scale   = (int) ($rate['precision_scale'] ?? 8);
        $divisor = MiningMath::divisor(
            (string) $rate['rate_basis'],
            $calculationDate,
            isset($rate['effective_month']) ? (string) $rate['effective_month'] : null,
            $divisorMode ?? strtolower((string) ($cfg->miningMonthDayDivisorMode ?? 'calendar_days'))
        );

        return MiningMath::dailyAmount(
            MoneyMath::normalize($eligibleCoins, 8),
            (string) $rate['rate_percent'],
            $divisor,
            $scale,
            $roundingMode ?? strtolower((string) ($cfg->miningRoundingMode ?? 'down'))
        );
    }

    private function daysInMonth(string $ym): int
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $ym)) {
            throw new InvalidArgumentException('Invalid month.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $ym . '-01');
        if ($dt === false) {
            throw new InvalidArgumentException('Invalid month.');
        }

        return (int) $dt->format('t');
    }

    private function normalizeMonth(string $month): string
    {
        $month = trim($month);
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            throw new InvalidArgumentException('Effective month must be YYYY-MM.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $month . '-01');
        if ($dt === false || $dt->format('Y-m') !== $month) {
            throw new InvalidArgumentException('Effective month is invalid.');
        }

        return $month;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordEvent(
        string $rateRef,
        string $type,
        int $actorUserId,
        ?string $from,
        ?string $to,
        array $payload = []
    ): void {
        db_connect()->table('mining_rate_approval_events')->insert([
            'rate_ref'       => $rateRef,
            'event_type'     => $type,
            'actor_user_id'  => $actorUserId,
            'from_status'    => $from,
            'to_status'      => $to,
            'payload_json'   => $payload === [] ? null : json_encode($payload),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
