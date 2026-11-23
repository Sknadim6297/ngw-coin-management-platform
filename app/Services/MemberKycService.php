<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal Admin-reviewed KYC for commission withdrawals.
 * Members submit; only Admin can approve/reject. Members cannot self-approve.
 */
class MemberKycService
{
    public const STATUS_NONE     = 'none';
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const DOCUMENT_TYPES = ['national_id', 'passport', 'drivers_license', 'other'];

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function statusForMember(int $memberUserId): array
    {
        $row = $this->find($memberUserId);
        if ($row === null) {
            return [
                'status'          => self::STATUS_NONE,
                'is_approved'     => false,
                'full_name'       => null,
                'document_type'   => null,
                'document_number' => null,
                'member_notes'    => null,
                'admin_notes'     => null,
                'submitted_at'    => null,
                'reviewed_at'     => null,
            ];
        }

        return [
            'status'          => (string) $row['status'],
            'is_approved'     => (string) $row['status'] === self::STATUS_APPROVED,
            'full_name'       => $row['full_name'] !== null ? (string) $row['full_name'] : null,
            'document_type'   => $row['document_type'] !== null ? (string) $row['document_type'] : null,
            'document_number' => $this->maskDocument($row['document_number'] !== null ? (string) $row['document_number'] : null),
            'member_notes'    => $row['member_notes'] !== null ? (string) $row['member_notes'] : null,
            'admin_notes'     => $row['admin_notes'] !== null ? (string) $row['admin_notes'] : null,
            'submitted_at'    => $row['submitted_at'] !== null ? (string) $row['submitted_at'] : null,
            'reviewed_at'     => $row['reviewed_at'] !== null ? (string) $row['reviewed_at'] : null,
        ];
    }

    public function isApproved(int $memberUserId): bool
    {
        $row = $this->find($memberUserId);

        return $row !== null && (string) $row['status'] === self::STATUS_APPROVED;
    }

    public function assertApproved(int $memberUserId): void
    {
        if (! $this->isApproved($memberUserId)) {
            throw new InvalidArgumentException('KYC approval is required before commission withdrawal.');
        }
    }

    /**
     * @param array{full_name: string, document_type: string, document_number: string, member_notes?: string} $data
     * @return array<string, mixed>
     */
    public function submit(int $memberUserId, array $data): array
    {
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $existing = $this->find($memberUserId);
        if ($existing !== null && (string) $existing['status'] === self::STATUS_APPROVED) {
            throw new InvalidArgumentException('KYC is already approved.');
        }
        if ($existing !== null && (string) $existing['status'] === self::STATUS_PENDING) {
            throw new InvalidArgumentException('KYC is already pending Admin review.');
        }

        $fullName = trim((string) ($data['full_name'] ?? ''));
        $docType  = strtolower(trim((string) ($data['document_type'] ?? '')));
        $docNo    = trim((string) ($data['document_number'] ?? ''));
        $notes    = trim((string) ($data['member_notes'] ?? ''));

        if ($fullName === '' || strlen($fullName) < 3) {
            throw new InvalidArgumentException('Full legal name is required.');
        }
        if (! in_array($docType, self::DOCUMENT_TYPES, true)) {
            throw new InvalidArgumentException('Invalid document type.');
        }
        if ($docNo === '' || strlen($docNo) < 4) {
            throw new InvalidArgumentException('Document number is required.');
        }

        $now = date('Y-m-d H:i:s');
        $payload = [
            'member_user_id'  => $memberUserId,
            'member_id'       => (string) $profile->member_id,
            'status'          => self::STATUS_PENDING,
            'full_name'       => substr($fullName, 0, 120),
            'document_type'   => $docType,
            'document_number' => substr($docNo, 0, 80),
            'member_notes'    => $notes !== '' ? substr($notes, 0, 500) : null,
            'admin_notes'     => null,
            'submitted_at'    => $now,
            'reviewed_by'     => null,
            'reviewed_at'     => null,
            'updated_at'      => $now,
        ];

        $db = db_connect();
        if (! $db->tableExists('member_kyc')) {
            throw new RuntimeException('member_kyc table is missing. Run migrations.');
        }

        if ($existing === null) {
            $payload['created_at'] = $now;
            $db->table('member_kyc')->insert($payload);
        } else {
            $db->table('member_kyc')->where('member_user_id', $memberUserId)->update($payload);
        }

        $this->audit->log('member.kyc_submitted', $memberUserId, 'member_kyc', (string) $profile->member_id, [
            'document_type' => $docType,
        ]);

        return $this->statusForMember($memberUserId);
    }

    /**
     * @return array<string, mixed>
     */
    public function approve(int $memberUserId, int $adminUserId, string $adminNotes = ''): array
    {
        return $this->review($memberUserId, $adminUserId, self::STATUS_APPROVED, $adminNotes);
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(int $memberUserId, int $adminUserId, string $adminNotes): array
    {
        $adminNotes = trim($adminNotes);
        if ($adminNotes === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        return $this->review($memberUserId, $adminUserId, self::STATUS_REJECTED, $adminNotes);
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(array $filters = [], int $limit = 100): array
    {
        if (! db_connect()->tableExists('member_kyc')) {
            return [];
        }
        $b = db_connect()->table('member_kyc')->orderBy('id', 'DESC');
        if (! empty($filters['status'])) {
            $b->where('status', (string) $filters['status']);
        }
        if (! empty($filters['member_q'])) {
            $q = trim((string) $filters['member_q']);
            $b->groupStart()->like('member_id', $q)->orLike('full_name', $q)->groupEnd();
        }
        $rows = $b->limit(max(1, min(200, $limit)))->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['document_number_masked'] = $this->maskDocument(
                $row['document_number'] !== null ? (string) $row['document_number'] : null
            );
            // Never expose full document number to list views.
            unset($row['document_number']);
        }

        return $rows;
    }

    /**
     * Admin detail (includes full document number for review).
     *
     * @return array<string, mixed>|null
     */
    public function adminFind(int $memberUserId): ?array
    {
        return $this->find($memberUserId);
    }

    /**
     * @return array<string, mixed>
     */
    private function review(int $memberUserId, int $adminUserId, string $toStatus, string $adminNotes): array
    {
        if ($adminUserId <= 0) {
            throw new InvalidArgumentException('Admin actor is required.');
        }
        if ($adminUserId === $memberUserId) {
            throw new InvalidArgumentException('Members cannot approve or reject their own KYC.');
        }

        $row = $this->find($memberUserId);
        if ($row === null || (string) $row['status'] !== self::STATUS_PENDING) {
            throw new InvalidArgumentException('Only pending KYC submissions can be reviewed.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('member_kyc')->where('member_user_id', $memberUserId)->update([
            'status'      => $toStatus,
            'admin_notes' => $adminNotes !== '' ? substr($adminNotes, 0, 500) : null,
            'reviewed_by' => $adminUserId,
            'reviewed_at' => $now,
            'updated_at'  => $now,
        ]);

        $this->audit->log(
            $toStatus === self::STATUS_APPROVED ? 'admin.kyc_approved' : 'admin.kyc_rejected',
            $adminUserId,
            'member_kyc',
            (string) $row['member_id'],
            ['member_user_id' => $memberUserId, 'notes' => $adminNotes]
        );

        return $this->statusForMember($memberUserId);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $memberUserId): ?array
    {
        if ($memberUserId <= 0 || ! db_connect()->tableExists('member_kyc')) {
            return null;
        }

        return db_connect()->table('member_kyc')->where('member_user_id', $memberUserId)->get()->getRowArray();
    }

    private function maskDocument(?string $doc): ?string
    {
        if ($doc === null || $doc === '') {
            return null;
        }
        $len = strlen($doc);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return str_repeat('*', max(0, $len - 4)) . substr($doc, -4);
    }
}
