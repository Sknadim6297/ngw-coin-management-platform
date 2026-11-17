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
    private const MINING_REASON     = 'Actual Mining: Not Activated — simulation preview only';
    private const COMMISSION_REASON = 'Actual Commission: Not Activated';

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
            'recent_transactions'  => $this->recentApprovedPurchases(8),
            'timezone'             => (string) config('App')->appTimezone,
            'today_start'          => $todayStart,
            'today_end'            => $todayEnd,
            'generated_at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentApprovedPurchases(int $limit = 8): array
    {
        return db_connect()->table('coin_purchase_requests')
            ->where('status', 'approved')
            ->orderBy('reviewed_at', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
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

            $purchasedQty  = $this->sumApprovedPurchaseQuantity($db);
            $memberSums    = $this->memberBalanceTotals($db);
            $referralStats = service('referralTeamService')->platformStats();
            $todayDate     = $todayStart !== null ? substr($todayStart, 0, 10) : date('Y-m-d');
            $miningSnap    = service('miningSimulationService')->adminSnapshot($todayDate);
            $commissionSnap = service('commissionSimulationService')->adminSnapshot($todayDate);
            $commWalletSnap = service('commissionWalletService')->platformSnapshot();
            $commWdService  = service('commissionWithdrawalService');
            $commRecon      = service('commissionWalletReconciliationService')->run();

            $pendingPurchases  = $purchases->countByStatus('pending');
            $approvedPurchases = $purchases->countByStatus('approved');
            $pendingPayments   = (int) $db->table('usdt_deposit_requests')
                ->whereIn('status', ['submitted', 'under_review'])
                ->countAllResults();
            $verifiedPayments  = (int) $db->table('usdt_deposit_requests')
                ->where('status', 'verified')
                ->countAllResults();

            return [
                KpiCardFactory::ok('total_members', 'Total Members', $totalMembers, 'blue', 'bi-people-fill', 'members', site_url('admin/members')),
                KpiCardFactory::ok('active_members', 'Active Members', $activeMembers, 'green', 'bi-person-check-fill', 'members', site_url('admin/members/active')),
                KpiCardFactory::ok('inactive_members', 'Inactive Members', $inactiveMembers, 'orange', 'bi-person-x-fill', 'members', site_url('admin/members/inactive')),
                KpiCardFactory::ok('total_coins', 'Total Inventory', $inventory['total_added'], 'purple', 'bi-database-fill', 'coins', site_url('admin/coins/inventory')),
                KpiCardFactory::ok('used_coins', 'Allocated Coins', $inventory['total_allocated'], 'pink', 'bi-box-arrow-up', 'coins', site_url('admin/coins/inventory')),
                KpiCardFactory::ok('available_inventory', 'Available Inventory', $inventory['available_balance'], 'gold', 'bi-box-seam', 'coins', site_url('admin/coins/inventory')),
                KpiCardFactory::ok('pending_payment_verification', 'Pending Payments', $pendingPayments, 'red', 'bi-shield-exclamation', 'payments', site_url('admin/payment-approvals?tab=pending')),
                KpiCardFactory::ok('verified_payments', 'Verified Payments', $verifiedPayments, 'green', 'bi-patch-check', 'payments', site_url('admin/payment-approvals?tab=completed')),
                KpiCardFactory::ok('pending_purchases', 'Pending Purchases', $pendingPurchases, 'red', 'bi-hourglass-split', 'requests', site_url('admin/payment-approvals?tab=pending')),
                KpiCardFactory::ok('approved_purchases', 'Approved Purchases', $approvedPurchases, 'green', 'bi-check2-circle', 'requests', site_url('admin/payment-approvals?tab=completed')),
                KpiCardFactory::ok('purchased_coins', 'Total Purchased Coins', $purchasedQty, 'teal', 'bi-cart-check-fill', 'coins', site_url('admin/payment-approvals?tab=completed')),
                KpiCardFactory::ok('total_locked_coins', 'Total Locked Coins', $memberSums['locked'], 'amber', 'bi-lock-fill', 'coins', site_url('admin/locking')),
                KpiCardFactory::ok('total_available_member_coins', 'Total Available Member Coins', $memberSums['available'], 'green', 'bi-wallet2', 'coins', site_url('admin/wallets')),
                KpiCardFactory::ok('total_member_owned_coins', 'Total Member-Owned Coins', $memberSums['balance'], 'purple', 'bi-safe', 'coins', site_url('admin/wallets')),
                KpiCardFactory::unavailable('today_mining', "Today's Mining (Actual)", 'cyan', 'bi-lightning-charge-fill', 'Actual Mining: Not Activated', site_url('admin/mining')),
                KpiCardFactory::unavailable('total_mining', 'Total Mining (Actual)', 'amber', 'bi-lightning-fill', 'Actual Mining: Not Activated', site_url('admin/mining')),
                KpiCardFactory::ok('today_mining_preview', "Today's Mining Simulation", $miningSnap['today_preview'], 'cyan', 'bi-lightning-charge', 'sim', site_url('admin/mining')),
                KpiCardFactory::ok('total_mining_preview', 'Total Mining Simulation', $miningSnap['total_preview'], 'teal', 'bi-graph-up', 'sim', site_url('admin/mining')),
                KpiCardFactory::unavailable('today_level_commission', "Today's Commission (Actual)", 'indigo', 'bi-diagram-3-fill', self::COMMISSION_REASON, site_url('admin/commissions')),
                KpiCardFactory::unavailable('total_level_commission', 'Total Commission (Actual)', 'indigo', 'bi-cash-stack', self::COMMISSION_REASON, site_url('admin/commissions')),
                KpiCardFactory::ok('today_commission_preview', "Today's Commission Simulation", $commissionSnap['today_preview'], 'cyan', 'bi-calendar-day', 'sim', site_url('admin/commissions')),
                KpiCardFactory::ok('total_commission_preview', 'Total Commission Simulation', $commissionSnap['total_preview'], 'teal', 'bi-graph-up-arrow', 'sim', site_url('admin/commissions')),
                KpiCardFactory::ok('commission_posting_status', 'Commission Posting Status', $commWalletSnap['posting_status'], 'purple', 'bi-toggle-off', null, site_url('admin/commission-wallet')),
                KpiCardFactory::ok('commission_withdrawal_status', 'Withdrawal Activation Status', $commWalletSnap['withdrawal_status'], 'indigo', 'bi-slash-circle', null, site_url('admin/withdrawals')),
                KpiCardFactory::ok('wallet_reconciliation_issues', 'Wallet Reconciliation Issues', $this->walletIssueCount(), 'red', 'bi-clipboard2-pulse', 'issues', site_url('admin/wallets/reconcile')),
                KpiCardFactory::ok('total_referral_relationships', 'Direct Referral Relationships', $referralStats['total_relationships'], 'purple', 'bi-diagram-2', 'edges', site_url('admin/referrals')),
                KpiCardFactory::ok('referral_integrity_alerts', 'Referral Integrity Alerts', $this->genealogyIssueCount(), 'red', 'bi-shield-exclamation', 'issues', site_url('admin/genealogy')),
                KpiCardFactory::ok('commission_ledger_alerts', 'Commission Ledger Alerts', $commRecon['issue_count'], 'red', 'bi-shield-exclamation', 'issues', site_url('admin/commission-wallet/reconcile')),
                KpiCardFactory::ok('commission_withdrawal_pending', 'Pending Withdrawal Reviews', $commWdService->countByStatus('submitted') + $commWdService->countByStatus('under_review'), 'amber', 'bi-hourglass', 'requests', site_url('admin/withdrawals')),
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
                'commission_trend' => service('commissionSimulationService')->previewTrend(14),
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
     * Bounded wallet recon issue count for dashboard KPI (read-only).
     */
    private function walletIssueCount(): int
    {
        try {
            return (int) service('walletReconciliationService')->issueCounts(50)['total'];
        } catch (Throwable $e) {
            log_message('error', 'AdminDashboardService::walletIssueCount failed: {msg}', ['msg' => $e->getMessage()]);

            return 0;
        }
    }

    private function genealogyIssueCount(): int
    {
        try {
            return (int) service('genealogyIntegrityService')->issueCounts(50)['total'];
        } catch (Throwable $e) {
            log_message('error', 'AdminDashboardService::genealogyIssueCount failed: {msg}', ['msg' => $e->getMessage()]);

            return 0;
        }
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
            ['today_mining_preview', "Today's Mining Preview", 'cyan', 'bi-lightning-charge'],
            ['total_mining_preview', 'Total Mining Preview', 'teal', 'bi-graph-up'],
            ['mining_eligible_coins', 'Eligible Coins (Mining Sim)', 'purple', 'bi-coin'],
            ['mining_eligible_members', 'Eligible Members (Mining Sim)', 'green', 'bi-people'],
            ['today_level_commission', "Today's Level Commission", 'indigo', 'bi-diagram-3-fill'],
            ['total_level_commission', 'Total Level Commission', 'indigo', 'bi-cash-stack'],
            ['today_commission_preview', "Today's Commission Preview", 'cyan', 'bi-calendar-day'],
            ['total_commission_preview', 'Total Commission Preview', 'teal', 'bi-graph-up-arrow'],
            ['commission_l1_preview', 'Level 1 Commission Preview', 'indigo', 'bi-1-circle'],
            ['commission_l2_preview', 'Level 2 Commission Preview', 'blue', 'bi-2-circle'],
            ['commission_l3_preview', 'Level 3 Commission Preview', 'purple', 'bi-3-circle'],
            ['commission_eligible_purchases', 'Eligible Purchases (Commission Sim)', 'green', 'bi-cart-check'],
            ['purchased_coins', 'Purchased Coins', 'teal', 'bi-cart-check-fill'],
            ['total_locked_coins', 'Total Locked Coins', 'amber', 'bi-lock-fill'],
            ['total_available_member_coins', 'Total Available Member Coins', 'green', 'bi-wallet2'],
            ['total_member_owned_coins', 'Total Member-Owned Coins', 'purple', 'bi-safe'],
            ['wallet_reconciliation_issues', 'Wallet Reconciliation Issues', 'red', 'bi-clipboard2-pulse'],
            ['total_referral_relationships', 'Total Direct Referral Relationships', 'purple', 'bi-diagram-2'],
            ['members_with_sponsors', 'Members With Sponsors', 'cyan', 'bi-link-45deg'],
            ['members_without_sponsors', 'Members Without Sponsors', 'amber', 'bi-exclamation-circle'],
            ['referral_integrity_alerts', 'Referral Network Integrity Alerts', 'red', 'bi-shield-exclamation'],
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
