<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Read-only view of ACTUAL posted mining income for ONE member (mining_wallets + mining_wallet_ledger).
 *
 * Rules (do not weaken):
 *  - Strictly read-only. Never writes, never calls posting/recovery, never touches financial switches.
 *  - Only posted ledger rows count as income. Simulation/preview tables are never read here.
 *  - Every query is scoped by the member_user_id passed in by the caller (the authenticated member, or the
 *    impersonation target in Admin Support View) — callers must never pass a user-supplied id.
 *  - If the ledger cannot be read the result says "unavailable"; it never silently reports zero.
 *  - Business dates are Asia/Kolkata (Platform::miningBusinessTimezone). All amounts are 8-decimal strings.
 */
class MemberMiningIncomeService
{
    public const CREDIT_TYPE = 'daily_mining_credit';

    public const PER_PAGE = 20;

    public function __construct(private readonly ?Platform $config = null)
    {
    }

    /**
     * @return array{
     *   available: bool, error: ?string, asset: string, timezone: string,
     *   total_earned: string, balance: string, total_credited: string, total_debited: string,
     *   today_date: string, today: string, today_posted: bool,
     *   yesterday_date: string, yesterday: string, yesterday_posted: bool,
     *   credit_count: int, last_credit_date: ?string, last_credit_at: ?string, wallet_reconciled: bool
     * }
     */
    public function summary(int $memberUserId, ?DateTimeImmutable $now = null): array
    {
        $tz        = $this->timezone();
        $today     = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($tz));
        $todayDate = $today->format('Y-m-d');
        $yestDate  = $today->modify('-1 day')->format('Y-m-d');
        $zero      = '0.00000000';

        $out = [
            'available'         => false,
            'error'             => null,
            'asset'             => $this->asset(),
            'timezone'          => $tz,
            'total_earned'      => $zero,
            'balance'           => $zero,
            'total_credited'    => $zero,
            'total_debited'     => $zero,
            'today_date'        => $todayDate,
            'today'             => $zero,
            'today_posted'      => false,
            'yesterday_date'    => $yestDate,
            'yesterday'         => $zero,
            'yesterday_posted'  => false,
            'credit_count'      => 0,
            'last_credit_date'  => null,
            'last_credit_at'    => null,
            'wallet_reconciled' => true,
        ];

        if ($memberUserId <= 0) {
            $out['error'] = 'Member not resolved.';

            return $out;
        }

        try {
            $db = db_connect();
            if (! $db->tableExists('mining_wallets') || ! $db->tableExists('mining_wallet_ledger')) {
                $out['error'] = 'Mining wallet ledger is not installed.';

                return $out;
            }

            $wallet = $db->table('mining_wallets')
                ->where('member_user_id', $memberUserId)
                ->where('asset_code', $out['asset'])
                ->get()->getRowArray();

            $agg = $db->table('mining_wallet_ledger')
                ->select('COUNT(*) AS c, COALESCE(SUM(amount), 0) AS total, MAX(business_date) AS last_date, MAX(created_at) AS last_at', false)
                ->where('member_user_id', $memberUserId)
                ->where('asset_code', $out['asset'])
                ->where('entry_type', self::CREDIT_TYPE)
                ->where('direction', 'credit')
                ->get()->getRowArray() ?? [];

            $days = $db->table('mining_wallet_ledger')
                ->select('business_date, COALESCE(SUM(amount), 0) AS qty', false)
                ->where('member_user_id', $memberUserId)
                ->where('asset_code', $out['asset'])
                ->where('entry_type', self::CREDIT_TYPE)
                ->where('direction', 'credit')
                ->whereIn('business_date', [$todayDate, $yestDate])
                ->groupBy('business_date')
                ->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'MemberMiningIncomeService::summary failed: {m}', ['m' => $e->getMessage()]);
            $out['error'] = 'Mining ledger is temporarily unavailable.';

            return $out;
        }

        $byDay = [];
        foreach ($days as $d) {
            $byDay[(string) $d['business_date']] = MoneyMath::normalize((string) $d['qty'], 8);
        }

        $out['available']        = true;
        $out['credit_count']     = (int) ($agg['c'] ?? 0);
        $out['total_earned']     = MoneyMath::normalize((string) ($agg['total'] ?? '0'), 8);
        $out['last_credit_date'] = ($agg['last_date'] ?? null) !== null ? (string) $agg['last_date'] : null;
        $out['last_credit_at']   = ($agg['last_at'] ?? null) !== null ? $this->toBusinessTime((string) $agg['last_at']) : null;
        $out['today_posted']     = isset($byDay[$todayDate]);
        $out['today']            = $byDay[$todayDate] ?? $zero;
        $out['yesterday_posted'] = isset($byDay[$yestDate]);
        $out['yesterday']        = $byDay[$yestDate] ?? $zero;

        if ($wallet !== null) {
            $out['balance']        = MoneyMath::normalize((string) ($wallet['available_balance'] ?? '0'), 8);
            $out['total_credited'] = MoneyMath::normalize((string) ($wallet['total_credited'] ?? '0'), 8);
            $out['total_debited']  = MoneyMath::normalize((string) ($wallet['total_debited'] ?? '0'), 8);
        }
        // Display-only consistency hint; this service never repairs anything.
        $out['wallet_reconciled'] = $wallet === null
            ? $out['credit_count'] === 0
            : MoneyMath::compare($out['total_credited'], $out['total_earned'], 8) >= 0;

        return $out;
    }

    /**
     * Paginated posted ledger rows, newest business date first.
     *
     * @param 'earnings'|'all' $scope earnings = daily mining credits only; all = every mining wallet entry
     * @return array{available: bool, error: ?string, rows: list<array<string, mixed>>, total: int, page: int, per_page: int, pages: int, scope: string, timezone: string}
     */
    public function history(int $memberUserId, int $page = 1, string $scope = 'earnings', int $perPage = self::PER_PAGE): array
    {
        $scope   = $scope === 'all' ? 'all' : 'earnings';
        $perPage = max(5, min(100, $perPage));
        $page    = max(1, $page);
        $out     = [
            'available' => false, 'error' => null, 'rows' => [], 'total' => 0,
            'page' => $page, 'per_page' => $perPage, 'pages' => 0, 'scope' => $scope, 'timezone' => $this->timezone(),
        ];

        if ($memberUserId <= 0) {
            $out['error'] = 'Member not resolved.';

            return $out;
        }

        try {
            $db = db_connect();
            if (! $db->tableExists('mining_wallet_ledger')) {
                $out['error'] = 'Mining wallet ledger is not installed.';

                return $out;
            }

            $base = static function () use ($db, $memberUserId, $scope) {
                $b = $db->table('mining_wallet_ledger')->where('member_user_id', $memberUserId);
                if ($scope === 'earnings') {
                    $b->where('entry_type', self::CREDIT_TYPE)->where('direction', 'credit');
                }

                return $b;
            };

            $total = (int) $base()->countAllResults();
            $pages = (int) max(1, (int) ceil($total / $perPage));
            $page  = min($page, $pages);
            $rows  = $base()
                ->select('entry_ref, entry_type, direction, amount, balance_after, business_date, asset_code, created_at')
                ->orderBy('business_date', 'DESC')
                ->orderBy('id', 'DESC')
                ->limit($perPage, ($page - 1) * $perPage)
                ->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'MemberMiningIncomeService::history failed: {m}', ['m' => $e->getMessage()]);
            $out['error'] = 'Mining history is temporarily unavailable.';

            return $out;
        }

        $out['available'] = true;
        $out['total']     = $total;
        $out['pages']     = $total === 0 ? 0 : $pages;
        $out['page']      = $page;
        $out['rows']      = array_map(fn (array $r): array => [
            'entry_ref'     => (string) $r['entry_ref'],
            'business_date' => (string) $r['business_date'],
            'type'          => $this->typeLabel((string) $r['entry_type']),
            'direction'     => (string) $r['direction'],
            'amount'        => MoneyMath::normalize((string) $r['amount'], 8),
            'balance_after' => MoneyMath::normalize((string) $r['balance_after'], 8),
            'asset'         => (string) ($r['asset_code'] ?? $this->asset()),
            'status'        => 'Posted',
            'credited_at'   => $this->toBusinessTime((string) $r['created_at']),
        ], $rows);

        return $out;
    }

    /**
     * Posted daily mining per business date for the last $days days (dashboard chart). Missing days are 0 —
     * the chart is explicitly labelled "posted credits", so a gap is shown as a gap, never as a simulated value.
     *
     * @return array{available: bool, reason: ?string, labels: list<string>, values: list<string>}
     */
    public function trend(int $memberUserId, int $days = 14, ?DateTimeImmutable $now = null): array
    {
        $days  = max(1, min(90, $days));
        $tz    = new DateTimeZone($this->timezone());
        $end   = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);
        $start = $end->modify('-' . ($days - 1) . ' days');

        try {
            $rows = db_connect()->table('mining_wallet_ledger')
                ->select('business_date, COALESCE(SUM(amount), 0) AS qty', false)
                ->where('member_user_id', $memberUserId)
                ->where('entry_type', self::CREDIT_TYPE)
                ->where('direction', 'credit')
                ->where('business_date >=', $start->format('Y-m-d'))
                ->where('business_date <=', $end->format('Y-m-d'))
                ->groupBy('business_date')
                ->get()->getResultArray();
        } catch (Throwable $e) {
            log_message('error', 'MemberMiningIncomeService::trend failed: {m}', ['m' => $e->getMessage()]);

            return ['available' => false, 'reason' => 'Mining ledger is temporarily unavailable.', 'labels' => [], 'values' => []];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r['business_date']] = MoneyMath::normalize((string) $r['qty'], 8);
        }
        $labels = [];
        $values = [];
        for ($i = 0; $i < $days; $i++) {
            $d        = $start->modify("+{$i} days")->format('Y-m-d');
            $labels[] = $d;
            $values[] = $map[$d] ?? '0.00000000';
        }

        return ['available' => true, 'reason' => null, 'labels' => $labels, 'values' => $values];
    }

    public function timezone(): string
    {
        return (string) (($this->config ?? config('Platform'))->miningBusinessTimezone ?: 'Asia/Kolkata');
    }

    private function asset(): string
    {
        return (string) (($this->config ?? config('Platform'))->miningEarningAsset ?: 'COIN');
    }

    /** Ledger timestamps are stored in the application timezone; show them in the business timezone. */
    private function toBusinessTime(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        try {
            $appTz = new DateTimeZone((string) (config('App')->appTimezone ?: 'UTC'));

            return (new DateTimeImmutable($stored, $appTz))->setTimezone(new DateTimeZone($this->timezone()))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $stored;
        }
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            self::CREDIT_TYPE => 'Daily mining credit',
            'p2p_debit'  => 'P2P transfer out',
            'p2p_credit' => 'P2P transfer in',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }
}
