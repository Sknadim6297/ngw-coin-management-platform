<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Dashboard\KpiCardFactory;
use App\Libraries\Mining\MiningWalletPresenter;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use Throwable;

/**
 * Read-only member analytics scoped to one authenticated member.
 */
class MemberDashboardService
{
    private const MINING_REASON     = 'Mining ledger is temporarily unavailable.';
    private const COMMISSION_REASON = 'Actual Commission: Not Activated';

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
     *   recent_wallet: list<array<string, mixed>>,
     *   timezone: string,
     *   today_start: string,
     *   today_end: string,
     *   generated_at: string
     * }
     */
    public function build(int $memberUserId, string $panelBase = 'member'): array
    {
        $profile = model(MemberProfileModel::class)->findByUserId($memberUserId);
        if ($profile === null) {
            throw new \RuntimeException('Member profile not found.');
        }

        [$todayStart, $todayEnd] = $this->todayBounds();
        $miningSummary           = service('memberMiningIncomeService')->summary($memberUserId);
        $relationship            = model(SponsorRelationshipModel::class)->findByMemberUserId($memberUserId);
        $locking                 = service('coinLockingService')->memberDashboardSnapshot($memberUserId);
        $walletOverview          = service('memberWalletService')->overview($memberUserId);
        $activation              = service('memberActivationService')->statusForMember($memberUserId);
        $pendingPayments         = $this->pendingPaymentsForMember($memberUserId);
        $recentPurchases         = $this->recentPurchasesForMember($memberUserId, 5);
        $team                    = service('referralTeamService')->countsForSponsor($memberUserId);

        return [
            'kpis'              => $this->kpis($memberUserId, $locking, $panelBase, $miningSummary),
            'charts'            => $this->charts($memberUserId, $locking['summary']),
            'mining_summary'    => $miningSummary,
            'profile'           => $profile,
            'relationship'      => $relationship,
            'activation'        => $activation,
            'wallet_summary'    => $locking['summary'],
            'pending_payments'  => $pendingPayments,
            'recent_purchases'  => $recentPurchases,
            'referral_summary'  => $team,
            'referral_link'     => site_url('register?sponsor=' . urlencode($profile->member_id)),
            'completion'        => $profile->profileCompletionPercent(),
            'price_summary'     => service('coinPriceService')->settingsSummary(),
            'inventory'         => service('coinInventoryService')->summary(),
            'active_locks'      => $locking['active'],
            'matured_locks'     => $locking['matured'],
            'recent_locks'      => $locking['recent'],
            'recent_wallet'     => array_slice($walletOverview['recent'], 0, 5),
            'timezone'          => (string) config('App')->appTimezone,
            'today_start'       => $todayStart,
            'today_end'         => $todayEnd,
            'generated_at'      => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed>|null $lockingSnapshot
     *
     * @return list<array<string, mixed>>
     */
    public function kpis(int $memberUserId, ?array $lockingSnapshot = null, string $panelBase = 'member', ?array $miningSummary = null): array
    {
        $miningCards = $this->actualMiningKpis(
            $miningSummary ?? service('memberMiningIncomeService')->summary($memberUserId),
            $panelBase
        );

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
            $miningBizDate = service('miningEligibilityService')->businessDate();
            $miningPreview = service('miningSimulationService')->memberPreview($memberUserId, $miningBizDate);
            $commissionSnap = service('commissionSimulationService')->memberSnapshot($memberUserId);
            $profileRow = model(\App\Models\MemberProfileModel::class)->findByUserId($memberUserId);
            if ($profileRow !== null) {
                service('commissionWalletService')->ensureAccount($memberUserId, (string) $profileRow->member_id);
            }
            $commWallet = service('commissionWalletService')->getAccount($memberUserId);
            $wdStatus   = service('commissionWithdrawalService')->activationStatus();

            $pendingPayments = (int) $db->table('usdt_deposit_requests')
                ->where('member_user_id', $memberUserId)
                ->whereIn('status', ['submitted', 'under_review'])
                ->countAllResults();

            return [
                KpiCardFactory::ok('my_total_coins', 'Total Owned Coins', $summary['balance'], 'purple', 'bi-coin', 'coins', site_url('member/wallet')),
                KpiCardFactory::ok('my_available_coins', 'Available Coins', $summary['available'], 'green', 'bi-wallet2', 'coins', site_url('member/wallet')),
                KpiCardFactory::ok('my_locked_coins', 'Locked Coins', $summary['locked'], 'amber', 'bi-lock-fill', 'coins', site_url('member/wallet?tab=locking')),
                KpiCardFactory::ok('my_purchased_coins', 'Purchased Coins', $summary['total_acquired'], 'teal', 'bi-cart-check-fill', 'coins', site_url('member/buy-coins')),
                KpiCardFactory::ok('my_pending_payments', 'Pending Payments', $pendingPayments, 'red', 'bi-shield-exclamation', 'payments', site_url('member/buy-coins')),
                KpiCardFactory::ok('my_pending_purchases', 'Pending Purchases', $pendingPurchases, 'red', 'bi-hourglass-split', 'requests', site_url('member/buy-coins')),
                KpiCardFactory::ok('my_approved_purchases', 'Approved Purchases', $approvedPurchases, 'green', 'bi-check2-circle', 'requests', site_url('member/buy-coins')),
                ...$miningCards,
                KpiCardFactory::ok('my_today_mining_preview', "Today's Mining Simulation", $miningPreview['preview_amount'], 'cyan', 'bi-lightning-charge', 'sim', site_url('member/mining')),
                KpiCardFactory::ok('my_total_mining_preview', 'Total Mining Simulation', service('miningSimulationService')->memberPreviewTotal($memberUserId), 'teal', 'bi-graph-up', 'sim', site_url('member/mining')),
                KpiCardFactory::unavailable('my_today_level_commission', "Today's Commission (Actual)", 'indigo', 'bi-diagram-3-fill', self::COMMISSION_REASON, site_url('member/commissions')),
                KpiCardFactory::unavailable('my_total_level_commission', 'Total Commission (Actual)', 'indigo', 'bi-cash-stack', self::COMMISSION_REASON, site_url('member/commissions')),
                KpiCardFactory::ok('my_today_commission_preview', "Today's Commission Simulation", $commissionSnap['today_preview'], 'cyan', 'bi-calendar-day', 'sim', site_url('member/commissions')),
                KpiCardFactory::ok('my_total_commission_preview', 'Total Commission Simulation', $commissionSnap['total_preview'], 'teal', 'bi-graph-up-arrow', 'sim', site_url('member/commissions')),
                KpiCardFactory::ok('my_commission_available', 'Commission Wallet (USDT)', $commWallet['available_balance'], 'green', 'bi-cash', 'usdt', site_url('member/commission-wallet')),
                KpiCardFactory::ok('my_withdrawal_status', 'Withdrawal Status', $wdStatus, 'indigo', 'bi-slash-circle', null, site_url('member/withdrawals')),
                KpiCardFactory::ok('my_direct_referrals', 'Direct Referrals', $team['direct'], 'purple', 'bi-person-plus-fill', 'members', site_url('member/referrals')),
                KpiCardFactory::ok('my_total_team', 'Total Team', $team['total'], 'blue', 'bi-people-fill', 'members', site_url('member/genealogy')),
                KpiCardFactory::ok('my_active_locks', 'Active Locks', $activeLockCount, 'amber', 'bi-lock', 'locks', site_url('member/wallet?tab=locking')),
                KpiCardFactory::ok('my_matured_locks', 'Matured Locks', $maturedLockCount, 'teal', 'bi-unlock-fill', 'locks', site_url('member/wallet?tab=locking')),
            ];
        } catch (Throwable $e) {
            log_message('error', 'MemberDashboardService::kpis failed: {msg}', ['msg' => $e->getMessage()]);

            return $this->errorKpiSet();
        }
    }

    /**
     * Actual posted mining (ledger-backed). A business date without a posted credit shows 0.00000000 with
     * "No credit posted"; an unreadable ledger shows an error card — never a fabricated zero.
     *
     * @param array<string, mixed> $s MemberMiningIncomeService::summary()
     * @return list<array<string, mixed>>
     */
    private function actualMiningKpis(array $s, string $panelBase): array
    {
        $wallet  = site_url($panelBase . '/mining');
        $history = site_url($panelBase . '/mining/history');
        $defs    = [
            ['my_today_mining', "Today's Mining (Actual)", 'cyan', 'bi-lightning-charge-fill'],
            ['my_yesterday_mining', "Yesterday's Mining (Actual)", 'indigo', 'bi-calendar-check'],
            ['my_total_mining', 'Total Mining Earned (Actual)', 'amber', 'bi-lightning-fill'],
            ['my_mining_balance', 'Mining Wallet Balance', 'green', 'bi-wallet2'],
        ];

        if (empty($s['available'])) {
            $reason = 'Mining earnings unavailable: ' . (string) ($s['error'] ?? 'ledger could not be read');
            $out    = [];
            foreach ($defs as [$key, $label, $accent, $icon]) {
                $out[] = KpiCardFactory::error($key, $label, $accent, $icon, $reason) + ['note' => 'Unavailable — not zero'];
            }

            return $out;
        }

        $unit = (string) $s['asset'];

        return [
            KpiCardFactory::exact('my_today_mining', $defs[0][1], (string) $s['today'], 'cyan', $defs[0][3], $unit, $history, MiningWalletPresenter::dayNote((string) $s['today_date'], (bool) $s['today_posted'])),
            KpiCardFactory::exact('my_yesterday_mining', $defs[1][1], (string) $s['yesterday'], 'indigo', $defs[1][3], $unit, $history, MiningWalletPresenter::dayNote((string) $s['yesterday_date'], (bool) $s['yesterday_posted'])),
            KpiCardFactory::exact('my_total_mining', $defs[2][1], (string) $s['total_earned'], 'amber', $defs[2][3], $unit, $history, (int) $s['credit_count'] . ' posted credits'),
            KpiCardFactory::exact('my_mining_balance', $defs[3][1], (string) $s['balance'], 'green', $defs[3][3], $unit, $wallet, 'Available balance'),
        ];
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
                'mining_history' => service('memberMiningIncomeService')->trend($memberUserId, 14),
                'commission_history' => service('commissionSimulationService')->previewTrend(14, $memberUserId),
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
    private function pendingPaymentsForMember(int $memberUserId): array
    {
        return db_connect()->table('usdt_deposit_requests')
            ->where('member_user_id', $memberUserId)
            ->whereIn('status', ['submitted', 'under_review'])
            ->orderBy('created_at', 'DESC')
            ->limit(5)
            ->get()
            ->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentPurchasesForMember(int $memberUserId, int $limit = 5): array
    {
        return db_connect()->table('coin_purchase_requests')
            ->where('member_user_id', $memberUserId)
            ->orderBy('requested_at', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function errorKpiSet(): array
    {
        $defs = [
            ['my_total_coins', 'Total Owned Coins', 'purple', 'bi-coin'],
            ['my_available_coins', 'Available Coins', 'green', 'bi-wallet2'],
            ['my_locked_coins', 'Locked Coins', 'amber', 'bi-lock-fill'],
            ['my_purchased_coins', 'Purchased Coins', 'teal', 'bi-cart-check-fill'],
            ['my_pending_payments', 'Pending Payments', 'red', 'bi-shield-exclamation'],
            ['my_pending_purchases', 'Pending Purchases', 'red', 'bi-hourglass-split'],
            ['my_approved_purchases', 'Approved Purchases', 'green', 'bi-check2-circle'],
            ['my_today_mining', "Today's Mining (Actual)", 'cyan', 'bi-lightning-charge-fill'],
            ['my_yesterday_mining', "Yesterday's Mining (Actual)", 'indigo', 'bi-calendar-check'],
            ['my_total_mining', 'Total Mining Earned (Actual)', 'amber', 'bi-lightning-fill'],
            ['my_mining_balance', 'Mining Wallet Balance', 'green', 'bi-wallet2'],
            ['my_today_mining_preview', "Today's Mining Simulation", 'cyan', 'bi-lightning-charge'],
            ['my_total_mining_preview', 'Total Mining Simulation', 'teal', 'bi-graph-up'],
            ['my_today_level_commission', "Today's Commission (Actual)", 'indigo', 'bi-diagram-3-fill'],
            ['my_total_level_commission', 'Total Commission (Actual)', 'indigo', 'bi-cash-stack'],
            ['my_today_commission_preview', "Today's Commission Simulation", 'cyan', 'bi-calendar-day'],
            ['my_total_commission_preview', 'Total Commission Simulation', 'teal', 'bi-graph-up-arrow'],
            ['my_commission_available', 'Commission Wallet (USDT)', 'green', 'bi-cash'],
            ['my_withdrawal_status', 'Withdrawal Status', 'indigo', 'bi-slash-circle'],
            ['my_direct_referrals', 'Direct Referrals', 'purple', 'bi-person-plus-fill'],
            ['my_total_team', 'Total Team', 'blue', 'bi-people-fill'],
            ['my_active_locks', 'Active Locks', 'amber', 'bi-lock'],
            ['my_matured_locks', 'Matured Locks', 'teal', 'bi-unlock-fill'],
        ];

        $out = [];
        foreach ($defs as [$key, $label, $accent, $icon]) {
            $out[] = KpiCardFactory::error($key, $label, $accent, $icon);
        }

        return $out;
    }
}
