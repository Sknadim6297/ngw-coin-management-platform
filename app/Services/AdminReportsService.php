<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Dashboard\KpiCardFactory;
use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 9B: read-only admin analytics/reports. Reuses ownership, inventory,
 * referral, mining, and commission services — never mutates balances.
 */
class AdminReportsService
{
    public const TABS = [
        'overview',
        'members',
        'inventory',
        'purchases',
        'payments',
        'wallets',
        'referrals',
        'mining',
        'commissions',
    ];

    private const EXPORT_MAX_ROWS = 10000;
    private const PAGE_MAX       = 100;

    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return list<array{key: string, label: string, url: string}>
     */
    public function tabNav(string $active = 'overview'): array
    {
        $labels = [
            'overview'    => 'Overview',
            'members'     => 'Members',
            'inventory'   => 'Coin Inventory',
            'purchases'   => 'Purchases',
            'payments'    => 'Payments',
            'wallets'     => 'Wallet Transactions',
            'referrals'   => 'Referrals',
            'mining'      => 'Mining Simulations',
            'commissions' => 'Commission Simulations',
        ];

        $tabs = [];
        foreach ($labels as $key => $label) {
            $tabs[] = [
                'key'   => $key,
                'label' => $label,
                'url'   => site_url('admin/reports?tab=' . $key),
            ];
        }

        return $tabs;
    }

    public function normalizeTab(?string $tab): string
    {
        $tab = strtolower(trim((string) $tab));

        return in_array($tab, self::TABS, true) ? $tab : 'overview';
    }

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   date_from: ?string,
     *   date_to: ?string,
     *   status: ?string,
     *   sponsor: ?string,
     *   member_id: ?string,
     *   q: ?string,
     *   page: int,
     *   per_page: int
     * }
     */
    public function normalizeFilters(array $input): array
    {
        return [
            'date_from' => $this->normalizeDate($input['date_from'] ?? null, false),
            'date_to'   => $this->normalizeDate($input['date_to'] ?? null, true),
            'status'    => $this->nullableTrim($input['status'] ?? null),
            'sponsor'   => $this->nullableTrim($input['sponsor'] ?? null),
            'member_id' => $this->nullableTrim($input['member_id'] ?? null),
            'q'         => $this->nullableTrim($input['q'] ?? null),
            'page'      => max(1, (int) ($input['page'] ?? 1)),
            'per_page'  => max(5, min(self::PAGE_MAX, (int) ($input['per_page'] ?? 25))),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function build(string $tab, array $filters = [], bool $includeSensitivePayments = false): array
    {
        $tab     = $this->normalizeTab($tab);
        $filters = $this->normalizeFilters($filters);

        $payload = [
            'tab'     => $tab,
            'tabs'    => $this->tabNav($tab),
            'filters' => $filters,
        ];

        return match ($tab) {
            'members'     => $payload + $this->membersReport($filters),
            'inventory'   => $payload + $this->inventoryReport($filters),
            'purchases'   => $payload + $this->purchasesReport($filters),
            'payments'    => $payload + $this->paymentsReport($filters, $includeSensitivePayments),
            'wallets'     => $payload + $this->walletsReport($filters),
            'referrals'   => $payload + $this->referralsReport($filters),
            'mining'      => $payload + $this->miningReport($filters),
            'commissions' => $payload + $this->commissionsReport($filters),
            default       => $payload + $this->overviewReport($filters),
        };
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{kpis: list<array<string, mixed>>, notes: list<string>}
     */
    public function overviewReport(array $filters = []): array
    {
        $filters   = $this->normalizeFilters($filters);
        $db        = db_connect();
        $profiles  = model(MemberProfileModel::class);
        $inventory = service('coinInventoryService')->summary();

        $approvedPurchases = (int) $this->applyDateRange(
            $db->table('coin_purchase_requests')->where('status', 'approved'),
            'reviewed_at',
            $filters
        )->countAllResults();
        $pendingPurchases = (int) $this->applyDateRange(
            $db->table('coin_purchase_requests')->where('status', 'pending'),
            'requested_at',
            $filters
        )->countAllResults();

        $verifiedAmt = $this->applyDateRange(
            $db->table('usdt_deposit_requests')->where('status', 'verified'),
            'verified_at',
            $filters
        )->selectSum('verified_amount_usdt', 'amt')->get()->getRowArray();

        $pendingDeposits = (int) $this->applyDateRange(
            $db->table('usdt_deposit_requests')->whereIn('status', ['submitted', 'under_review']),
            'submitted_at',
            $filters
        )->countAllResults();

        $rejectedPayments = (int) $this->applyDateRange(
            $db->table('usdt_deposit_requests')->where('status', 'rejected'),
            'updated_at',
            $filters
        )->countAllResults();

        $verifiedPaymentsCount = (int) $this->applyDateRange(
            $db->table('usdt_deposit_requests')->where('status', 'verified'),
            'verified_at',
            $filters
        )->countAllResults();

        return [
            'kpis' => [
                KpiCardFactory::ok('rpt_total_members', 'Total Members', $profiles->countByStatus(), 'blue', 'bi-people-fill', 'members'),
                KpiCardFactory::ok('rpt_active_members', 'Active Members', $profiles->countByStatus('active'), 'green', 'bi-person-check-fill', 'members'),
                KpiCardFactory::ok('rpt_inactive_members', 'Inactive Members', $profiles->countByStatus('inactive'), 'orange', 'bi-person-x-fill', 'members'),
                KpiCardFactory::ok('rpt_coins_added', 'Total Coins Added', $inventory['total_added'], 'purple', 'bi-database-fill', 'coins'),
                KpiCardFactory::ok('rpt_available_inv', 'Available Inventory', $inventory['available_balance'], 'gold', 'bi-box-seam', 'coins'),
                KpiCardFactory::ok('rpt_allocated', 'Allocated Coins', $inventory['total_allocated'], 'pink', 'bi-box-arrow-up', 'coins'),
                KpiCardFactory::ok('rpt_approved_purchases', 'Approved Purchases', $approvedPurchases, 'teal', 'bi-check2-circle', 'requests'),
                KpiCardFactory::ok('rpt_pending_purchases', 'Pending Purchases', $pendingPurchases, 'red', 'bi-hourglass-split', 'requests'),
                KpiCardFactory::ok('rpt_verified_usdt', 'Verified Payment Totals', MoneyMath::normalize((string) ($verifiedAmt['amt'] ?? '0'), 8), 'green', 'bi-cash-stack', 'usdt'),
                KpiCardFactory::ok('rpt_verified_count', 'Verified Payments', $verifiedPaymentsCount, 'cyan', 'bi-patch-check', 'payments'),
                KpiCardFactory::ok('rpt_pending_deposits', 'Pending Deposits', $pendingDeposits, 'amber', 'bi-shield-exclamation', 'payments'),
                KpiCardFactory::ok('rpt_rejected_payments', 'Rejected Payments', $rejectedPayments, 'red', 'bi-x-circle', 'payments'),
            ],
            'notes' => [
                'Pending deposits are not counted as received revenue.',
                'USDT totals and coin quantities are never mixed.',
            ],
            'rows'  => [],
            'total' => 0,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>}
     */
    public function membersReport(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $db      = db_connect();

        $applyMemberFilters = static function ($b) use ($filters) {
            $b->where('p.is_root_sponsor', 0);
            if ($filters['status'] !== null && in_array($filters['status'], ['active', 'inactive'], true)) {
                $b->where('p.account_status', $filters['status']);
            }
            if ($filters['member_id'] !== null) {
                $b->like('p.member_id', $filters['member_id']);
            }
            if ($filters['sponsor'] !== null) {
                $b->like('sr.sponsor_member_id', $filters['sponsor']);
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('p.member_id', $filters['q'])
                    ->orLike('p.member_name', $filters['q'])
                    ->groupEnd();
            }
            if ($filters['date_from'] !== null) {
                $b->where('p.created_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('p.created_at <=', $filters['date_to']);
            }

            return $b;
        };

        $countBuilder = $applyMemberFilters(
            $db->table('member_profiles p')
                ->join('sponsor_relationships sr', 'sr.member_user_id = p.user_id', 'left')
        );
        $total = (int) $countBuilder->countAllResults();

        $rows = $applyMemberFilters(
            $db->table('member_profiles p')
                ->select('p.member_id, p.member_name, p.account_status, p.created_at, p.activated_at, p.user_id,
                    sr.sponsor_member_id,
                    COALESCE(b.balance, 0) AS owned_coins')
                ->join('sponsor_relationships sr', 'sr.member_user_id = p.user_id', 'left')
                ->join('member_coin_balances b', 'b.member_user_id = p.user_id', 'left')
        )
            ->orderBy('p.created_at', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        foreach ($rows as &$row) {
            $row['owned_coins'] = MoneyMath::normalize((string) ($row['owned_coins'] ?? '0'), $this->scale());
            $row['approved_purchases'] = (int) $db->table('coin_purchase_requests')
                ->where('member_user_id', (int) ($row['user_id'] ?? 0))
                ->where('status', 'approved')
                ->countAllResults();
        }
        unset($row);

        $profiles = model(MemberProfileModel::class);

        return [
            'rows'  => $rows,
            'total' => $total,
            'kpis'  => [
                KpiCardFactory::ok('mem_total', 'Matching Members', $total, 'blue', 'bi-people', 'members'),
                KpiCardFactory::ok('mem_active', 'Active (platform)', $profiles->countByStatus('active'), 'green', 'bi-person-check', 'members'),
                KpiCardFactory::ok('mem_inactive', 'Inactive (platform)', $profiles->countByStatus('inactive'), 'orange', 'bi-person-x', 'members'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>, locks: list<array<string, mixed>>, unlocks: list<array<string, mixed>>, prices: list<array<string, mixed>>, ownership: array<string, string>}
     */
    public function inventoryReport(array $filters = []): array
    {
        $filters   = $this->normalizeFilters($filters);
        $inventory = service('coinInventoryService')->summary();
        $db        = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('coin_inventory_transactions');
            if ($filters['date_from'] !== null) {
                $b->where('created_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('created_at <=', $filters['date_to']);
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('transaction_ref', $filters['q'])
                    ->orLike('reference', $filters['q'])
                    ->orLike('type', $filters['q'])
                    ->groupEnd();
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $ownership = $db->table('member_coin_balances')
            ->selectSum('balance', 'balance')
            ->selectSum('available_balance', 'available')
            ->selectSum('locked_balance', 'locked')
            ->get()
            ->getRowArray() ?: [];

        $ownershipNorm = [
            'balance'   => MoneyMath::normalize((string) ($ownership['balance'] ?? '0'), $this->scale()),
            'available' => MoneyMath::normalize((string) ($ownership['available'] ?? '0'), $this->scale()),
            'locked'    => MoneyMath::normalize((string) ($ownership['locked'] ?? '0'), $this->scale()),
        ];

        $locks = $db->table('member_coin_locks')
            ->orderBy('id', 'DESC')
            ->limit(15)
            ->get()
            ->getResultArray();

        $unlocks = $db->table('member_coin_locks')
            ->where('status', 'unlocked')
            ->orderBy('unlocked_at', 'DESC')
            ->limit(15)
            ->get()
            ->getResultArray();

        $prices = service('coinPriceService')->priceHistory(15, 0);

        $reconOk = MoneyMath::compare(
            $inventory['total_added'],
            MoneyMath::add($inventory['available_balance'], $inventory['total_allocated'], $this->scale()),
            $this->scale()
        ) === 0;

        return [
            'rows'      => $rows,
            'total'     => $total,
            'ownership' => $ownershipNorm,
            'locks'     => $locks,
            'unlocks'   => $unlocks,
            'prices'    => $prices,
            'reconciled'=> $reconOk,
            'kpis'      => [
                KpiCardFactory::ok('inv_added', 'Inventory Added', $inventory['total_added'], 'purple', 'bi-plus-circle', 'coins'),
                KpiCardFactory::ok('inv_alloc', 'Coins Allocated', $inventory['total_allocated'], 'pink', 'bi-box-arrow-up', 'coins'),
                KpiCardFactory::ok('inv_avail', 'Available Coins', $inventory['available_balance'], 'gold', 'bi-box-seam', 'coins'),
                KpiCardFactory::ok('own_total', 'Member Ownership', $ownershipNorm['balance'], 'teal', 'bi-safe', 'coins'),
                KpiCardFactory::ok('own_locked', 'Locked Coins', $ownershipNorm['locked'], 'amber', 'bi-lock', 'coins'),
                KpiCardFactory::ok('inv_recon', 'Inventory Reconciled', $reconOk ? 'Yes' : 'Mismatch', $reconOk ? 'green' : 'red', 'bi-clipboard-check', null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>}
     */
    public function purchasesReport(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $db      = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('coin_purchase_requests');
            if ($filters['status'] !== null && in_array($filters['status'], ['pending', 'approved', 'rejected', 'cancelled'], true)) {
                $b->where('status', $filters['status']);
            }
            if ($filters['member_id'] !== null) {
                $b->like('member_id', $filters['member_id']);
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('purchase_ref', $filters['q'])
                    ->orLike('member_id', $filters['q'])
                    ->groupEnd();
            }
            if ($filters['date_from'] !== null) {
                $b->where('requested_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('requested_at <=', $filters['date_to']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('requested_at', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $approved = (int) $db->table('coin_purchase_requests')->where('status', 'approved')->countAllResults();
        $pending  = (int) $db->table('coin_purchase_requests')->where('status', 'pending')->countAllResults();

        return [
            'rows'  => $rows,
            'total' => $total,
            'kpis'  => [
                KpiCardFactory::ok('pur_match', 'Matching Rows', $total, 'blue', 'bi-list-ul', 'requests'),
                KpiCardFactory::ok('pur_approved', 'Approved (all)', $approved, 'green', 'bi-check2-circle', 'requests'),
                KpiCardFactory::ok('pur_pending', 'Pending (all)', $pending, 'red', 'bi-hourglass', 'requests'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>, include_sensitive: bool}
     */
    public function paymentsReport(array $filters = [], bool $includeSensitive = false): array
    {
        $filters = $this->normalizeFilters($filters);
        $db      = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('usdt_deposit_requests');
            if ($filters['status'] !== null && in_array($filters['status'], ['submitted', 'under_review', 'verified', 'rejected', 'cancelled', 'draft'], true)) {
                $b->where('status', $filters['status']);
            }
            if ($filters['member_id'] !== null) {
                $b->like('member_id', $filters['member_id']);
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('deposit_ref', $filters['q'])
                    ->orLike('member_id', $filters['q'])
                    ->orLike('linked_purchase_ref', $filters['q'])
                    ->groupEnd();
            }
            if ($filters['date_from'] !== null) {
                $b->where('created_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('created_at <=', $filters['date_to']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $raw   = $base()
            ->orderBy('id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $rows = [];
        foreach ($raw as $row) {
            $out = [
                'deposit_ref'         => $row['deposit_ref'] ?? '',
                'member_id'           => $row['member_id'] ?? '',
                'network_key'         => $row['network_key'] ?? '',
                'expected_amount_usdt'=> MoneyMath::normalize((string) ($row['expected_amount_usdt'] ?? '0'), 8),
                'verified_amount_usdt'=> $row['verified_amount_usdt'] !== null
                    ? MoneyMath::normalize((string) $row['verified_amount_usdt'], 8)
                    : '',
                'verification_method' => $row['verification_method'] ?? '',
                'status'              => $row['status'] ?? '',
                'linked_purchase_ref' => $row['linked_purchase_ref'] ?? '',
                'submitted_at'        => $row['submitted_at'] ?? $row['created_at'] ?? '',
                'verified_at'         => $row['verified_at'] ?? '',
            ];
            if ($includeSensitive) {
                $out['tx_hash']                      = $row['tx_hash'] ?? '';
                $out['destination_wallet_snapshot']  = $row['destination_wallet_snapshot'] ?? '';
                $out['verification_reference']       = $row['verification_reference'] ?? '';
            }
            $rows[] = $out;
        }

        $verified = (int) $db->table('usdt_deposit_requests')->where('status', 'verified')->countAllResults();
        $pending  = (int) $db->table('usdt_deposit_requests')->whereIn('status', ['submitted', 'under_review'])->countAllResults();
        $rejected = (int) $db->table('usdt_deposit_requests')->where('status', 'rejected')->countAllResults();

        return [
            'rows'              => $rows,
            'total'             => $total,
            'include_sensitive' => $includeSensitive,
            'kpis'              => [
                KpiCardFactory::ok('pay_match', 'Matching Rows', $total, 'blue', 'bi-list-ul', 'payments'),
                KpiCardFactory::ok('pay_verified', 'Verified', $verified, 'green', 'bi-patch-check', 'payments'),
                KpiCardFactory::ok('pay_pending', 'Pending', $pending, 'amber', 'bi-hourglass', 'payments'),
                KpiCardFactory::ok('pay_rejected', 'Rejected', $rejected, 'red', 'bi-x-circle', 'payments'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>}
     */
    public function walletsReport(array $filters = []): array
    {
        $filters = $this->normalizeFilters($filters);
        $db      = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('member_coin_ownership_transactions t')
                ->select('t.*, p.member_id')
                ->join('member_profiles p', 'p.user_id = t.member_user_id', 'left');
            if ($filters['member_id'] !== null) {
                $b->like('p.member_id', $filters['member_id']);
            }
            if ($filters['status'] !== null) {
                $b->where('t.type', strtoupper($filters['status']));
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('t.transaction_ref', $filters['q'])
                    ->orLike('p.member_id', $filters['q'])
                    ->orLike('t.purchase_ref', $filters['q'])
                    ->orLike('t.lock_ref', $filters['q'])
                    ->groupEnd();
            }
            if ($filters['date_from'] !== null) {
                $b->where('t.created_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('t.created_at <=', $filters['date_to']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('t.id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $sums = $db->table('member_coin_balances')
            ->selectSum('balance', 'balance')
            ->selectSum('available_balance', 'available')
            ->selectSum('locked_balance', 'locked')
            ->get()
            ->getRowArray() ?: [];

        return [
            'rows'  => $rows,
            'total' => $total,
            'kpis'  => [
                KpiCardFactory::ok('wal_rows', 'Matching Txns', $total, 'blue', 'bi-list-ul', 'coins'),
                KpiCardFactory::ok('wal_bal', 'Total Owned', MoneyMath::normalize((string) ($sums['balance'] ?? '0'), $this->scale()), 'purple', 'bi-safe', 'coins'),
                KpiCardFactory::ok('wal_avail', 'Available', MoneyMath::normalize((string) ($sums['available'] ?? '0'), $this->scale()), 'green', 'bi-wallet2', 'coins'),
                KpiCardFactory::ok('wal_locked', 'Locked', MoneyMath::normalize((string) ($sums['locked'] ?? '0'), $this->scale()), 'amber', 'bi-lock', 'coins'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>, platform: array<string, mixed>}
     */
    public function referralsReport(array $filters = []): array
    {
        $filters  = $this->normalizeFilters($filters);
        $platform = service('referralTeamService')->platformStats();
        $db       = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('sponsor_relationships sr')
                ->select('sr.sponsor_member_id, sr.created_at AS linked_at,
                    p.member_id, p.member_name, p.account_status, p.activated_at, p.created_at AS registered_at')
                ->join('member_profiles p', 'p.user_id = sr.member_user_id', 'inner');
            if ($filters['sponsor'] !== null) {
                $b->like('sr.sponsor_member_id', $filters['sponsor']);
            }
            if ($filters['member_id'] !== null) {
                $b->like('p.member_id', $filters['member_id']);
            }
            if ($filters['status'] !== null && in_array($filters['status'], ['active', 'inactive'], true)) {
                $b->where('p.account_status', $filters['status']);
            }
            if ($filters['q'] !== null) {
                $b->groupStart()
                    ->like('p.member_id', $filters['q'])
                    ->orLike('p.member_name', $filters['q'])
                    ->orLike('sr.sponsor_member_id', $filters['q'])
                    ->groupEnd();
            }
            if ($filters['date_from'] !== null) {
                $b->where('sr.created_at >=', $filters['date_from']);
            }
            if ($filters['date_to'] !== null) {
                $b->where('sr.created_at <=', $filters['date_to']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('sr.id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        // Optional L1/L2/L3 for a specific sponsor filter
        $levels = null;
        if ($filters['sponsor'] !== null) {
            $sponsorProfile = model(MemberProfileModel::class)->findByMemberId($filters['sponsor']);
            if ($sponsorProfile !== null) {
                $levels = service('referralTeamService')->countsForSponsor((int) $sponsorProfile->user_id);
            }
        }

        return [
            'rows'     => $rows,
            'total'    => $total,
            'platform' => $platform,
            'levels'   => $levels,
            'kpis'     => [
                KpiCardFactory::ok('ref_edges', 'Direct Relationships', $platform['total_relationships'], 'purple', 'bi-diagram-2', 'edges'),
                KpiCardFactory::ok('ref_with', 'Members With Sponsors', $platform['members_with_sponsors'], 'cyan', 'bi-link-45deg', 'members'),
                KpiCardFactory::ok('ref_without', 'Without Sponsors', $platform['members_without_sponsors'], 'amber', 'bi-exclamation-circle', 'members'),
                KpiCardFactory::ok('ref_match', 'Matching Rows', $total, 'blue', 'bi-list-ul', 'edges'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>, snapshot: array<string, mixed>}
     */
    public function miningReport(array $filters = []): array
    {
        $filters  = $this->normalizeFilters($filters);
        $snapshot = service('miningSimulationService')->adminSnapshot();
        $db       = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('mining_simulation_runs');
            if ($filters['date_from'] !== null) {
                $b->where('calculation_date >=', substr($filters['date_from'], 0, 10));
            }
            if ($filters['date_to'] !== null) {
                $b->where('calculation_date <=', substr($filters['date_to'], 0, 10));
            }
            if ($filters['q'] !== null) {
                $b->like('run_ref', $filters['q']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $rate = $snapshot['current_rate'] ?? null;

        return [
            'rows'     => $rows,
            'total'    => $total,
            'snapshot' => $snapshot,
            'kpis'     => [
                KpiCardFactory::unavailable('mine_actual', 'Actual Posted Earnings', 'cyan', 'bi-lightning-charge-fill', (string) ($snapshot['actual_mining_status'] ?? 'Actual Mining: Not Activated')),
                KpiCardFactory::ok('mine_preview', 'Simulation Preview Total', $snapshot['total_preview'], 'teal', 'bi-graph-up', 'sim'),
                KpiCardFactory::ok('mine_today', "Today's Simulation", $snapshot['today_preview'], 'cyan', 'bi-calendar-day', 'sim'),
                KpiCardFactory::ok('mine_cfg', 'Approved Config', $rate !== null ? ((string) ($rate['rate_percent'] ?? '0') . '%') : 'None', 'purple', 'bi-sliders', null),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{rows: list<array<string, mixed>>, total: int, kpis: list<array<string, mixed>>, snapshot: array<string, mixed>, posting_enabled: bool}
     */
    public function commissionsReport(array $filters = []): array
    {
        $filters  = $this->normalizeFilters($filters);
        $snapshot = service('commissionSimulationService')->adminSnapshot();
        $posting  = (bool) ($this->config ?? config('Platform'))->commissionPostingEnabled;
        $wallet   = service('commissionWalletService')->platformSnapshot();
        $db       = db_connect();

        $base = static function () use ($db, $filters) {
            $b = $db->table('commission_simulation_runs');
            if ($filters['date_from'] !== null) {
                $b->where('calculation_date >=', substr($filters['date_from'], 0, 10));
            }
            if ($filters['date_to'] !== null) {
                $b->where('calculation_date <=', substr($filters['date_to'], 0, 10));
            }
            if ($filters['q'] !== null) {
                $b->like('run_ref', $filters['q']);
            }

            return $b;
        };

        $total = (int) $base()->countAllResults(false);
        $rows  = $base()
            ->orderBy('id', 'DESC')
            ->limit($filters['per_page'], ($filters['page'] - 1) * $filters['per_page'])
            ->get()
            ->getResultArray();

        $plan = $snapshot['active_plan'] ?? null;

        return [
            'rows'             => $rows,
            'total'            => $total,
            'snapshot'         => $snapshot,
            'posting_enabled'  => $posting,
            'wallet_snapshot'  => $wallet,
            'kpis'             => [
                KpiCardFactory::unavailable(
                    'comm_actual',
                    'Actual Posted Earnings',
                    'indigo',
                    'bi-cash-stack',
                    $posting ? 'Posting enabled — verify ledger' : (string) ($snapshot['actual_commission_status'] ?? 'Actual Commission: Not Activated')
                ),
                KpiCardFactory::ok('comm_preview', 'Simulation Preview Total', $snapshot['total_preview'], 'teal', 'bi-graph-up-arrow', 'sim'),
                KpiCardFactory::ok('comm_today', "Today's Simulation", $snapshot['today_preview'], 'cyan', 'bi-calendar-day', 'sim'),
                KpiCardFactory::ok('comm_cfg', 'Approved Plan', $plan !== null ? (string) ($plan['plan_ref'] ?? 'Configured') : 'None', 'purple', 'bi-sliders', null),
                KpiCardFactory::ok('comm_wallet', 'Commission Wallet Credits', $wallet['total_credits'] ?? '0', 'green', 'bi-wallet2', 'usdt'),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function exportCsv(string $tab, array $filters = [], bool $includeSensitivePayments = false): string
    {
        $tab     = $this->normalizeTab($tab);
        $filters = $this->normalizeFilters($filters);
        $filters['page']     = 1;
        $filters['per_page'] = self::EXPORT_MAX_ROWS;

        $data = $this->build($tab, $filters, $includeSensitivePayments);

        return match ($tab) {
            'members'     => $this->csvFromRows($data['rows'] ?? [], [
                'member_id', 'member_name', 'sponsor_member_id', 'created_at', 'activated_at',
                'account_status', 'approved_purchases', 'owned_coins',
            ]),
            'inventory'   => $this->csvFromRows($data['rows'] ?? [], [
                'transaction_ref', 'type', 'direction', 'quantity', 'previous_balance', 'new_balance',
                'reference', 'created_at',
            ]),
            'purchases'   => $this->csvFromRows($data['rows'] ?? [], [
                'purchase_ref', 'member_id', 'coin_quantity', 'price_usdt_snapshot', 'total_usdt',
                'status', 'requested_at', 'reviewed_at',
            ]),
            'payments'    => $this->csvFromRows($data['rows'] ?? [], array_keys(($data['rows'][0] ?? [
                'deposit_ref' => '', 'member_id' => '', 'network_key' => '', 'expected_amount_usdt' => '',
                'verified_amount_usdt' => '', 'verification_method' => '', 'status' => '',
                'linked_purchase_ref' => '', 'submitted_at' => '', 'verified_at' => '',
            ]))),
            'wallets'     => $this->csvFromRows($data['rows'] ?? [], [
                'transaction_ref', 'member_id', 'type', 'quantity', 'new_balance', 'new_available',
                'new_locked', 'purchase_ref', 'lock_ref', 'created_at',
            ]),
            'referrals'   => $this->csvFromRows($data['rows'] ?? [], [
                'member_id', 'member_name', 'sponsor_member_id', 'account_status',
                'registered_at', 'activated_at', 'linked_at',
            ]),
            'mining'      => $this->csvFromRows($data['rows'] ?? [], [
                'run_ref', 'calculation_date', 'status', 'member_count', 'eligible_member_count',
                'total_eligible_coins', 'total_preview_amount', 'is_payable', 'is_posted', 'created_at',
            ], ['SIMULATION_ONLY' => '1', 'ACTUAL_POSTED' => '0']),
            'commissions' => $this->csvFromRows($data['rows'] ?? [], [
                'run_ref', 'calculation_date', 'status', 'eligible_purchase_count',
                'total_preview_amount', 'is_payable', 'is_posted', 'created_at',
            ], ['SIMULATION_ONLY' => '1', 'ACTUAL_POSTED' => '0']),
            default       => $this->overviewCsv($data),
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function overviewCsv(array $data): string
    {
        $rows = [];
        foreach ($data['kpis'] ?? [] as $kpi) {
            $rows[] = [
                'metric' => (string) ($kpi['label'] ?? ''),
                'value'  => (string) ($kpi['display'] ?? $kpi['value'] ?? ''),
                'unit'   => (string) ($kpi['unit'] ?? ''),
                'status' => (string) ($kpi['status'] ?? ''),
            ];
        }

        return $this->csvFromRows($rows, ['metric', 'value', 'unit', 'status']);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $columns
     * @param array<string, string> $extra
     */
    private function csvFromRows(array $rows, array $columns, array $extra = []): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV buffer.');
        }

        $header = $columns;
        foreach (array_keys($extra) as $key) {
            $header[] = $key;
        }
        fputcsv($handle, $header);

        $seen = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $col) {
                $line[] = $this->csvCell((string) ($row[$col] ?? ''));
            }
            foreach ($extra as $val) {
                $line[] = $this->csvCell($val);
            }
            $fingerprint = implode('|', $line);
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            fputcsv($handle, $line);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }

    private function csvCell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * @param object $builder
     * @param array<string, mixed> $filters
     */
    private function applyDateRange(object $builder, string $column, array $filters): object
    {
        if (($filters['date_from'] ?? null) !== null) {
            $builder->where($column . ' >=', $filters['date_from']);
        }
        if (($filters['date_to'] ?? null) !== null) {
            $builder->where($column . ' <=', $filters['date_to']);
        }

        return $builder;
    }

    private function normalizeDate(mixed $value, bool $endOfDay): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Date filters must be YYYY-MM-DD.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($dt === false || $dt->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid date filter.');
        }

        return $endOfDay ? $dt->format('Y-m-d 23:59:59') : $dt->format('Y-m-d 00:00:00');
    }

    private function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
