<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\UsdtPaymentNetworkModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

class UsdtPaymentNetworkService
{
    public function __construct(
        private readonly UsdtPaymentNetworkModel $networks = new UsdtPaymentNetworkModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly PaymentQrService $qr = new PaymentQrService(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAll(): array
    {
        return $this->networks->allOrdered();
    }

    /**
     * Member-visible live payment destinations only.
     * Demo/burn wallets and networks without a configured+matching on-chain verifier are excluded.
     *
     * @return list<array<string, mixed>>
     */
    public function listEnabledForMembers(): array
    {
        $rows = $this->networks->enabledWithWallet();
        $globalVerifier = strtolower((string) (config('Blockchain')->depositVerifier ?? 'not_configured'));

        $out = [];
        foreach ($rows as $row) {
            if ($this->qr->isDemoNetwork($row) || $this->qr->isBurnOrNullWallet((string) ($row['destination_wallet'] ?? ''))) {
                continue;
            }
            if (! $this->isLiveVerifierEligible($row, $globalVerifier)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $network
     */
    public function isLiveVerifierEligible(array $network, ?string $globalVerifier = null): bool
    {
        $globalVerifier ??= strtolower((string) (config('Blockchain')->depositVerifier ?? 'not_configured'));
        if ($globalVerifier === '' || $globalVerifier === 'not_configured') {
            return false;
        }

        $vt = strtolower((string) ($network['verifier_type'] ?? 'none'));
        if ($vt === 'none' || $vt === '') {
            return false;
        }

        if ($globalVerifier === 'evm_erc20') {
            return $vt === 'evm_erc20';
        }

        return $vt === $globalVerifier;
    }

    public function find(int $id): ?array
    {
        return $this->networks->find($id);
    }

    public function findByKey(string $key): ?array
    {
        return $this->networks->findByKey($key);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data, int $adminUserId): array
    {
        $payload = $this->normalizePayload($data, true);

        if ($this->networks->findByKey($payload['network_key']) !== null) {
            throw new InvalidArgumentException('Network key already exists.');
        }

        $id = $this->networks->insert($payload, true);
        if ($id === false) {
            throw new RuntimeException('Unable to create payment network.');
        }

        $row = $this->networks->find($id);
        $this->audit->log('usdt.network_created', $adminUserId, 'usdt_payment_network', (string) $id, [
            'network_key' => $payload['network_key'],
            'is_enabled'  => $payload['is_enabled'],
        ]);

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(int $id, array $data, int $adminUserId): array
    {
        $existing = $this->networks->find($id);
        if ($existing === null) {
            throw new InvalidArgumentException('Payment network not found.');
        }

        $payload = $this->normalizePayload($data, false, $existing);

        // network_key is immutable after create
        unset($payload['network_key']);

        if ($this->networks->update($id, $payload) === false) {
            throw new RuntimeException('Unable to update payment network.');
        }

        $row = $this->networks->find($id);
        $this->audit->log('usdt.network_updated', $adminUserId, 'usdt_payment_network', (string) $id, [
            'is_enabled' => $row['is_enabled'],
        ]);

        return $row;
    }

    public function assertDepositAllowed(array $network, string $amountUsdt): void
    {
        if ((int) $network['is_enabled'] !== 1) {
            throw new InvalidArgumentException('Selected payment network is disabled.');
        }

        if ($this->qr->isDemoNetwork($network)) {
            throw new InvalidArgumentException('Demo payment networks cannot accept live deposits.');
        }

        $wallet = trim((string) ($network['destination_wallet'] ?? ''));
        if ($wallet === '') {
            throw new InvalidArgumentException('Selected network has no destination wallet configured.');
        }

        if ($this->qr->isBurnOrNullWallet($wallet)) {
            throw new InvalidArgumentException('Burn or demo wallets cannot be used as payment destinations.');
        }

        $this->assertValidWallet($wallet, $network);

        $scale = $this->usdtScale();
        $amount = MoneyMath::normalize($amountUsdt, $scale);
        if (! MoneyMath::isPositive($amount, $scale)) {
            throw new InvalidArgumentException('Deposit amount must be greater than zero.');
        }

        $min = MoneyMath::normalize((string) $network['min_deposit_usdt'], $scale);
        $max = MoneyMath::normalize((string) $network['max_deposit_usdt'], $scale);

        if (MoneyMath::compare($amount, $min, $scale) < 0) {
            throw new InvalidArgumentException('Deposit amount is below the network minimum of ' . $min . ' USDT.');
        }

        if (MoneyMath::compare($amount, $max, $scale) > 0) {
            throw new InvalidArgumentException('Deposit amount exceeds the network maximum of ' . $max . ' USDT.');
        }
    }

    public function assertValidWallet(string $wallet, array $network): void
    {
        $pattern = trim((string) ($network['address_pattern'] ?? ''));
        if ($pattern === '') {
            $pattern = ($this->config ?? config('Platform'))->bep20Pattern;
        }

        if (@preg_match($pattern, $wallet) !== 1) {
            throw new InvalidArgumentException('Destination wallet address is invalid for this network.');
        }
    }

    public function assertValidTxHash(string $txHash, array $network): string
    {
        $normalized = strtolower(trim($txHash));
        if ($normalized === '') {
            throw new InvalidArgumentException('Transaction hash is required.');
        }

        $pattern = trim((string) ($network['tx_hash_pattern'] ?? ''));
        if ($pattern === '') {
            $pattern = '/^0x[a-fA-F0-9]{64}$/';
        }

        if (@preg_match($pattern, $normalized) !== 1 && @preg_match($pattern, $txHash) !== 1) {
            throw new InvalidArgumentException('Transaction hash format is invalid for this network.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function normalizePayload(array $data, bool $creating, ?array $existing = null): array
    {
        $scale = $this->usdtScale();
        $key   = strtolower(trim((string) ($data['network_key'] ?? ($existing['network_key'] ?? ''))));
        $name  = trim((string) ($data['network_name'] ?? ''));

        if ($creating && ($key === '' || ! preg_match('/^[a-z0-9_\-]{2,40}$/', $key))) {
            throw new InvalidArgumentException('Network key must be 2–40 characters: lowercase letters, numbers, _ or -.');
        }

        if ($name === '') {
            throw new InvalidArgumentException('Network name is required.');
        }

        $wallet = trim((string) ($data['destination_wallet'] ?? ''));
        $wallet = $wallet === '' ? null : $wallet;
        if ($wallet !== null && $this->qr->isBurnOrNullWallet($wallet)) {
            throw new InvalidArgumentException('Burn or null wallets cannot be configured as payment destinations.');
        }

        $contract = trim((string) ($data['token_contract_address'] ?? ''));
        $contract = $contract === '' ? null : $contract;

        $enabled = ! empty($data['is_enabled']) ? 1 : 0;
        $isDemo  = ! empty($data['is_demo']) ? 1 : (int) ($existing['is_demo'] ?? 0);
        $displayStatus = strtolower(trim((string) ($data['display_status'] ?? ($existing['display_status'] ?? 'hidden'))));
        if (! in_array($displayStatus, ['live', 'hidden', 'pending'], true)) {
            $displayStatus = 'hidden';
        }
        if ($isDemo === 1) {
            $displayStatus = 'hidden';
            $enabled = 0;
        }

        $addressPattern = trim((string) ($data['address_pattern'] ?? ''));
        $addressPattern = $addressPattern === ''
            ? (($this->config ?? config('Platform'))->bep20Pattern)
            : $addressPattern;

        $txPattern = trim((string) ($data['tx_hash_pattern'] ?? ''));
        $txPattern = $txPattern === '' ? '/^0x[a-fA-F0-9]{64}$/' : $txPattern;

        $min = MoneyMath::normalize((string) ($data['min_deposit_usdt'] ?? '5'), $scale);
        $max = MoneyMath::normalize((string) ($data['max_deposit_usdt'] ?? '100000'), $scale);
        if (MoneyMath::compare($min, $max, $scale) > 0) {
            throw new InvalidArgumentException('Minimum deposit cannot exceed maximum deposit.');
        }

        $decimals = (int) ($data['token_decimals'] ?? 18);
        if ($decimals < 0 || $decimals > 36) {
            throw new InvalidArgumentException('Token decimals must be between 0 and 36.');
        }

        $confirmations = (int) ($data['required_confirmations'] ?? 12);
        if ($confirmations < 1 || $confirmations > 100000) {
            throw new InvalidArgumentException('Required confirmations must be a positive integer.');
        }

        $verifierType = strtolower(trim((string) ($data['verifier_type'] ?? 'none')));
        if (! in_array($verifierType, ['none', 'evm_erc20'], true)) {
            throw new InvalidArgumentException('Verifier type must be none or evm_erc20.');
        }

        $chainRaw = $data['chain_id'] ?? null;
        $chainId  = ($chainRaw !== null && $chainRaw !== '')
            ? (int) $chainRaw
            : null;
        if ($verifierType === 'evm_erc20' && ($chainId === null || $chainId <= 0)) {
            throw new InvalidArgumentException('EVM networks require a positive chain ID.');
        }

        $explorer = trim((string) ($data['explorer_tx_url_template'] ?? ''));
        $explorer = $explorer === '' ? null : $explorer;
        $rpcEnv   = trim((string) ($data['rpc_url_env_key'] ?? ''));
        $rpcEnv   = $rpcEnv === '' ? null : $rpcEnv;
        if ($rpcEnv !== null && ! preg_match('/^blockchain\.[A-Za-z0-9_]+$/', $rpcEnv)) {
            throw new InvalidArgumentException('RPC env key must look like blockchain.someKey.');
        }

        $payload = [
            'network_name'             => $name,
            'chain_id'                 => $chainId,
            'verifier_type'            => $verifierType,
            'token_symbol'             => strtoupper(trim((string) ($data['token_symbol'] ?? 'USDT'))) ?: 'USDT',
            'token_contract_address'   => $contract,
            'token_decimals'           => $decimals,
            'destination_wallet'       => $wallet,
            'address_pattern'          => $addressPattern,
            'tx_hash_pattern'          => $txPattern,
            'required_confirmations'   => $confirmations,
            'min_deposit_usdt'         => $min,
            'max_deposit_usdt'         => $max,
            'instructions'             => trim((string) ($data['instructions'] ?? '')) ?: null,
            'explorer_tx_url_template' => $explorer,
            'rpc_url_env_key'          => $rpcEnv,
            'is_enabled'               => $enabled,
            'is_demo'                  => $isDemo,
            'display_status'           => $displayStatus,
            'sort_order'               => max(0, (int) ($data['sort_order'] ?? 100)),
        ];

        // QR fields are only written when explicitly provided (dual-control approval path).
        foreach (['qr_stored_name', 'qr_mime', 'qr_source', 'qr_decoded_payload', 'qr_verified_at'] as $qrField) {
            if (array_key_exists($qrField, $data)) {
                $payload[$qrField] = $data[$qrField];
            }
        }

        if ($creating) {
            $payload['network_key'] = $key;
            $payload['qr_source'] = $payload['qr_source'] ?? PaymentQrService::SOURCE_NONE;
        }

        if ($enabled === 1) {
            if ($wallet === null) {
                throw new InvalidArgumentException('An enabled network requires a destination wallet address.');
            }
            $this->assertValidWallet($wallet, [
                'address_pattern' => $addressPattern,
            ]);
            if ($verifierType === 'evm_erc20' && $contract === null) {
                throw new InvalidArgumentException('EVM USDT networks require a token contract address.');
            }
        } elseif ($wallet !== null) {
            $this->assertValidWallet($wallet, [
                'address_pattern' => $addressPattern,
            ]);
        }

        if ($contract !== null) {
            $this->assertValidWallet($contract, [
                'address_pattern' => $addressPattern,
            ]);
        }

        return $payload;
    }

    private function usdtScale(): int
    {
        return ($this->config ?? config('Platform'))->usdtScale;
    }
}
