<?php

declare(strict_types=1);

namespace App\Libraries\Mining;

use App\Libraries\Dashboard\KpiCardFactory;
use App\Services\FinancialAutomationService;
use Throwable;

/**
 * Builds read-only Mining Wallet / Mining History page data for one member.
 * Shared by the member panel (authenticated member) and Admin Support View (impersonation target).
 * The $memberUserId must always come from the server-side context, never from the request.
 */
final class MiningWalletPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function walletPage(int $memberUserId, string $panelBase): array
    {
        $income  = service('memberMiningIncomeService');
        $summary = $income->summary($memberUserId);
        $recent  = $income->history($memberUserId, 1, 'all', 10);

        return [
            'summary'       => $summary,
            'kpis'          => self::walletKpis($summary, $panelBase),
            'recent'        => $recent,
            'postingActive' => self::postingActive(),
            'preview'       => self::preview($memberUserId),
            'panelBase'     => $panelBase,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function historyPage(int $memberUserId, string $panelBase, int $page, string $scope): array
    {
        $income  = service('memberMiningIncomeService');
        $history = $income->history($memberUserId, $page, $scope);
        $pager   = null;
        if ($history['available'] && $history['total'] > $history['per_page']) {
            $pager = service('pager')->makeLinks($history['page'], $history['per_page'], $history['total'], 'default_full');
        }

        return [
            'history'   => $history,
            'pagerHtml' => $pager,
            'summary'   => $income->summary($memberUserId),
            'panelBase' => $panelBase,
        ];
    }

    /**
     * @param array<string, mixed> $s MemberMiningIncomeService::summary()
     * @return list<array<string, mixed>>
     */
    public static function walletKpis(array $s, string $panelBase): array
    {
        $unit    = (string) $s['asset'];
        $history = site_url($panelBase . '/mining/history');

        if (empty($s['available'])) {
            $reason = (string) ($s['error'] ?? 'Mining ledger unavailable.');
            $err    = static fn (string $k, string $l, string $icon): array => KpiCardFactory::error($k, $l, 'gold', $icon, $reason)
                + ['note' => 'Unavailable — ' . $reason];

            return [
                $err('mining_total_earned', 'Total Mining Earned', 'bi-graph-up-arrow'),
                $err('mining_balance', 'Mining Wallet Balance', 'bi-wallet2'),
                $err('mining_today', "Today's Actual Mining", 'bi-lightning-charge'),
                $err('mining_yesterday', "Yesterday's Actual Mining", 'bi-calendar-check'),
                $err('mining_credit_count', 'Total Mining Credits', 'bi-list-check'),
                $err('mining_last_credit', 'Last Mining Credit', 'bi-clock-history'),
            ];
        }

        return [
            KpiCardFactory::exact('mining_total_earned', 'Total Mining Earned', (string) $s['total_earned'], 'green', 'bi-graph-up-arrow', $unit, $history, 'Lifetime posted daily mining credits'),
            KpiCardFactory::exact('mining_balance', 'Mining Wallet Balance', (string) $s['balance'], 'amber', 'bi-wallet2', $unit, null, 'Available now · debited ' . $s['total_debited']),
            KpiCardFactory::exact('mining_today', "Today's Actual Mining", (string) $s['today'], 'indigo', 'bi-lightning-charge', $unit, $history, self::dayNote((string) $s['today_date'], (bool) $s['today_posted'])),
            KpiCardFactory::exact('mining_yesterday', "Yesterday's Actual Mining", (string) $s['yesterday'], 'cyan', 'bi-calendar-check', $unit, $history, self::dayNote((string) $s['yesterday_date'], (bool) $s['yesterday_posted'])),
            KpiCardFactory::exact('mining_credit_count', 'Total Mining Credits', (int) $s['credit_count'], 'purple', 'bi-list-check', 'credits', $history, 'Posted daily credit entries'),
            KpiCardFactory::exact('mining_last_credit', 'Last Mining Credit', $s['last_credit_date'] ?? 'None yet', 'teal', 'bi-clock-history', null, $history, $s['last_credit_at'] !== null ? 'Credited ' . $s['last_credit_at'] . ' IST' : 'No posted mining credit yet'),
        ];
    }

    public static function dayNote(string $businessDate, bool $posted): string
    {
        return 'Business date ' . $businessDate . ' (IST) · ' . ($posted ? 'Posted' : 'No credit posted');
    }

    public static function postingActive(): ?bool
    {
        try {
            return service('financialAutomationService')->isActive(FinancialAutomationService::CONTROL_MINING)
                || (bool) config('Platform')->miningPostingEnabled;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Expected-amount preview for today. Clearly labelled as an estimate in the view; never income.
     *
     * @return array<string, mixed>|null
     */
    private static function preview(int $memberUserId): ?array
    {
        try {
            $today = service('miningEligibilityService')->businessDate();
            $rate  = service('miningRateService')->approvedForDate($today);
            $eval  = service('miningEligibilityService')->evaluate($memberUserId, $today, $rate);
            $expected = '0.00000000';
            if ($rate !== null && ! empty($eval['eligible'])) {
                $expected = service('miningRateService')->previewAmount((string) $eval['eligible_coins'], $rate, $today);
            }

            return ['today' => $today, 'rate' => $rate, 'eval' => $eval, 'expected' => $expected];
        } catch (Throwable $e) {
            log_message('error', 'Mining preview failed: {m}', ['m' => $e->getMessage()]);

            return null;
        }
    }
}
