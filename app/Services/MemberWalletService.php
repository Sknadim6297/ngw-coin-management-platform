<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberCoinOwnershipTransactionModel;
use App\Models\MemberProfileModel;
use CodeIgniter\Pager\Pager;
use Config\Platform;
use InvalidArgumentException;

/**
 * Phase 6: internal member coin wallet reporting over the ownership ledger.
 * Read-only for balances/history. No direct balance edits, transfers, or ROI.
 */
class MemberWalletService
{
    public const TYPES = [
        MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT,
        MemberCoinOwnershipService::TYPE_LOCK,
        MemberCoinOwnershipService::TYPE_UNLOCK,
    ];

    public const EXPORT_MAX_ROWS = 5000;

    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MemberCoinOwnershipTransactionModel $transactions = new MemberCoinOwnershipTransactionModel(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   balance: string,
     *   available: string,
     *   locked: string,
     *   total_acquired: string,
     *   member_id: ?string,
     *   total_locked_events: string,
     *   total_unlocked_events: string,
     *   purchase_credit_count: int,
     *   recent: list<array<string, mixed>>
     * }
     */
    public function overview(int $memberUserId): array
    {
        $summary = $this->ownership->summaryForUser($memberUserId);
        $scale   = $this->scale();
        $db      = db_connect();

        $agg = $db->query(
            'SELECT
                COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) AS total_locked_qty,
                COALESCE(SUM(CASE WHEN type = ? THEN quantity ELSE 0 END), 0) AS total_unlocked_qty,
                SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) AS purchase_credit_count
             FROM member_coin_ownership_transactions
             WHERE member_user_id = ?',
            [
                MemberCoinOwnershipService::TYPE_LOCK,
                MemberCoinOwnershipService::TYPE_UNLOCK,
                MemberCoinOwnershipService::TYPE_PURCHASE_CREDIT,
                $memberUserId,
            ]
        )->getRowArray() ?: [];

        $recent = $db->table('member_coin_ownership_transactions')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        return [
            'balance'               => $summary['balance'],
            'available'             => $summary['available'],
            'locked'                => $summary['locked'],
            'total_acquired'        => $summary['total_acquired'],
            'member_id'             => $summary['member_id'],
            'total_locked_events'   => MoneyMath::normalize((string) ($agg['total_locked_qty'] ?? '0'), $scale),
            'total_unlocked_events' => MoneyMath::normalize((string) ($agg['total_unlocked_qty'] ?? '0'), $scale),
            'purchase_credit_count' => (int) ($agg['purchase_credit_count'] ?? 0),
            'recent'                => $recent,
        ];
    }

    /**
     * @param array{type?: string, date_from?: string, date_to?: string, per_page?: int, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, pager: Pager, filters: array<string, mixed>}
     */
    public function history(int $memberUserId, array $filters = []): array
    {
        $type     = $this->normalizeType($filters['type'] ?? null);
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);
        $perPage  = max(5, min(50, (int) ($filters['per_page'] ?? 20)));

        $builder = $this->transactions->where('member_user_id', $memberUserId);
        if ($type !== null) {
            $builder->where('type', $type);
        }
        if ($dateFrom !== null) {
            $builder->where('created_at >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $builder->where('created_at <=', $dateTo);
        }

        $rows = $builder->orderBy('id', 'DESC')->paginate($perPage);

        return [
            'rows'    => $rows,
            'pager'   => $this->transactions->pager,
            'filters' => [
                'type'      => $type ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to'   => $filters['date_to'] ?? '',
                'per_page'  => $perPage,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function findOwnedTransaction(int $memberUserId, string $transactionRef): array
    {
        $ref = trim($transactionRef);
        if ($ref === '') {
            throw new InvalidArgumentException('Transaction reference is required.');
        }

        $row = $this->transactions
            ->where('transaction_ref', $ref)
            ->where('member_user_id', $memberUserId)
            ->first();

        if ($row === null) {
            throw new InvalidArgumentException('Wallet transaction not found.');
        }

        return $row;
    }

    /**
     * Admin lookup by member_id. Read-only.
     *
     * @return array{profile: object, overview: array<string, mixed>}|null
     */
    public function lookupByMemberId(string $memberId): ?array
    {
        $memberId = strtoupper(trim($memberId));
        if ($memberId === '') {
            return null;
        }

        $profile = $this->profiles->findByMemberId($memberId);
        if ($profile === null) {
            return null;
        }

        return [
            'profile'  => $profile,
            'overview' => $this->overview((int) $profile->user_id),
        ];
    }

    /**
     * @param array{type?: string, date_from?: string, date_to?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $memberUserId, array $filters = [], int $limit = self::EXPORT_MAX_ROWS): array
    {
        $limit    = max(1, min(self::EXPORT_MAX_ROWS, $limit));
        $type     = $this->normalizeType($filters['type'] ?? null);
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);

        $builder = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $memberUserId);
        if ($type !== null) {
            $builder->where('type', $type);
        }
        if ($dateFrom !== null) {
            $builder->where('created_at >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $builder->where('created_at <=', $dateTo);
        }

        return $builder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();
    }

    /**
     * Build CSV with formula-injection prevention.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function buildCsv(array $rows): string
    {
        $scale  = $this->scale();
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV buffer.');
        }

        fputcsv($handle, [
            'transaction_ref',
            'created_at',
            'type',
            'direction',
            'quantity',
            'previous_balance',
            'new_balance',
            'previous_available',
            'new_available',
            'previous_locked',
            'new_locked',
            'purchase_ref',
            'lock_ref',
            'member_id',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $this->csvCell((string) ($row['transaction_ref'] ?? '')),
                $this->csvCell((string) ($row['created_at'] ?? '')),
                $this->csvCell((string) ($row['type'] ?? '')),
                $this->csvCell((string) ($row['direction'] ?? '')),
                $this->csvCell(MoneyMath::normalize((string) ($row['quantity'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['previous_balance'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['new_balance'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['previous_available'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['new_available'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['previous_locked'] ?? '0'), $scale)),
                $this->csvCell(MoneyMath::normalize((string) ($row['new_locked'] ?? '0'), $scale)),
                $this->csvCell((string) ($row['purchase_ref'] ?? '')),
                $this->csvCell((string) ($row['lock_ref'] ?? '')),
                $this->csvCell((string) ($row['member_id'] ?? '')),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function csvCell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    private function normalizeType(mixed $type): ?string
    {
        $type = strtoupper(trim((string) $type));
        if ($type === '') {
            return null;
        }
        if (! in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Invalid wallet transaction type filter.');
        }

        return $type;
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $endOfDay ? $value . ' 23:59:59' : $value . ' 00:00:00';
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
