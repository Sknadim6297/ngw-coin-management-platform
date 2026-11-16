<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Dashboard\KpiCardFactory;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use Throwable;

/**
 * Read-only member analytics scoped to one authenticated member.
 */
class MemberDashboardService
{
    private const MINING_REASON     = 'Mining module is not implemented.';
    private const COMMISSION_REASON = 'Level commission module is not implemented.';

    /**
     * @return array{
     *   kpis: list<array<string, mixed>>,
     *   charts: array<string, mixed>,
     *   profile: object,
     *   relationship: array<string, mixed>|null,
     *   referral_link: string,
     *   completion: int,
     *   price_summary: array<string, mixed>,
     *   inventory: array<string, mixed>,
     *   active_locks: list<array<string, mixed>>,
     *   matured_locks: list<array<string, mixed>>,
     *   recent_locks: list<array<string, mixed>>,
     *   timezone: string,
     *   today_start: string,
     *   today_end: string,
     *   generated_at: string
     * }
     */
    public function build(int $memberUserId): array
    {
        $profile = model(MemberProfileModel::class)->findByUserId($memberUserId);
        if ($profile === null) {
            throw new \RuntimeException('Member profile not found.');
        }

        [$todayStart, $todayEnd] = $this->todayBounds();
        $relationship            = model(SponsorRelationshipModel::class)->findByMemberUserId($memberUserId);
        $locking                 = service('coinLockingService')->memberDashboardSnapshot($memberUserId);

        return [
            'kpis'           => $this->kpis($memberUserId, $locking),
            'charts'         => $this->charts($memberUserId, $locking['summary']),
            'profile'        => $profile,
            'relationship'   => $relationship,
            'referral_link'  => site_url('register?sponsor=' . urlencode($profile->member_id)),
            'completion'     => $profile->profileCompletionPercent(),
            'price_summary'  => service('coinPriceService')->settingsSummary(),
            'inventory'      => service('coinInventoryService')->summary(),
            'active_locks'   => $locking['active'],
            'matured_locks'  => $locking['matured'],
            'recent_locks'   => $locking['recent'],
            'timezone'       => (string) config('App')->appTimezone,
            'today_start'    => $todayStart,
            'today_end'      => $todayEnd,
            'generated_at'   => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed>|null $lockingSnapshot
     *
     * @return list<array<string, mixed>>
     */
    public function kpis(int $memberUserId, ?array $lockingSnapshot = null): array
    {
        try {
            $db      = db_connect();
            $locking = $lockingSnapshot ?? service('coinLockingService')->memberDashboardSnapshot($memberUserId);
            $summary = $locking['summary'];

            $pendingPurchases  = (int) $db->table('coin_purchase_requests')
                ->where('member_user_id', $memberUserId)
                ->where('status', 'pending')
                ->countAllResults();
            $approvedPurchases = (int) $db->table('coin_purchase_requests')
                ->where('member_user_id', $memberUserId)
                ->where('status', 'approved')
                ->countAllResults();

            $activeLockCount  = (int) $db->table('member_coin_locks')
                ->where('member_user_id', $memberUserId)
                ->where('status', 'active')
                ->countAllResults();
            $maturedLockCount = (int) $db->table('member_coin_locks')
                ->where('member_user_id', $memberUserId)
                ->where('status', 'matured')
                ->countAllResults();

            $team = service('referralTeamService')->countsForSponsor($memberUserId);

            return [
                KpiCardFactory::ok('my_total_coins', 'My Total Coins', $summary['balance'], 'purple', 'bi-coin', 'coins', site_url('member/wallet')),
                KpiCardFactory::ok('my_available_coins', 'My Available Coins', $summary['available'], 'green', 'bi-wallet2', 'coins', site_url('member/wallet')),
                KpiCardFactory::ok('my_locked_coins', 'My Locked Coins', $summary['locked'], 'amber', 'bi-lock-fill', 'coins', site_url('member/locking')),
                KpiCardFactory::ok('my_purchased_coins', 'My Purchased Coins', $summary['total_acquired'], 'teal', 'bi-cart-check-fill', 'coins', site_url('member/purchases')),
                KpiCardFactory::unavailable('my_today_mining', "My Today's Mining", 'cyan', 'bi-lightning-charge-fill', self::MINING_REASON, site_url('member/coming-soon/mining')),
                KpiCardFactory::unavailable('my_total_mining', 'My Total Mining', 'amber', 'bi-lightning-fill', self::MINING_REASON, site_url('member/coming-soon/mining')),
                KpiCardFactory::unavailable('my_today_level_commission', "My Today's Level Commission", 'indigo', 'bi-diagram-3-fill', self::COMMISSION_REASON, site_url('member/coming-soon/level-income')),
                KpiCardFactory::unavailable('my_total_level_commission', 'My Total Level Commission', 'indigo', 'bi-cash-stack', self::COMMISSION_REASON, site_url('member/coming-soon/commission-wallet')),
                KpiCardFactory::ok('my_total_team', 'My Total Team Members', $team['total'], 'blue', 'bi-people-fill', 'members', site_url('member/coming-soon/referrals')),
                KpiCardFactory::ok('my_active_team', 'My Active Team Members', $team['active'], 'green', 'bi-person-check-fill', 'members', site_url('member/coming-soon/referrals')),
                KpiCardFactory::ok('my_inactive_team', 'My Inactive Team Members', $team['inactive'], 'orange', 'bi-person-x-fill', 'members', site_url('member/coming-soon/referrals')),
                KpiCardFactory::ok('my_direct_referrals', 'My Direct Referrals', $team['direct'], 'blue', 'bi-person-plus-fill', 'members', site_url('member/coming-soon/referrals')),
                KpiCardFactory::ok('my_pending_purchases', 'My Pending Purchases', $pendingPurchases, 'red', 'bi-hourglass-split', 'requests', site_url('member/purchases?status=pending')),
                KpiCardFactory::ok('my_approved_purchases', 'My Approved Purchases', $approvedPurchases, 'green', 'bi-check2-circle', 'requests', site_url('member/purchases?status=approved')),
                KpiCardFactory::ok('my_active_locks', 'My Active Coin Locks', $activeLockCount, 'amber', 'bi-lock', 'locks', site_url('member/locking')),
                KpiCardFactory::ok('my_matured_locks', 'My Matured Coin Locks', $maturedLockCount, 'teal', 'bi-unlock-fill', 'locks', site_url('member/locking')),
            ];
        } catch (Throwable $e) {
            log_message('error', 'MemberDashboardService::kpis failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->errorKpiSet();
        }
    }

    /**
     * @param array<string, string> $summary
     *
     * @return array<string, mixed>
     */
    public function charts(int $memberUserId, array $summary): array
    {
        try {
            $teamTrend = service('referralTeamService')->directReferralTrend($memberUserId, 14);

            return [
                'holdings_breakdown' => [
                    'labels' => ['Available', 'Locked'],
                    'values' => [
                        MoneyMath::normalize((string) ($summary['available'] ?? '0')),
                        MoneyMath::normalize((string) ($summary['locked'] ?? '0')),
                    ],
                ],
                'purchase_activity' => $this->memberPurchaseTrend($memberUserId, 14),
                'locking_activity'  => $this->memberLockTrend($memberUserId, 14),
                'team_growth'       => [
                    'available' => true,
                    'labels'    => $teamTrend['labels'],
                    'values'    => $teamTrend['values'],
                ],
                'mining_history' => [
                    'available' => false,
                    'reason'    => self::MINING_REASON,
                    'labels'    => [],
                    'values'    => [],
                ],
                'commission_history' => [
                    'available' => false,
                    'reason'    => self::COMMISSION_REASON,
                    'labels'    => [],
                    'values'    => [],
                ],
            ];
        } catch (Throwable $e) {
            log_message('error', 'MemberDashboardService::charts failed: {msg}', ['msg' => $e->getMessage()]);

            return [
                'holdings_breakdown' => ['labels' => [], 'values' => []],
                'purchase_activity'  => ['labels' => [], 'values' => []],
                'locking_activity'   => ['labels' => [], 'values' => []],
                'team_growth'        => ['available' => true, 'labels' => [], 'values' => []],
                'mining_history'     => ['available' => false, 'reason' => self::MINING_REASON, 'labels' => [], 'values' => []],
                'commission_history' => ['available' => false, 'reason' => self::COMMISSION_REASON, 'labels' => [], 'values' => []],
            ];
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function todayBounds(): array
    {
        $tz    = new \DateTimeZone((string) config('App')->appTimezone);
        $start = new \DateTimeImmutable('today', $tz);

        return [
            $start->format('Y-m-d H:i:s'),
            $start->modify('+1 day')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array{labels: list<string>, values: list<string>}
     */
    private function memberPurchaseTrend(int $memberUserId, int $days): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new \DateTimeZone((string) config('App')->appTimezone);
        $end   = new \DateTimeImmutable('today', $tz);
        $start = $end->modify('-' . ($days - 1) . ' days');

        $db   = db_connect();
        $rows = $db->query(
            'SELECT DATE(requested_at) AS day_key, COALESCE(SUM(coin_quantity), 0) AS qty
             FROM coin_purchase_requests
             WHERE member_user_id = ?
               AND status = ?
               AND requested_at >= ?
               AND requested_at < ?
             GROUP BY DATE(requested_at)
             ORDER BY day_key ASC',
            [
                $memberUserId,
                'approved',
                $start->format('Y-m-d 00:00:00'),
                $end->modify('+1 day')->format('Y-m-d 00:00:00'),
            ]
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day_key']] = MoneyMath::normalize((string) $row['qty']);
        }

        $labels = [];
        $values = [];
        for ($i = 0; $i < $days; $i++) {
            $day      = $start->modify("+{$i} days")->format('Y-m-d');
            $labels[] = $day;
            $values[] = $map[$day] ?? '0.00000000';
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return array{labels: list<string>, values: list<string>}
     */
    private function memberLockTrend(int $memberUserId, int $days): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new \DateTimeZone((string) config('App')->appTimezone);
        $end   = new \DateTimeImmutable('today', $tz);
        $start = $end->modify('-' . ($days - 1) . ' days');

        $db   = db_connect();
        $rows = $db->query(
            'SELECT DATE(locked_at) AS day_key, COALESCE(SUM(quantity), 0) AS qty
             FROM member_coin_locks
             WHERE member_user_id = ?
               AND locked_at >= ?
               AND locked_at < ?
             GROUP BY DATE(locked_at)
             ORDER BY day_key ASC',
            [
                $memberUserId,
                $start->format('Y-m-d 00:00:00'),
                $end->modify('+1 day')->format('Y-m-d 00:00:00'),
            ]
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $map[(string) $row['day_key']] = MoneyMath::normalize((string) $row['qty']);
        }

        $labels = [];
        $values = [];
        for ($i = 0; $i < $days; $i++) {
            $day      = $start->modify("+{$i} days")->format('Y-m-d');
            $labels[] = $day;
            $values[] = $map[$day] ?? '0.00000000';
        }

        return ['labels' => $labels, 'values' => $values];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errorKpiSet(): array
    {
        $defs = [
            ['my_total_coins', 'My Total Coins', 'purple', 'bi-coin'],
            ['my_available_coins', 'My Available Coins', 'green', 'bi-wallet2'],
            ['my_locked_coins', 'My Locked Coins', 'amber', 'bi-lock-fill'],
            ['my_purchased_coins', 'My Purchased Coins', 'teal', 'bi-cart-check-fill'],
            ['my_today_mining', "My Today's Mining", 'cyan', 'bi-lightning-charge-fill'],
            ['my_total_mining', 'My Total Mining', 'amber', 'bi-lightning-fill'],
            ['my_today_level_commission', "My Today's Level Commission", 'indigo', 'bi-diagram-3-fill'],
            ['my_total_level_commission', 'My Total Level Commission', 'indigo', 'bi-cash-stack'],
            ['my_total_team', 'My Total Team Members', 'blue', 'bi-people-fill'],
            ['my_active_team', 'My Active Team Members', 'green', 'bi-person-check-fill'],
            ['my_inactive_team', 'My Inactive Team Members', 'orange', 'bi-person-x-fill'],
            ['my_direct_referrals', 'My Direct Referrals', 'blue', 'bi-person-plus-fill'],
            ['my_pending_purchases', 'My Pending Purchases', 'red', 'bi-hourglass-split'],
            ['my_approved_purchases', 'My Approved Purchases', 'green', 'bi-check2-circle'],
            ['my_active_locks', 'My Active Coin Locks', 'amber', 'bi-lock'],
            ['my_matured_locks', 'My Matured Coin Locks', 'teal', 'bi-unlock-fill'],
        ];

        $out = [];
        foreach ($defs as [$key, $label, $accent, $icon]) {
            $out[] = KpiCardFactory::error($key, $label, $accent, $icon);
        }

        return $out;
    }
}
