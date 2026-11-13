<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinInventoryTransactionModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

class CoinLedgerService
{
    public const TYPE_ADMIN_ADD         = 'ADMIN_INVENTORY_ADD';
    public const TYPE_ADMIN_CORRECTION  = 'ADMIN_INVENTORY_CORRECTION';
    public const TYPE_MEMBER_ALLOCATION = 'MEMBER_PURCHASE_ALLOCATION';
    public const TYPE_PURCHASE_REVERSAL = 'PURCHASE_REVERSAL';

    public function __construct(
        private readonly CoinInventoryTransactionModel $transactions = new CoinInventoryTransactionModel(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Append an immutable ledger entry. Caller must hold inventory row lock
     * and manage the surrounding DB transaction.
     *
     * @param array{
     *   type: string,
     *   direction: 'credit'|'debit',
     *   quantity: string,
     *   previous_balance: string,
     *   new_balance: string,
     *   actor_user_id?: ?int,
     *   reference?: ?string,
     *   reason?: ?string,
     *   idempotency_key?: ?string,
     *   metadata?: array<string, mixed>
     * } $payload
     * @return array<string, mixed>
     */
    public function append(array $payload): array
    {
        $scale = ($this->config ?? config('Platform'))->coinQuantityScale;
        $qty   = MoneyMath::normalize((string) $payload['quantity'], $scale);

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Ledger quantity must be positive.');
        }

        if (! in_array($payload['direction'], ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Invalid ledger direction.');
        }

        $ref = $this->generateRef();

        $id = $this->transactions->insert([
            'transaction_ref'  => $ref,
            'type'             => $payload['type'],
            'direction'        => $payload['direction'],
            'quantity'         => $qty,
            'previous_balance' => MoneyMath::normalize((string) $payload['previous_balance'], $scale),
            'new_balance'      => MoneyMath::normalize((string) $payload['new_balance'], $scale),
            'actor_user_id'    => $payload['actor_user_id'] ?? null,
            'reference'        => $payload['reference'] ?? null,
            'reason'           => $payload['reason'] ?? null,
            'idempotency_key'  => $payload['idempotency_key'] ?? null,
            'metadata'         => isset($payload['metadata'])
                ? json_encode($payload['metadata'], JSON_THROW_ON_ERROR)
                : null,
        ], true);

        if ($id === false) {
            throw new RuntimeException('Unable to write inventory ledger entry.');
        }

        $row = $this->transactions->find($id);
        if ($row === null) {
            throw new RuntimeException('Ledger entry not found after insert.');
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 50, int $offset = 0): array
    {
        return $this->transactions
            ->orderBy('id', 'DESC')
            ->findAll($limit, $offset);
    }

    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->transactions->findByIdempotencyKey($key);
    }

    private function generateRef(): string
    {
        return 'INV-' . strtoupper(bin2hex(random_bytes(8)));
    }
}
