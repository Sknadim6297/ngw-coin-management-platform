<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FinancialAlertModel;
use InvalidArgumentException;

/**
 * Phase 4D: internal financial alerts with dedupe and acknowledgement.
 * External notification providers are not invoked unless explicitly configured later.
 */
class FinancialAlertService
{
    public const TYPE_RPC_UNAVAILABLE           = 'rpc_unavailable';
    public const TYPE_CHAIN_MISMATCH            = 'chain_id_mismatch';
    public const TYPE_REORG_SUSPECTED           = 'reorg_suspected';
    public const TYPE_INTEGRITY_INVESTIGATION   = 'deposit_integrity_investigation';
    public const TYPE_ORPHAN_ALLOCATION         = 'orphan_allocation';
    public const TYPE_DUPLICATE_CONSUMPTION     = 'duplicate_consumption';
    public const TYPE_APPROVED_WITHOUT_PAYMENT  = 'approved_without_payment';
    public const TYPE_LEDGER_MISMATCH           = 'ownership_inventory_mismatch';
    public const TYPE_REPEATED_VERIFY_FAILURES  = 'repeated_verification_failures';

    public function __construct(
        private readonly FinancialAlertModel $alerts = new FinancialAlertModel(),
        private readonly AuditLogger $audit = new AuditLogger()
    ) {
    }

    /**
     * Upsert by dedupe_key. Returns the alert row.
     *
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    public function raise(
        string $type,
        string $severity,
        string $title,
        string $message,
        string $dedupeKey,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = []
    ): array {
        $now  = date('Y-m-d H:i:s');
        $safe = $this->redact($message);
        $existing = $this->alerts->where('dedupe_key', $dedupeKey)->first();

        if ($existing !== null) {
            $this->alerts->update((int) $existing['id'], [
                'status'            => 'open',
                'severity'          => $severity,
                'title'             => mb_substr($title, 0, 180),
                'message'           => mb_substr($safe, 0, 500),
                'occurrence_count'  => (int) $existing['occurrence_count'] + 1,
                'last_seen_at'      => $now,
                'acknowledged_by'   => null,
                'acknowledged_at'   => null,
                'ack_note'          => null,
                'metadata'          => json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);

            $updated = $this->alerts->find((int) $existing['id']);
            $this->maybeEnqueueNotification($updated);

            return $updated;
        }

        $ref = 'ALT-' . strtoupper(bin2hex(random_bytes(6)));
        $id  = $this->alerts->insert([
            'alert_ref'         => $ref,
            'alert_type'        => $type,
            'severity'          => $severity,
            'dedupe_key'        => $dedupeKey,
            'status'            => 'open',
            'title'             => mb_substr($title, 0, 180),
            'message'           => mb_substr($safe, 0, 500),
            'entity_type'       => $entityType,
            'entity_id'         => $entityId,
            'metadata'          => json_encode($metadata, JSON_THROW_ON_ERROR),
            'occurrence_count'  => 1,
            'first_seen_at'     => $now,
            'last_seen_at'      => $now,
        ], true);

        $created = $this->alerts->find($id);
        $this->maybeEnqueueNotification($created);

        return $created;
    }

    /**
     * @param array<string, mixed>|null $alert
     */
    private function maybeEnqueueNotification(?array $alert): void
    {
        if ($alert === null) {
            return;
        }

        try {
            service('financialNotificationService')->enqueueForAlert($alert);
        } catch (\Throwable $e) {
            log_message('warning', 'Financial notification enqueue skipped: {message}', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function acknowledge(string $alertRef, int $adminUserId, string $note = ''): array
    {
        $row = $this->alerts->where('alert_ref', $alertRef)->first();
        if ($row === null) {
            throw new InvalidArgumentException('Alert not found.');
        }

        if ($row['status'] === 'acknowledged') {
            return $row; // idempotent
        }

        $now = date('Y-m-d H:i:s');
        $this->alerts->update((int) $row['id'], [
            'status'          => 'acknowledged',
            'acknowledged_by' => $adminUserId,
            'acknowledged_at' => $now,
            'ack_note'        => mb_substr(trim($note), 0, 500),
        ]);

        $this->audit->log('finance.alert_acknowledged', $adminUserId, 'financial_alert', $alertRef, [
            'type' => $row['alert_type'],
        ]);

        return $this->alerts->find((int) $row['id']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOpen(int $limit = 100): array
    {
        return $this->alerts->where('status', 'open')
            ->orderBy('severity', 'ASC')
            ->orderBy('last_seen_at', 'DESC')
            ->findAll($limit);
    }

    /**
     * @return array{rows: list<array<string, mixed>>, pager: mixed}
     */
    public function search(?string $status = null, ?string $type = null, int $perPage = 20): array
    {
        if ($status !== null && $status !== '') {
            $this->alerts->where('status', $status);
        }
        if ($type !== null && $type !== '') {
            $this->alerts->where('alert_type', $type);
        }

        $rows = $this->alerts->orderBy('id', 'DESC')->paginate($perPage);

        return ['rows' => $rows, 'pager' => $this->alerts->pager];
    }

    private function redact(string $message): string
    {
        $clean = preg_replace('#https?://\S+#i', '[redacted-url]', $message) ?? $message;
        $clean = preg_replace('/(api[_-]?key|token|secret|password)=[^\s&]+/i', '$1=[redacted]', $clean) ?? $clean;

        return $clean;
    }
}
