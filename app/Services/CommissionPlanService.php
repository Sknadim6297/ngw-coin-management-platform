<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7C commission plan configuration. Dual-control. Approved plans immutable. Simulation-only.
 */
class CommissionPlanService
{
    public const STATUS_DRAFT    = 'draft';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_DISABLED = 'disabled';

    public const PURCHASE_POLICIES = ['approved_verified_usdt', 'approved_only'];
    public const SPONSOR_POLICIES  = ['active_sponsors_only', 'any_registered', 'unresolved'];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createDraft(array $data, int $actorUserId): array
    {
        $name = trim((string) ($data['plan_name'] ?? ''));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Plan name is required (max 120).');
        }

        $from = $this->normalizeDate((string) ($data['effective_from'] ?? ''));
        $to   = trim((string) ($data['effective_to'] ?? ''));
        $to   = $to === '' ? null : $this->normalizeDate($to);
        if ($to !== null && strcmp($to, $from) < 0) {
            throw new InvalidArgumentException('Effective to must be on or after effective from.');
        }

        $levelCount = (int) ($data['level_count'] ?? 3);
        if ($levelCount < 1 || $levelCount > 3) {
            throw new InvalidArgumentException('Level count must be 1–3.');
        }

        $levels = $this->normalizeLevels($data, $levelCount);
        $currency = strtoupper(trim((string) ($data['commission_currency'] ?? 'USDT')));
        if ($currency !== 'USDT') {
            throw new InvalidArgumentException('Only USDT commission currency is supported.');
        }

        $purchasePolicy = strtolower(trim((string) ($data['eligible_purchase_policy'] ?? 'approved_verified_usdt')));
        $sponsorPolicy  = strtolower(trim((string) ($data['sponsor_eligibility_policy'] ?? 'active_sponsors_only')));
        if (! in_array($purchasePolicy, self::PURCHASE_POLICIES, true)) {
            throw new InvalidArgumentException('Invalid eligible purchase policy.');
        }
        if (! in_array($sponsorPolicy, self::SPONSOR_POLICIES, true)) {
            throw new InvalidArgumentException('Invalid sponsor eligibility policy.');
        }

        $ref = 'CPL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');
        $db  = db_connect();

        $db->transStart();
        $db->table('commission_plans')->insert([
            'plan_ref'                   => $ref,
            'plan_name'                  => $name,
            'effective_from'             => $from,
            'effective_to'               => $to,
            'level_count'                => $levelCount,
            'commission_currency'        => $currency,
            'eligible_purchase_policy'   => $purchasePolicy,
            'sponsor_eligibility_policy' => $sponsorPolicy,
            'status'                     => self::STATUS_DRAFT,
            'created_by'                 => $actorUserId,
            'notes'                      => trim((string) ($data['notes'] ?? '')) ?: null,
            'created_at'                 => $now,
            'updated_at'                 => $now,
        ]);
        foreach ($levels as $level => $percent) {
            $db->table('commission_plan_levels')->insert([
                'plan_ref'   => $ref,
                'level'      => $level,
                'percent'    => $percent,
                'created_at' => $now,
            ]);
        }
        $this->recordEvent($ref, 'created', $actorUserId, null, self::STATUS_DRAFT, [
            'levels' => $levels,
        ]);
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('Failed to create commission plan draft.');
        }

        $this->audit->log('commission.plan_created', $actorUserId, 'commission_plan', $ref, [
            'levels' => $levels,
            'from'   => $from,
        ]);

        return $this->findByRef($ref) ?? ['plan_ref' => $ref];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateDraft(string $planRef, array $data, int $actorUserId): array
    {
        $row = $this->requirePlan($planRef);
        if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft plans can be edited.');
        }

        $name = trim((string) ($data['plan_name'] ?? $row['plan_name']));
        if ($name === '' || strlen($name) > 120) {
            throw new InvalidArgumentException('Plan name is required (max 120).');
        }

        $from = $this->normalizeDate((string) ($data['effective_from'] ?? $row['effective_from']));
        $toRaw = array_key_exists('effective_to', $data) ? trim((string) $data['effective_to']) : (string) ($row['effective_to'] ?? '');
        $to    = $toRaw === '' ? null : $this->normalizeDate($toRaw);
        if ($to !== null && strcmp($to, $from) < 0) {
            throw new InvalidArgumentException('Effective to must be on or after effective from.');
        }

        $levelCount = (int) ($data['level_count'] ?? $row['level_count']);
        if ($levelCount < 1 || $levelCount > 3) {
            throw new InvalidArgumentException('Level count must be 1–3.');
        }
        $levels = $this->normalizeLevels($data, $levelCount);

        $purchasePolicy = strtolower(trim((string) ($data['eligible_purchase_policy'] ?? $row['eligible_purchase_policy'])));
        $sponsorPolicy  = strtolower(trim((string) ($data['sponsor_eligibility_policy'] ?? $row['sponsor_eligibility_policy'])));
        if (! in_array($purchasePolicy, self::PURCHASE_POLICIES, true)
            || ! in_array($sponsorPolicy, self::SPONSOR_POLICIES, true)) {
            throw new InvalidArgumentException('Invalid eligibility policy.');
        }

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->transStart();
        $db->table('commission_plans')->where('plan_ref', $planRef)->update([
            'plan_name'                  => $name,
            'effective_from'             => $from,
            'effective_to'               => $to,
            'level_count'                => $levelCount,
            'eligible_purchase_policy'   => $purchasePolicy,
            'sponsor_eligibility_policy' => $sponsorPolicy,
            'notes'                      => trim((string) ($data['notes'] ?? $row['notes'] ?? '')) ?: null,
            'updated_at'                 => $now,
        ]);
        $db->table('commission_plan_levels')->where('plan_ref', $planRef)->delete();
        foreach ($levels as $level => $percent) {
            $db->table('commission_plan_levels')->insert([
                'plan_ref'   => $planRef,
                'level'      => $level,
                'percent'    => $percent,
                'created_at' => $now,
            ]);
        }
        $this->recordEvent($planRef, 'updated', $actorUserId, self::STATUS_DRAFT, self::STATUS_DRAFT, [
            'levels' => $levels,
        ]);
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('Failed to update commission plan draft.');
        }

        $this->audit->log('commission.plan_updated', $actorUserId, 'commission_plan', $planRef, [
            'levels' => $levels,
        ]);

        return $this->findByRef($planRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(string $planRef, int $actorUserId): array
    {
        $row = $this->requirePlan($planRef);
        if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft plans can be approved.');
        }
        if ((int) ($row['created_by'] ?? 0) === $actorUserId) {
            throw new InvalidArgumentException('Self-approval is not allowed. A different finance approver must approve.');
        }

        $this->assertNoOverlap($row['effective_from'], $row['effective_to'] ?? null, $planRef);

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->transStart();
        $db->table('commission_plans')->where('plan_ref', $planRef)->where('status', self::STATUS_DRAFT)->update([
            'status'      => self::STATUS_APPROVED,
            'approved_by' => $actorUserId,
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);
        if ($db->affectedRows() !== 1) {
            $db->transComplete();
            throw new RuntimeException('Commission plan approval failed (race or invalid state).');
        }
        $this->recordEvent($planRef, 'approved', $actorUserId, self::STATUS_DRAFT, self::STATUS_APPROVED, null);
        $db->transComplete();
        if (! $db->transStatus()) {
            throw new RuntimeException('Commission plan approval transaction failed.');
        }

        $this->audit->log('commission.plan_approved', $actorUserId, 'commission_plan', $planRef, [
            'effective_from' => $row['effective_from'],
        ]);

        return $this->findByRef($planRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function disable(string $planRef, int $actorUserId): array
    {
        $row = $this->requirePlan($planRef);
        if (($row['status'] ?? '') !== self::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved plans can be disabled.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('commission_plans')->where('plan_ref', $planRef)->update([
            'status'      => self::STATUS_DISABLED,
            'disabled_by' => $actorUserId,
            'disabled_at' => $now,
            'updated_at'  => $now,
        ]);
        $this->recordEvent($planRef, 'disabled', $actorUserId, self::STATUS_APPROVED, self::STATUS_DISABLED, null);
        $this->audit->log('commission.plan_disabled', $actorUserId, 'commission_plan', $planRef, []);

        return $this->findByRef($planRef) ?? $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlans(?string $status = null, int $limit = 100): array
    {
        $b = db_connect()->table('commission_plans')->orderBy('effective_from', 'DESC')->orderBy('id', 'DESC');
        if ($status !== null && $status !== '') {
            $b->where('status', $status);
        }
        $rows = $b->limit(max(1, min(200, $limit)))->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['levels'] = $this->levelsFor($row['plan_ref']);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $planRef): ?array
    {
        $row = db_connect()->table('commission_plans')->where('plan_ref', $planRef)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['levels'] = $this->levelsFor($planRef);
        $row['events'] = db_connect()->table('commission_plan_approval_events')
            ->where('plan_ref', $planRef)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $row;
    }

    /**
     * Active approved plan covering a purchase/decision date.
     *
     * @return array<string, mixed>|null
     */
    public function findApplicablePlan(string $dateYmd): ?array
    {
        $date = $this->normalizeDate($dateYmd);
        $row  = db_connect()->table('commission_plans')
            ->where('status', self::STATUS_APPROVED)
            ->where('effective_from <=', $date)
            ->groupStart()
                ->where('effective_to', null)
                ->orWhere('effective_to >=', $date)
            ->groupEnd()
            ->orderBy('effective_from', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        if ($row === null) {
            return null;
        }
        $row['levels'] = $this->levelsFor($row['plan_ref']);

        return $row;
    }

    public function pendingApprovalCount(): int
    {
        return db_connect()->table('commission_plans')->where('status', self::STATUS_DRAFT)->countAllResults();
    }

    /**
     * @return array<int, string> level => percent
     */
    public function levelsFor(string $planRef): array
    {
        $rows = db_connect()->table('commission_plan_levels')
            ->where('plan_ref', $planRef)
            ->orderBy('level', 'ASC')
            ->get()
            ->getResultArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['level']] = MoneyMath::normalize((string) $r['percent'], 8);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, string>
     */
    private function normalizeLevels(array $data, int $levelCount): array
    {
        $levels = [];
        for ($i = 1; $i <= $levelCount; $i++) {
            $raw = $data['level_' . $i . '_percent'] ?? ($data['levels'][$i] ?? null);
            if ($raw === null || $raw === '') {
                throw new InvalidArgumentException("Level {$i} percentage is required.");
            }
            $percent = MoneyMath::normalize((string) $raw, 8);
            if (! MoneyMath::isPositive($percent, 8) || MoneyMath::compare($percent, '100', 8) === 1) {
                throw new InvalidArgumentException("Level {$i} percent must be > 0 and ≤ 100.");
            }
            $levels[$i] = $percent;
        }

        return $levels;
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        $parts = explode('-', $date);
        if (! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
            throw new InvalidArgumentException('Invalid calendar date.');
        }

        return $date;
    }

    /**
     * @return array<string, mixed>
     */
    private function requirePlan(string $planRef): array
    {
        $row = $this->findByRef($planRef);
        if ($row === null) {
            throw new InvalidArgumentException('Commission plan not found.');
        }

        return $row;
    }

    private function assertNoOverlap(string $from, ?string $to, string $excludeRef): void
    {
        $rows = db_connect()->table('commission_plans')
            ->where('status', self::STATUS_APPROVED)
            ->where('plan_ref !=', $excludeRef)
            ->get()
            ->getResultArray();

        foreach ($rows as $r) {
            $aFrom = (string) $r['effective_from'];
            $aTo   = $r['effective_to'] !== null && $r['effective_to'] !== '' ? (string) $r['effective_to'] : '9999-12-31';
            $bTo   = $to ?? '9999-12-31';
            if ($from <= $aTo && $aFrom <= $bTo) {
                throw new InvalidArgumentException(
                    'Overlapping approved effective period with plan ' . $r['plan_ref'] . '.'
                );
            }
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $planRef,
        string $eventType,
        int $actorUserId,
        ?string $fromStatus,
        ?string $toStatus,
        ?array $payload
    ): void {
        db_connect()->table('commission_plan_approval_events')->insert([
            'plan_ref'      => $planRef,
            'event_type'    => $eventType,
            'actor_user_id' => $actorUserId,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'payload_json'  => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
