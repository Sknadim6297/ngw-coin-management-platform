<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberCoinLockModel;
use Config\Platform;
use InvalidArgumentException;

/**
 * Phase 3 — Daily principal release schedule (0.1% of ORIGINAL principal per day).
 *
 * READ-ONLY by default. Ledger posting remains kill-switched OFF
 * (Platform::principalDailyReleasePostingEnabled).
 *
 * Schedule is derived from mandatory purchase locks. Does not mutate balances,
 * commissions, mining, withdrawals, or Business Volume.
 */
class PrincipalDailyReleaseService
{
    public const STATUS_SCHEDULED_NOT_POSTED = 'scheduled_not_posted';
    public const STATUS_POSTED               = 'posted';
    public const STATUS_SKIPPED_FUTURE       = 'future';
    public const STATUS_COMPLETE             = 'complete';

    /**
     * Provisional until business confirms: Day 1 = Asia/Kolkata calendar date of locked_at (inclusive).
     * Do not treat as final policy without owner approval.
     */
    public const RELEASE_START_POLICY = 'lock_business_date_inclusive_PENDING_APPROVAL';

    public function __construct(
        private readonly MemberCoinLockModel $locks = new MemberCoinLockModel(),
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly ?Platform $config = null
    ) {
    }

    public function postingEnabled(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->principalDailyReleasePostingEnabled;
    }

    /**
     * Member dashboard / summary cards (COIN units, not USDT).
     *
     * @return array<string, mixed>
     */
    public function summaryForMember(int $memberUserId): array
    {
        if ($memberUserId <= 0) {
            throw new InvalidArgumentException('Invalid member.');
        }

        $tz      = $this->timezone();
        $today   = $this->todayBusinessDate($tz);
        $rate    = $this->rate();
        $maxDays = $this->maxDays();
        $scale   = $this->scale();

        $lockRows = $this->mandatoryLocksForMember($memberUserId);
        $original = '0.00000000';
        $todayRelease = '0.00000000';
        $scheduledCumulative = '0.00000000';
        $remainingScheduled = '0.00000000';

        foreach ($lockRows as $lock) {
            $qty = MoneyMath::normalize((string) $lock['quantity'], $scale);
            $original = MoneyMath::add($original, $qty, $scale);

            $sched = $this->scheduleStateForLock($lock, $today, $rate, $maxDays, $scale);
            $todayRelease = MoneyMath::add($todayRelease, $sched['today_quantity'], $scale);
            $scheduledCumulative = MoneyMath::add($scheduledCumulative, $sched['cumulative_released'], $scale);
            $remainingScheduled = MoneyMath::add($remainingScheduled, $sched['remaining_locked'], $scale);
        }

        $posted = $this->postedTotals($memberUserId);
        $balances = $this->ownership->summaryForUser($memberUserId);
        $postingOn = $this->postingEnabled();
        $sellingOn = (bool) (($this->config ?? config('Platform'))->coinSellingEnabled);

        // P2P principal is only meaningful when daily release posting AND selling are authorized.
        // Never fabricate a sellable balance from schedule-only math.
        $p2pEnabled = $postingOn && $sellingOn;
        $p2pAmount  = $p2pEnabled ? $posted['released_quantity'] : null;

        return [
            'asset_code'                         => 'COIN',
            'unit_label'                         => 'COIN',
            'posting_enabled'                    => $postingOn,
            'mode'                               => $postingOn ? 'posted_and_schedule' : 'schedule_preview_only',
            'rate'                               => $rate,
            'max_days'                           => $maxDays,
            'release_start_policy'               => self::RELEASE_START_POLICY,
            'timezone'                           => $tz,
            'business_date_today'                => $today,
            'original_principal'                 => $original,
            'today_principal_release'            => $todayRelease,
            'today_principal_release_label'      => $postingOn ? "Today's Principal Release" : "Today's Scheduled Release",
            'total_released_principal_scheduled' => $scheduledCumulative,
            'total_released_principal_posted'    => $posted['released_quantity'],
            'total_scheduled_release'            => $scheduledCumulative,
            'total_released_principal'           => $postingOn ? $posted['released_quantity'] : $scheduledCumulative,
            'total_released_label'               => $postingOn ? 'Total Released Principal' : 'Total Scheduled Release',
            'remaining_locked_principal'         => $postingOn
                ? MoneyMath::sub($original, $posted['released_quantity'], $scale)
                : $remainingScheduled,
            'remaining_locked_label'             => $postingOn ? 'Remaining Locked Principal' : 'Projected Remaining Principal',
            'available_p2p_principal'            => $p2pAmount,
            'p2p_principal_status'               => $p2pEnabled ? 'enabled' : 'not_enabled',
            'p2p_principal_message'              => $p2pEnabled
                ? null
                : 'P2P Principal Available: Not enabled',
            'ownership_available_balance'        => MoneyMath::normalize((string) ($balances['available'] ?? '0'), $scale),
            'ownership_locked_balance'           => MoneyMath::normalize((string) ($balances['locked'] ?? '0'), $scale),
            'lock_count'                         => count($lockRows),
            'notes'                              => [
                'Schedule Preview — No Coins Released while principalDailyReleasePostingEnabled is false.',
                'Schedule uses original lock quantity × 0.001 per day; never remaining principal.',
                'Existing locks remain cliff 1000-day until daily posting architecture is activated.',
                'Values are COIN principal, not commission USDT and not mining rewards.',
            ],
        ];
    }

    /**
     * Paginated release history: posted rows if any, else schedule preview rows.
     *
     * @param array{date_from?: string, date_to?: string, purchase_ref?: string, per_page?: int|string, page?: int|string} $filters
     * @return array<string, mixed>
     */
    public function historyForMember(int $memberUserId, array $filters = []): array
    {
        if ($memberUserId <= 0) {
            throw new InvalidArgumentException('Invalid member.');
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null);
        $purchase = trim((string) ($filters['purchase_ref'] ?? ''));
        if (strlen($purchase) > 40) {
            throw new InvalidArgumentException('Purchase reference is too long.');
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 25, 50], true) ? $perPage : 20;
        $page    = max(1, (int) ($filters['page'] ?? 1));

        $postedCount = 0;
        if (db_connect()->tableExists('member_principal_daily_releases')) {
            $postedCount = (int) db_connect()->table('member_principal_daily_releases')
                ->where('member_user_id', $memberUserId)
                ->countAllResults();
        }

        if ($postedCount > 0 && $this->postingEnabled()) {
            return $this->historyFromPostedTable($memberUserId, $dateFrom, $dateTo, $purchase, $perPage, $page);
        }

        return $this->historyFromSchedule($memberUserId, $dateFrom, $dateTo, $purchase, $perPage, $page);
    }

    /**
     * Pure day amount for a lock day index (1..maxDays). Uses original × rate; last day absorbs remainder.
     */
    public function dayReleaseQuantity(string $originalPrincipal, int $dayIndex, ?int $maxDays = null, ?string $rate = null): string
    {
        $scale   = $this->scale();
        $maxDays = $maxDays ?? $this->maxDays();
        $rate    = $rate ?? $this->rate();
        $original = MoneyMath::normalize($originalPrincipal, $scale);

        if ($dayIndex < 1 || $dayIndex > $maxDays) {
            return MoneyMath::normalize('0', $scale);
        }

        $daily = MoneyMath::mul($original, $rate, $scale);
        if ($dayIndex < $maxDays) {
            // Never exceed remaining theoretical headroom.
            $prior = MoneyMath::mul($daily, (string) ($dayIndex - 1), $scale);
            $headroom = MoneyMath::sub($original, $prior, $scale);
            if (MoneyMath::compare($daily, $headroom, $scale) === 1) {
                return MoneyMath::compare($headroom, '0', $scale) === 1 ? $headroom : MoneyMath::normalize('0', $scale);
            }

            return $daily;
        }

        $prior = MoneyMath::mul($daily, (string) ($maxDays - 1), $scale);
        if (MoneyMath::compare($prior, $original, $scale) === 1) {
            return MoneyMath::normalize('0', $scale);
        }

        return MoneyMath::sub($original, $prior, $scale);
    }

    /**
     * @return array{cumulative_released: string, remaining_locked: string, today_quantity: string, day_index: int, start_date: string, end_date: string}
     */
    public function scheduleStateForLock(array $lock, string $asOfBusinessDate, ?string $rate = null, ?int $maxDays = null, ?int $scale = null): array
    {
        $scale   = $scale ?? $this->scale();
        $rate    = $rate ?? $this->rate();
        $maxDays = $maxDays ?? $this->maxDays();
        $original = MoneyMath::normalize((string) $lock['quantity'], $scale);
        $start    = $this->lockStartBusinessDate($lock);
        $end      = $this->addDays($start, $maxDays - 1);
        $dayIndex = $this->dayIndexOnDate($start, $asOfBusinessDate, $maxDays);

        $cumulative = $this->cumulativeThroughDay($original, $dayIndex, $maxDays, $rate, $scale);

        $todayQty = '0.00000000';
        if ($dayIndex >= 1 && $dayIndex <= $maxDays && $asOfBusinessDate >= $start && $asOfBusinessDate <= $end) {
            $todayQty = $this->dayReleaseQuantity($original, $dayIndex, $maxDays, $rate);
        }

        return [
            'cumulative_released' => $cumulative,
            'remaining_locked'    => MoneyMath::sub($original, $cumulative, $scale),
            'today_quantity'      => $todayQty,
            'day_index'           => $dayIndex,
            'start_date'          => $start,
            'end_date'            => $end,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mandatoryLocksForMember(int $memberUserId): array
    {
        return db_connect()->table('member_coin_locks')
            ->where('member_user_id', $memberUserId)
            ->where('lock_kind', CoinLockingService::KIND_MANDATORY_PURCHASE)
            ->whereIn('status', [
                CoinLockingService::STATUS_ACTIVE,
                CoinLockingService::STATUS_MATURED,
                CoinLockingService::STATUS_UNLOCKED,
            ])
            ->orderBy('locked_at', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return array{released_quantity: string, row_count: int}
     */
    private function postedTotals(int $memberUserId): array
    {
        $zero = MoneyMath::normalize('0', $this->scale());
        if (! db_connect()->tableExists('member_principal_daily_releases')) {
            return ['released_quantity' => $zero, 'row_count' => 0];
        }
        $row = db_connect()->table('member_principal_daily_releases')
            ->select('COALESCE(SUM(released_quantity), 0) AS qty, COUNT(*) AS cnt', false)
            ->where('member_user_id', $memberUserId)
            ->where('status', self::STATUS_POSTED)
            ->get()->getRowArray();

        return [
            'released_quantity' => MoneyMath::normalize((string) ($row['qty'] ?? '0'), $this->scale()),
            'row_count'         => (int) ($row['cnt'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyFromSchedule(
        int $memberUserId,
        ?string $dateFrom,
        ?string $dateTo,
        string $purchase,
        int $perPage,
        int $page
    ): array {
        $tz    = $this->timezone();
        $today = $this->todayBusinessDate($tz);
        $rate  = $this->rate();
        $maxDays = $this->maxDays();
        $scale = $this->scale();
        $locks = $this->mandatoryLocksForMember($memberUserId);

        $all = [];
        foreach ($locks as $lock) {
            if ($purchase !== '' && strcasecmp((string) ($lock['purchase_ref'] ?? ''), $purchase) !== 0) {
                continue;
            }
            $start = $this->lockStartBusinessDate($lock);
            $end   = $this->addDays($start, $maxDays - 1);
            $rangeEnd = $dateTo !== null && $dateTo < $end ? $dateTo : $end;
            if ($rangeEnd > $today) {
                $rangeEnd = $today; // do not list unbounded future in default history
            }
            $rangeStart = $dateFrom !== null && $dateFrom > $start ? $dateFrom : $start;
            if ($rangeStart > $rangeEnd) {
                continue;
            }

            $original = MoneyMath::normalize((string) $lock['quantity'], $scale);
            $cursor   = $rangeStart;
            $guard    = 0;
            while ($cursor <= $rangeEnd && $guard < $maxDays + 5) {
                $guard++;
                $idx = $this->dayIndexOnDate($start, $cursor, $maxDays);
                if ($idx >= 1 && $idx <= $maxDays) {
                    $qty = $this->dayReleaseQuantity($original, $idx, $maxDays, $rate);
                    $cum = $this->cumulativeThroughDay($original, $idx, $maxDays, $rate, $scale);
                    $all[] = [
                        'purchase_ref'            => (string) ($lock['purchase_ref'] ?? ''),
                        'lock_ref'                => (string) $lock['lock_ref'],
                        'original_principal'      => $original,
                        'release_date'            => $cursor,
                        'day_index'               => $idx,
                        'released_quantity'       => $qty,
                        'cumulative_released'     => $cum,
                        'remaining_locked'        => MoneyMath::sub($original, $cum, $scale),
                        'status'                  => self::STATUS_SCHEDULED_NOT_POSTED,
                        'asset_code'              => 'COIN',
                    ];
                }
                $cursor = $this->addDays($cursor, 1);
            }
        }

        usort($all, static function (array $a, array $b): int {
            return strcmp($b['release_date'], $a['release_date']) ?: strcmp($a['lock_ref'], $b['lock_ref']);
        });

        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
        $pager = service('pager');
        $pager->store('principal_release', $page, $perPage, $total);

        return [
            'rows'    => $slice,
            'pager'   => $pager,
            'total'   => $total,
            'source'  => 'schedule_preview',
            'filters' => [
                'date_from'    => $dateFrom ?? '',
                'date_to'      => $dateTo ?? '',
                'purchase_ref' => $purchase,
                'per_page'     => $perPage,
                'page'         => $page,
            ],
            'posting_enabled' => false,
            'timezone'        => $tz,
            'notes'           => 'Showing schedule preview only. No principal release has been posted to the ownership ledger.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function historyFromPostedTable(
        int $memberUserId,
        ?string $dateFrom,
        ?string $dateTo,
        string $purchase,
        int $perPage,
        int $page
    ): array {
        $db = db_connect();
        $b  = $db->table('member_principal_daily_releases')
            ->where('member_user_id', $memberUserId);
        if ($dateFrom !== null) {
            $b->where('business_date >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $b->where('business_date <=', $dateTo);
        }
        if ($purchase !== '') {
            $b->where('purchase_ref', $purchase);
        }
        $total = $b->countAllResults(false);
        $rows  = $b->orderBy('business_date', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'purchase_ref'        => (string) ($row['purchase_ref'] ?? ''),
                'lock_ref'            => (string) $row['lock_ref'],
                'original_principal'  => MoneyMath::normalize((string) $row['original_principal'], $this->scale()),
                'release_date'        => (string) $row['business_date'],
                'day_index'           => (int) $row['day_index'],
                'released_quantity'   => MoneyMath::normalize((string) $row['released_quantity'], $this->scale()),
                'cumulative_released' => MoneyMath::normalize((string) $row['cumulative_released'], $this->scale()),
                'remaining_locked'    => MoneyMath::normalize((string) $row['remaining_locked'], $this->scale()),
                'status'              => (string) $row['status'],
                'asset_code'          => (string) ($row['asset_code'] ?? 'COIN'),
            ];
        }

        $pager = service('pager');
        $pager->store('principal_release', $page, $perPage, $total);

        return [
            'rows'    => $out,
            'pager'   => $pager,
            'total'   => $total,
            'source'  => 'posted_table',
            'filters' => [
                'date_from'    => $dateFrom ?? '',
                'date_to'      => $dateTo ?? '',
                'purchase_ref' => $purchase,
                'per_page'     => $perPage,
                'page'         => $page,
            ],
            'posting_enabled' => true,
            'timezone'        => $this->timezone(),
            'notes'           => 'Posted principal release rows.',
        ];
    }

    private function cumulativeThroughDay(string $original, int $dayIndex, int $maxDays, string $rate, int $scale): string
    {
        if ($dayIndex <= 0) {
            return MoneyMath::normalize('0', $scale);
        }
        if ($dayIndex >= $maxDays) {
            return $original;
        }
        $daily = MoneyMath::mul($original, $rate, $scale);
        $cum   = MoneyMath::mul($daily, (string) $dayIndex, $scale);

        return MoneyMath::compare($cum, $original, $scale) === 1 ? $original : $cum;
    }

    private function lockStartBusinessDate(array $lock): string
    {
        $lockedAt = (string) ($lock['locked_at'] ?? '');
        if ($lockedAt === '') {
            throw new InvalidArgumentException('Lock missing locked_at.');
        }
        $tz = new \DateTimeZone($this->timezone());
        try {
            $dt = new \DateTimeImmutable($lockedAt);
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable('now');
        }

        return $dt->setTimezone($tz)->format('Y-m-d');
    }

    private function dayIndexOnDate(string $startDate, string $asOfDate, int $maxDays): int
    {
        if ($asOfDate < $startDate) {
            return 0;
        }
        $start = new \DateTimeImmutable($startDate . ' 00:00:00');
        $asOf  = new \DateTimeImmutable($asOfDate . ' 00:00:00');
        $diff  = (int) $start->diff($asOf)->days;
        $index = $diff + 1; // inclusive start

        return min($maxDays, max(0, $index));
    }

    private function addDays(string $ymd, int $days): string
    {
        return (new \DateTimeImmutable($ymd . ' 00:00:00'))->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('Y-m-d');
    }

    private function todayBusinessDate(string $tz): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('Y-m-d');
    }

    private function normalizeDate(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD.');
        }

        return $raw;
    }

    private function rate(): string
    {
        return MoneyMath::normalize((string) (($this->config ?? config('Platform'))->principalDailyReleaseRate ?: '0.001'), 8);
    }

    private function maxDays(): int
    {
        return max(1, (int) (($this->config ?? config('Platform'))->principalDailyReleaseMaxDays ?: 1000));
    }

    private function timezone(): string
    {
        return (string) (($this->config ?? config('Platform'))->miningBusinessTimezone ?: 'Asia/Kolkata');
    }

    private function scale(): int
    {
        return (int) (($this->config ?? config('Platform'))->coinQuantityScale ?: 8);
    }
}
