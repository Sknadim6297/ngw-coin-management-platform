<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinPriceHistoryModel;
use App\Models\CoinSettingsModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

class CoinPriceService
{
    public function __construct(
        private readonly CoinSettingsModel $settings = new CoinSettingsModel(),
        private readonly CoinPriceHistoryModel $history = new CoinPriceHistoryModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    public function getCurrentPrice(): ?string
    {
        $row   = $this->settings->singleton();
        $price = $row['current_price_usdt'];

        if ($price === null || $price === '') {
            return null;
        }

        return MoneyMath::normalize((string) $price, $this->usdtScale());
    }

    public function getMinPurchaseUsdt(): string
    {
        $row = $this->settings->singleton();

        return MoneyMath::normalize((string) $row['min_purchase_usdt'], $this->usdtScale());
    }

    public function isPriceConfigured(): bool
    {
        return $this->getCurrentPrice() !== null;
    }

    /**
     * @return array{
     *   current_price_usdt: ?string,
     *   min_purchase_usdt: string,
     *   price_updated_at: ?string,
     *   price_updated_by: ?int
     * }
     */
    public function settingsSummary(): array
    {
        $row = $this->settings->singleton();

        return [
            'current_price_usdt' => $this->getCurrentPrice(),
            'min_purchase_usdt'  => MoneyMath::normalize((string) $row['min_purchase_usdt'], $this->usdtScale()),
            'price_updated_at'   => $row['price_updated_at'],
            'price_updated_by'   => $row['price_updated_by'] !== null ? (int) $row['price_updated_by'] : null,
        ];
    }

    /**
     * Set or change the current coin price. History is append-only.
     *
     * @return array{settings: array<string, mixed>, history: array<string, mixed>}
     */
    public function updatePrice(string $newPrice, int $adminUserId, string $reason): array
    {
        $scale = $this->usdtScale();
        $price = MoneyMath::normalize($newPrice, $scale);

        if (! MoneyMath::isPositive($price, $scale)) {
            throw new InvalidArgumentException('Coin price must be greater than zero.');
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason for price change is required.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query('SELECT * FROM coin_settings WHERE id = 1 FOR UPDATE')->getRowArray();
            if ($row === null) {
                throw new RuntimeException('Coin settings row missing.');
            }

            $previous = $row['current_price_usdt'] !== null
                ? MoneyMath::normalize((string) $row['current_price_usdt'], $scale)
                : null;

            $now = date('Y-m-d H:i:s');

            $historyId = $this->history->insert([
                'previous_price_usdt' => $previous,
                'new_price_usdt'      => $price,
                'actor_user_id'       => $adminUserId,
                'reason'              => trim($reason),
                'effective_at'        => $now,
            ], true);

            if ($historyId === false) {
                throw new RuntimeException('Unable to record price history.');
            }

            $db->table('coin_settings')->where('id', 1)->update([
                'current_price_usdt' => $price,
                'price_updated_at'   => $now,
                'price_updated_by'   => $adminUserId,
                'updated_at'         => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Price update failed.');
            }

            $db->transCommit();

            $historyRow = $this->history->find($historyId);

            $this->audit->log(
                'coin.price_updated',
                $adminUserId,
                'coin_price_history',
                (string) $historyId,
                [
                    'previous_price' => $previous,
                    'new_price'      => $price,
                    'reason'         => trim($reason),
                ]
            );

            return [
                'settings' => $this->settingsSummary(),
                'history'  => $historyRow,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Purchase-time quote from a USDT amount.
     * Snapshots the current price; later admin price changes must not rewrite this quote.
     *
     * @return array{
     *   usdt_amount: string,
     *   coin_price_usdt: string,
     *   coin_quantity: string,
     *   min_purchase_usdt: string,
     *   quoted_at: string
     * }
     */
    public function previewPurchase(string $usdtAmount): array
    {
        $scale = $this->usdtScale();
        $qtyScale = ($this->config ?? config('Platform'))->coinQuantityScale;
        $amount = MoneyMath::normalize($usdtAmount, $scale);

        if (! MoneyMath::isPositive($amount, $scale)) {
            throw new InvalidArgumentException('Purchase amount must be greater than zero.');
        }

        $min = $this->getMinPurchaseUsdt();
        if (MoneyMath::compare($amount, $min, $scale) < 0) {
            throw new InvalidArgumentException('Purchase amount is below the minimum of ' . $min . ' USDT.');
        }

        $price = $this->getCurrentPrice();
        if ($price === null) {
            throw new InvalidArgumentException('Coin price is not configured yet.');
        }

        $quantity = MoneyMath::div($amount, $price, $qtyScale);

        return [
            'usdt_amount'       => $amount,
            'coin_price_usdt'   => $price,
            'coin_quantity'     => $quantity,
            'min_purchase_usdt' => $min,
            'quoted_at'         => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Server-side quote from a requested coin quantity.
     * Never trusts client-submitted price or totals.
     *
     * @return array{
     *   usdt_amount: string,
     *   coin_price_usdt: string,
     *   coin_quantity: string,
     *   min_purchase_usdt: string,
     *   max_purchase_quantity: string,
     *   quoted_at: string
     * }
     */
    public function quoteByQuantity(string $coinQuantity): array
    {
        $config   = $this->config ?? config('Platform');
        $scale    = $this->usdtScale();
        $qtyScale = $config->coinQuantityScale;
        $quantity = MoneyMath::normalize($coinQuantity, $qtyScale);

        if (! MoneyMath::isPositive($quantity, $qtyScale)) {
            throw new InvalidArgumentException('Coin quantity must be greater than zero.');
        }

        $maxQty = MoneyMath::normalize((string) $config->maxPurchaseQuantity, $qtyScale);
        if (MoneyMath::compare($quantity, $maxQty, $qtyScale) > 0) {
            throw new InvalidArgumentException('Coin quantity exceeds the maximum of ' . $maxQty . '.');
        }

        $price = $this->getCurrentPrice();
        if ($price === null) {
            throw new InvalidArgumentException('Coin price is not configured yet.');
        }

        $total = MoneyMath::mul($quantity, $price, $scale);
        $min   = $this->getMinPurchaseUsdt();
        if (MoneyMath::compare($total, $min, $scale) < 0) {
            throw new InvalidArgumentException(
                'Purchase total is below the minimum of ' . $min . ' USDT.'
            );
        }

        return [
            'usdt_amount'           => $total,
            'coin_price_usdt'       => $price,
            'coin_quantity'         => $quantity,
            'min_purchase_usdt'     => $min,
            'max_purchase_quantity' => $maxQty,
            'quoted_at'             => date('Y-m-d H:i:s'),
        ];
    }

    /**
     * Recalculate quantity from a frozen purchase-time price snapshot.
     * Used to prove historical prices are not revalued.
     */
    public function quantityFromSnapshot(string $usdtAmount, string $purchasePrice): string
    {
        $scale    = $this->usdtScale();
        $qtyScale = ($this->config ?? config('Platform'))->coinQuantityScale;

        return MoneyMath::div(
            MoneyMath::normalize($usdtAmount, $scale),
            MoneyMath::normalize($purchasePrice, $scale),
            $qtyScale
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function priceHistory(int $limit = 50, int $offset = 0): array
    {
        return $this->history->orderBy('id', 'DESC')->findAll($limit, $offset);
    }

    private function usdtScale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale;
    }
}
