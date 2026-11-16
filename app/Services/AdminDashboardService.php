<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Dashboard\KpiCardFactory;
use App\Libraries\MoneyMath;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use Throwable;

/**
 * Read-only admin analytics. Never mutates balances, purchases, or ledgers.
 */
class AdminDashboardService
{
    private const MINING_REASON     = 'Mining module is not implemented.';
    private const COMMISSION_REASON = 'Level commission module is not implemented.';

    /**
     * @return array{
     *   kpis: list<array<string, mixed>>,
     *   charts: array<string, mixed>,
     *   recent_registrations: list<object>,
     *   timezone: string,
     *   today_start: string,
     *   today_end: string,
     *   generated_at: string
     * }
     */
    public function build(): array
    {
        [$todayStart, $todayEnd] = $this->todayBounds();

        return [
            'kpis'                 => $this->kpis($todayStart, $todayEnd),
            'charts'               => $this->charts(),
            'recent_registrations' => model(MemberProfileModel::class)->recentRegistrations(8),
            'timezone'             => (string) config('App')->appTimezone,
            'today_start'          => $todayStart,
            'today_end'            => $todayEnd,
            'generated_at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function kpis(?string $todayStart = null, ?string $todayEnd = null): array
    {
        if ($todayStart === null || $todayEnd === null) {
            [$todayStart, $todayEnd] = $this->todayBounds();
        }

        try {
            $db        = db_connect();
            $profiles  = model(MemberProfileModel::class);
            $purchases = model(CoinPurchaseRequestModel::class);
            $inventory = service('coinInventoryService')->summary();

            $totalMembers    = $profiles->countByStatus();
            $activeMembers   = $profiles->countByStatus('active');
            $inactiveMembers = $profiles->countByStatus('inactive');

            $purchasedQty = $this->sumApprovedPurchaseQuantity($db);
            $memberSums   = $this->memberBalanceTotals($db);

            $pendingPurchases  = $purchases->countByStatus('pending');
            $approvedPurchases = $purchases->countByStatus('approved');
            $pendingPayments   = (int) $db->table('usdt_deposit_requests')
                ->whereIn('status', ['submitted', 'under_review'])
                ->countAllResults();

            // $todayStart / $todayEnd reserved for mining & commission day filters when modules ship.
            return [
                KpiCardFactory::ok('total_members', 'Total Members', $totalMembers, 'blue', 'bi-people-fill', 'members', site_url('admin/members')),
                KpiCardFactory::ok('active_members', 'Active Members', $activeMembers, 'green', 'bi-person-check-fill', 'members', site_url('admin/members/active')),
                KpiCardFactory::ok('inactive_members', 'Inactive Members', $inactiveMembers, 'orange', 'bi-person-x-fill', 'members', site_url('admin/members/inactive')),
                KpiCardFactory::ok('total_coins', 'Total Coins', $inventory['total_added'], 'purple', 'bi-database-fill', 'coins', site_url('admin/coins')),
                KpiCardFactory::ok('used_coins', 'Used Coins', $inventory['total_allocated'], 'pink', 'bi-box-arrow-up', 'coins', site_url('admin/coins/inventory')),
                KpiCardFactory::ok('available_inventory', 'Available Inventory Coins', $inventory['available_balance'], 'gold', 'bi-box-seam', 'coins', site_url('admin/coins/inventory')),
                KpiCardFactory::unavailable('today_mining', "Today's Mining", 'cyan', 'bi-lightning-charge-fill', self::MINING_REASON, site_url('admin/coming-soon/mining')),
                KpiCardFactory::unavailable('total_mining', 'Total Mining', 'amber', 'bi-lightning-fill', self::MINING_REASON, site_url('admin/coming-soon/mining')),
                KpiCardFactory::unavailable('today_level_commission', "Today's Level Commission", 'indigo', 'bi-diagram-3-fill', self::COMMISSION_REASON, site_url('admin/coming-soon/level-income')),
                KpiCardFactory::unavailable('total_level_commission', 'Total Level Commission', 'indigo', 'bi-cash-stack', self::COMMISSION_REASON, site_url('admin/coming-soon/level-income')),
                KpiCardFactory::ok('purchased_coins', 'Purchased Coins', $purchasedQty, 'teal', 'bi-cart-check-fill', 'coins', site_url('admin/purchases?status=approved')),
                KpiCardFactory::ok('total_locked_coins', 'Total Locked Coins', $memberSums['locked'], 'amber', 'bi-lock-fill', 'coins', site_url('admin/locking')),
                KpiCardFactory::ok('total_available_member_coins', 'Total Available Member Coins', $memberSums['available'], 'green', 'bi-wallet2', 'coins', site_url('admin/wallets')),
                KpiCardFactory::ok('pending_purchases', 'Pending Coin Purchases', $pendingPurchases, 'red', 'bi-hourglass-split', 'requests', site_url('admin/purchases?status=pending')),
                KpiCardFactory::ok('approved_purchases', 'Approved Coin Purchases', $approvedPurchases, 'green', 'bi-check2-circle', 'requests', site_url('admin/purchases?status=approved')),
                KpiCardFactory::ok('pending_payment_verification', 'Pending Payment Verification', $pendingPayments, 'red', 'bi-shield-exclamation', 'deposits', site_url('admin/deposits?status=submitted')),
            ];
        } catch (Throwable $e) {
            log_message('error', 'AdminDashboardService::kpis failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->errorKpiSet();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function charts(): array
    {
        try {
            return [
                'member_registration_trend' => $this->memberRegistrationTrend(14),
                'coin_purchase_trend'       => $this->dailyPurchaseQuantityTrend(14),
                'inventory_distribution'    => $this->inventoryDistribution(),
                'member_status'             => $this->memberStatusDistribution(),
                'mining_trend'              => [
                    'available' => false,
                    'reason'    => self::MINING_REASON,
                    'labels'    => [],
                    'values'    => [],
                ],
                'commission_trend' => [
                    'available' => false,
                    'reason'    => self::COMMISSION_REASON,
                    'labels'    => [],
                    'values'    => [],
                ],
            ];
        } catch (Throwable $e) {
            log_message('error', 'AdminDashboardService::charts failed: {msg}', ['msg' => $e->getMessage()]);

            return [
                'member_registration_trend' => ['labels' => [], 'values' => []],
                'coin_purchase_trend'       => ['labels' => [], 'values' => []],
                'inventory_distribution'    => ['labels' => [], 'values' => []],
                'member_status'             => ['labels' => [], 'values' => []],
                'mining_trend'              => ['available' => false, 'reason' => self::MINING_REASON, 'labels' => [], 'values' => []],
                'commission_trend'          => ['available' => false, 'reason' => self::COMMISSION_REASON, 'labels' => [], 'values' => []],
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

    private function sumApprovedPurchaseQuantity($db): string
    {
        $row = $db->table('coin_purchase_requests')
            ->selectSum('coin_quantity', 'qty')
            ->where('status', 'approved')
            ->get()
            ->getRowArray();

        return MoneyMath::normalize((string) ($row['qty'] ?? '0'));
    }

    /**
     * @return array{available: string, locked: string, balance: string}
     */
    private function memberBalanceTotals($db): array
    {
        $row = $db->table('member_coin_balances')
            ->selectSum('available_balance', 'available')
            ->selectSum('locked_balance', 'locked')
            ->selectSum('balance', 'balance')
            ->get()
            ->getRowArray();

        return [
            'available' => MoneyMath::normalize((string) ($row['available'] ?? '0')),
            'locked'    => MoneyMath::normalize((string) ($row['locked'] ?? '0')),
            'balance'   => MoneyMath::normalize((string) ($row['balance'] ?? '0')),
        ];
    }

    /**
     * @return array{labels: list<string>, values: list<int>}
     */
    private function memberRegistrationTrend(int $days): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new \DateTimeZone((string) config('App')->appTimezone);
        $end   = new \DateTimeImmutable('today', $tz);
        $start = $end->modify('-' . ($days - 1) . ' days');
        $rootId = trim((string) config('Platform')->rootSponsorId);

        $db   = db_connect();
        $rows = $db->query(
            'SELECT DATE(created_at) AS day_key, COUNT(*) AS cnt
             FROM member_profiles
             WHERE created_at >= ?
               AND created_at < ?
               AND is_root_sponsor = 0
               AND (? = \'\' OR member_id != ?)
             GROUP BY DATE(created_at)
             ORDER BY day_key ASC',
            [
                $start->format('Y-m-d 00:00:00'),
                $end->modify('+1 day')->format('Y-m-d 00:00:00'),
                $rootId,
                $rootId,
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

    /**
     * @return array{labels: list<string>, values: list<string>}
     */
    private function dailyPurchaseQuantityTrend(int $days): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new \DateTimeZone((string) config('App')->appTimezone);
        $end   = new \DateTimeImmutable('today', $tz);
        $start = $end->modify('-' . ($days - 1) . ' days');

        $db   = db_connect();
        $rows = $db->query(
            'SELECT DATE(requested_at) AS day_key, COALESCE(SUM(coin_quantity), 0) AS qty
             FROM coin_purchase_requests
             WHERE status = ?
               AND requested_at >= ?
               AND requested_at < ?
             GROUP BY DATE(requested_at)
             ORDER BY day_key ASC',
            [
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
    private function inventoryDistribution(): array
    {
        $inv = service('coinInventoryService')->summary();

        return [
            'labels' => ['Available', 'Allocated (Used)'],
            'values' => [
                $inv['available_balance'],
                $inv['total_allocated'],
            ],
        ];
    }

    /**
     * @return array{labels: list<string>, values: list<int>}
     */
    private function memberStatusDistribution(): array
    {
        $profiles = model(MemberProfileModel::class);

        return [
            'labels' => ['Active', 'Inactive'],
            'values' => [
                $profiles->countByStatus('active'),
                $profiles->countByStatus('inactive'),
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errorKpiSet(): array
    {
        $defs = [
            ['total_members', 'Total Members', 'blue', 'bi-people-fill'],
            ['active_members', 'Active Members', 'green', 'bi-person-check-fill'],
            ['inactive_members', 'Inactive Members', 'orange', 'bi-person-x-fill'],
            ['total_coins', 'Total Coins', 'purple', 'bi-database-fill'],
            ['used_coins', 'Used Coins', 'pink', 'bi-box-arrow-up'],
            ['available_inventory', 'Available Inventory Coins', 'gold', 'bi-box-seam'],
            ['today_mining', "Today's Mining", 'cyan', 'bi-lightning-charge-fill'],
            ['total_mining', 'Total Mining', 'amber', 'bi-lightning-fill'],
            ['today_level_commission', "Today's Level Commission", 'indigo', 'bi-diagram-3-fill'],
            ['total_level_commission', 'Total Level Commission', 'indigo', 'bi-cash-stack'],
            ['purchased_coins', 'Purchased Coins', 'teal', 'bi-cart-check-fill'],
            ['total_locked_coins', 'Total Locked Coins', 'amber', 'bi-lock-fill'],
            ['total_available_member_coins', 'Total Available Member Coins', 'green', 'bi-wallet2'],
            ['pending_purchases', 'Pending Coin Purchases', 'red', 'bi-hourglass-split'],
            ['approved_purchases', 'Approved Coin Purchases', 'green', 'bi-check2-circle'],
            ['pending_payment_verification', 'Pending Payment Verification', 'red', 'bi-shield-exclamation'],
        ];

        $out = [];
        foreach ($defs as [$key, $label, $accent, $icon]) {
            $out[] = KpiCardFactory::error($key, $label, $accent, $icon);
        }

        return $out;
    }
}
