<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Dashboard\KpiCardFactory;
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

    private const SORT_COLUMNS = [
        'id'         => 'id',
        'created_at' => 'created_at',
        'quantity'   => 'quantity',
        'type'       => 'type',
    ];

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
     *   active_lock_count: int,
     *   matured_lock_count: int,
     *   pending_purchase_count: int,
     *   pending_purchases: list<array<string, mixed>>,
     *   invariant_ok: bool,
     *   recent: list<array<string, mixed>>,
     *   kpis: list<array<string, mixed>>
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

        $activeLockCount = (int) $db->table('member_coin_locks')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'active')
            ->countAllResults();
        $maturedLockCount = (int) $db->table('member_coin_locks')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'matured')
            ->countAllResults();

        $pendingPurchaseCount = (int) $db->table('coin_purchase_requests')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'pending')
            ->countAllResults();
        $pendingPurchases = $db->table('coin_purchase_requests')
            ->select('purchase_ref, coin_quantity, total_usdt, status, created_at')
            ->where('member_user_id', $memberUserId)
            ->where('status', 'pending')
            ->orderBy('id', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();

        $recent = $db->table('member_coin_ownership_transactions')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(8)
            ->get()
            ->getResultArray();

        $invariantOk = MoneyMath::compare(
            $summary['balance'],
            MoneyMath::add($summary['available'], $summary['locked'], $scale),
            $scale
        ) === 0;

        $overview = [
            'balance'                 => $summary['balance'],
            'available'               => $summary['available'],
            'locked'                  => $summary['locked'],
            'total_acquired'          => $summary['total_acquired'],
            'member_id'               => $summary['member_id'],
            'total_locked_events'     => MoneyMath::normalize((string) ($agg['total_locked_qty'] ?? '0'), $scale),
            'total_unlocked_events'   => MoneyMath::normalize((string) ($agg['total_unlocked_qty'] ?? '0'), $scale),
            'purchase_credit_count'   => (int) ($agg['purchase_credit_count'] ?? 0),
            'active_lock_count'       => $activeLockCount,
            'matured_lock_count'      => $maturedLockCount,
            'pending_purchase_count'  => $pendingPurchaseCount,
            'pending_purchases'       => $pendingPurchases,
            'invariant_ok'            => $invariantOk,
            'recent'                  => array_map(fn (array $row): array => $this->decorateRow($row), $recent),
        ];

        $overview['kpis'] = $this->overviewKpis($overview);

        return $overview;
    }

    /**
     * @param array{type?: string, date_from?: string, date_to?: string, ref?: string, sort?: string, dir?: string, per_page?: int, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, pager: Pager, filters: array<string, mixed>}
     */
    public function history(int $memberUserId, array $filters = []): array
    {
        $type     = $this->normalizeType($filters['type'] ?? null);
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);
        $ref      = $this->normalizeRefSearch($filters['ref'] ?? null);
        $sort     = $this->normalizeSort($filters['sort'] ?? null);
        $dir      = $this->normalizeDir($filters['dir'] ?? null);
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
        if ($ref !== null) {
            $like = '%' . $ref . '%';
            $builder->groupStart()
                ->like('transaction_ref', $like)
                ->orLike('purchase_ref', $like)
                ->orLike('lock_ref', $like)
                ->groupEnd();
        }

        $rows = $builder->orderBy($sort, $dir)->paginate($perPage);
        $rows = array_map(fn (array $row): array => $this->decorateRow($row), $rows);

        return [
            'rows'    => $rows,
            'pager'   => $this->transactions->pager,
            'filters' => [
                'type'      => $type ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to'   => $filters['date_to'] ?? '',
                'ref'       => $filters['ref'] ?? '',
                'sort'      => array_search($sort, self::SORT_COLUMNS, true) ?: 'id',
                'dir'       => strtolower($dir),
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

        return $this->decorateRow($row);
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
     * Admin fuzzy search by member_id, name, mobile, or email. Read-only.
     *
     * @return list<array{profile: object, overview: array<string, mixed>}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $limit = max(1, min(50, $limit));
        if ($query === '') {
            return [];
        }

        $exact = $this->lookupByMemberId($query);
        if ($exact !== null) {
            return [$exact];
        }

        $like = '%' . $query . '%';
        $db   = db_connect();

        $rows = $db->table('member_profiles p')
            ->select('p.*')
            ->join('auth_identities ai', "ai.user_id = p.user_id AND ai.type = 'email'", 'left')
            ->groupStart()
                ->like('p.member_id', $like)
                ->orLike('p.member_name', $like)
                ->orLike('p.mobile', $like)
                ->orLike('ai.secret', $like)
            ->groupEnd()
            ->orderBy('p.member_id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $profile = $this->profiles->findByMemberId((string) $row['member_id']);
            if ($profile === null) {
                continue;
            }
            $out[] = [
                'profile'  => $profile,
                'overview' => $this->overview((int) $profile->user_id),
            ];
        }

        return $out;
    }

    /**
     * @param array{type?: string, date_from?: string, date_to?: string, ref?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function exportRows(int $memberUserId, array $filters = [], int $limit = self::EXPORT_MAX_ROWS): array
    {
        $limit    = max(1, min(self::EXPORT_MAX_ROWS, $limit));
        $type     = $this->normalizeType($filters['type'] ?? null);
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);
        $ref      = $this->normalizeRefSearch($filters['ref'] ?? null);

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
        if ($ref !== null) {
            $like = '%' . $ref . '%';
            $builder->groupStart()
                ->like('transaction_ref', $like)
                ->orLike('purchase_ref', $like)
                ->orLike('lock_ref', $like)
                ->groupEnd();
        }

        $rows = $builder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray();

        return array_map(fn (array $row): array => $this->decorateRow($row), $rows);
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
            'id',
            'transaction_ref',
            'created_at',
            'type',
            'direction',
            'status',
            'quantity',
            'available_impact',
            'locked_impact',
            'total_impact',
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
            $row = $this->decorateRow($row);
            fputcsv($handle, [
                $this->csvCell((string) ($row['id'] ?? '')),
                $this->csvCell((string) ($row['transaction_ref'] ?? '')),
                $this->csvCell((string) ($row['created_at'] ?? '')),
                $this->csvCell((string) ($row['type'] ?? '')),
                $this->csvCell((string) ($row['direction'] ?? '')),
                $this->csvCell((string) ($row['status'] ?? 'settled')),
                $this->csvCell(MoneyMath::normalize((string) ($row['quantity'] ?? '0'), $scale)),
                $this->csvCell((string) ($row['available_impact'] ?? '0')),
                $this->csvCell((string) ($row['locked_impact'] ?? '0')),
                $this->csvCell((string) ($row['total_impact'] ?? '0')),
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

    /**
     * Derive recorded balance impacts from ledger snapshots. Never fabricates missing history.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function decorateRow(array $row): array
    {
        $scale = $this->scale();

        $prevBalance   = MoneyMath::normalize((string) ($row['previous_balance'] ?? '0'), $scale);
        $newBalance    = MoneyMath::normalize((string) ($row['new_balance'] ?? '0'), $scale);
        $prevAvailable = array_key_exists('previous_available', $row) && $row['previous_available'] !== null
            ? MoneyMath::normalize((string) $row['previous_available'], $scale)
            : null;
        $newAvailable = array_key_exists('new_available', $row) && $row['new_available'] !== null
            ? MoneyMath::normalize((string) $row['new_available'], $scale)
            : null;
        $prevLocked = array_key_exists('previous_locked', $row) && $row['previous_locked'] !== null
            ? MoneyMath::normalize((string) $row['previous_locked'], $scale)
            : null;
        $newLocked = array_key_exists('new_locked', $row) && $row['new_locked'] !== null
            ? MoneyMath::normalize((string) $row['new_locked'], $scale)
            : null;

        $row['status']           = 'settled';
        $row['total_impact']     = MoneyMath::sub($newBalance, $prevBalance, $scale);
        $row['available_impact'] = ($prevAvailable !== null && $newAvailable !== null)
            ? MoneyMath::sub($newAvailable, $prevAvailable, $scale)
            : null;
        $row['locked_impact'] = ($prevLocked !== null && $newLocked !== null)
            ? MoneyMath::sub($newLocked, $prevLocked, $scale)
            : null;
        $row['source_ref'] = (string) ($row['purchase_ref'] ?: ($row['lock_ref'] ?? ''));

        return $row;
    }

    /**
     * @param array<string, mixed> $overview
     * @return list<array<string, mixed>>
     */
    private function overviewKpis(array $overview): array
    {
        return [
            KpiCardFactory::ok('wallet_total', 'Total Owned Coins', (string) $overview['balance'], 'purple', 'bi-coin', 'coins'),
            KpiCardFactory::ok('wallet_available', 'Available Coins', (string) $overview['available'], 'green', 'bi-wallet2', 'coins'),
            KpiCardFactory::ok('wallet_locked', 'Locked Coins', (string) $overview['locked'], 'orange', 'bi-lock-fill', 'coins'),
            KpiCardFactory::ok('wallet_purchased', 'Total Purchased Coins', (string) $overview['total_acquired'], 'blue', 'bi-cart-check-fill', 'coins'),
            KpiCardFactory::ok('wallet_lock_qty', 'Total Locked Qty', (string) $overview['total_locked_events'], 'amber', 'bi-lock', 'coins'),
            KpiCardFactory::ok('wallet_unlock_qty', 'Total Unlocked Qty', (string) $overview['total_unlocked_events'], 'teal', 'bi-unlock-fill', 'coins'),
            KpiCardFactory::ok('wallet_active_locks', 'Active Locks', (int) $overview['active_lock_count'], 'cyan', 'bi-shield-lock', 'locks', site_url('member/wallet?tab=locking')),
            KpiCardFactory::ok('wallet_matured_locks', 'Matured Locks', (int) $overview['matured_lock_count'], 'teal', 'bi-hourglass-split', 'locks', site_url('member/wallet?tab=locking')),
            KpiCardFactory::ok('wallet_pending_purchases', 'Pending Purchases', (int) $overview['pending_purchase_count'], 'red', 'bi-hourglass', 'requests', site_url('member/buy-coins')),
        ];
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

    private function normalizeRefSearch(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 80 || ! preg_match('/^[A-Za-z0-9_\-:.#]+$/', $value)) {
            throw new InvalidArgumentException('Reference search contains invalid characters.');
        }

        return $value;
    }

    private function normalizeSort(mixed $value): string
    {
        $key = strtolower(trim((string) $value));
        if ($key === '' || ! isset(self::SORT_COLUMNS[$key])) {
            return self::SORT_COLUMNS['id'];
        }

        return self::SORT_COLUMNS[$key];
    }

    private function normalizeDir(mixed $value): string
    {
        $dir = strtoupper(trim((string) $value));

        return $dir === 'ASC' ? 'ASC' : 'DESC';
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
