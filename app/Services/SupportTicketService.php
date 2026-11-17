<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 9C member support tickets with ownership scoping.
 */
class SupportTicketService
{
    public const CATEGORIES = [
        'account'       => 'Account',
        'payment'       => 'Payment',
        'coin_purchase' => 'Coin Purchase',
        'wallet'        => 'Wallet',
        'other'         => 'Other',
    ];

    public const STATUSES = [
        'open'        => 'Open',
        'in_progress' => 'In Progress',
        'resolved'    => 'Resolved',
        'closed'      => 'Closed',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel()
    ) {
    }

    /**
     * @param array{category: string, subject: string, message: string} $input
     * @return array{ticket: array<string, mixed>, message: array<string, mixed>}
     */
    public function createTicket(int $memberUserId, array $input): array
    {
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $category = $this->normalizeCategory($input['category'] ?? null);
        $subject  = $this->normalizeSubject($input['subject'] ?? null);
        $message  = $this->normalizeMessage($input['message'] ?? null);

        $openCount = (int) db_connect()->table('support_tickets')
            ->where('member_user_id', $memberUserId)
            ->whereIn('status', ['open', 'in_progress'])
            ->countAllResults();
        if ($openCount >= 10) {
            throw new InvalidArgumentException('You already have the maximum number of open tickets.');
        }

        $now = date('Y-m-d H:i:s');
        $ref = 'SUP-' . strtoupper(bin2hex(random_bytes(5)));
        $db  = db_connect();
        $db->transBegin();

        try {
            $db->table('support_tickets')->insert([
                'ticket_ref'      => $ref,
                'member_user_id'  => $memberUserId,
                'member_id'       => (string) $profile->member_id,
                'category'        => $category,
                'subject'         => $subject,
                'status'          => 'open',
                'last_reply_at'   => $now,
                'last_reply_by'   => 'member',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
            $ticketId = (int) $db->insertID();
            $db->table('support_ticket_messages')->insert([
                'ticket_id'      => $ticketId,
                'ticket_ref'     => $ref,
                'sender_role'    => 'member',
                'sender_user_id' => $memberUserId,
                'message'        => $message,
                'created_at'     => $now,
            ]);
            $messageId = (int) $db->insertID();

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to create support ticket.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $this->audit->log('support.ticket_created', $memberUserId, 'support_ticket', $ref, [
            'category' => $category,
        ]);

        $this->maybeNotifyAdmins($ref, (string) $profile->member_id, $subject);

        return [
            'ticket'  => $this->findTicketByRef($ref) ?? [],
            'message' => db_connect()->table('support_ticket_messages')->where('id', $messageId)->get()->getRowArray() ?: [],
        ];
    }

    /**
     * @return array{ticket: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function memberThread(int $memberUserId, string $ticketRef): array
    {
        $ticket = $this->requireOwnedTicket($memberUserId, $ticketRef);

        return [
            'ticket'   => $ticket,
            'messages' => $this->messagesFor((int) $ticket['id']),
        ];
    }

    /**
     * @return array{ticket: array<string, mixed>, messages: list<array<string, mixed>>}
     */
    public function adminThread(string $ticketRef): array
    {
        $ticket = $this->findTicketByRef($ticketRef);
        if ($ticket === null) {
            throw new InvalidArgumentException('Support ticket not found.');
        }

        return [
            'ticket'   => $ticket,
            'messages' => $this->messagesFor((int) $ticket['id']),
        ];
    }

    /**
     * @return array{ticket: array<string, mixed>, message: array<string, mixed>}
     */
    public function memberReply(int $memberUserId, string $ticketRef, string $message): array
    {
        $ticket = $this->requireOwnedTicket($memberUserId, $ticketRef);
        if (in_array($ticket['status'], ['closed'], true)) {
            throw new InvalidArgumentException('Closed tickets cannot receive new replies.');
        }

        return $this->appendReply($ticket, 'member', $memberUserId, $message, null);
    }

    /**
     * @return array{ticket: array<string, mixed>, message: array<string, mixed>}
     */
    public function adminReply(int $adminUserId, string $ticketRef, string $message, ?string $status = null): array
    {
        $ticket = $this->findTicketByRef($ticketRef);
        if ($ticket === null) {
            throw new InvalidArgumentException('Support ticket not found.');
        }
        if ($ticket['status'] === 'closed' && ($status === null || $status === 'closed')) {
            throw new InvalidArgumentException('Reopen or choose another status before replying to a closed ticket.');
        }

        $result = $this->appendReply($ticket, 'admin', $adminUserId, $message, $status);
        $this->audit->log('support.admin_reply', $adminUserId, 'support_ticket', $ticketRef, [
            'status' => $result['ticket']['status'] ?? null,
        ]);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function updateStatus(int $adminUserId, string $ticketRef, string $status): array
    {
        $ticket = $this->findTicketByRef($ticketRef);
        if ($ticket === null) {
            throw new InvalidArgumentException('Support ticket not found.');
        }
        $status = $this->normalizeStatus($status);
        if ($ticket['status'] === $status) {
            return $ticket;
        }

        $now = date('Y-m-d H:i:s');
        $update = [
            'status'     => $status,
            'updated_at' => $now,
        ];
        if ($status === 'closed') {
            $update['closed_at'] = $now;
            $update['closed_by'] = $adminUserId;
        } else {
            $update['closed_at'] = null;
            $update['closed_by'] = null;
        }

        db_connect()->table('support_tickets')->where('ticket_ref', $ticketRef)->update($update);
        $this->audit->log('support.status_changed', $adminUserId, 'support_ticket', $ticketRef, [
            'from' => $ticket['status'],
            'to'   => $status,
        ]);

        return $this->findTicketByRef($ticketRef) ?? $ticket;
    }

    /**
     * @param array{status?: string, category?: string, q?: string, member_id?: string, page?: int, per_page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, total: int, filters: array<string, mixed>}
     */
    public function adminSearch(array $filters = []): array
    {
        $status   = $this->nullableStatus($filters['status'] ?? null);
        $category = isset($filters['category']) && $filters['category'] !== ''
            ? $this->normalizeCategory($filters['category'])
            : null;
        $q        = trim((string) ($filters['q'] ?? ''));
        $memberId = trim((string) ($filters['member_id'] ?? ''));
        $page     = max(1, (int) ($filters['page'] ?? 1));
        $perPage  = max(5, min(50, (int) ($filters['per_page'] ?? 25)));

        $apply = static function ($b) use ($status, $category, $q, $memberId) {
            if ($status !== null) {
                $b->where('status', $status);
            }
            if ($category !== null) {
                $b->where('category', $category);
            }
            if ($memberId !== '') {
                $b->like('member_id', $memberId);
            }
            if ($q !== '') {
                $b->groupStart()
                    ->like('ticket_ref', $q)
                    ->orLike('subject', $q)
                    ->orLike('member_id', $q)
                    ->groupEnd();
            }

            return $b;
        };

        $db    = db_connect();
        $total = (int) $apply($db->table('support_tickets'))->countAllResults();
        $rows  = $apply($db->table('support_tickets'))
            ->orderBy('updated_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return [
            'rows'    => $rows,
            'total'   => $total,
            'filters' => [
                'status'    => $status,
                'category'  => $category,
                'q'         => $q !== '' ? $q : null,
                'member_id' => $memberId !== '' ? $memberId : null,
                'page'      => $page,
                'per_page'  => $perPage,
            ],
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function memberList(int $memberUserId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = max(5, min(50, $perPage));
        $db      = db_connect();
        $total   = (int) $db->table('support_tickets')->where('member_user_id', $memberUserId)->countAllResults();
        $rows    = $db->table('support_tickets')
            ->where('member_user_id', $memberUserId)
            ->orderBy('updated_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = ['open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0, 'all' => 0];
        $rows = db_connect()->table('support_tickets')
            ->select('status, COUNT(*) AS cnt')
            ->groupBy('status')
            ->get()
            ->getResultArray();
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status] = (int) $row['cnt'];
                $counts['all'] += (int) $row['cnt'];
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findTicketByRef(string $ticketRef): ?array
    {
        $row = db_connect()->table('support_tickets')
            ->where('ticket_ref', trim($ticketRef))
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwnedTicket(int $memberUserId, string $ticketRef): array
    {
        $ticket = $this->findTicketByRef($ticketRef);
        if ($ticket === null || (int) $ticket['member_user_id'] !== $memberUserId) {
            throw new InvalidArgumentException('Support ticket not found.');
        }

        return $ticket;
    }

    /**
     * @param array<string, mixed> $ticket
     * @return array{ticket: array<string, mixed>, message: array<string, mixed>}
     */
    private function appendReply(
        array $ticket,
        string $role,
        int $senderUserId,
        string $message,
        ?string $status
    ): array {
        $message = $this->normalizeMessage($message);
        $now     = date('Y-m-d H:i:s');
        $update  = [
            'last_reply_at' => $now,
            'last_reply_by' => $role,
            'updated_at'    => $now,
        ];

        if ($status !== null && $status !== '') {
            $status = $this->normalizeStatus($status);
            $update['status'] = $status;
            if ($status === 'closed') {
                $update['closed_at'] = $now;
                $update['closed_by'] = $senderUserId;
            } elseif (($ticket['status'] ?? '') === 'closed') {
                $update['closed_at'] = null;
                $update['closed_by'] = null;
            }
        } elseif ($role === 'admin' && ($ticket['status'] ?? '') === 'open') {
            $update['status'] = 'in_progress';
        } elseif ($role === 'member' && in_array($ticket['status'] ?? '', ['resolved'], true)) {
            $update['status'] = 'open';
        }

        $db = db_connect();
        $db->transBegin();
        try {
            $db->table('support_ticket_messages')->insert([
                'ticket_id'      => (int) $ticket['id'],
                'ticket_ref'     => (string) $ticket['ticket_ref'],
                'sender_role'    => $role,
                'sender_user_id' => $senderUserId,
                'message'        => $message,
                'created_at'     => $now,
            ]);
            $messageId = (int) $db->insertID();
            $db->table('support_tickets')->where('id', (int) $ticket['id'])->update($update);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to save reply.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        return [
            'ticket'  => $this->findTicketByRef((string) $ticket['ticket_ref']) ?? $ticket,
            'message' => $db->table('support_ticket_messages')->where('id', $messageId)->get()->getRowArray() ?: [],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messagesFor(int $ticketId): array
    {
        return db_connect()->table('support_ticket_messages')
            ->where('ticket_id', $ticketId)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    private function maybeNotifyAdmins(string $ticketRef, string $memberId, string $subject): void
    {
        try {
            if (! service('platformSettingsService')->bool('notify_admin_alerts')) {
                return;
            }
            if (! service('financialNotificationService')->isDeliveryConfigured()) {
                return;
            }
            service('financialNotificationService')->enqueueForAlert([
                'alert_ref'  => 'support-' . $ticketRef,
                'dedupe_key' => 'support-ticket:' . $ticketRef,
                'severity'  => 'info',
                'title'      => 'New support ticket ' . $ticketRef,
                'message'    => 'Member ' . $memberId . ' opened: ' . $subject,
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Support admin notify skipped: {msg}', ['msg' => $e->getMessage()]);
        }
    }

    private function normalizeCategory(mixed $category): string
    {
        $category = strtolower(trim((string) $category));
        if (! isset(self::CATEGORIES[$category])) {
            throw new InvalidArgumentException('Select a valid support category.');
        }

        return $category;
    }

    private function normalizeStatus(mixed $status): string
    {
        $status = strtolower(trim((string) $status));
        if (! isset(self::STATUSES[$status])) {
            throw new InvalidArgumentException('Select a valid ticket status.');
        }

        return $status;
    }

    private function nullableStatus(mixed $status): ?string
    {
        $status = trim((string) $status);
        if ($status === '' || $status === 'all') {
            return null;
        }

        return $this->normalizeStatus($status);
    }

    private function normalizeSubject(mixed $subject): string
    {
        $subject = trim((string) $subject);
        $len = mb_strlen($subject);
        if ($len < 5 || $len > 180) {
            throw new InvalidArgumentException('Subject must be between 5 and 180 characters.');
        }

        return $subject;
    }

    private function normalizeMessage(mixed $message): string
    {
        $message = trim((string) $message);
        $len = mb_strlen($message);
        if ($len < 5 || $len > 5000) {
            throw new InvalidArgumentException('Message must be between 5 and 5000 characters.');
        }

        return $message;
    }
}
