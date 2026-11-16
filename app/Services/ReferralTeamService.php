<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Read-only referral/team aggregates from sponsor_relationships.
 *
 * Genealogy depth beyond direct sponsors is computed via BFS on existing
 * sponsor edges. No separate genealogy table is required.
 */
class ReferralTeamService
{
    /** Safety cap to avoid runaway walks on corrupted cycles. */
    private const MAX_TEAM_SIZE = 50000;

    /**
     * @return array{
     *   direct: int,
     *   total: int,
     *   active: int,
     *   inactive: int
     * }
     */
    public function countsForSponsor(int $sponsorUserId): array
    {
        $db = db_connect();

        $direct = (int) $db->table('sponsor_relationships')
            ->where('sponsor_user_id', $sponsorUserId)
            ->countAllResults();

        $teamUserIds = $this->collectDownlineUserIds($sponsorUserId);
        $total       = count($teamUserIds);

        if ($total === 0) {
            return [
                'direct'   => $direct,
                'total'    => 0,
                'active'   => 0,
                'inactive' => 0,
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
                $seen[$uid]   = true;
                $result[]     = $uid;
                $frontier[]   = $uid;

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
        $days = max(1, min(90, $days));
        $tz   = new \DateTimeZone(config('App')->appTimezone);
        $end  = new \DateTimeImmutable('today', $tz);
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
}
