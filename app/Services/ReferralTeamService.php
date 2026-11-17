<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Pager\Pager;
use InvalidArgumentException;

/**
 * Read-only referral/team aggregates from sponsor_relationships.
 *
 * Genealogy depth beyond direct sponsors is computed via BFS on existing
 * sponsor edges. No separate genealogy/closure table. No commissions.
 */
class ReferralTeamService
{
    /** Safety cap to avoid runaway walks on corrupted cycles. */
    public const MAX_TEAM_SIZE = 50000;

    public const MAX_TREE_DEPTH = 10;

    public const MAX_TREE_NODES = 500;

    /**
     * @return array{
     *   direct: int,
     *   total: int,
     *   active: int,
     *   inactive: int,
     *   levels: array<int, array{total: int, active: int, inactive: int}>
     * }
     */
    public function countsForSponsor(int $sponsorUserId, int $maxLevels = 3): array
    {
        $db = db_connect();

        $direct = (int) $db->table('sponsor_relationships')
            ->where('sponsor_user_id', $sponsorUserId)
            ->countAllResults();

        $levels = $this->levelCounts($sponsorUserId, $maxLevels);
        $teamUserIds = $this->collectDownlineUserIds($sponsorUserId);
        $total       = count($teamUserIds);

        if ($total === 0) {
            return [
                'direct'   => $direct,
                'total'    => 0,
                'active'   => 0,
                'inactive' => 0,
                'levels'   => $levels,
            ];
        }

        $active = (int) $db->table('member_profiles')
            ->whereIn('user_id', $teamUserIds)
            ->where('account_status', 'active')
            ->countAllResults();

        return [
            'direct'   => $direct,
            'total'    => $total,
            'active'   => $active,
            'inactive' => max(0, $total - $active),
            'levels'   => $levels,
        ];
    }

    /**
     * Level-wise counts (sponsor-tree depth). Each member counted once at first depth.
     *
     * @return array<int, array{total: int, active: int, inactive: int}>
     */
    public function levelCounts(int $sponsorUserId, int $maxLevels = 3): array
    {
        $maxLevels = max(1, min(self::MAX_TREE_DEPTH, $maxLevels));
        $db        = db_connect();
        $frontier  = [$sponsorUserId];
        $seen      = [$sponsorUserId => true];
        $levels    = [];

        for ($level = 1; $level <= $maxLevels; $level++) {
            $levels[$level] = ['total' => 0, 'active' => 0, 'inactive' => 0];
            if ($frontier === []) {
                continue;
            }

            $rows = $db->table('sponsor_relationships')
                ->select('member_user_id')
                ->whereIn('sponsor_user_id', $frontier)
                ->get()
                ->getResultArray();

            $next = [];
            $ids  = [];
            foreach ($rows as $row) {
                $uid = (int) $row['member_user_id'];
                if ($uid <= 0 || isset($seen[$uid])) {
                    continue;
                }
                $seen[$uid] = true;
                $ids[]      = $uid;
                $next[]     = $uid;
            }

            if ($ids === []) {
                $frontier = [];
                continue;
            }

            $active = (int) $db->table('member_profiles')
                ->whereIn('user_id', $ids)
                ->where('account_status', 'active')
                ->countAllResults();
            $total = count($ids);

            $levels[$level] = [
                'total'    => $total,
                'active'   => $active,
                'inactive' => max(0, $total - $active),
            ];
            $frontier = $next;
        }

        return $levels;
    }

    /**
     * Paginated direct referrals (level 1). Safe public fields only.
     *
     * @param array{status?: string, q?: string, per_page?: int, page?: int} $filters
     * @return array{rows: list<array<string, mixed>>, pager: Pager, filters: array<string, mixed>, summary: array<string, int>}
     */
    public function directReferrals(int $sponsorUserId, array $filters = []): array
    {
        $status  = $this->normalizeStatus($filters['status'] ?? null);
        $q       = $this->normalizeSearch($filters['q'] ?? null);
        $perPage = max(5, min(50, (int) ($filters['per_page'] ?? 20)));

        $db = db_connect();
        $applyFilters = static function ($builder) use ($sponsorUserId, $status, $q) {
            $builder->join('member_profiles p', 'p.user_id = sr.member_user_id', 'inner')
                ->where('sr.sponsor_user_id', $sponsorUserId);
            if ($status !== null) {
                $builder->where('p.account_status', $status);
            }
            if ($q !== null) {
                $builder->groupStart()
                    ->like('p.member_id', $q)
                    ->orLike('p.member_name', $q)
                    ->groupEnd();
            }

            return $builder;
        };

        $total = (int) $applyFilters($db->table('sponsor_relationships sr'))->countAllResults();

        $page   = max(1, (int) ($filters['page'] ?? service('request')->getGet('page') ?? 1));
        $offset = ($page - 1) * $perPage;

        $rows = $applyFilters($db->table('sponsor_relationships sr')
            ->select('p.member_id, p.member_name, p.account_status, p.created_at AS registered_at, sr.created_at AS sponsored_at, sr.member_user_id'))
            ->orderBy('sr.id', 'DESC')
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['referral_level'] = 1;
        }
        unset($row);

        $pager = service('pager');
        $pager->store('default', $page, $perPage, $total);

        $summary = [
            'total'    => (int) db_connect()->table('sponsor_relationships')->where('sponsor_user_id', $sponsorUserId)->countAllResults(),
            'active'   => (int) db_connect()->table('sponsor_relationships sr')
                ->join('member_profiles p', 'p.user_id = sr.member_user_id')
                ->where('sr.sponsor_user_id', $sponsorUserId)
                ->where('p.account_status', 'active')
                ->countAllResults(),
            'inactive' => 0,
        ];
        $summary['inactive'] = max(0, $summary['total'] - $summary['active']);

        return [
            'rows'    => $rows,
            'pager'   => $pager,
            'filters' => [
                'status'   => $status ?? '',
                'q'        => $filters['q'] ?? '',
                'per_page' => $perPage,
            ],
            'summary' => $summary,
        ];
    }

    /**
     * Bounded subtree for genealogy UI. Cycle-safe BFS.
     *
     * @return array{
     *   root_user_id: int,
     *   max_depth: int,
     *   truncated: bool,
     *   nodes: list<array<string, mixed>>,
     *   by_parent: array<int, list<array<string, mixed>>>
     * }
     */
    public function subtree(int $rootUserId, int $maxDepth = 3, int $maxNodes = self::MAX_TREE_NODES): array
    {
        $maxDepth = max(1, min(self::MAX_TREE_DEPTH, $maxDepth));
        $maxNodes = max(1, min(self::MAX_TEAM_SIZE, $maxNodes));
        $db       = db_connect();

        $frontier = [$rootUserId];
        $seen     = [$rootUserId => true];
        $nodes    = [];
        $byParent = [];
        $truncated = false;
        $depthMap = [$rootUserId => 0];

        for ($depth = 1; $depth <= $maxDepth; $depth++) {
            if ($frontier === []) {
                break;
            }

            $rows = $db->table('sponsor_relationships sr')
                ->select('sr.member_user_id, sr.sponsor_user_id, p.member_id, p.member_name, p.account_status, p.created_at AS registered_at')
                ->join('member_profiles p', 'p.user_id = sr.member_user_id', 'inner')
                ->whereIn('sr.sponsor_user_id', $frontier)
                ->orderBy('p.member_id', 'ASC')
                ->get()
                ->getResultArray();

            $next = [];
            foreach ($rows as $row) {
                $uid = (int) $row['member_user_id'];
                $parent = (int) $row['sponsor_user_id'];
                if ($uid <= 0 || isset($seen[$uid])) {
                    continue;
                }
                if (count($nodes) >= $maxNodes) {
                    $truncated = true;
                    break 2;
                }

                $seen[$uid]     = true;
                $depthMap[$uid] = $depth;
                $node = [
                    'user_id'         => $uid,
                    'parent_user_id'  => $parent,
                    'member_id'       => $row['member_id'],
                    'member_name'     => $row['member_name'],
                    'account_status'  => $row['account_status'],
                    'registered_at'   => $row['registered_at'],
                    'level'           => $depth,
                    'has_children'    => false,
                ];
                $nodes[]              = $node;
                $byParent[$parent][]  = $node;
                $next[]               = $uid;
            }
            $frontier = $next;
        }

        // Mark has_children for leaves that may have deeper unexplored children.
        if ($frontier !== [] && ! $truncated) {
            $childCounts = $db->table('sponsor_relationships')
                ->select('sponsor_user_id, COUNT(*) AS cnt')
                ->whereIn('sponsor_user_id', $frontier)
                ->groupBy('sponsor_user_id')
                ->get()
                ->getResultArray();
            $hasKids = [];
            foreach ($childCounts as $c) {
                $hasKids[(int) $c['sponsor_user_id']] = (int) $c['cnt'] > 0;
            }
            foreach ($byParent as $parentId => &$children) {
                foreach ($children as &$child) {
                    if (($depthMap[$child['user_id']] ?? 0) >= $maxDepth) {
                        $child['has_children'] = ! empty($hasKids[$child['user_id']]);
                    } else {
                        $child['has_children'] = isset($byParent[$child['user_id']]);
                    }
                }
                unset($child);
            }
            unset($children);
        } else {
            foreach ($byParent as &$children) {
                foreach ($children as &$child) {
                    $child['has_children'] = isset($byParent[$child['user_id']])
                        || (($depthMap[$child['user_id']] ?? 0) >= $maxDepth && $this->hasDirectChildren((int) $child['user_id']));
                }
                unset($child);
            }
            unset($children);
        }

        return [
            'root_user_id' => $rootUserId,
            'max_depth'    => $maxDepth,
            'truncated'   => $truncated,
            'nodes'        => $nodes,
            'by_parent'    => $byParent,
        ];
    }

    /**
     * Children of one node (for expand/collapse). Must belong under $viewerRootUserId.
     *
     * @return list<array<string, mixed>>
     */
    public function childrenOf(int $viewerRootUserId, int $parentUserId, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        if ($parentUserId !== $viewerRootUserId && ! $this->isInDownline($viewerRootUserId, $parentUserId)) {
            throw new InvalidArgumentException('Parent member is outside your referral network.');
        }

        $rows = db_connect()->table('sponsor_relationships sr')
            ->select('sr.member_user_id AS user_id, sr.sponsor_user_id AS parent_user_id, p.member_id, p.member_name, p.account_status, p.created_at AS registered_at')
            ->join('member_profiles p', 'p.user_id = sr.member_user_id', 'inner')
            ->where('sr.sponsor_user_id', $parentUserId)
            ->orderBy('p.member_id', 'ASC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row['user_id'];
            $out[] = [
                'user_id'        => $uid,
                'parent_user_id' => (int) $row['parent_user_id'],
                'member_id'      => $row['member_id'],
                'member_name'    => $row['member_name'],
                'account_status' => $row['account_status'],
                'registered_at'  => $row['registered_at'],
                'has_children'   => $this->hasDirectChildren($uid),
            ];
        }

        return $out;
    }

    public function isInDownline(int $sponsorUserId, int $memberUserId): bool
    {
        if ($sponsorUserId === $memberUserId) {
            return false;
        }

        return in_array($memberUserId, $this->collectDownlineUserIds($sponsorUserId), true);
    }

    /**
     * Walk upward from proposed sponsor; true if $memberUserId appears (would create a cycle).
     */
    public function wouldCreateCycle(int $memberUserId, int $sponsorUserId): bool
    {
        if ($memberUserId === $sponsorUserId) {
            return true;
        }

        $db      = db_connect();
        $current = $sponsorUserId;
        $seen    = [];
        $guard   = 0;

        while ($current > 0 && $guard < self::MAX_TREE_DEPTH + 50) {
            $guard++;
            if ($current === $memberUserId) {
                return true;
            }
            if (isset($seen[$current])) {
                return true; // existing cycle in ancestry
            }
            $seen[$current] = true;
            $row = $db->table('sponsor_relationships')
                ->select('sponsor_user_id')
                ->where('member_user_id', $current)
                ->get()
                ->getRowArray();
            if ($row === null) {
                return false;
            }
            $current = (int) $row['sponsor_user_id'];
        }

        return false;
    }

    /**
     * Platform-wide referral stats for admin dashboards.
     *
     * @return array{
     *   total_relationships: int,
     *   members_with_sponsors: int,
     *   members_without_sponsors: int,
     *   total_members: int,
     *   active_members: int,
     *   inactive_members: int
     * }
     */
    public function platformStats(): array
    {
        $db = db_connect();
        $profiles = model(\App\Models\MemberProfileModel::class);

        $totalMembers    = $profiles->countByStatus();
        $activeMembers   = $profiles->countByStatus('active');
        $inactiveMembers = $profiles->countByStatus('inactive');
        $withSponsors    = (int) $db->table('sponsor_relationships')->countAllResults();

        // Profiles excluding root sponsors that have no sponsor edge.
        $without = (int) $db->query(
            'SELECT COUNT(*) AS cnt
             FROM member_profiles p
             LEFT JOIN sponsor_relationships sr ON sr.member_user_id = p.user_id
             WHERE p.is_root_sponsor = 0 AND sr.id IS NULL'
        )->getRowArray()['cnt'] ?? 0;

        return [
            'total_relationships'       => $withSponsors,
            'members_with_sponsors'     => $withSponsors,
            'members_without_sponsors'  => (int) $without,
            'total_members'             => $totalMembers,
            'active_members'            => $activeMembers,
            'inactive_members'          => $inactiveMembers,
        ];
    }

    /**
     * @return list<int>
     */
    public function collectDownlineUserIds(int $sponsorUserId): array
    {
        $db       = db_connect();
        $frontier = [$sponsorUserId];
        $seen     = [];
        $result   = [];

        while ($frontier !== [] && count($result) < self::MAX_TEAM_SIZE) {
            $rows = $db->table('sponsor_relationships')
                ->select('member_user_id')
                ->whereIn('sponsor_user_id', $frontier)
                ->get()
                ->getResultArray();

            $frontier = [];
            foreach ($rows as $row) {
                $uid = (int) $row['member_user_id'];
                if ($uid <= 0 || isset($seen[$uid]) || $uid === $sponsorUserId) {
                    continue;
                }
                $seen[$uid] = true;
                $result[]   = $uid;
                $frontier[] = $uid;

                if (count($result) >= self::MAX_TEAM_SIZE) {
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Daily direct-referral registrations for charting (last N days inclusive).
     *
     * @return array{labels: list<string>, values: list<int>}
     */
    public function directReferralTrend(int $sponsorUserId, int $days = 14): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new \DateTimeZone(config('App')->appTimezone);
        $end   = new \DateTimeImmutable('today', $tz);
        $start = $end->modify('-' . ($days - 1) . ' days');

        $db   = db_connect();
        $rows = $db->query(
            'SELECT DATE(sr.created_at) AS day_key, COUNT(*) AS cnt
             FROM sponsor_relationships sr
             WHERE sr.sponsor_user_id = ?
               AND sr.created_at >= ?
               AND sr.created_at < ?
             GROUP BY DATE(sr.created_at)
             ORDER BY day_key ASC',
            [
                $sponsorUserId,
                $start->format('Y-m-d 00:00:00'),
                $end->modify('+1 day')->format('Y-m-d 00:00:00'),
            ]
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day_key']] = (int) $row['cnt'];
        }

        $labels = [];
        $values = [];
        for ($i = 0; $i < $days; $i++) {
            $day      = $start->modify("+{$i} days")->format('Y-m-d');
            $labels[] = $day;
            $values[] = $map[$day] ?? 0;
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function hasDirectChildren(int $userId): bool
    {
        return (int) db_connect()->table('sponsor_relationships')
            ->where('sponsor_user_id', $userId)
            ->countAllResults() > 0;
    }

    private function normalizeStatus(mixed $status): ?string
    {
        $status = strtolower(trim((string) $status));
        if ($status === '') {
            return null;
        }
        if (! in_array($status, ['active', 'inactive'], true)) {
            throw new InvalidArgumentException('Status filter must be active or inactive.');
        }

        return $status;
    }

    private function normalizeSearch(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 64 || ! preg_match('/^[A-Za-z0-9 _.\-]+$/', $value)) {
            throw new InvalidArgumentException('Search contains invalid characters.');
        }

        return $value;
    }
}
