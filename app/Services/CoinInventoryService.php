<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinInventoryModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

class CoinInventoryService
{
    public function __construct(
        private readonly CoinLedgerService $ledger = new CoinLedgerService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly CoinInventoryModel $inventory = new CoinInventoryModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   available_balance: string,
     *   total_added: string,
     *   total_allocated: string,
     *   is_available: bool
     * }
     */
    public function summary(): array
    {
        $row   = $this->inventory->singleton();
        $scale = $this->scale();

        $available = MoneyMath::normalize((string) $row['available_balance'], $scale);

        return [
            'available_balance' => $available,
            'total_added'       => MoneyMath::normalize((string) $row['total_added'], $scale),
            'total_allocated'   => MoneyMath::normalize((string) $row['total_allocated'], $scale),
            'is_available'      => MoneyMath::isPositive($available, $scale),
        ];
    }

    public function availableBalance(): string
    {
        return $this->summary()['available_balance'];
    }

    public function hasSufficientInventory(string $quantity): bool
    {
        $scale = $this->scale();
        $need  = MoneyMath::normalize($quantity, $scale);

        return MoneyMath::compare($this->availableBalance(), $need, $scale) >= 0;
    }

    /**
     * @return array{inventory: array<string, string>, transaction: array<string, mixed>}
     */
    public function addInventory(
        string $quantity,
        int $adminUserId,
        ?string $reference = null,
        ?string $remarks = null,
        ?string $idempotencyKey = null
    ): array {
        $scale = $this->scale();
        $qty   = MoneyMath::normalize($quantity, $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Coin quantity must be greater than zero.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->ledger->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'inventory'   => $this->summary(),
                    'transaction' => $existing,
                    'duplicate'   => true,
                ];
            }
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query('SELECT * FROM coin_inventory WHERE id = 1 FOR UPDATE')->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Inventory row missing.');
            }

            $previous = MoneyMath::normalize((string) $row['available_balance'], $scale);
            $newBal   = MoneyMath::add($previous, $qty, $scale);
            $totalAdd = MoneyMath::add((string) $row['total_added'], $qty, $scale);

            $db->table('coin_inventory')->where('id', 1)->update([
                'available_balance' => $newBal,
                'total_added'       => $totalAdd,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            $txn = $this->ledger->append([
                'type'             => CoinLedgerService::TYPE_ADMIN_ADD,
                'direction'        => 'credit',
                'quantity'         => $qty,
                'previous_balance' => $previous,
                'new_balance'      => $newBal,
                'actor_user_id'    => $adminUserId,
                'reference'        => $reference,
                'reason'           => $remarks,
                'idempotency_key'  => $idempotencyKey,
                'metadata'         => [
                    'label' => 'Unverified internal inventory record',
                ],
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Inventory add failed.');
            }

            $db->transCommit();

            $this->audit->log(
                'coin.inventory_added',
                $adminUserId,
                'coin_inventory_transaction',
                (string) $txn['transaction_ref'],
                [
                    'quantity'     => $qty,
                    'new_balance'  => $newBal,
                    'reference'    => $reference,
                ]
            );

            return [
                'inventory'   => $this->summary(),
                'transaction' => $txn,
                'duplicate'   => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            // Race on unique idempotency_key: return existing entry.
            if ($idempotencyKey && $this->isDuplicateKeyException($e)) {
                $existing = $this->ledger->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return [
                        'inventory'   => $this->summary(),
                        'transaction' => $existing,
                        'duplicate'   => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * Correction entry. Positive quantity + credit increases stock;
     * positive quantity + debit decreases stock (cannot go negative).
     *
     * @return array{inventory: array<string, string>, transaction: array<string, mixed>}
     */
    public function correctInventory(
        string $quantity,
        string $direction,
        int $adminUserId,
        string $reason,
        ?string $reference = null,
        ?string $idempotencyKey = null
    ): array {
        $scale = $this->scale();
        $qty   = MoneyMath::normalize($quantity, $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Correction quantity must be greater than zero.');
        }

        if (! in_array($direction, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Correction direction must be credit or debit.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Correction reason is required.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->ledger->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'inventory'   => $this->summary(),
                    'transaction' => $existing,
                    'duplicate'   => true,
                ];
            }
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query('SELECT * FROM coin_inventory WHERE id = 1 FOR UPDATE')->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Inventory row missing.');
            }

            $previous = MoneyMath::normalize((string) $row['available_balance'], $scale);
            $newBal   = $direction === 'credit'
                ? MoneyMath::add($previous, $qty, $scale)
                : MoneyMath::sub($previous, $qty, $scale);

            if (MoneyMath::isNegative($newBal, $scale)) {
                throw new InvalidArgumentException('Correction would result in negative inventory.');
            }

            $db->table('coin_inventory')->where('id', 1)->update([
                'available_balance' => $newBal,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            $txn = $this->ledger->append([
                'type'             => CoinLedgerService::TYPE_ADMIN_CORRECTION,
                'direction'        => $direction,
                'quantity'         => $qty,
                'previous_balance' => $previous,
                'new_balance'      => $newBal,
                'actor_user_id'    => $adminUserId,
                'reference'        => $reference,
                'reason'           => $reason,
                'idempotency_key'  => $idempotencyKey,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Inventory correction failed.');
            }

            $db->transCommit();

            $this->audit->log(
                'coin.inventory_corrected',
                $adminUserId,
                'coin_inventory_transaction',
                (string) $txn['transaction_ref'],
                [
                    'quantity'  => $qty,
                    'direction' => $direction,
                    'reason'    => $reason,
                ]
            );

            return [
                'inventory'   => $this->summary(),
                'transaction' => $txn,
                'duplicate'   => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Ensures insufficient inventory is detectable before allocation.
     */
    public function assertSufficient(string $quantity): void
    {
        if (! $this->hasSufficientInventory($quantity)) {
            throw new InvalidArgumentException('Insufficient coin inventory for this operation.');
        }
    }

    /**
     * Allocate coins from inventory for an approved member purchase.
     * Caller may already hold an open DB transaction (nested-safe).
     *
     * @return array{inventory: array<string, string>, transaction: array<string, mixed>, duplicate?: bool}
     */
    public function allocateForPurchase(
        string $quantity,
        int $adminUserId,
        string $purchaseRef,
        ?string $idempotencyKey = null
    ): array {
        $scale = $this->scale();
        $qty   = MoneyMath::normalize($quantity, $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Allocation quantity must be greater than zero.');
        }

        if (trim($purchaseRef) === '') {
            throw new InvalidArgumentException('Purchase reference is required for allocation.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->ledger->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'inventory'   => $this->summary(),
                    'transaction' => $existing,
                    'duplicate'   => true,
                ];
            }
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query('SELECT * FROM coin_inventory WHERE id = 1 FOR UPDATE')->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Inventory row missing.');
            }

            $previous = MoneyMath::normalize((string) $row['available_balance'], $scale);
            if (MoneyMath::compare($previous, $qty, $scale) < 0) {
                throw new InvalidArgumentException('Insufficient coin inventory for this operation.');
            }

            $newBal     = MoneyMath::sub($previous, $qty, $scale);
            $allocated  = MoneyMath::add((string) $row['total_allocated'], $qty, $scale);

            $db->table('coin_inventory')->where('id', 1)->update([
                'available_balance' => $newBal,
                'total_allocated'   => $allocated,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);

            $txn = $this->ledger->append([
                'type'             => CoinLedgerService::TYPE_MEMBER_ALLOCATION,
                'direction'        => 'debit',
                'quantity'         => $qty,
                'previous_balance' => $previous,
                'new_balance'      => $newBal,
                'actor_user_id'    => $adminUserId,
                'reference'        => $purchaseRef,
                'reason'           => 'Approved member purchase allocation',
                'idempotency_key'  => $idempotencyKey,
                'metadata'         => [
                    'purchase_ref' => $purchaseRef,
                    'label'        => 'Internal inventory allocation for approved purchase request',
                ],
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Inventory allocation failed.');
            }

            $db->transCommit();

            return [
                'inventory'   => $this->summary(),
                'transaction' => $txn,
                'duplicate'   => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($idempotencyKey && $this->isDuplicateKeyException($e)) {
                $existing = $this->ledger->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return [
                        'inventory'   => $this->summary(),
                        'transaction' => $existing,
                        'duplicate'   => true,
                    ];
                }
            }

            throw $e;
        }
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
