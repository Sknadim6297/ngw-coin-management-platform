<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11E purchase-lot coin selling architecture.
 * Execution remains kill-switched OFF until separately authorized.
 */
class CoinSellingService
{
    public const STATUS_DRAFT                = 'draft';
    public const STATUS_PENDING_REVIEW       = 'pending_review';
    public const STATUS_APPROVED             = 'approved';
    public const STATUS_SETTLEMENT_PENDING   = 'settlement_pending';
    public const STATUS_SETTLEMENT_SUBMITTED = 'settlement_submitted';
    public const STATUS_COMPLETED            = 'completed';
    public const STATUS_REJECTED             = 'rejected';
    public const STATUS_CANCELLED            = 'cancelled';
    public const STATUS_FAILED               = 'failed';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_DRAFT                => [self::STATUS_PENDING_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_PENDING_REVIEW       => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED             => [self::STATUS_SETTLEMENT_PENDING, self::STATUS_CANCELLED],
        self::STATUS_SETTLEMENT_PENDING   => [self::STATUS_SETTLEMENT_SUBMITTED, self::STATUS_FAILED],
        self::STATUS_SETTLEMENT_SUBMITTED => [self::STATUS_COMPLETED, self::STATUS_FAILED],
        self::STATUS_COMPLETED            => [],
        self::STATUS_REJECTED             => [],
        self::STATUS_CANCELLED            => [],
        self::STATUS_FAILED               => [],
    ];

    /** Active reservation statuses (count toward reserved qty on a lot). */
    public const RESERVED_STATUSES = [
        self::STATUS_PENDING_REVIEW,
        self::STATUS_APPROVED,
        self::STATUS_SETTLEMENT_PENDING,
        self::STATUS_SETTLEMENT_SUBMITTED,
    ];

    public function __construct(
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly CoinLockingService $locking = new CoinLockingService(),
        private readonly CoinPriceService $prices = new CoinPriceService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    public function enabled(): bool
    {
        $cfg = $this->config ?? config('Platform');

        return (bool) $cfg->coinSellingEnabled
            || service('financialSandboxService')->allows('coin_selling');
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $cfg = $this->config ?? config('Platform');

        return [
            'coin_selling_enabled'              => (bool) $cfg->coinSellingEnabled,
            'execution_authorized'              => false,
            'fee_policy_status'                 => (string) $cfg->coinSellingFeePolicyStatus,
            'settlement_asset_status'           => (string) $cfg->coinSellingSettlementAssetStatus,
            'counterparty_status'               => (string) $cfg->coinSellingCounterpartyStatus,
            'post_sale_mining_policy_status'    => (string) $cfg->coinSellingPostSaleMiningPolicyStatus,
            'mining_posting_enabled'            => (bool) $cfg->miningPostingEnabled,
            'mining_p2p_enabled'                => (bool) $cfg->miningP2pEnabled,
            'commission_posting_enabled'        => (bool) $cfg->commissionPostingEnabled,
            'commission_withdrawal_enabled'     => (bool) $cfg->commissionWithdrawalEnabled,
            'commission_blockchain_payout_enabled' => (bool) $cfg->commissionBlockchainPayoutEnabled,
            'blockers'                          => [
                'coinSellingEnabled=false',
                'Selling fee / minimum / settlement asset / counterparty pending business decision.',
                'Post-sale mining eligibility policy unapproved — mining posting remains OFF.',
                'Executable coin selling not authorized in Phase 11E.',
            ],
        ];
    }

    /**
     * Purchase-lot maturity snapshot using Asia/Kolkata business dates.
     *
     * @return array{
     *   lots: list<array<string, mixed>>,
     *   totals: array<string, string>,
     *   business_date: string,
     *   indicative_price_usdt: ?string
     * }
     */
    public function memberLotSummary(int $memberUserId): array
    {
        $bizDate = service('miningEligibilityService')->businessDate();
        $scale   = ($this->config ?? config('Platform'))->coinQuantityScale;
        $locks   = db_connect()->table('member_coin_locks')
            ->where('member_user_id', $memberUserId)
            ->whereIn('status', [
                CoinLockingService::STATUS_ACTIVE,
                CoinLockingService::STATUS_MATURED,
                CoinLockingService::STATUS_UNLOCKED,
            ])
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $lots = [];
        $owned = MoneyMath::normalize('0', $scale);
        $lockedQty = MoneyMath::normalize('0', $scale);
        $maturedQty = MoneyMath::normalize('0', $scale);
        $eligibleQty = MoneyMath::normalize('0', $scale);
        $reservedQty = MoneyMath::normalize('0', $scale);
        $soldQty = MoneyMath::normalize('0', $scale);

        foreach ($locks as $lock) {
            $lot = $this->buildLotRow($lock, $bizDate, $scale);
            $lots[] = $lot;
            $owned = MoneyMath::add($owned, $lot['coin_quantity'], $scale);
            if ($lot['maturity_status'] === 'locked') {
                $lockedQty = MoneyMath::add($lockedQty, $lot['remaining_on_lot'], $scale);
            }
            if ($lot['is_matured']) {
                $maturedQty = MoneyMath::add($maturedQty, $lot['remaining_on_lot'], $scale);
                $eligibleQty = MoneyMath::add($eligibleQty, $lot['eligible_quantity'], $scale);
            }
            $reservedQty = MoneyMath::add($reservedQty, $lot['reserved_quantity'], $scale);
            $soldQty = MoneyMath::add($soldQty, $lot['sold_quantity'], $scale);
        }

        $summary = $this->ownership->summaryForUser($memberUserId);

        return [
            'lots'   => $lots,
            'totals' => [
                'total_owned'           => $summary['balance'],
                'total_locked'          => $summary['locked'],
                'total_matured'         => $maturedQty,
                'available_matured'     => $eligibleQty,
                'reserved'              => $summary['reserved'],
                'total_sold'            => $soldQty,
                'eligible_selling_qty'  => $eligibleQty,
                'lot_owned_sum'         => $owned,
                'lot_locked_sum'        => $lockedQty,
                'lot_reserved_sum'      => $reservedQty,
            ],
            'business_date'         => $bizDate,
            'indicative_price_usdt' => $this->prices->getCurrentPrice(),
            'selling_enabled'       => $this->enabled(),
        ];
    }

    /**
     * Read-only selling preview (no writes).
     *
     * @return array<string, mixed>
     */
    public function preview(int $memberUserId, string $purchaseRef, string $quantity): array
    {
        $this->assertNotImpersonation();
        $summary = $this->memberLotSummary($memberUserId);
        $lot = null;
        foreach ($summary['lots'] as $row) {
            if ((string) $row['purchase_ref'] === trim($purchaseRef)) {
                $lot = $row;
                break;
            }
        }
        if ($lot === null) {
            throw new InvalidArgumentException('Purchase lot not found for this member.');
        }
        if (! $lot['is_matured']) {
            throw new InvalidArgumentException('Coins cannot be sold before the 1000-day maturity date.');
        }

        $scale = ($this->config ?? config('Platform'))->coinQuantityScale;
        $qty   = MoneyMath::normalize(trim($quantity), $scale);
        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Sell quantity must be greater than zero.');
        }
        if (MoneyMath::compare($qty, $lot['eligible_quantity'], $scale) === 1) {
            throw new InvalidArgumentException('Quantity exceeds eligible matured coins for this lot.');
        }

        $price = $this->prices->getCurrentPrice();
        $gross = null;
        if ($price !== null) {
            $gross = MoneyMath::mul($qty, $price, ($this->config ?? config('Platform'))->usdtScale);
        }

        $cfg = $this->config ?? config('Platform');
        $blockers = [];
        if (! $this->enabled()) {
            $blockers[] = 'coin_selling_kill_switch_off';
        }
        $blockers[] = 'fee_settlement_counterparty_pending_business_decision';
        $blockers[] = 'phase_11e_execution_not_authorized';

        return [
            'purchase_ref'        => $lot['purchase_ref'],
            'lock_ref'            => $lot['lock_ref'],
            'quantity'            => $qty,
            'eligible_quantity'   => $lot['eligible_quantity'],
            'indicative_price'    => $price,
            'gross_value_usdt'    => $gross,
            'fee_amount'          => null,
            'net_amount'          => null,
            'fee_policy_status'   => (string) $cfg->coinSellingFeePolicyStatus,
            'settlement_asset'    => null,
            'settlement_method'   => null,
            'can_execute'         => false,
            'blockers'            => $blockers,
            'note'                => 'PREVIEW ONLY — Coin Selling Not Activated — Awaiting Business Approval. Indicative Admin price only.',
        ];
    }

    /**
     * @return never
     */
    public function submit(int $memberUserId, string $purchaseRef, string $quantity, string $password, ?string $idempotencyKey = null): never
    {
        $this->assertNotImpersonation();
        $this->assertRateLimit($memberUserId);
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $memberUserId) {
            throw new InvalidArgumentException('Authenticated member mismatch.');
        }
        if (! $this->verifyPassword($user, $password)) {
            $this->audit->log('coin.selling_denied', $memberUserId, 'coin_sell', null, ['reason' => 'reauth_failed']);
            throw new InvalidArgumentException('Password confirmation failed.');
        }

        // Validate preview before refusal.
        $this->preview($memberUserId, $purchaseRef, $quantity);

        if (! $this->enabled()) {
            $this->audit->log('coin.selling_denied', $memberUserId, 'coin_sell', null, [
                'reason' => 'kill_switch_off',
                'purchase_ref' => $purchaseRef,
            ]);
            throw new RuntimeException('Coin Selling Not Activated — Awaiting Business Approval. Submission is blocked server-side.');
        }

        $this->audit->log('coin.selling_denied', $memberUserId, 'coin_sell', null, [
            'reason' => 'phase_11e_execution_not_authorized',
        ]);
        throw new RuntimeException(
            'Executable coin selling is not authorized in Phase 11E. Architecture is prepared; activation requires separate approval.'
        );
    }

    /**
     * Future authorized path: reserve matured lot coins and mark pending_review.
     *
     * @return array<string, mixed>
     */
    public function performSubmitWithReserve(
        int $memberUserId,
        string $purchaseRef,
        string $quantity,
        ?string $idempotencyKey = null
    ): array {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Submission is blocked server-side.');
        }

        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $preview = $this->preview($memberUserId, $purchaseRef, $quantity);
        $idem = trim((string) $idempotencyKey);
        if ($idem === '') {
            $idem = 'sell:' . $memberUserId . ':' . $purchaseRef . ':' . $preview['quantity'] . ':' . bin2hex(random_bytes(4));
        }
        if ($existing = $this->findByIdempotency($idem)) {
            throw new InvalidArgumentException('Duplicate sell request (idempotency).');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $lock = $db->query(
                'SELECT * FROM member_coin_locks WHERE purchase_ref = ? AND member_user_id = ? FOR UPDATE',
                [$purchaseRef, $memberUserId]
            )->getRowArray();
            if ($lock === null) {
                throw new InvalidArgumentException('Purchase lock lot not found.');
            }

            $bizDate = service('miningEligibilityService')->businessDate();
            $scale   = ($this->config ?? config('Platform'))->coinQuantityScale;
            $lot     = $this->buildLotRow($lock, $bizDate, $scale);
            if (! $lot['is_matured']) {
                throw new InvalidArgumentException('Early selling rejected — lot is not matured.');
            }
            if (MoneyMath::compare($preview['quantity'], $lot['eligible_quantity'], $scale) === 1) {
                throw new InvalidArgumentException('Quantity exceeds eligible matured coins for this lot.');
            }

            // Ensure sellable coins are available (unlock matured lock portion if still locked).
            if (in_array((string) $lock['status'], [CoinLockingService::STATUS_ACTIVE, CoinLockingService::STATUS_MATURED], true)) {
                $this->ownership->applyUnlock(
                    $memberUserId,
                    (string) $profile->member_id,
                    $preview['quantity'],
                    (string) $lock['lock_ref'],
                    $memberUserId,
                    'sell-unlock:' . $idem,
                    false
                );
                $lockRemaining = MoneyMath::sub((string) $lock['quantity'], $preview['quantity'], $scale);
                $lockUpdate = [
                    'quantity'   => $lockRemaining,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                if (MoneyMath::compare($lockRemaining, '0', $scale) <= 0) {
                    $lockUpdate['quantity']    = MoneyMath::normalize('0', $scale);
                    $lockUpdate['status']      = CoinLockingService::STATUS_UNLOCKED;
                    $lockUpdate['unlocked_at'] = date('Y-m-d H:i:s');
                } elseif ((string) $lock['status'] === CoinLockingService::STATUS_ACTIVE && $lot['is_matured']) {
                    $lockUpdate['status']     = CoinLockingService::STATUS_MATURED;
                    $lockUpdate['matured_at'] = $lock['matured_at'] ?? date('Y-m-d H:i:s');
                }
                $db->table('member_coin_locks')->where('id', (int) $lock['id'])->update($lockUpdate);
            }

            $sellRef = 'CSL-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $this->ownership->reserveForSale(
                $memberUserId,
                (string) $profile->member_id,
                $preview['quantity'],
                $sellRef,
                $memberUserId,
                'sell-reserve:' . $idem,
                false
            );

            $now = date('Y-m-d H:i:s');
            $db->table('coin_sell_requests')->insert([
                'sell_ref'             => $sellRef,
                'member_user_id'       => $memberUserId,
                'member_id'            => (string) $profile->member_id,
                'purchase_ref'         => $purchaseRef,
                'lock_ref'             => (string) $lock['lock_ref'],
                'quantity'             => $preview['quantity'],
                'price_snapshot_usdt'  => $preview['indicative_price'],
                'gross_value_usdt'     => $preview['gross_value_usdt'],
                'fee_amount'           => null,
                'net_amount'           => null,
                'fee_policy_status'    => 'pending_business_decision',
                'status'               => self::STATUS_PENDING_REVIEW,
                'idempotency_key'      => $idem,
                'is_reserved'          => 1,
                'is_settled'           => 0,
                'requested_at'         => $now,
                'created_by'           => $memberUserId,
                'created_at'           => $now,
                'updated_at'           => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Sell submit failed.');
            }
            $db->transCommit();

            $this->recordEvent($sellRef, 'submitted', $memberUserId, self::STATUS_DRAFT, self::STATUS_PENDING_REVIEW, [
                'purchase_ref' => $purchaseRef,
                'quantity'     => $preview['quantity'],
            ]);
            $this->audit->log('coin.selling_submitted', $memberUserId, 'coin_sell', $sellRef, [
                'purchase_ref' => $purchaseRef,
                'quantity'     => $preview['quantity'],
            ]);

            return $this->findByRef($sellRef) ?? [];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * @return never
     */
    public function approve(string $sellRef, int $adminUserId, string $password = ''): never
    {
        $this->assertAdminActor($adminUserId, $password);
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Approval is blocked.');
        }
        throw new RuntimeException('Executable sell approval is not authorized in Phase 11E.');
    }

    /**
     * @return array<string, mixed>
     */
    public function performApprove(string $sellRef, int $adminUserId): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Approval is blocked.');
        }
        $row = $this->requireRow($sellRef);
        $this->assertTransition((string) $row['status'], self::STATUS_APPROVED);
        $now = date('Y-m-d H:i:s');
        db_connect()->table('coin_sell_requests')->where('sell_ref', $sellRef)->update([
            'status'      => self::STATUS_APPROVED,
            'reviewed_at' => $now,
            'reviewer_id' => $adminUserId,
            'updated_at'  => $now,
        ]);
        $this->recordEvent($sellRef, 'approved', $adminUserId, (string) $row['status'], self::STATUS_APPROVED, null);
        $this->audit->log('coin.selling_approved', $adminUserId, 'coin_sell', $sellRef, []);

        return $this->findByRef($sellRef) ?? [];
    }

    /**
     * @return never
     */
    public function reject(string $sellRef, int $adminUserId, string $reason, string $password = ''): never
    {
        $this->assertAdminActor($adminUserId, $password);
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Rejection is blocked.');
        }
        throw new RuntimeException('Executable sell rejection is not authorized in Phase 11E.');
    }

    /**
     * @return array<string, mixed>
     */
    public function performRejectWithRelease(string $sellRef, int $adminUserId, string $reason): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Rejection is blocked.');
        }

        return $this->performReleaseTerminal($sellRef, $adminUserId, self::STATUS_REJECTED, $reason, 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    public function performCancelWithRelease(string $sellRef, int $actorUserId): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Cancellation is blocked.');
        }

        return $this->performReleaseTerminal($sellRef, $actorUserId, self::STATUS_CANCELLED, 'cancelled', 'cancelled');
    }

    /**
     * Record settlement evidence — does not complete sale without verified evidence.
     *
     * @param array{evidence_ref: string, settlement_asset?: string, settlement_amount?: string, settlement_method?: string} $data
     * @return array<string, mixed>
     */
    public function performRecordSettlement(string $sellRef, int $adminUserId, array $data): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Settlement recording is blocked.');
        }
        $row = $this->requireRow($sellRef);
        if (! in_array((string) $row['status'], [self::STATUS_APPROVED, self::STATUS_SETTLEMENT_PENDING], true)) {
            throw new InvalidArgumentException('Sell request must be approved before settlement evidence.');
        }
        $evidence = trim((string) ($data['evidence_ref'] ?? ''));
        if ($evidence === '') {
            throw new InvalidArgumentException('Settlement evidence reference is required.');
        }

        $from = (string) $row['status'];
        $now  = date('Y-m-d H:i:s');
        if ($from === self::STATUS_APPROVED) {
            $this->assertTransition($from, self::STATUS_SETTLEMENT_PENDING);
            db_connect()->table('coin_sell_requests')->where('sell_ref', $sellRef)->update([
                'status'     => self::STATUS_SETTLEMENT_PENDING,
                'updated_at' => $now,
            ]);
            $this->recordEvent($sellRef, 'settlement_pending', $adminUserId, $from, self::STATUS_SETTLEMENT_PENDING, null);
            $from = self::STATUS_SETTLEMENT_PENDING;
        }

        $this->assertTransition($from, self::STATUS_SETTLEMENT_SUBMITTED);
        db_connect()->table('coin_sell_requests')->where('sell_ref', $sellRef)->update([
            'status'                   => self::STATUS_SETTLEMENT_SUBMITTED,
            'settlement_evidence_ref'  => $evidence,
            'settlement_asset'         => $data['settlement_asset'] ?? null,
            'settlement_amount'        => $data['settlement_amount'] ?? null,
            'settlement_method'        => $data['settlement_method'] ?? null,
            'updated_at'               => $now,
        ]);
        $this->recordEvent($sellRef, 'settlement_submitted', $adminUserId, $from, self::STATUS_SETTLEMENT_SUBMITTED, [
            'evidence_ref' => $evidence,
        ]);

        return $this->findByRef($sellRef) ?? [];
    }

    /**
     * Complete sale only with independently verified settlement evidence flag.
     *
     * @return array<string, mixed>
     */
    public function performCompleteWithVerifiedSettlement(
        string $sellRef,
        int $adminUserId,
        bool $settlementVerified
    ): array {
        if (! $this->enabled()) {
            throw new RuntimeException('Coin Selling Not Activated. Completion is blocked.');
        }
        if (! $settlementVerified) {
            throw new RuntimeException('COMPLETED requires independently verified settlement evidence.');
        }

        $row = $this->requireRow($sellRef);
        $this->assertTransition((string) $row['status'], self::STATUS_COMPLETED);
        if (trim((string) ($row['settlement_evidence_ref'] ?? '')) === '') {
            throw new InvalidArgumentException('Settlement evidence is missing.');
        }
        if ((int) $row['is_settled'] === 1) {
            throw new InvalidArgumentException('Sale already settled.');
        }

        $db = db_connect();
        $db->transBegin();
        try {
            $locked = $db->query(
                'SELECT * FROM coin_sell_requests WHERE sell_ref = ? FOR UPDATE',
                [$sellRef]
            )->getRowArray();
            if ($locked === null || (string) $locked['status'] === self::STATUS_COMPLETED) {
                throw new InvalidArgumentException('Sell request already completed or missing.');
            }

            $this->ownership->debitSale(
                (int) $locked['member_user_id'],
                (string) $locked['member_id'],
                (string) $locked['quantity'],
                $sellRef,
                $adminUserId,
                'sell-debit:' . $sellRef,
                false
            );

            $now = date('Y-m-d H:i:s');
            $db->table('coin_sell_requests')->where('sell_ref', $sellRef)->update([
                'status'       => self::STATUS_COMPLETED,
                'is_reserved'  => 0,
                'is_settled'   => 1,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Sale completion failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }

        $this->recordEvent($sellRef, 'completed', $adminUserId, (string) $row['status'], self::STATUS_COMPLETED, [
            'settlement_evidence_ref' => $row['settlement_evidence_ref'],
        ]);
        $this->audit->log('coin.selling_completed', $adminUserId, 'coin_sell', $sellRef, []);

        return $this->findByRef($sellRef) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        $row = db_connect()->table('coin_sell_requests')->where('sell_ref', $ref)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['events'] = db_connect()->table('coin_sell_events')
            ->where('sell_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOwned(string $ref, int $memberUserId): ?array
    {
        $row = $this->findByRef($ref);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForMember(int $memberUserId, int $limit = 50): array
    {
        if (! db_connect()->tableExists('coin_sell_requests')) {
            return [];
        }

        return db_connect()->table('coin_sell_requests')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(array $filters = [], int $limit = 100): array
    {
        if (! db_connect()->tableExists('coin_sell_requests')) {
            return [];
        }
        $b = db_connect()->table('coin_sell_requests')->orderBy('id', 'DESC');
        if (! empty($filters['status'])) {
            $b->where('status', (string) $filters['status']);
        }
        if (! empty($filters['member_q'])) {
            $q = trim((string) $filters['member_q']);
            $b->groupStart()->like('member_id', $q)->orLike('sell_ref', $q)->orLike('purchase_ref', $q)->groupEnd();
        }

        return $b->limit(max(1, min(200, $limit)))->get()->getResultArray();
    }

    /**
     * @return array<string, int>
     */
    public function adminStatusCounts(): array
    {
        $out = ['total' => 0];
        foreach ([
            self::STATUS_PENDING_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_SETTLEMENT_PENDING,
            self::STATUS_SETTLEMENT_SUBMITTED,
            self::STATUS_COMPLETED,
            self::STATUS_REJECTED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_DRAFT,
        ] as $st) {
            $out[$st] = db_connect()->tableExists('coin_sell_requests')
                ? (int) db_connect()->table('coin_sell_requests')->where('status', $st)->countAllResults()
                : 0;
            $out['total'] += $out[$st];
        }

        return $out;
    }

    /**
     * Read-only maturity check for a lock using Kolkata business dates.
     */
    public function isLotMaturedByBusinessDate(array $lock, ?string $businessDate = null): bool
    {
        $bizDate = $businessDate ?? service('miningEligibilityService')->businessDate();
        $maturityBiz = $this->toBusinessDate((string) $lock['matures_at']);

        return $bizDate >= $maturityBiz
            || in_array((string) ($lock['status'] ?? ''), [
                CoinLockingService::STATUS_MATURED,
                CoinLockingService::STATUS_UNLOCKED,
            ], true);
    }

    public function assertTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException('Invalid sell status transition: ' . $from . ' → ' . $to);
        }
    }

    /**
     * @param array<string, mixed> $lock
     * @return array<string, mixed>
     */
    private function buildLotRow(array $lock, string $bizDate, int $scale): array
    {
        $qty = MoneyMath::normalize((string) $lock['quantity'], $scale);
        $purchaseRef = (string) ($lock['purchase_ref'] ?? '');
        $sold = MoneyMath::normalize('0', $scale);
        $reserved = MoneyMath::normalize('0', $scale);

        if ($purchaseRef !== '' && db_connect()->tableExists('coin_sell_requests')) {
            $soldRow = db_connect()->table('coin_sell_requests')
                ->selectSum('quantity', 'qty')
                ->where('purchase_ref', $purchaseRef)
                ->where('status', self::STATUS_COMPLETED)
                ->get()->getRowArray();
            $resRow = db_connect()->table('coin_sell_requests')
                ->selectSum('quantity', 'qty')
                ->where('purchase_ref', $purchaseRef)
                ->where('is_reserved', 1)
                ->whereIn('status', self::RESERVED_STATUSES)
                ->get()->getRowArray();
            $sold = MoneyMath::normalize((string) ($soldRow['qty'] ?? '0'), $scale);
            $reserved = MoneyMath::normalize((string) ($resRow['qty'] ?? '0'), $scale);
        }

        $remaining = MoneyMath::sub($qty, MoneyMath::add($sold, $reserved, $scale), $scale);
        if (MoneyMath::compare($remaining, '0', $scale) === -1) {
            $remaining = MoneyMath::normalize('0', $scale);
        }

        $matured = $this->isLotMaturedByBusinessDate($lock, $bizDate);
        $maturityBiz = $this->toBusinessDate((string) $lock['matures_at']);
        $statusLabel = $matured ? 'matured' : 'locked';
        if ((string) $lock['status'] === CoinLockingService::STATUS_UNLOCKED) {
            $statusLabel = 'unlocked';
        }

        return [
            'purchase_ref'       => $purchaseRef,
            'lock_ref'           => (string) $lock['lock_ref'],
            'member_id'          => (string) $lock['member_id'],
            'coin_quantity'      => $qty,
            'approval_date'      => $this->toBusinessDate((string) ($lock['locked_at'] ?? $lock['created_at'] ?? '')),
            'lock_start_date'    => $this->toBusinessDate((string) $lock['locked_at']),
            'lock_end_date'      => $maturityBiz,
            'maturity_status'    => $statusLabel,
            'lock_status'        => (string) $lock['status'],
            'is_matured'         => $matured,
            'sold_quantity'      => $sold,
            'reserved_quantity'  => $reserved,
            'remaining_on_lot'   => $remaining,
            'eligible_quantity'  => $matured ? $remaining : MoneyMath::normalize('0', $scale),
        ];
    }

    private function toBusinessDate(string $datetime): string
    {
        if ($datetime === '') {
            return service('miningEligibilityService')->businessDate();
        }
        try {
            $dt = new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            $dt = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        }

        return service('miningEligibilityService')->businessDate($dt);
    }

    /**
     * @return array<string, mixed>
     */
    private function performReleaseTerminal(
        string $sellRef,
        int $actorUserId,
        string $toStatus,
        string $reason,
        string $eventType
    ): array {
        $db = db_connect();
        $db->transBegin();
        try {
            $row = $db->query(
                'SELECT * FROM coin_sell_requests WHERE sell_ref = ? FOR UPDATE',
                [$sellRef]
            )->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Sell request not found.');
            }
            $this->assertTransition((string) $row['status'], $toStatus);

            if ((int) $row['is_reserved'] === 1) {
                $key = 'sell-release:' . $sellRef;
                $existing = $db->table('member_coin_ownership_transactions')
                    ->where('idempotency_key', $key)
                    ->get()->getRowArray();
                if ($existing === null) {
                    $this->ownership->releaseSaleReserve(
                        (int) $row['member_user_id'],
                        (string) $row['member_id'],
                        (string) $row['quantity'],
                        $sellRef,
                        $actorUserId,
                        $key,
                        false
                    );
                }
            }

            $now = date('Y-m-d H:i:s');
            $db->table('coin_sell_requests')->where('sell_ref', $sellRef)->update([
                'status'           => $toStatus,
                'rejection_reason' => substr($reason, 0, 500),
                'is_reserved'      => 0,
                'reviewed_at'      => $now,
                'reviewer_id'      => $actorUserId,
                'updated_at'       => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Sell terminal release failed.');
            }
            $db->transCommit();

            $this->recordEvent($sellRef, $eventType, $actorUserId, (string) $row['status'], $toStatus, ['reason' => $reason]);
            $this->audit->log('coin.selling_' . $eventType, $actorUserId, 'coin_sell', $sellRef, ['reason' => $reason]);

            return $this->findByRef($sellRef) ?? [];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRow(string $ref): array
    {
        $row = db_connect()->table('coin_sell_requests')->where('sell_ref', $ref)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Sell request not found.');
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdempotency(string $key): ?array
    {
        if (! db_connect()->tableExists('coin_sell_requests')) {
            return null;
        }

        return db_connect()->table('coin_sell_requests')->where('idempotency_key', $key)->get()->getRowArray();
    }

    private function assertNotImpersonation(): void
    {
        if (service('impersonationService')->activeContext() !== null) {
            throw new RuntimeException('Coin selling is not allowed from Admin support-view impersonation.');
        }
        // Web-only URI guard — ignore stale request paths under CLI/PHPUnit.
        if (! is_cli() && str_starts_with(uri_string(), 'support-view')) {
            throw new RuntimeException('Coin selling is not allowed from Admin support-view.');
        }
    }

    private function assertAdminActor(int $adminUserId, string $password): void
    {
        $this->assertNotImpersonation();
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $adminUserId) {
            throw new InvalidArgumentException('Authenticated admin mismatch.');
        }
        if (! $user->can('coin.selling.review')) {
            throw new InvalidArgumentException('Missing coin.selling.review permission.');
        }
        if (! $this->verifyPassword($user, $password)) {
            throw new InvalidArgumentException('Password confirmation failed.');
        }
    }

    private function assertRateLimit(int $memberUserId): void
    {
        if (! db_connect()->tableExists('coin_sell_requests')) {
            return;
        }
        $since = date('Y-m-d H:i:s', time() - 3600);
        $count = db_connect()->table('coin_sell_requests')
            ->where('member_user_id', $memberUserId)
            ->where('created_at >=', $since)
            ->countAllResults();
        if ($count >= 20) {
            throw new InvalidArgumentException('Coin selling rate limit exceeded.');
        }
    }

    private function verifyPassword(object $user, string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }
        try {
            $hash = (string) ($user->password_hash ?? '');

            return $hash !== '' && service('passwords')->verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $ref,
        string $type,
        ?int $actor,
        ?string $from,
        ?string $to,
        ?array $payload
    ): void {
        db_connect()->table('coin_sell_events')->insert([
            'sell_ref'       => $ref,
            'event_type'     => $type,
            'actor_user_id'  => $actor,
            'from_status'    => $from,
            'to_status'      => $to,
            'payload_json'   => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
