<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Pager\Pager;
use InvalidArgumentException;

/**
 * Phase 2 — Level-wise referral Business Volume (read-only).
 *
 * Volume attribution uses purchase-time sponsor snapshots (L1–L3).
 * Eligible purchases: coin_purchase_requests.status=approved AND payment_status=verified.
 * Commission percents (10/5/3) are informational only — never calculated or posted here.
 */
class ReferralBusinessVolumeService
{
    public const MAX_LEVEL = 3;

    /** Informational only — do not use for payout math in this feature. */
    public const LEVEL_PERCENT_INFO = [
        1 => '10',
        2 => '5',
        3 => '3',
    ];

    public function __construct(
        private readonly ReferralTeamService $team = new ReferralTeamService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel()
    ) {
    }

    /**
     * Dashboard / page summary for one authenticated sponsor.
     *
     * @param array{date_from?: string, date_to?: string} $filters
     * @return array<string, mixed>
     */
    public function summary(int $sponsorUserId, array $filters = []): array
    {
        if ($sponsorUserId <= 0) {
            throw new InvalidArgumentException('Invalid member.');
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);

        $counts = $this->team->countsForSponsor($sponsorUserId, self::MAX_LEVEL);
        $levels = [];
        $totalVolume = '0.00000000';
        $networkMembers = 0;

        for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
            $agg = $this->levelVolumeAggregate($sponsorUserId, $level, $dateFrom, $dateTo);
            $memberCount = (int) ($counts['levels'][$level]['total'] ?? 0);
            $networkMembers += $memberCount;
            $totalVolume = MoneyMath::add($totalVolume, $agg['volume_usdt'], 8);

            $levels[$level] = [
                'level'                   => $level,
                'commission_percent_info' => self::LEVEL_PERCENT_INFO[$level],
                'business_volume_usdt'    => $agg['volume_usdt'],
                'purchase_count'          => $agg['purchase_count'],
                'purchasing_member_count' => $agg['purchasing_member_count'],
                'referral_member_count'   => $memberCount,
            ];
        }

        return [
            'sponsor_user_id'             => $sponsorUserId,
            'total_business_volume_usdt'  => $totalVolume,
            'levels'                      => $levels,
            'direct_referral_count'       => (int) ($counts['direct'] ?? 0),
            'network_member_count'        => $networkMembers,
            'team_active'                 => (int) ($counts['active'] ?? 0),
            'team_inactive'               => (int) ($counts['inactive'] ?? 0),
            'attribution'                 => 'purchase_sponsor_snapshots',
            'eligible_rule'               => 'approved_and_payment_verified',
            'date_field'                  => 'coin_purchase_requests.reviewed_at',
            'date_from'                   => $dateFrom,
            'date_to'                     => $dateTo,
            'timezone'                    => $this->displayTimezone(),
            'commission_rates_info_only'  => self::LEVEL_PERCENT_INFO,
        ];
    }

    /**
     * Paginated members at a live referral level with eligible volume attributed to the viewer.
     *
     * @param array{q?: string, date_from?: string, date_to?: string, per_page?: int|string, page?: int|string} $filters
     * @return array{rows: list<array<string, mixed>>, pager: Pager|null, filters: array<string, mixed>, summary: array<string, mixed>, level: int}
     */
    public function membersAtLevel(int $sponsorUserId, int $level, array $filters = []): array
    {
        $level = $this->assertLevel($level);
        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);
        $q        = trim((string) ($filters['q'] ?? ''));
        if (strlen($q) > 64) {
            throw new InvalidArgumentException('Search query is too long.');
        }

        $perPage = (int) ($filters['per_page'] ?? 20);
        $perPage = in_array($perPage, [10, 20, 25, 50], true) ? $perPage : 20;
        $page    = max(1, (int) ($filters['page'] ?? 1));

        $levelUserIds = $this->userIdsAtLevel($sponsorUserId, $level);
        $levelAgg     = $this->levelVolumeAggregate($sponsorUserId, $level, $dateFrom, $dateTo);

        $filtersOut = [
            'level'     => $level,
            'q'         => $q,
            'date_from' => $dateFrom !== null ? substr($dateFrom, 0, 10) : '',
            'date_to'   => $dateTo !== null ? substr($dateTo, 0, 10) : '',
            'per_page'  => $perPage,
            'page'      => $page,
        ];

        if ($levelUserIds === []) {
            return [
                'rows'    => [],
                'pager'   => null,
                'filters' => $filtersOut,
                'summary' => $levelAgg + ['referral_member_count' => 0],
                'level'   => $level,
            ];
        }

        $db = db_connect();
        $b  = $db->table('member_profiles')
            ->select('user_id, member_id, member_name, account_status, created_at')
            ->whereIn('user_id', $levelUserIds);

        if ($q !== '') {
            $b->groupStart()
                ->like('member_id', $q)
                ->orLike('member_name', $q)
                ->groupEnd();
        }

        $total = $b->countAllResults(false);
        $offset = ($page - 1) * $perPage;
        $profiles = $b->orderBy('created_at', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        $pageUserIds = array_map(static fn (array $r): int => (int) $r['user_id'], $profiles);
        $volumes     = $this->volumeByPurchasers($sponsorUserId, $level, $pageUserIds, $dateFrom, $dateTo);

        $rows = [];
        foreach ($profiles as $p) {
            $uid = (int) $p['user_id'];
            $vol = $volumes[$uid] ?? [
                'purchase_count'       => 0,
                'business_volume_usdt' => '0.00000000',
                'latest_purchase_at'   => null,
            ];
            $rows[] = [
                'user_id'               => $uid,
                'member_id'             => (string) $p['member_id'],
                'member_name'           => (string) $p['member_name'],
                'referral_level'        => $level,
                'joined_at'             => (string) ($p['created_at'] ?? ''),
                'account_status'        => (string) ($p['account_status'] ?? ''),
                'purchase_count'        => (int) $vol['purchase_count'],
                'business_volume_usdt'  => (string) $vol['business_volume_usdt'],
                'latest_purchase_at'    => $vol['latest_purchase_at'],
                'commission_percent_info' => self::LEVEL_PERCENT_INFO[$level],
            ];
        }

        /** @var Pager $pager */
        $pager = service('pager');
        $pager->store('bv_level', $page, $perPage, $total);

        return [
            'rows'    => $rows,
            'pager'   => $pager,
            'filters' => $filtersOut,
            'summary' => $levelAgg + ['referral_member_count' => count($levelUserIds)],
            'level'   => $level,
        ];
    }

    /**
     * Eligible purchase history for one downline member attributed to the viewer at a given level.
     *
     * @param array{date_from?: string, date_to?: string, per_page?: int|string, page?: int|string} $filters
     * @return array<string, mixed>
     */
    public function memberPurchases(int $viewerUserId, string $targetMemberId, array $filters = []): array
    {
        $target = $this->profiles->findByMemberId(trim($targetMemberId));
        if ($target === null) {
            throw new InvalidArgumentException('Member not found.');
        }
        $targetUserId = (int) $target->user_id;
        if (! $this->team->isInDownline($viewerUserId, $targetUserId)) {
            throw new InvalidArgumentException('Member is outside your referral network.');
        }

        $level = $this->levelOfDownline($viewerUserId, $targetUserId);
        if ($level === null || $level > self::MAX_LEVEL) {
            throw new InvalidArgumentException('Member is outside your three-level business network.');
        }

        $dateFrom = $this->normalizeDate($filters['date_from'] ?? null, false);
        $dateTo   = $this->normalizeDate($filters['date_to'] ?? null, true);
        $perPage  = (int) ($filters['per_page'] ?? 20);
        $perPage  = in_array($perPage, [10, 20, 25, 50], true) ? $perPage : 20;
        $page     = max(1, (int) ($filters['page'] ?? 1));

        $applyEligibility = static function ($b) use ($viewerUserId, $level, $targetUserId, $dateFrom, $dateTo): void {
            $b->join('coin_purchase_requests p', 'p.purchase_ref = s.purchase_ref', 'inner')
                ->where('s.sponsor_user_id', $viewerUserId)
                ->where('s.sponsor_level', $level)
                ->where('s.is_missing', 0)
                ->where('s.purchaser_user_id', $targetUserId)
                ->where('p.status', 'approved')
                ->where('p.payment_status', 'verified');
            if ($dateFrom !== null) {
                $b->where('p.reviewed_at >=', $dateFrom);
            }
            if ($dateTo !== null) {
                $b->where('p.reviewed_at <=', $dateTo);
            }
        };

        $db = db_connect();
        $countB = $db->table('purchase_sponsor_snapshots s');
        $applyEligibility($countB);
        $total = $countB->countAllResults();

        $sumB = $db->table('purchase_sponsor_snapshots s');
        $applyEligibility($sumB);
        $sumRow = $sumB->select('COALESCE(SUM(p.total_usdt), 0) AS volume, COUNT(*) AS purchase_count', false)
            ->get()->getRowArray();

        $volume = MoneyMath::normalize((string) ($sumRow['volume'] ?? '0'), 8);
        $purchaseCount = (int) ($sumRow['purchase_count'] ?? 0);

        $listB = $db->table('purchase_sponsor_snapshots s');
        $applyEligibility($listB);
        $rows = $listB->select(
            'p.purchase_ref, p.requested_at, p.reviewed_at, p.total_usdt, p.status, p.payment_status, p.coin_quantity'
        )
            ->orderBy('p.reviewed_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'purchase_ref'              => (string) $row['purchase_ref'],
                'requested_at'              => (string) ($row['requested_at'] ?? ''),
                'approved_at'               => (string) ($row['reviewed_at'] ?? ''),
                'eligible_amount_usdt'      => MoneyMath::normalize((string) $row['total_usdt'], 8),
                'purchase_status'           => (string) $row['status'],
                'deposit_verification'      => (string) $row['payment_status'],
                'coin_quantity'             => MoneyMath::normalize((string) ($row['coin_quantity'] ?? '0'), 8),
            ];
        }

        /** @var Pager $pager */
        $pager = service('pager');
        $pager->store('bv_member', $page, $perPage, $total);

        return [
            'member' => [
                'user_id'        => $targetUserId,
                'member_id'      => (string) $target->member_id,
                'member_name'    => (string) $target->member_name,
                'referral_level' => $level,
                'joined_at'      => (string) ($target->created_at ?? ''),
            ],
            'business_volume_usdt' => $volume,
            'purchase_count'       => $purchaseCount,
            'rows'                 => $out,
            'pager'                => $pager,
            'filters'              => [
                'date_from' => $dateFrom !== null ? substr($dateFrom, 0, 10) : '',
                'date_to'   => $dateTo !== null ? substr($dateTo, 0, 10) : '',
                'per_page'  => $perPage,
                'page'      => $page,
            ],
        ];
    }

    /**
     * @return array{volume_usdt: string, purchase_count: int, purchasing_member_count: int}
     */
    private function levelVolumeAggregate(int $sponsorUserId, int $level, ?string $dateFrom, ?string $dateTo): array
    {
        $db = db_connect();
        $b  = $db->table('purchase_sponsor_snapshots s')
            ->join('coin_purchase_requests p', 'p.purchase_ref = s.purchase_ref', 'inner')
            ->where('s.sponsor_user_id', $sponsorUserId)
            ->where('s.sponsor_level', $level)
            ->where('s.is_missing', 0)
            ->where('p.status', 'approved')
            ->where('p.payment_status', 'verified');

        $this->applyReviewedAtFilter($b, $dateFrom, $dateTo);

        $row = $b->select(
            'COALESCE(SUM(p.total_usdt), 0) AS volume, COUNT(DISTINCT p.purchase_ref) AS purchase_count, COUNT(DISTINCT s.purchaser_user_id) AS purchasing_members',
            false
        )->get()->getRowArray();

        return [
            'volume_usdt'             => MoneyMath::normalize((string) ($row['volume'] ?? '0'), 8),
            'purchase_count'          => (int) ($row['purchase_count'] ?? 0),
            'purchasing_member_count' => (int) ($row['purchasing_members'] ?? 0),
        ];
    }

    /**
     * @param list<int> $purchaserUserIds
     * @return array<int, array{purchase_count: int, business_volume_usdt: string, latest_purchase_at: ?string}>
     */
    private function volumeByPurchasers(
        int $sponsorUserId,
        int $level,
        array $purchaserUserIds,
        ?string $dateFrom,
        ?string $dateTo
    ): array {
        if ($purchaserUserIds === []) {
            return [];
        }

        $db = db_connect();
        $b  = $db->table('purchase_sponsor_snapshots s')
            ->join('coin_purchase_requests p', 'p.purchase_ref = s.purchase_ref', 'inner')
            ->where('s.sponsor_user_id', $sponsorUserId)
            ->where('s.sponsor_level', $level)
            ->where('s.is_missing', 0)
            ->whereIn('s.purchaser_user_id', $purchaserUserIds)
            ->where('p.status', 'approved')
            ->where('p.payment_status', 'verified');

        $this->applyReviewedAtFilter($b, $dateFrom, $dateTo);

        $rows = $b->select(
            's.purchaser_user_id, COUNT(DISTINCT p.purchase_ref) AS purchase_count, COALESCE(SUM(p.total_usdt), 0) AS volume, MAX(p.reviewed_at) AS latest_purchase_at',
            false
        )
            ->groupBy('s.purchaser_user_id')
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['purchaser_user_id'];
            $out[$uid] = [
                'purchase_count'       => (int) $row['purchase_count'],
                'business_volume_usdt' => MoneyMath::normalize((string) $row['volume'], 8),
                'latest_purchase_at'   => $row['latest_purchase_at'] !== null ? (string) $row['latest_purchase_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function userIdsAtLevel(int $sponsorUserId, int $level): array
    {
        $level = $this->assertLevel($level);
        $db    = db_connect();
        $frontier = [$sponsorUserId];
        $seen     = [$sponsorUserId => true];

        for ($depth = 1; $depth <= $level; $depth++) {
            if ($frontier === []) {
                return [];
            }
            $rows = $db->table('sponsor_relationships')
                ->select('member_user_id')
                ->whereIn('sponsor_user_id', $frontier)
                ->get()
                ->getResultArray();

            $next = [];
            foreach ($rows as $row) {
                $uid = (int) $row['member_user_id'];
                if ($uid <= 0 || isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                $next[]     = $uid;
            }
            $frontier = $next;
            if ($depth === $level) {
                return $frontier;
            }
        }

        return [];
    }

    private function levelOfDownline(int $sponsorUserId, int $memberUserId): ?int
    {
        for ($level = 1; $level <= self::MAX_LEVEL; $level++) {
            if (in_array($memberUserId, $this->userIdsAtLevel($sponsorUserId, $level), true)) {
                return $level;
            }
        }

        return null;
    }

    private function assertLevel(int $level): int
    {
        if ($level < 1 || $level > self::MAX_LEVEL) {
            throw new InvalidArgumentException('Referral level must be 1, 2, or 3.');
        }

        return $level;
    }

    /**
     * @param object $builder Query builder
     */
    private function applyReviewedAtFilter(object $builder, ?string $dateFrom, ?string $dateTo): void
    {
        if ($dateFrom !== null) {
            $builder->where('p.reviewed_at >=', $dateFrom);
        }
        if ($dateTo !== null) {
            $builder->where('p.reviewed_at <=', $dateTo);
        }
    }

    private function normalizeDate(?string $raw, bool $endOfDay): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new InvalidArgumentException('Dates must use YYYY-MM-DD format.');
        }

        $tz = new \DateTimeZone($this->displayTimezone());
        try {
            $dt = new \DateTimeImmutable($raw . ($endOfDay ? ' 23:59:59' : ' 00:00:00'), $tz);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid date value.');
        }

        // Store comparisons against DB timestamps (typically server/local); convert to UTC wall for consistency
        // when app timezone differs. Prefer comparing as business-local formatted datetime strings.
        return $dt->format('Y-m-d H:i:s');
    }

    private function displayTimezone(): string
    {
        return (string) (config('Platform')->miningBusinessTimezone ?: 'Asia/Kolkata');
    }
}
