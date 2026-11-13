<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialRemediationProposalModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 4D dual-control remediation: propose + dual review only.
 * Ledger execution is intentionally unavailable until a proven safe executor exists.
 */
class FinancialRemediationService
{
    public const STATUS_PROPOSED              = 'proposed';
    public const STATUS_APPROVED              = 'approved';
    public const STATUS_REJECTED              = 'rejected';
    public const STATUS_EXECUTION_UNAVAILABLE = 'execution_unavailable';

    public function __construct(
        private readonly FinancialRemediationProposalModel $proposals = new FinancialRemediationProposalModel(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $plannedAfter
     * @return array<string, mixed>
     */
    public const REASON_CODES = [
        'deposit_integrity',
        'allocation_anomaly',
        'ledger_mismatch',
        'payment_coverage',
        'reorg_investigation',
        'other',
    ];

    /**
     * @param array<string, mixed>|null $evidenceFile meta: stored_name, original_name, mime
     */
    public function propose(
        int $proposerUserId,
        string $type,
        string $title,
        string $reason,
        ?string $evidenceNotes,
        ?string $entityType,
        ?string $entityId,
        array $beforeState,
        array $plannedAfter,
        ?string $idempotencyKey = null,
        ?string $reasonCode = null,
        ?array $evidenceFile = null
    ): array {
        $reason = trim($reason);
        $title  = trim($title);
        if ($reason === '' || $title === '') {
            throw new InvalidArgumentException('Title and documented reason are required.');
        }

        $reasonCode = $reasonCode !== null && $reasonCode !== '' ? $reasonCode : $type;
        if (! in_array($reasonCode, self::REASON_CODES, true)) {
            throw new InvalidArgumentException('Invalid remediation reason code.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->proposals->where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        // Duplicate open proposal prevention for same entity + type.
        if ($entityType && $entityId) {
            $dup = $this->proposals
                ->where('status', self::STATUS_PROPOSED)
                ->where('remediation_type', $type)
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->first();
            if ($dup !== null) {
                throw new InvalidArgumentException(
                    'An open remediation proposal already exists for this entity (' . $dup['proposal_ref'] . ').'
                );
            }
        }

        $now = date('Y-m-d H:i:s');
        $ref = 'REM-' . strtoupper(bin2hex(random_bytes(6)));

        $id = $this->proposals->insert([
            'proposal_ref'              => $ref,
            'status'                    => self::STATUS_PROPOSED,
            'remediation_type'          => $type,
            'reason_code'               => $reasonCode,
            'title'                     => mb_substr($title, 0, 180),
            'reason'                    => $reason,
            'evidence_notes'            => $evidenceNotes,
            'evidence_stored_name'      => $evidenceFile['stored_name'] ?? null,
            'evidence_original_name'    => $evidenceFile['original_name'] ?? null,
            'evidence_mime'             => $evidenceFile['mime'] ?? null,
            'entity_type'               => $entityType,
            'entity_id'                 => $entityId,
            'before_state_json'         => json_encode($beforeState, JSON_THROW_ON_ERROR),
            'planned_after_state_json'  => json_encode($plannedAfter, JSON_THROW_ON_ERROR),
            'proposed_by'               => $proposerUserId,
            'proposed_at'               => $now,
            'execution_allowed'         => 0,
            'execution_result'          => null,
            'idempotency_key'           => $idempotencyKey,
        ], true);

        if ($id === false) {
            throw new RuntimeException('Unable to create remediation proposal.');
        }

        $this->recordEvent($ref, $proposerUserId, 'remediation.proposed', [
            'type' => $type,
        ]);
        $this->audit->log('finance.remediation_proposed', $proposerUserId, 'financial_remediation', $ref, [
            'type' => $type,
        ]);

        return $this->proposals->find($id);
    }

    /**
     * Dual-control approve: different admin, marks execution_unavailable (no ledger mutation).
     *
     * @return array<string, mixed>
     */
    public function approve(string $proposalRef, int $reviewerUserId, string $reviewNote, bool $confirm = false): array
    {
        if (! $confirm) {
            throw new InvalidArgumentException('Explicit confirmation is required to approve a remediation proposal.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM financial_remediation_proposals WHERE proposal_ref = ? FOR UPDATE',
                [$proposalRef]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Remediation proposal not found.');
            }

            if ($row['status'] !== self::STATUS_PROPOSED) {
                throw new InvalidArgumentException('Only proposed remediations can be approved.');
            }

            if ((int) $row['proposed_by'] === $reviewerUserId) {
                throw new InvalidArgumentException('Separation of duties: the proposing administrator cannot approve their own proposal.');
            }

            $now = date('Y-m-d H:i:s');
            // Safe execution of ledger mutation is not proven in Phase 4D.
            $db->table('financial_remediation_proposals')->where('id', (int) $row['id'])->update([
                'status'            => self::STATUS_EXECUTION_UNAVAILABLE,
                'reviewed_by'       => $reviewerUserId,
                'review_note'       => trim($reviewNote),
                'reviewed_at'       => $now,
                'execution_allowed' => 0,
                'execution_result'  => 'execution_unavailable',
                'updated_at'        => $now,
            ]);

            $this->recordEvent($proposalRef, $reviewerUserId, 'remediation.approved_execution_unavailable', [
                'note' => $reviewNote,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to approve remediation.');
            }

            $db->transCommit();

            $this->audit->log('finance.remediation_reviewed', $reviewerUserId, 'financial_remediation', $proposalRef, [
                'outcome' => self::STATUS_EXECUTION_UNAVAILABLE,
            ]);

            return $this->proposals->where('proposal_ref', $proposalRef)->first();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    public function reject(string $proposalRef, int $reviewerUserId, string $reviewNote): array
    {
        $row = $this->proposals->where('proposal_ref', $proposalRef)->first();
        if ($row === null) {
            throw new InvalidArgumentException('Remediation proposal not found.');
        }
        if ($row['status'] !== self::STATUS_PROPOSED) {
            throw new InvalidArgumentException('Only proposed remediations can be rejected.');
        }
        if ((int) $row['proposed_by'] === $reviewerUserId) {
            throw new InvalidArgumentException('Separation of duties: proposer cannot reject as sole reviewer of their own proposal in dual-control path.');
        }

        $now = date('Y-m-d H:i:s');
        $this->proposals->update((int) $row['id'], [
            'status'      => self::STATUS_REJECTED,
            'reviewed_by' => $reviewerUserId,
            'review_note' => trim($reviewNote),
            'reviewed_at' => $now,
        ]);

        $this->recordEvent($proposalRef, $reviewerUserId, 'remediation.rejected', ['note' => $reviewNote]);
        $this->audit->log('finance.remediation_rejected', $reviewerUserId, 'financial_remediation', $proposalRef, []);

        return $this->proposals->find((int) $row['id']);
    }

    /**
     * Explicitly blocked — no silent or automated ledger mutation.
     */
    public function execute(string $proposalRef, int $actorUserId): never
    {
        $this->recordEvent($proposalRef, $actorUserId, 'remediation.execute_blocked', []);

        throw new InvalidArgumentException(
            'Remediation execution is unavailable in Phase 4D. Proposals are for investigation/audit only; settled financial records are not silently reversed.'
        );
    }

    /**
     * @return array{rows: list<array<string, mixed>>, pager: mixed}
     */
    public function search(?string $status = null, int $perPage = 20): array
    {
        if ($status !== null && $status !== '') {
            $this->proposals->where('status', $status);
        }
        $rows = $this->proposals->orderBy('id', 'DESC')->paginate($perPage);

        return ['rows' => $rows, 'pager' => $this->proposals->pager];
    }

    public function findByRef(string $ref): ?array
    {
        return $this->proposals->where('proposal_ref', $ref)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsFor(string $proposalRef): array
    {
        return db_connect()->table('financial_remediation_events')
            ->where('proposal_ref', $proposalRef)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(string $proposalRef, int $actorUserId, string $type, array $metadata): void
    {
        db_connect()->table('financial_remediation_events')->insert([
            'proposal_ref'  => $proposalRef,
            'actor_user_id' => $actorUserId,
            'event_type'    => $type,
            'metadata'      => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }
}
