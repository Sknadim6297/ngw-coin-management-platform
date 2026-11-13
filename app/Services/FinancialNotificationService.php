<?php

declare(strict_types=1);

namespace App\Services;

use Config\FinanceNotifications;
use RuntimeException;

/**
 * Phase 4E: optional email outbox for financial alerts.
 * Never sends unless emailEnabled + recipients + SMTP are configured.
 */
class FinancialNotificationService
{
    public function __construct(
        private readonly ?FinanceNotifications $config = null,
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly OpsJobLockService $locks = new OpsJobLockService()
    ) {
    }

    public function isDeliveryConfigured(): bool
    {
        $cfg = $this->config ?? config('FinanceNotifications');
        if (! $cfg->emailEnabled) {
            return false;
        }
        if (trim($cfg->recipients) === '') {
            return false;
        }

        $email = config('Email');
        $protocol = strtolower((string) ($email->protocol ?? 'mail'));
        if ($protocol === 'smtp') {
            return trim((string) $email->SMTPHost) !== ''
                && trim((string) $email->fromEmail) !== '';
        }

        // Non-SMTP protocols still require fromEmail; treat as configured only if from is set.
        return trim((string) $email->fromEmail) !== '';
    }

    /**
     * Enqueue a notification for an alert (deduped). No-op if delivery not configured.
     *
     * @param array<string, mixed> $alert
     */
    public function enqueueForAlert(array $alert): ?array
    {
        if (! $this->isDeliveryConfigured()) {
            return null;
        }

        $cfg = $this->config ?? config('FinanceNotifications');
        if (! $this->meetsSeverity((string) ($alert['severity'] ?? 'info'), $cfg->minSeverity)) {
            return null;
        }

        $dedupe = 'alert-email:' . ($alert['dedupe_key'] ?? $alert['alert_ref'] ?? uniqid('a', true));
        $existing = db_connect()->table('notification_outbox')->where('dedupe_key', $dedupe)->get()->getRowArray();
        if ($existing !== null) {
            // Refresh pending/failed for re-open alerts; leave sent alone (dedupe).
            if (in_array($existing['status'], ['pending', 'failed'], true)) {
                db_connect()->table('notification_outbox')->where('id', (int) $existing['id'])->update([
                    'status'         => 'pending',
                    'next_attempt_at'=> date('Y-m-d H:i:s'),
                    'subject'        => mb_substr('[NGW Finance] ' . ($alert['title'] ?? 'Alert'), 0, 255),
                    'body_text'      => $this->buildBody($alert),
                    'updated_at'     => date('Y-m-d H:i:s'),
                ]);
            }

            return $existing;
        }

        $now = date('Y-m-d H:i:s');
        $ref = 'NOB-' . strtoupper(bin2hex(random_bytes(6)));
        $recipients = $this->parseRecipients($cfg->recipients);

        db_connect()->table('notification_outbox')->insert([
            'outbox_ref'          => $ref,
            'channel'             => 'email',
            'dedupe_key'          => $dedupe,
            'status'              => 'pending',
            'severity'            => (string) ($alert['severity'] ?? 'warning'),
            'subject'             => mb_substr('[NGW Finance] ' . ($alert['title'] ?? 'Alert'), 0, 255),
            'body_text'           => $this->buildBody($alert),
            'recipients_redacted' => $this->redactRecipients($recipients),
            'alert_ref'           => $alert['alert_ref'] ?? null,
            'attempt_count'       => 0,
            'next_attempt_at'     => $now,
            'created_at'          => $now,
            'updated_at'          => $now,
        ]);

        $this->recordEvent($ref, 'outbox.enqueued', 'Alert queued for email delivery');

        return db_connect()->table('notification_outbox')->where('outbox_ref', $ref)->get()->getRowArray();
    }

    /**
     * @return array{processed: int, sent: int, failed: int, skipped: int, configured: bool, lock_busy?: bool}
     */
    public function processPending(int $limit = 25, ?string $owner = null): array
    {
        if (! $this->isDeliveryConfigured()) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'configured' => false];
        }

        $owner = $owner ?? ('cli:' . gethostname() . ':' . getmypid());
        if (! $this->locks->acquire('finance_alert_delivery', $owner, 600)) {
            return [
                'processed'  => 0,
                'sent'       => 0,
                'failed'     => 0,
                'skipped'    => 0,
                'configured' => true,
                'lock_busy'  => true,
            ];
        }

        try {
            $cfg   = $this->config ?? config('FinanceNotifications');
            $limit = max(1, min(100, $limit > 0 ? $limit : (int) $cfg->deliveryBatchSize));
            $now   = date('Y-m-d H:i:s');

            $rows = db_connect()->table('notification_outbox')
                ->whereIn('status', ['pending', 'failed'])
                ->where('attempt_count <', (int) $cfg->maxAttempts)
                ->groupStart()
                    ->where('next_attempt_at <=', $now)
                    ->orWhere('next_attempt_at', null)
                ->groupEnd()
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            $sent = $failed = $skipped = 0;
            foreach ($rows as $row) {
                $result = $this->attemptSend($row, $cfg);
                if ($result === 'sent') {
                    $sent++;
                } elseif ($result === 'failed') {
                    $failed++;
                } else {
                    $skipped++;
                }
            }

            return [
                'processed'  => count($rows),
                'sent'       => $sent,
                'failed'     => $failed,
                'skipped'    => $skipped,
                'configured' => true,
            ];
        } finally {
            $this->locks->release('finance_alert_delivery', $owner);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function attemptSend(array $row, FinanceNotifications $cfg): string
    {
        $db = db_connect();
        $db->table('notification_outbox')->where('id', (int) $row['id'])->update([
            'status'     => 'sending',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $attempts = (int) $row['attempt_count'] + 1;
        $recipients = $this->parseRecipients($cfg->recipients);

        try {
            $email = \Config\Services::email();
            $email->setTo($recipients);
            $email->setSubject((string) $row['subject']);
            $email->setMessage((string) $row['body_text']);

            if (! $email->send(false)) {
                throw new RuntimeException($this->redactError($email->printDebugger(['headers'])));
            }

            $now = date('Y-m-d H:i:s');
            $db->table('notification_outbox')->where('id', (int) $row['id'])->update([
                'status'        => 'sent',
                'attempt_count' => $attempts,
                'sent_at'       => $now,
                'last_error'    => null,
                'updated_at'    => $now,
            ]);
            $this->recordEvent((string) $row['outbox_ref'], 'outbox.sent', 'Email delivered');
            $this->audit->log('finance.notification_sent', null, 'notification_outbox', (string) $row['outbox_ref'], [
                'alert_ref' => $row['alert_ref'] ?? null,
            ]);

            return 'sent';
        } catch (\Throwable $e) {
            $backoff = min(
                (int) $cfg->retryMaxBackoffSeconds,
                (int) $cfg->retryBackoffSeconds * (2 ** max(0, $attempts - 1))
            );
            $status = $attempts >= (int) $cfg->maxAttempts ? 'failed' : 'pending';
            $db->table('notification_outbox')->where('id', (int) $row['id'])->update([
                'status'          => $status,
                'attempt_count'   => $attempts,
                'next_attempt_at' => date('Y-m-d H:i:s', time() + $backoff),
                'last_error'      => mb_substr($this->redactError($e->getMessage()), 0, 500),
                'updated_at'      => date('Y-m-d H:i:s'),
            ]);
            $this->recordEvent((string) $row['outbox_ref'], 'outbox.failed', $this->redactError($e->getMessage()));

            return 'failed';
        }
    }

    /**
     * @param array<string, mixed> $alert
     */
    private function buildBody(array $alert): string
    {
        $lines = [
            'NGW financial alert (internal).',
            'Type: ' . ($alert['alert_type'] ?? 'n/a'),
            'Severity: ' . ($alert['severity'] ?? 'n/a'),
            'Ref: ' . ($alert['alert_ref'] ?? 'n/a'),
            'Message: ' . $this->redactError((string) ($alert['message'] ?? '')),
            '',
            'Do not reply with credentials. Review Admin → Financial Alerts.',
        ];

        return implode("\n", $lines);
    }

    /**
     * @return list<string>
     */
    private function parseRecipients(string $csv): array
    {
        $parts = array_filter(array_map('trim', explode(',', $csv)));
        $out   = [];
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
                $out[] = $p;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $recipients
     */
    private function redactRecipients(array $recipients): string
    {
        $redacted = [];
        foreach ($recipients as $email) {
            $at = strpos($email, '@');
            if ($at === false) {
                $redacted[] = '***';
                continue;
            }
            $redacted[] = substr($email, 0, 1) . '***' . substr($email, $at);
        }

        return implode(',', $redacted);
    }

    private function meetsSeverity(string $severity, string $min): bool
    {
        $rank = ['info' => 1, 'warning' => 2, 'critical' => 3];
        $s = $rank[strtolower($severity)] ?? 1;
        $m = $rank[strtolower($min)] ?? 3;

        return $s >= $m;
    }

    private function redactError(string $message): string
    {
        $clean = preg_replace('#https?://\S+#i', '[redacted-url]', $message) ?? $message;
        $clean = preg_replace('/(api[_-]?key|token|secret|password|SMTPPass)=[^\s&]+/i', '$1=[redacted]', $clean) ?? $clean;

        return $clean;
    }

    private function recordEvent(string $outboxRef, string $type, string $detail): void
    {
        db_connect()->table('notification_delivery_events')->insert([
            'outbox_ref' => $outboxRef,
            'event_type' => $type,
            'detail'     => mb_substr($detail, 0, 500),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
