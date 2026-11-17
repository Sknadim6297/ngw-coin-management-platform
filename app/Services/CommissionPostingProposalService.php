<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7D controlled commission posting proposals.
 * Approval does NOT post while commissionPostingEnabled=false.
 * No executable production posting command in this phase.
 */
class CommissionPostingProposalService
{
    public const STATUS_DRAFT            = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED         = 'approved';
    public const STATUS_REJECTED         = 'rejected';
    public const STATUS_CANCELLED        = 'cancelled';

    public function __construct(
        private readonly CommissionPlanService $plans = new CommissionPlanService(),
        private readonly PurchaseSponsorSnapshotService $snapshots = new PurchaseSponsorSnapshotService(),
        private readonly CommissionWalletService $wallets = new CommissionWalletService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @param array{purchase_ref: string, level: int, plan_ref?: string} $data
     * @return array<string, mixed>
     */
    public function createDraft(array $data, int $actorUserId): array
    {
        $purchaseRef = trim((string) ($data['purchase_ref'] ?? ''));
        $level       = (int) ($data['level'] ?? 0);
        if ($purchaseRef === '' || $level < 1 || $level > 3) {
            throw new InvalidArgumentException('purchase_ref and level (1–3) are required.');
        }

        $purchase = db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $purchaseRef)
            ->get()
            ->getRowArray();
        if ($purchase === null) {
            throw new InvalidArgumentException('Purchase not found.');
        }
        if (($purchase['status'] ?? '') !== 'approved') {
            throw new InvalidArgumentException('Only approved purchases can enter posting proposals.');
        }

        $trust = $this->snapshots->evaluateTrust($purchaseRef);
        if ($trust['status'] === PurchaseSponsorSnapshotService::STATUS_UNAVAILABLE) {
            throw new InvalidArgumentException('HISTORICAL_SNAPSHOT_UNAVAILABLE — cannot create payable proposal.');
        }
        if (! $trust['trusted']) {
            throw new InvalidArgumentException('Sponsor snapshot is not trusted for posting: ' . implode(',', $trust['reason_codes']));
        }

        $snapRow = null;
        foreach ($trust['levels'] as $lvl) {
            if ((int) $lvl['sponsor_level'] === $level && empty($lvl['is_missing'])) {
                $snapRow = $lvl;
                break;
            }
        }
        if ($snapRow === null) {
            throw new InvalidArgumentException('No captured sponsor at requested level.');
        }

        $purchaseDate = substr((string) ($purchase['reviewed_at'] ?? $purchase['requested_at'] ?? date('Y-m-d')), 0, 10);
        $plan = null;
        if (! empty($data['plan_ref'])) {
            $plan = $this->plans->findByRef((string) $data['plan_ref']);
        } else {
            $plan = $this->plans->findApplicablePlan($purchaseDate);
        }
        if ($plan === null || ($plan['status'] ?? '') !== CommissionPlanService::STATUS_APPROVED) {
            throw new InvalidArgumentException('Approved commission plan required.');
        }

        $percent = $plan['levels'][$level] ?? null;
        if ($percent === null) {
            throw new InvalidArgumentException('Plan has no percent for level ' . $level);
        }

        $amount = MoneyMath::normalize((string) $purchase['total_usdt'], 8);
        if (! MoneyMath::isPositive($amount, 8)) {
            throw new InvalidArgumentException('Purchase amount missing.');
        }
        if (strtoupper((string) ($purchase['payment_status'] ?? '')) !== 'VERIFIED'
            && (bool) ($this->config ?? config('Platform'))->requireVerifiedDepositForApproval) {
            // When platform requires verified deposits, proposals must too.
            if (($purchase['payment_status'] ?? '') !== 'verified') {
                throw new InvalidArgumentException('Payment verification required for posting proposal.');
            }
        }

        $proposed = MoneyMath::div(MoneyMath::mul($amount, MoneyMath::normalize((string) $percent, 8), 12), '100', 8);
        $ref = 'CPP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');

        $preflight = [
            'purchase_status'   => $purchase['status'],
            'payment_status'    => $purchase['payment_status'] ?? null,
            'snapshot_status'   => $trust['status'],
            'posting_enabled'   => $this->wallets->postingEnabled(),
            'kill_switch'       => ! $this->wallets->postingEnabled(),
            'currency'          => 'USDT',
            'note'              => 'Approval will not post while kill-switch is off.',
        ];

        try {
            db_connect()->table('commission_posting_proposals')->insert([
                'proposal_ref'            => $ref,
                'purchase_ref'            => $purchaseRef,
                'plan_ref'                => $plan['plan_ref'],
                'beneficiary_user_id'     => (int) $snapRow['sponsor_user_id'],
                'beneficiary_member_id'   => (string) $snapRow['sponsor_member_id'],
                'level'                   => $level,
                'purchase_amount'         => $amount,
                'percent'                 => MoneyMath::normalize((string) $percent, 8),
                'proposed_amount'         => $proposed,
                'currency'                => 'USDT',
                'status'                  => self::STATUS_DRAFT,
                'snapshot_status'         => $trust['status'],
                'preflight_json'          => json_encode($preflight, JSON_THROW_ON_ERROR),
                'created_by'              => $actorUserId,
                'is_executable'           => 0,
                'is_posted'               => 0,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw new InvalidArgumentException('Duplicate commission entitlement proposal for purchase/level/plan.');
            }
            throw $e;
        }

        $this->recordEvent($ref, 'created', $actorUserId, null, self::STATUS_DRAFT, $preflight);
        $this->audit->log('commission.posting_proposal_created', $actorUserId, 'commission_posting_proposal', $ref, [
            'purchase_ref' => $purchaseRef,
            'level'        => $level,
        ]);

        return $this->findByRef($ref) ?? ['proposal_ref' => $ref];
    }

    /**
     * @return array<string, mixed>
     */
    public function submit(string $proposalRef, int $actorUserId): array
    {
        $row = $this->require($proposalRef);
        if (($row['status'] ?? '') !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft proposals can be submitted.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('commission_posting_proposals')->where('proposal_ref', $proposalRef)->update([
            'status'       => self::STATUS_PENDING_APPROVAL,
            'submitted_at' => $now,
            'updated_at'   => $now,
        ]);
        $this->recordEvent($proposalRef, 'submitted', $actorUserId, self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL, null);
        $this->audit->log('commission.posting_proposal_submitted', $actorUserId, 'commission_posting_proposal', $proposalRef, []);

        return $this->findByRef($proposalRef) ?? $row;
    }

    /**
     * Dual-control approve. Does NOT credit wallets while posting kill-switch is off.
     *
     * @return array<string, mixed>
     */
    public function approve(string $proposalRef, int $actorUserId): array
    {
        $row = $this->require($proposalRef);
        if (($row['status'] ?? '') !== self::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException('Only pending proposals can be approved.');
        }
        if ((int) ($row['created_by'] ?? 0) === $actorUserId) {
            throw new InvalidArgumentException('Self-approval is not allowed.');
        }

        $executable = $this->wallets->postingEnabled() ? 1 : 0;
        $now = date('Y-m-d H:i:s');
        db_connect()->table('commission_posting_proposals')->where('proposal_ref', $proposalRef)->update([
            'status'        => self::STATUS_APPROVED,
            'approved_by'   => $actorUserId,
            'approved_at'   => $now,
            'is_executable' => $executable,
            'is_posted'     => 0,
            'updated_at'    => $now,
        ]);
        $this->recordEvent($proposalRef, 'approved', $actorUserId, self::STATUS_PENDING_APPROVAL, self::STATUS_APPROVED, [
            'is_executable' => $executable,
            'posted'        => false,
            'note'          => $executable
                ? 'Posting flag on but Phase 7D has no executable poster.'
                : 'Approved but non-executable — commission posting kill-switch is OFF.',
        ]);
        $this->audit->log('commission.posting_proposal_approved', $actorUserId, 'commission_posting_proposal', $proposalRef, [
            'is_executable' => (bool) $executable,
            'is_posted'     => false,
        ]);

        // Hard rule: never credit wallet in Phase 7D.
        if ($executable) {
            throw new RuntimeException('Posting kill-switch unexpectedly enabled; Phase 7D refuses execution.');
        }

        return $this->findByRef($proposalRef) ?? $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $proposalRef, int $actorUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason required.');
        }
        $row = $this->require($proposalRef);
        if (! in_array($row['status'], [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Proposal cannot be rejected in current status.');
        }
        $from = $row['status'];
        $now  = date('Y-m-d H:i:s');
        db_connect()->table('commission_posting_proposals')->where('proposal_ref', $proposalRef)->update([
            'status'           => self::STATUS_REJECTED,
            'rejected_by'      => $actorUserId,
            'rejected_at'      => $now,
            'rejection_reason' => $reason,
            'updated_at'       => $now,
        ]);
        $this->recordEvent($proposalRef, 'rejected', $actorUserId, $from, self::STATUS_REJECTED, ['reason' => $reason]);

        return $this->findByRef($proposalRef) ?? $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        $row = db_connect()->table('commission_posting_proposals')->where('proposal_ref', $ref)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['events'] = db_connect()->table('commission_posting_proposal_events')
            ->where('proposal_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $row;
    }

    public function pendingApprovalCount(): int
    {
        return db_connect()->table('commission_posting_proposals')
            ->where('status', self::STATUS_PENDING_APPROVAL)
            ->countAllResults();
    }

    /**
     * @return array<string, mixed>
     */
    private function require(string $ref): array
    {
        $row = $this->findByRef($ref);
        if ($row === null) {
            throw new InvalidArgumentException('Posting proposal not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $ref,
        string $type,
        int $actor,
        ?string $from,
        ?string $to,
        ?array $payload
    ): void {
        db_connect()->table('commission_posting_proposal_events')->insert([
            'proposal_ref'  => $ref,
            'event_type'    => $type,
            'actor_user_id' => $actor,
            'from_status'   => $from,
            'to_status'     => $to,
            'payload_json'  => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
