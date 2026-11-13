<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberCoinBalanceModel;
use App\Models\MemberCoinOwnershipTransactionModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Append-only member coin ownership ledger + cached balances.
 *
 * Invariant: balance (total owned) = available_balance + locked_balance.
 */
class MemberCoinOwnershipService
{
    public const TYPE_PURCHASE_CREDIT = 'PURCHASE_CREDIT';
    public const TYPE_LOCK            = 'LOCK';
    public const TYPE_UNLOCK          = 'UNLOCK';

    public function __construct(
        private readonly MemberCoinBalanceModel $balances = new MemberCoinBalanceModel(),
        private readonly MemberCoinOwnershipTransactionModel $transactions = new MemberCoinOwnershipTransactionModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   balance: string,
     *   available: string,
     *   locked: string,
     *   total_acquired: string,
     *   member_id: ?string
     * }
     */
    public function summaryForUser(int $memberUserId): array
    {
        $scale = $this->scale();
        $row   = $this->balances->findByUserId($memberUserId);

        if ($row === null) {
            $zero = MoneyMath::normalize('0', $scale);

            return [
                'balance'        => $zero,
                'available'      => $zero,
                'locked'         => $zero,
                'total_acquired' => $zero,
                'member_id'      => null,
            ];
        }

        return $this->normalizeBalanceRow($row, $scale);
    }

    /**
     * Credit ownership for an approved purchase. Nested-transaction safe.
     *
     * @return array{balance: array<string, string|null>, transaction: array<string, mixed>, duplicate?: bool}
     */
    public function creditPurchase(
        int $memberUserId,
        string $memberId,
        string $quantity,
        string $purchaseRef,
        int $actorUserId,
        ?string $inventoryTransactionRef = null,
        ?string $idempotencyKey = null
    ): array {
        $scale = $this->scale();
        $qty   = MoneyMath::normalize($quantity, $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Ownership credit quantity must be greater than zero.');
        }

        if (trim($purchaseRef) === '') {
            throw new InvalidArgumentException('Purchase reference is required.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'balance'     => $this->summaryForUser($memberUserId),
                    'transaction' => $existing,
                    'duplicate'   => true,
                ];
            }
        }

        $byPurchase = $this->transactions->findByPurchaseRef($purchaseRef);
        if ($byPurchase !== null) {
            return [
                'balance'     => $this->summaryForUser($memberUserId),
                'transaction' => $byPurchase,
                'duplicate'   => true,
            ];
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockOrCreateBalance($db, $memberUserId, $memberId, $scale);
            $now = date('Y-m-d H:i:s');

            $prevTotal     = MoneyMath::normalize((string) $row['balance'], $scale);
            $prevAvailable = MoneyMath::normalize((string) ($row['available_balance'] ?? $row['balance']), $scale);
            $prevLocked    = MoneyMath::normalize((string) ($row['locked_balance'] ?? '0'), $scale);
            $this->assertInvariant($prevTotal, $prevAvailable, $prevLocked, $scale);

            $newAvailable = MoneyMath::add($prevAvailable, $qty, $scale);
            $newLocked    = $prevLocked;
            $newTotal     = MoneyMath::add($newAvailable, $newLocked, $scale);
            $acquired     = MoneyMath::add((string) $row['total_acquired'], $qty, $scale);

            $db->table('member_coin_balances')->where('id', (int) $row['id'])->update([
                'balance'           => $newTotal,
                'available_balance' => $newAvailable,
                'locked_balance'    => $newLocked,
                'total_acquired'    => $acquired,
                'updated_at'        => $now,
            ]);

            $ref = 'OWN-' . strtoupper(bin2hex(random_bytes(8)));
            $id  = $this->transactions->insert([
                'transaction_ref'           => $ref,
                'member_user_id'            => $memberUserId,
                'member_id'                 => $memberId,
                'type'                      => self::TYPE_PURCHASE_CREDIT,
                'direction'                 => 'credit',
                'quantity'                  => $qty,
                'previous_balance'          => $prevTotal,
                'new_balance'               => $newTotal,
                'previous_available'        => $prevAvailable,
                'new_available'             => $newAvailable,
                'previous_locked'           => $prevLocked,
                'new_locked'                => $newLocked,
                'purchase_ref'              => $purchaseRef,
                'inventory_transaction_ref' => $inventoryTransactionRef,
                'actor_user_id'             => $actorUserId,
                'idempotency_key'           => $idempotencyKey,
                'metadata'                  => json_encode(['source' => 'approved_purchase'], JSON_THROW_ON_ERROR),
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to write ownership ledger entry.');
            }

            $txn = $this->transactions->find($id);
            if ($txn === null) {
                throw new RuntimeException('Ownership ledger entry missing after insert.');
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Ownership credit failed.');
            }

            $db->transCommit();

            return [
                'balance'     => [
                    'balance'        => $newTotal,
                    'available'      => $newAvailable,
                    'locked'         => $newLocked,
                    'total_acquired' => $acquired,
                    'member_id'      => $memberId,
                ],
                'transaction' => $txn,
                'duplicate'   => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($idempotencyKey && $this->isDuplicateKeyException($e)) {
                $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return [
                        'balance'     => $this->summaryForUser($memberUserId),
                        'transaction' => $existing,
                        'duplicate'   => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * Move available → locked. Total owned unchanged.
     * Caller must hold/own the outer transaction when composing with lock rows.
     *
     * @return array{balance: array<string, string|null>, transaction: array<string, mixed>, duplicate?: bool}
     */
    public function applyLock(
        int $memberUserId,
        string $memberId,
        string $quantity,
        string $lockRef,
        int $actorUserId,
        ?string $idempotencyKey = null,
        bool $manageTransaction = true
    ): array {
        return $this->applyLockTransfer(
            self::TYPE_LOCK,
            $memberUserId,
            $memberId,
            $quantity,
            $lockRef,
            $actorUserId,
            $idempotencyKey,
            $manageTransaction
        );
    }

    /**
     * Move locked → available. Total owned unchanged.
     *
     * @return array{balance: array<string, string|null>, transaction: array<string, mixed>, duplicate?: bool}
     */
    public function applyUnlock(
        int $memberUserId,
        string $memberId,
        string $quantity,
        string $lockRef,
        int $actorUserId,
        ?string $idempotencyKey = null,
        bool $manageTransaction = true
    ): array {
        return $this->applyLockTransfer(
            self::TYPE_UNLOCK,
            $memberUserId,
            $memberId,
            $quantity,
            $lockRef,
            $actorUserId,
            $idempotencyKey,
            $manageTransaction
        );
    }

    /**
     * @return array{balance: array<string, string|null>, transaction: array<string, mixed>, duplicate?: bool}
     */
    private function applyLockTransfer(
        string $type,
        int $memberUserId,
        string $memberId,
        string $quantity,
        string $lockRef,
        int $actorUserId,
        ?string $idempotencyKey,
        bool $manageTransaction
    ): array {
        $scale = $this->scale();
        $qty   = MoneyMath::normalize($quantity, $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Lock quantity must be greater than zero.');
        }
        if (trim($lockRef) === '') {
            throw new InvalidArgumentException('Lock reference is required.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'balance'     => $this->summaryForUser($memberUserId),
                    'transaction' => $existing,
                    'duplicate'   => true,
                ];
            }
        }

        $db = db_connect();
        if ($manageTransaction) {
            $db->transBegin();
        }

        try {
            $row = $db->query(
                'SELECT * FROM member_coin_balances WHERE member_user_id = ? FOR UPDATE',
                [$memberUserId]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('No coin balance available to lock.');
            }

            $prevTotal     = MoneyMath::normalize((string) $row['balance'], $scale);
            $prevAvailable = MoneyMath::normalize((string) ($row['available_balance'] ?? $row['balance']), $scale);
            $prevLocked    = MoneyMath::normalize((string) ($row['locked_balance'] ?? '0'), $scale);
            $this->assertInvariant($prevTotal, $prevAvailable, $prevLocked, $scale);

            if ($type === self::TYPE_LOCK) {
                if (MoneyMath::compare($prevAvailable, $qty, $scale) < 0) {
                    throw new InvalidArgumentException('Insufficient available coins to lock.');
                }
                $newAvailable = MoneyMath::sub($prevAvailable, $qty, $scale);
                $newLocked    = MoneyMath::add($prevLocked, $qty, $scale);
                $direction    = 'debit';
            } else {
                if (MoneyMath::compare($prevLocked, $qty, $scale) < 0) {
                    throw new InvalidArgumentException('Insufficient locked coins to unlock.');
                }
                $newAvailable = MoneyMath::add($prevAvailable, $qty, $scale);
                $newLocked    = MoneyMath::sub($prevLocked, $qty, $scale);
                $direction    = 'credit';
            }

            $newTotal = MoneyMath::add($newAvailable, $newLocked, $scale);
            if (MoneyMath::compare($newTotal, $prevTotal, $scale) !== 0) {
                throw new RuntimeException('Lock transfer must not change total owned balance.');
            }
            if (MoneyMath::isNegative($newAvailable, $scale) || MoneyMath::isNegative($newLocked, $scale)) {
                throw new RuntimeException('Negative balances are not allowed.');
            }

            $now = date('Y-m-d H:i:s');
            $db->table('member_coin_balances')->where('id', (int) $row['id'])->update([
                'balance'           => $newTotal,
                'available_balance' => $newAvailable,
                'locked_balance'    => $newLocked,
                'updated_at'        => $now,
            ]);

            $ref = 'OWN-' . strtoupper(bin2hex(random_bytes(8)));
            $id  = $this->transactions->insert([
                'transaction_ref'    => $ref,
                'member_user_id'     => $memberUserId,
                'member_id'          => $memberId,
                'type'               => $type,
                'direction'          => $direction,
                'quantity'           => $qty,
                'previous_balance'   => $prevTotal,
                'new_balance'        => $newTotal,
                'previous_available' => $prevAvailable,
                'new_available'      => $newAvailable,
                'previous_locked'    => $prevLocked,
                'new_locked'         => $newLocked,
                'lock_ref'           => $lockRef,
                'actor_user_id'      => $actorUserId,
                'idempotency_key'    => $idempotencyKey,
                'metadata'           => json_encode([
                    'source' => $type === self::TYPE_LOCK ? 'coin_lock' : 'coin_unlock',
                    'lock_ref' => $lockRef,
                ], JSON_THROW_ON_ERROR),
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to write ownership lock ledger entry.');
            }

            $txn = $this->transactions->find($id);
            if ($txn === null) {
                throw new RuntimeException('Ownership lock ledger entry missing after insert.');
            }

            if ($manageTransaction) {
                if ($db->transStatus() === false) {
                    $db->transRollback();
                    throw new RuntimeException('Ownership lock transfer failed.');
                }
                $db->transCommit();
            }

            return [
                'balance'     => [
                    'balance'        => $newTotal,
                    'available'      => $newAvailable,
                    'locked'         => $newLocked,
                    'total_acquired' => MoneyMath::normalize((string) $row['total_acquired'], $scale),
                    'member_id'      => $memberId,
                ],
                'transaction' => $txn,
                'duplicate'   => false,
            ];
        } catch (\Throwable $e) {
            if ($manageTransaction && $db->transDepth > 0) {
                $db->transRollback();
            }

            if ($idempotencyKey && $this->isDuplicateKeyException($e)) {
                $existing = $this->transactions->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return [
                        'balance'     => $this->summaryForUser($memberUserId),
                        'transaction' => $existing,
                        'duplicate'   => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{balance: string, available: string, locked: string, total_acquired: string, member_id: string}
     */
    private function normalizeBalanceRow(array $row, int $scale): array
    {
        $total     = MoneyMath::normalize((string) $row['balance'], $scale);
        $available = MoneyMath::normalize((string) ($row['available_balance'] ?? $row['balance']), $scale);
        $locked    = MoneyMath::normalize((string) ($row['locked_balance'] ?? '0'), $scale);
        $this->assertInvariant($total, $available, $locked, $scale);

        return [
            'balance'        => $total,
            'available'      => $available,
            'locked'         => $locked,
            'total_acquired' => MoneyMath::normalize((string) $row['total_acquired'], $scale),
            'member_id'      => (string) $row['member_id'],
        ];
    }

    private function assertInvariant(string $total, string $available, string $locked, int $scale): void
    {
        $sum = MoneyMath::add($available, $locked, $scale);
        if (MoneyMath::compare($total, $sum, $scale) !== 0) {
            throw new RuntimeException('Coin balance invariant violated: total must equal available + locked.');
        }
        if (MoneyMath::isNegative($available, $scale) || MoneyMath::isNegative($locked, $scale) || MoneyMath::isNegative($total, $scale)) {
            throw new RuntimeException('Negative coin balances are not allowed.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockOrCreateBalance($db, int $memberUserId, string $memberId, int $scale): array
    {
        $row = $db->query(
            'SELECT * FROM member_coin_balances WHERE member_user_id = ? FOR UPDATE',
            [$memberUserId]
        )->getRowArray();

        $now  = date('Y-m-d H:i:s');
        $zero = MoneyMath::normalize('0', $scale);

        if ($row === null) {
            $db->table('member_coin_balances')->insert([
                'member_user_id'    => $memberUserId,
                'member_id'         => $memberId,
                'balance'           => $zero,
                'available_balance' => $zero,
                'locked_balance'    => $zero,
                'total_acquired'    => $zero,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

            $row = $db->query(
                'SELECT * FROM member_coin_balances WHERE member_user_id = ? FOR UPDATE',
                [$memberUserId]
            )->getRowArray();
        }

        if ($row === null) {
            throw new RuntimeException('Unable to create member coin balance row.');
        }

        return $row;
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }
}
