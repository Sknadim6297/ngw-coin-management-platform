<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\MoneyMath;
use Config\Blockchain;
use Config\Platform;

/**
 * Phase 11D outgoing USDT payout verification adapter.
 * Reuses deposit EVM Transfer-log matching with destination = member wallet.
 * Never invents a verified result when the provider is unavailable.
 */
class CommissionPayoutVerificationService
{
    public function __construct(
        private readonly ?Blockchain $blockchain = null,
        private readonly ?Platform $platform = null
    ) {
    }

    /**
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $withdrawal
     * @return array{status: string, method: string, message: string, details: array<string, mixed>, block_number?: int|null, confirmations?: int|null, tx_status?: string}
     */
    public function verifyEvidence(array $evidence, array $withdrawal): array
    {
        $chain = $this->blockchain ?? config('Blockchain');
        if (($chain->depositVerifier ?? 'not_configured') !== 'evm_erc20' || trim((string) $chain->evmRpcUrl) === '') {
            return [
                'status'  => 'blocked',
                'method'  => 'provider_unavailable',
                'message' => 'Blockchain payout verification is BLOCKED — EVM verifier is not configured. Do not mark PAID on manual confirmation alone.',
                'details' => ['provider' => $chain->depositVerifier ?? 'not_configured'],
            ];
        }

        $networkKey = strtolower((string) ($withdrawal['network'] ?? 'bep20'));
        $network = service('usdtPaymentNetworkService')->findByKey($networkKey)
            ?? service('usdtPaymentNetworkService')->findByKey('BEP20')
            ?? service('usdtPaymentNetworkService')->findByKey('bep20');

        if ($network === null) {
            return [
                'status'  => 'blocked',
                'method'  => 'network_missing',
                'message' => 'Approved BEP20 USDT network configuration is missing.',
                'details' => [],
            ];
        }

        $expectedNetwork = strtoupper((string) ($withdrawal['network'] ?? ''));
        $configuredNet   = strtoupper((string) ($network['network_key'] ?? $network['key'] ?? 'BEP20'));
        if ($expectedNetwork !== '' && ! str_contains($configuredNet, 'BEP20') && $configuredNet !== $expectedNetwork) {
            return [
                'status'  => 'wrong_network',
                'method'  => 'evm_erc20',
                'message' => 'Withdrawal network does not match configured payout network.',
                'details' => ['expected' => $expectedNetwork, 'configured' => $configuredNet],
            ];
        }

        $destination = strtolower(trim((string) ($evidence['destination_address']
            ?? $withdrawal['destination_address_snapshot']
            ?? $withdrawal['destination_address']
            ?? '')));
        $amount = MoneyMath::normalize((string) ($evidence['paid_amount'] ?? $withdrawal['amount'] ?? '0'), 8);

        // Reuse deposit verifier: match Transfer logs TO the member destination.
        $syntheticNetwork = $network;
        $syntheticNetwork['destination_wallet'] = $destination;
        $syntheticNetwork['verifier_type'] = $network['verifier_type'] ?? 'evm_erc20';

        $result = service('depositVerificationProvider')->verify([
            'tx_hash'                      => (string) ($evidence['tx_hash_normalized'] ?? $evidence['tx_hash'] ?? ''),
            'tx_hash_normalized'           => (string) ($evidence['tx_hash_normalized'] ?? $evidence['tx_hash'] ?? ''),
            'expected_amount_usdt'         => $amount,
            'destination_wallet_snapshot'  => $destination,
        ], $syntheticNetwork);

        return $this->mapResult($result, $destination, (string) ($network['token_contract_address'] ?? ''));
    }

    /**
     * @return array{status: string, method: string, message: string, details: array<string, mixed>, block_number?: int|null, confirmations?: int|null, tx_status?: string}
     */
    private function mapResult(DepositVerificationResult $result, string $destination, string $token): array
    {
        $status = match ($result->status) {
            DepositVerificationResult::STATUS_VERIFIED => 'verified',
            DepositVerificationResult::STATUS_NOT_CONFIGURED,
            DepositVerificationResult::STATUS_UNAVAILABLE => 'blocked',
            DepositVerificationResult::STATUS_WRONG_NETWORK => 'wrong_network',
            DepositVerificationResult::STATUS_WRONG_TOKEN_OR_DESTINATION => 'wrong_token_or_destination',
            DepositVerificationResult::STATUS_INSUFFICIENT_AMOUNT => 'insufficient_amount',
            DepositVerificationResult::STATUS_TX_FAILED => 'tx_failed',
            DepositVerificationResult::STATUS_FAILED => 'failed',
            DepositVerificationResult::STATUS_PENDING,
            DepositVerificationResult::STATUS_INSUFFICIENT_CONFIRMATIONS => 'pending_confirmations',
            DepositVerificationResult::STATUS_TX_NOT_FOUND => 'tx_not_found',
            default => 'failed',
        };

        return [
            'status'        => $status,
            'method'        => 'evm_erc20_outgoing',
            'message'       => $result->message,
            'details'       => array_merge($result->details, [
                'destination' => $destination,
                'token'       => $token,
                'provider'    => $result->status,
            ]),
            'block_number'  => isset($result->details['block_number']) ? (int) $result->details['block_number'] : null,
            'confirmations' => $result->confirmations,
            'tx_status'     => $status === 'verified' ? 'success' : ($status === 'tx_failed' ? 'failed' : 'recorded'),
        ];
    }

    /**
     * Deterministic offline classification helpers for focused tests (no RPC).
     *
     * @param array<string, mixed> $evidence
     * @param array<string, mixed> $withdrawal
     * @param array<string, mixed> $observed on-chain observation fixture
     * @return array{status: string, method: string, message: string, details: array<string, mixed>}
     */
    public function classifyObservedTransfer(array $evidence, array $withdrawal, array $observed): array
    {
        $expectedDest = strtolower(trim((string) ($withdrawal['destination_address_snapshot'] ?? $withdrawal['destination_address'] ?? '')));
        $expectedAmt  = MoneyMath::normalize((string) ($withdrawal['amount'] ?? '0'), 8);
        $expectedTok  = strtolower(trim((string) ($evidence['token_contract'] ?? '')));
        $expectedNet  = strtoupper(trim((string) ($withdrawal['network'] ?? '')));

        if (strtoupper((string) ($observed['network'] ?? '')) !== $expectedNet) {
            return ['status' => 'wrong_network', 'method' => 'fixture', 'message' => 'Wrong network.', 'details' => $observed];
        }
        if (strtolower(trim((string) ($observed['token'] ?? ''))) !== $expectedTok && $expectedTok !== '') {
            return ['status' => 'wrong_token', 'method' => 'fixture', 'message' => 'Wrong token contract.', 'details' => $observed];
        }
        if (strtolower(trim((string) ($observed['to'] ?? ''))) !== $expectedDest) {
            return ['status' => 'wrong_destination', 'method' => 'fixture', 'message' => 'Wrong destination.', 'details' => $observed];
        }
        if (($observed['success'] ?? true) === false) {
            return ['status' => 'tx_failed', 'method' => 'fixture', 'message' => 'Transaction failed/reverted.', 'details' => $observed];
        }
        if (MoneyMath::compare(MoneyMath::normalize((string) ($observed['amount'] ?? '0'), 8), $expectedAmt, 8) === -1) {
            return ['status' => 'insufficient_amount', 'method' => 'fixture', 'message' => 'Insufficient transfer amount.', 'details' => $observed];
        }

        return ['status' => 'verified', 'method' => 'fixture', 'message' => 'Observed transfer matches payout.', 'details' => $observed];
    }
}
