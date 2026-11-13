<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Member purchase-request workflow and admin approval accounting.
 * Does not verify or settle real USDT payments.
 */
class CoinPurchaseService
{
    public const STATUS_PENDING   = 'pending';
    public const STATUS_APPROVED  = 'approved';
    public const STATUS_REJECTED  = 'rejected';
    public const STATUS_CANCELLED = 'cancelled';

    public function __construct(
        private readonly CoinPurchaseRequestModel $requests = new CoinPurchaseRequestModel(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly CoinPriceService $prices = new CoinPriceService(),
        private readonly CoinInventoryService $inventory = new CoinInventoryService(),
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?UsdtDepositService $deposits = null,
        private readonly ?UsdtPaymentAllocationService $paymentAllocations = null,
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Whether live purchase approval currently requires a verified deposit.
     */
    public function requiresVerifiedDeposit(): bool
    {
        return (bool) ($this->config ?? config('Platform'))->requireVerifiedDepositForApproval;
    }

    /**
     * @return array{
     *   quote: ?array<string, string>,
     *   inventory: array<string, mixed>,
     *   price_configured: bool,
     *   can_purchase: bool,
     *   block_reason: ?string
     * }
     */
    public function purchasePageContext(?string $quantityPreview = null): array
    {
        $inventory = $this->inventory->summary();
        $priceOk   = $this->prices->isPriceConfigured();
        $quote     = null;
        $block     = null;

        if (! $priceOk) {
            $block = 'Coin price is not configured yet.';
        } elseif (! $inventory['is_available']) {
            $block = 'Coin inventory is currently unavailable.';
        }

        if ($quantityPreview !== null && trim($quantityPreview) !== '' && $block === null) {
            try {
                $quote = $this->prices->quoteByQuantity($quantityPreview);
            } catch (InvalidArgumentException $e) {
                $block = $e->getMessage();
            }
        }

        return [
            'quote'            => $quote,
            'inventory'        => $inventory,
            'price'            => $this->prices->settingsSummary(),
            'price_configured' => $priceOk,
            'can_purchase'     => $block === null && $priceOk && $inventory['is_available'],
            'block_reason'     => $block,
            'max_quantity'     => MoneyMath::normalize(
                (string) ($this->config ?? config('Platform'))->maxPurchaseQuantity,
                ($this->config ?? config('Platform'))->coinQuantityScale
            ),
        ];
    }

    /**
     * Create a pending purchase request. Never trusts client price/total.
     *
     * @return array{request: array<string, mixed>, duplicate: bool}
     */
    public function createRequest(
        int $memberUserId,
        string $quantity,
        ?string $memberNote,
        string $idempotencyKey
    ): array {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Submission key is required.');
        }

        $existing = $this->requests->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['request' => $existing, 'duplicate' => true];
        }

        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $pendingCount = (int) $this->requests
            ->where('member_user_id', $memberUserId)
            ->where('status', self::STATUS_PENDING)
            ->countAllResults();

        $maxPending = ($this->config ?? config('Platform'))->maxPendingPurchaseRequests;
        if ($pendingCount >= $maxPending) {
            throw new InvalidArgumentException(
                'You already have the maximum number of pending purchase requests (' . $maxPending . ').'
            );
        }

        $quote = $this->prices->quoteByQuantity($quantity);

        if (! $this->inventory->hasSufficientInventory($quote['coin_quantity'])) {
            throw new InvalidArgumentException('Insufficient coin inventory for this request.');
        }

        $note = $memberNote !== null ? trim($memberNote) : null;
        if ($note === '') {
            $note = null;
        }

        $now = date('Y-m-d H:i:s');
        $ref = $this->generatePurchaseRef();

        $db = db_connect();
        $db->transBegin();

        try {
            // Re-check inventory under lock for race-safe request creation.
            $invRow = $db->query('SELECT available_balance FROM coin_inventory WHERE id = 1 FOR UPDATE')->getRowArray();
            if ($invRow === null) {
                throw new RuntimeException('Inventory row missing.');
            }

            $scale = ($this->config ?? config('Platform'))->coinQuantityScale;
            $available = MoneyMath::normalize((string) $invRow['available_balance'], $scale);
            if (MoneyMath::compare($available, $quote['coin_quantity'], $scale) < 0) {
                throw new InvalidArgumentException('Insufficient coin inventory for this request.');
            }

            $id = $this->requests->insert([
                'purchase_ref'              => $ref,
                'member_user_id'            => $memberUserId,
                'member_id'                 => $profile->member_id,
                'coin_quantity'             => $quote['coin_quantity'],
                'price_usdt_snapshot'       => $quote['coin_price_usdt'],
                'total_usdt'                => $quote['usdt_amount'],
                'status'                    => self::STATUS_PENDING,
                'member_note'               => $note,
                'rejection_reason'          => null,
                'reviewed_by'               => null,
                'requested_at'              => $now,
                'reviewed_at'               => null,
                'idempotency_key'           => $idempotencyKey,
                'inventory_transaction_ref' => null,
                'ownership_transaction_ref' => null,
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to create purchase request.');
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Purchase request creation failed.');
            }

            $db->transCommit();

            $row = $this->requests->find($id);
            if ($row === null) {
                throw new RuntimeException('Purchase request missing after insert.');
            }

            $this->audit->log(
                'coin.purchase_requested',
                $memberUserId,
                'coin_purchase_request',
                $ref,
                [
                    'quantity'   => $quote['coin_quantity'],
                    'price'      => $quote['coin_price_usdt'],
                    'total_usdt' => $quote['usdt_amount'],
                ]
            );

            return ['request' => $row, 'duplicate' => false];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($this->isDuplicateKeyException($e)) {
                $dup = $this->requests->findByIdempotencyKey($idempotencyKey);
                if ($dup !== null) {
                    return ['request' => $dup, 'duplicate' => true];
                }
            }

            throw $e;
        }
    }

    /**
     * @return array{request: array<string, mixed>}
     */
    public function cancelRequest(int $memberUserId, string $purchaseRef): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM coin_purchase_requests WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Purchase request not found.');
            }

            if ((int) $row['member_user_id'] !== $memberUserId) {
                throw new InvalidArgumentException('Purchase request not found.');
            }

            if ($row['status'] !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending purchase requests can be cancelled.');
            }

            // Phase 4D: release reserved (linked) payment before cancel commits.
            ($this->paymentAllocations ?? service('usdtPaymentAllocationService'))
                ->releaseReservedAllocationForPurchase(
                    $purchaseRef,
                    $memberUserId,
                    'purchase_cancelled'
                );

            $now = date('Y-m-d H:i:s');
            $db->table('coin_purchase_requests')->where('id', (int) $row['id'])->update([
                'status'     => self::STATUS_CANCELLED,
                'updated_at' => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to cancel purchase request.');
            }

            $db->transCommit();

            $updated = $this->requests->find((int) $row['id']);

            $this->audit->log(
                'coin.purchase_cancelled',
                $memberUserId,
                'coin_purchase_request',
                $purchaseRef,
                []
            );

            return ['request' => $updated];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Approve a pending request: deduct inventory once, credit ownership, activate member.
     *
     * @return array{
     *   request: array<string, mixed>,
     *   inventory: array<string, string>,
     *   ownership: array<string, string>,
     *   activated: bool
     * }
     */
    public function approveRequest(string $purchaseRef, int $adminUserId): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM coin_purchase_requests WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Purchase request not found.');
            }

            if ($row['status'] !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending purchase requests can be approved.');
            }

            $paymentMeta = $this->assertAndPreparePaymentForApproval($row, $adminUserId);

            $qty = (string) $row['coin_quantity'];
            $allocKey = 'alloc:' . $purchaseRef;
            $ownKey   = 'own:' . $purchaseRef;

            $allocation = $this->inventory->allocateForPurchase(
                $qty,
                $adminUserId,
                $purchaseRef,
                $allocKey
            );

            $ownership = $this->ownership->creditPurchase(
                (int) $row['member_user_id'],
                (string) $row['member_id'],
                $qty,
                $purchaseRef,
                $adminUserId,
                (string) $allocation['transaction']['transaction_ref'],
                $ownKey
            );

            $now = date('Y-m-d H:i:s');
            $db->table('coin_purchase_requests')->where('id', (int) $row['id'])->update([
                'status'                    => self::STATUS_APPROVED,
                'reviewed_by'               => $adminUserId,
                'reviewed_at'               => $now,
                'rejection_reason'          => null,
                'inventory_transaction_ref' => $allocation['transaction']['transaction_ref'],
                'ownership_transaction_ref' => $ownership['transaction']['transaction_ref'],
                'payment_deposit_ref'       => $paymentMeta['deposit_ref'] ?? ($row['payment_deposit_ref'] ?? null),
                'payment_status'            => $paymentMeta['payment_status'] ?? ($row['payment_status'] ?? 'unpaid'),
                'updated_at'                => $now,
            ]);

            $activated = false;
            $profile = $db->query(
                'SELECT * FROM member_profiles WHERE user_id = ? FOR UPDATE',
                [(int) $row['member_user_id']]
            )->getRowArray();

            if ($profile !== null && $profile['account_status'] === 'inactive') {
                $db->table('member_profiles')->where('id', (int) $profile['id'])->update([
                    'account_status' => 'active',
                    'updated_at'     => $now,
                ]);
                $activated = true;
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Purchase approval failed.');
            }

            $db->transCommit();

            $updated = $this->requests->find((int) $row['id']);

            $this->audit->log(
                'coin.purchase_approved',
                $adminUserId,
                'coin_purchase_request',
                $purchaseRef,
                [
                    'quantity'                  => $qty,
                    'inventory_transaction_ref' => $allocation['transaction']['transaction_ref'],
                    'ownership_transaction_ref' => $ownership['transaction']['transaction_ref'],
                    'activated'                 => $activated,
                ]
            );

            return [
                'request'   => $updated,
                'inventory' => $allocation['inventory'],
                'ownership' => $ownership['balance'],
                'activated' => $activated,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * @return array{request: array<string, mixed>}
     */
    public function rejectRequest(string $purchaseRef, int $adminUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM coin_purchase_requests WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Purchase request not found.');
            }

            if ($row['status'] !== self::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending purchase requests can be rejected.');
            }

            // Phase 4D: release reserved (linked) payment before reject commits.
            ($this->paymentAllocations ?? service('usdtPaymentAllocationService'))
                ->releaseReservedAllocationForPurchase(
                    $purchaseRef,
                    $adminUserId,
                    'purchase_rejected:' . $reason
                );

            $now = date('Y-m-d H:i:s');
            $db->table('coin_purchase_requests')->where('id', (int) $row['id'])->update([
                'status'           => self::STATUS_REJECTED,
                'rejection_reason' => $reason,
                'reviewed_by'      => $adminUserId,
                'reviewed_at'      => $now,
                'updated_at'       => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Purchase rejection failed.');
            }

            $db->transCommit();

            $updated = $this->requests->find((int) $row['id']);

            $this->audit->log(
                'coin.purchase_rejected',
                $adminUserId,
                'coin_purchase_request',
                $purchaseRef,
                ['reason' => $reason]
            );

            return ['request' => $updated];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    public function findOwnedByMember(int $memberUserId, string $purchaseRef): ?array
    {
        $row = $this->requests->findByRef($purchaseRef);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    public function findByRef(string $purchaseRef): ?array
    {
        return $this->requests->findByRef($purchaseRef);
    }

    /**
     * Phase 4B payment safety: require linked+verified payment and consume it atomically.
     *
     * @param array<string, mixed> $row
     * @return array{deposit_ref: ?string, payment_status: string}
     */
    private function assertAndPreparePaymentForApproval(array $row, int $adminUserId): array
    {
        if (! $this->requiresVerifiedDeposit()) {
            return [
                'deposit_ref'    => $row['payment_deposit_ref'] ?? null,
                'payment_status' => (string) ($row['payment_status'] ?? 'unpaid'),
            ];
        }

        $paymentStatus = (string) ($row['payment_status'] ?? 'unpaid');
        $allocService  = $this->paymentAllocations ?? service('usdtPaymentAllocationService');

        if ($paymentStatus === 'deposit_linked' && ! empty($row['payment_deposit_ref'])) {
            $allocService->consumeLinkedAllocationForApproval(
                (string) $row['purchase_ref'],
                $adminUserId
            );

            return [
                'deposit_ref'    => (string) $row['payment_deposit_ref'],
                'payment_status' => 'verified',
            ];
        }

        // Auto-link exact available verified deposit then consume (same member, exact amount).
        $depositService = $this->deposits ?? service('usdtDepositService');
        $available = $depositService->findAvailableVerifiedDepositForAmount(
            (int) $row['member_user_id'],
            (string) $row['total_usdt']
        );

        if ($available === null) {
            throw new InvalidArgumentException(
                'Purchase approval requires a verified USDT deposit with an exact amount match, '
                . 'explicitly linked (or available to auto-link) for this member. '
                . 'Screenshots and hashes alone are not payment.'
            );
        }

        $allocService->linkDepositToPurchase(
            (int) $row['member_user_id'],
            (string) $available['deposit_ref'],
            (string) $row['purchase_ref']
        );
        $allocService->consumeLinkedAllocationForApproval(
            (string) $row['purchase_ref'],
            $adminUserId
        );

        return [
            'deposit_ref'    => (string) $available['deposit_ref'],
            'payment_status' => 'verified',
        ];
    }

    private function generatePurchaseRef(): string
    {
        return 'PUR-' . strtoupper(bin2hex(random_bytes(8)));
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }
}
