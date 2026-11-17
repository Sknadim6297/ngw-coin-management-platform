<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinPurchaseRequestModel;
use App\Models\UsdtDepositRequestModel;
use App\Models\UsdtPaymentAllocationModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Explicit 1:1 verified-deposit ↔ pending-purchase linking, consumption, and release.
 *
 * State machine (DB values):
 * - linked   (reserved) → consumed  (purchase approval only)
 * - linked   (reserved) → released  (pending cancel/reject only)
 * - consumed → released             FORBIDDEN without separate remediation (not auto-executed)
 * - released → consumed             FORBIDDEN
 *
 * Partial payments, splits, and refunds are rejected (not silently adjusted).
 */
class UsdtPaymentAllocationService
{
    /** Reserved / held for a pending purchase (spec: reserved). */
    public const STATUS_RESERVED = 'linked';
    public const STATUS_LINKED   = 'linked';
    public const STATUS_CONSUMED = 'consumed';
    public const STATUS_RELEASED = 'released';

    public function __construct(
        private readonly UsdtPaymentAllocationModel $allocations = new UsdtPaymentAllocationModel(),
        private readonly UsdtDepositRequestModel $deposits = new UsdtDepositRequestModel(),
        private readonly CoinPurchaseRequestModel $purchases = new CoinPurchaseRequestModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{allocation: array<string, mixed>, deposit: array<string, mixed>, purchase: array<string, mixed>}
     */
    public function linkDepositToPurchase(
        int $memberUserId,
        string $depositRef,
        string $purchaseRef
    ): array {
        $scale = ($this->config ?? config('Platform'))->usdtScale;
        $db    = db_connect();
        $db->transBegin();

        try {
            $deposit = $db->query(
                'SELECT * FROM usdt_deposit_requests WHERE deposit_ref = ? FOR UPDATE',
                [$depositRef]
            )->getRowArray();
            $purchase = $db->query(
                'SELECT * FROM coin_purchase_requests WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();

            if ($deposit === null || $purchase === null) {
                throw new InvalidArgumentException('Deposit or purchase request not found.');
            }

            if ((int) $deposit['member_user_id'] !== $memberUserId
                || (int) $purchase['member_user_id'] !== $memberUserId) {
                throw new InvalidArgumentException('Cross-member payment linking is not allowed.');
            }

            if ($deposit['status'] !== UsdtDepositService::STATUS_VERIFIED) {
                throw new InvalidArgumentException('Only verified deposits can be linked.');
            }

            if (($deposit['payment_consumption_status'] ?? 'available') !== 'available') {
                throw new InvalidArgumentException('Deposit payment is already linked or consumed.');
            }

            if ($purchase['status'] !== CoinPurchaseService::STATUS_PENDING) {
                throw new InvalidArgumentException('Only pending purchases can accept a deposit link.');
            }

            // Soft Buy Coins association may already store the same deposit_ref while unpaid.
            if (! empty($purchase['payment_deposit_ref'])
                && (string) $purchase['payment_deposit_ref'] !== $depositRef) {
                throw new InvalidArgumentException('Purchase already has a linked deposit.');
            }
            if (($purchase['payment_status'] ?? '') === 'deposit_linked'
                || ($purchase['payment_status'] ?? '') === 'verified') {
                throw new InvalidArgumentException('Purchase already has a linked deposit.');
            }

            $verified = MoneyMath::normalize((string) $deposit['verified_amount_usdt'], $scale);
            $total    = MoneyMath::normalize((string) $purchase['total_usdt'], $scale);

            if (MoneyMath::compare($verified, $total, $scale) !== 0) {
                throw new InvalidArgumentException(
                    'Verified deposit amount (' . $verified . ') must exactly equal purchase total ('
                    . $total . '). Partial payments, overpayments, and splits are not supported.'
                );
            }

            $activeDeposit = $db->query(
                'SELECT id FROM usdt_payment_allocations WHERE deposit_active_key = ? FOR UPDATE',
                [$depositRef]
            )->getRowArray();
            $activePurchase = $db->query(
                'SELECT id FROM usdt_payment_allocations WHERE purchase_active_key = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();

            if ($activeDeposit !== null || $activePurchase !== null) {
                throw new InvalidArgumentException('An active allocation already exists for this deposit or purchase.');
            }

            $now = date('Y-m-d H:i:s');
            $ref = 'PAY-' . strtoupper(bin2hex(random_bytes(8)));

            $id = $this->allocations->insert([
                'allocation_ref'      => $ref,
                'deposit_ref'         => $depositRef,
                'deposit_active_key'  => $depositRef,
                'purchase_ref'        => $purchaseRef,
                'purchase_active_key' => $purchaseRef,
                'member_user_id'      => $memberUserId,
                'amount_usdt'         => $total,
                'status'              => self::STATUS_RESERVED,
                'linked_by'           => $memberUserId,
                'linked_at'           => $now,
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to create payment allocation.');
            }

            $db->table('usdt_deposit_requests')->where('id', (int) $deposit['id'])->update([
                'linked_purchase_ref'        => $purchaseRef,
                'payment_consumption_status' => 'linked',
                'updated_at'                 => $now,
            ]);

            $db->table('coin_purchase_requests')->where('id', (int) $purchase['id'])->update([
                'payment_deposit_ref' => $depositRef,
                'payment_status'      => 'deposit_linked',
                'updated_at'          => $now,
            ]);

            $this->recordEvent($ref, $depositRef, $purchaseRef, $memberUserId, 'allocation.reserved', null, self::STATUS_RESERVED, [
                'amount' => $total,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Payment linking failed.');
            }

            $db->transCommit();

            $allocation = $this->allocations->find($id);
            $this->audit->log('usdt.payment_linked', $memberUserId, 'usdt_payment_allocation', $ref, [
                'deposit_ref'  => $depositRef,
                'purchase_ref' => $purchaseRef,
                'amount'       => $total,
            ]);

            return [
                'allocation' => $allocation,
                'deposit'    => $this->deposits->findByRef($depositRef),
                'purchase'   => $this->purchases->findByRef($purchaseRef),
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Consume a reserved (linked) allocation inside an already-open DB transaction.
     *
     * @return array<string, mixed>
     */
    public function consumeLinkedAllocationForApproval(
        string $purchaseRef,
        int $adminUserId
    ): array {
        $db = db_connect();

        $allocation = $db->query(
            'SELECT * FROM usdt_payment_allocations WHERE purchase_ref = ? AND purchase_active_key IS NOT NULL FOR UPDATE',
            [$purchaseRef]
        )->getRowArray();

        if ($allocation === null) {
            // Fallback for pre-migration rows / tests mid-upgrade.
            $allocation = $db->query(
                'SELECT * FROM usdt_payment_allocations WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();
        }

        if ($allocation === null) {
            throw new InvalidArgumentException('No linked payment allocation found for this purchase.');
        }

        if ($allocation['status'] === self::STATUS_RELEASED) {
            throw new InvalidArgumentException('Released allocations cannot be consumed.');
        }

        if ($allocation['status'] !== self::STATUS_RESERVED) {
            throw new InvalidArgumentException('Payment allocation is not in a consumable reserved/linked state.');
        }

        $deposit = $db->query(
            'SELECT * FROM usdt_deposit_requests WHERE deposit_ref = ? FOR UPDATE',
            [$allocation['deposit_ref']]
        )->getRowArray();

        if ($deposit === null || $deposit['status'] !== UsdtDepositService::STATUS_VERIFIED) {
            throw new InvalidArgumentException('Linked deposit is not verified.');
        }

        if (($deposit['payment_consumption_status'] ?? '') !== 'linked') {
            throw new InvalidArgumentException('Linked deposit is not available for consumption.');
        }

        $now = date('Y-m-d H:i:s');

        $db->table('usdt_payment_allocations')->where('id', (int) $allocation['id'])->update([
            'status'              => self::STATUS_CONSUMED,
            'deposit_active_key'  => $allocation['deposit_ref'],
            'purchase_active_key' => $allocation['purchase_ref'],
            'consumed_by'         => $adminUserId,
            'consumed_at'         => $now,
            'updated_at'          => $now,
        ]);

        $db->table('usdt_deposit_requests')->where('id', (int) $deposit['id'])->update([
            'payment_consumption_status' => 'consumed',
            'payment_consumed_at'        => $now,
            'updated_at'                 => $now,
        ]);

        $this->recordEvent(
            (string) $allocation['allocation_ref'],
            (string) $allocation['deposit_ref'],
            (string) $allocation['purchase_ref'],
            $adminUserId,
            'allocation.consumed',
            self::STATUS_RESERVED,
            self::STATUS_CONSUMED,
            []
        );

        return $allocation;
    }

    /**
     * Release a reserved allocation for pending purchase cancel/reject.
     * Must run inside caller's open transaction with purchase row locked.
     * Idempotent if already released for this purchase.
     *
     * @return array{released: bool, allocation: ?array<string, mixed>}
     */
    public function releaseReservedAllocationForPurchase(
        string $purchaseRef,
        int $actorUserId,
        string $reason
    ): array {
        $db = db_connect();

        $allocation = $db->query(
            'SELECT * FROM usdt_payment_allocations WHERE purchase_ref = ? FOR UPDATE',
            [$purchaseRef]
        )->getRowArray();

        if ($allocation === null) {
            return ['released' => false, 'allocation' => null];
        }

        if ($allocation['status'] === self::STATUS_RELEASED) {
            // Idempotent duplicate release.
            return ['released' => true, 'allocation' => $allocation];
        }

        if ($allocation['status'] === self::STATUS_CONSUMED) {
            throw new InvalidArgumentException(
                'Consumed allocations cannot be released via cancel/reject. Use dual-control remediation investigation.'
            );
        }

        if ($allocation['status'] !== self::STATUS_RESERVED) {
            throw new InvalidArgumentException('Allocation is not in a releasable reserved/linked state.');
        }

        $deposit = $db->query(
            'SELECT * FROM usdt_deposit_requests WHERE deposit_ref = ? FOR UPDATE',
            [$allocation['deposit_ref']]
        )->getRowArray();

        if ($deposit === null) {
            throw new RuntimeException('Allocation deposit row missing.');
        }

        // Preserve verified deposit; restore availability only.
        if ($deposit['status'] !== UsdtDepositService::STATUS_VERIFIED) {
            throw new InvalidArgumentException('Linked deposit is no longer verified; escalate for investigation.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('usdt_payment_allocations')->where('id', (int) $allocation['id'])->update([
            'status'              => self::STATUS_RELEASED,
            'deposit_active_key'  => null,
            'purchase_active_key' => null,
            'released_by'         => $actorUserId,
            'released_at'         => $now,
            'release_reason'      => mb_substr(trim($reason), 0, 255),
            'updated_at'          => $now,
        ]);

        $db->table('usdt_deposit_requests')->where('id', (int) $deposit['id'])->update([
            'linked_purchase_ref'        => null,
            'payment_consumption_status' => 'available',
            'payment_consumed_at'        => null,
            'updated_at'                 => $now,
        ]);

        // Clear purchase payment pointer if still pointing at this deposit.
        $db->table('coin_purchase_requests')
            ->where('purchase_ref', $purchaseRef)
            ->where('payment_deposit_ref', $allocation['deposit_ref'])
            ->update([
                'payment_deposit_ref' => null,
                'payment_status'      => 'unpaid',
                'updated_at'          => $now,
            ]);

        $this->recordEvent(
            (string) $allocation['allocation_ref'],
            (string) $allocation['deposit_ref'],
            (string) $allocation['purchase_ref'],
            $actorUserId,
            'allocation.released',
            self::STATUS_RESERVED,
            self::STATUS_RELEASED,
            ['reason' => $reason]
        );

        $this->audit->log('usdt.payment_released', $actorUserId, 'usdt_payment_allocation', (string) $allocation['allocation_ref'], [
            'deposit_ref'  => $allocation['deposit_ref'],
            'purchase_ref' => $purchaseRef,
            'reason'       => $reason,
        ]);

        $updated = $db->table('usdt_payment_allocations')->where('id', (int) $allocation['id'])->get()->getRowArray();

        return ['released' => true, 'allocation' => $updated];
    }

    public function findForPurchase(string $purchaseRef): ?array
    {
        return $this->allocations->findByPurchaseRef($purchaseRef);
    }

    public function findActiveForDeposit(string $depositRef): ?array
    {
        return $this->allocations->where('deposit_active_key', $depositRef)->first();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(
        string $allocationRef,
        string $depositRef,
        string $purchaseRef,
        ?int $actorUserId,
        string $eventType,
        ?string $previous,
        ?string $newStatus,
        array $metadata
    ): void {
        db_connect()->table('usdt_payment_allocation_events')->insert([
            'allocation_ref'  => $allocationRef,
            'deposit_ref'     => $depositRef,
            'purchase_ref'    => $purchaseRef,
            'actor_user_id'   => $actorUserId,
            'event_type'      => $eventType,
            'previous_status' => $previous,
            'new_status'      => $newStatus,
            'metadata'        => json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }
}
