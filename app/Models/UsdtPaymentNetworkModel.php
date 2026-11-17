<?php

declare(strict_types=1);

namespace App\Models;

use CodeIgniter\Model;

class UsdtPaymentNetworkModel extends Model
{
    protected $table            = 'usdt_payment_networks';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $useTimestamps    = true;
    protected $allowedFields    = [
        'network_key',
        'network_name',
        'chain_id',
        'verifier_type',
        'token_symbol',
        'token_contract_address',
        'token_decimals',
        'destination_wallet',
        'address_pattern',
        'tx_hash_pattern',
        'required_confirmations',
        'min_deposit_usdt',
        'max_deposit_usdt',
        'instructions',
        'recipient_name',
        'logo_stored_name',
        'logo_mime',
        'qr_stored_name',
        'qr_mime',
        'qr_source',
        'qr_decoded_payload',
        'qr_verified_at',
        'explorer_tx_url_template',
        'rpc_url_env_key',
        'is_enabled',
        'is_demo',
        'display_status',
        'sort_order',
    ];

    public function findByKey(string $key): ?array
    {
        return $this->where('network_key', $key)->first();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabledWithWallet(): array
    {
        $rows = $this->where('is_enabled', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('id', 'ASC')
            ->findAll();

        return array_values(array_filter(
            $rows,
            static function (array $row): bool {
                if (trim((string) ($row['destination_wallet'] ?? '')) === '') {
                    return false;
                }
                if (! empty($row['is_demo'])) {
                    return false;
                }
                $display = strtolower((string) ($row['display_status'] ?? 'live'));

                // Legacy rows without display_status column value treat enabled+wallet as visible.
                return $display === '' || $display === 'live';
            }
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allOrdered(): array
    {
        return $this->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();
    }
}
