<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

use App\Libraries\MoneyMath;
use Config\Blockchain;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Production-oriented EVM ERC-20 USDT verification via trusted JSON-RPC.
 *
 * Transfer matching policy:
 * - Collect all Transfer logs from the configured token contract in the receipt
 *   where `to` equals the network destination wallet.
 * - Sum those transfer amounts (integer base units → decimal via token decimals).
 * - Require sum >= expected deposit amount.
 * - Ignore transfers to other addresses.
 * - One deposit row per tx hash (DB unique) prevents cross-deposit double counting.
 */
class EvmUsdtVerificationProvider implements DepositVerificationProviderInterface
{
    /** keccak256("Transfer(address,address,uint256)") */
    public const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    public function __construct(
        private readonly JsonRpcClient $rpc = new JsonRpcClient(),
        private readonly ?Blockchain $blockchain = null,
        private readonly ?Platform $platform = null
    ) {
    }

    public function providerKey(): string
    {
        return 'evm_erc20';
    }

    public function verify(array $deposit, array $network): DepositVerificationResult
    {
        $chainConfig = $this->blockchain ?? config('Blockchain');
        if (($chainConfig->depositVerifier ?? 'not_configured') !== 'evm_erc20') {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_NOT_CONFIGURED,
                'EVM verifier is not enabled in blockchain.depositVerifier.'
            );
        }

        if (trim($chainConfig->evmRpcUrl) === '' && empty($network['resolved_rpc_url'])) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_NOT_CONFIGURED,
                'Trusted EVM RPC URL is not configured.'
            );
        }

        if (($network['verifier_type'] ?? 'none') !== 'evm_erc20') {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_UNAVAILABLE,
                'Selected network is not configured for EVM ERC-20 verification.'
            );
        }

        try {
            return $this->verifyStrict($deposit, $network, $chainConfig);
        } catch (InvalidArgumentException $e) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_FAILED,
                $e->getMessage(),
                null,
                null,
                null,
                ['classification' => 'validation']
            );
        } catch (RuntimeException $e) {
            $message = $e->getMessage();
            $status  = DepositVerificationResult::STATUS_UNAVAILABLE;
            if (str_contains(strtolower($message), 'not found')) {
                $status = DepositVerificationResult::STATUS_TX_NOT_FOUND;
            }

            return new DepositVerificationResult(
                $status,
                $message,
                null,
                null,
                null,
                ['classification' => 'provider']
            );
        }
    }

    /**
     * @param array<string, mixed> $deposit
     * @param array<string, mixed> $network
     */
    private function verifyStrict(array $deposit, array $network, Blockchain $chainConfig): DepositVerificationResult
    {
        $platform = $this->platform ?? config('Platform');
        $scale    = $platform->usdtScale;

        $txHash = strtolower(trim((string) ($deposit['tx_hash_normalized'] ?? $deposit['tx_hash'] ?? '')));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw new InvalidArgumentException('Transaction hash format is invalid.');
        }

        $expectedChainId = (int) ($network['chain_id'] ?? 0);
        if ($expectedChainId <= 0) {
            throw new InvalidArgumentException('Network chain ID is not configured.');
        }

        $token = TokenAmountMath::normalizeAddress((string) ($network['token_contract_address'] ?? ''));
        $dest  = TokenAmountMath::normalizeAddress(
            (string) ($network['destination_wallet'] ?? $deposit['destination_wallet_snapshot'] ?? '')
        );
        $decimals = (int) ($network['token_decimals'] ?? 18);
        $requiredConfirmations = max(1, (int) ($network['required_confirmations'] ?? 12));
        $expectedAmount = MoneyMath::normalize((string) $deposit['expected_amount_usdt'], $scale);

        $rpcUrl = $this->resolveNetworkRpcUrl($network, $chainConfig);

        $rpcChainHex = $this->rpc->call('eth_chainId', [], $rpcUrl);
        $rpcChainId  = (int) TokenAmountMath::hexToDec($this->hexString($rpcChainHex));
        if ($rpcChainId !== $expectedChainId) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_WRONG_NETWORK,
                'RPC chain ID ' . $rpcChainId . ' does not match configured network chain ID ' . $expectedChainId . '.',
                null,
                null,
                $txHash,
                ['rpc_chain_id' => $rpcChainId, 'expected_chain_id' => $expectedChainId]
            );
        }

        $tx = $this->rpc->call('eth_getTransactionByHash', [$txHash], $rpcUrl);
        if ($tx === null) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_TX_NOT_FOUND,
                'Transaction not found on the configured chain.',
                null,
                null,
                $txHash
            );
        }
        if (! is_array($tx)) {
            throw new RuntimeException('Malformed transaction payload.');
        }

        $receipt = $this->rpc->call('eth_getTransactionReceipt', [$txHash], $rpcUrl);
        if ($receipt === null) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_PENDING,
                'Transaction is pending; receipt not available yet.',
                null,
                null,
                $txHash
            );
        }
        if (! is_array($receipt)) {
            throw new RuntimeException('Malformed transaction receipt.');
        }

        // Replacement / inconsistency checks
        $txBlock = isset($tx['blockHash']) ? strtolower((string) $tx['blockHash']) : null;
        $rcBlock = isset($receipt['blockHash']) ? strtolower((string) $receipt['blockHash']) : null;
        if ($txBlock !== null && $rcBlock !== null && $txBlock !== $rcBlock) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_REORG_OR_INCONSISTENT,
                'Transaction and receipt block hashes disagree; possible replacement or reorg.',
                null,
                null,
                $txHash,
                ['tx_block_hash' => $txBlock, 'receipt_block_hash' => $rcBlock]
            );
        }

        $statusHex = strtolower((string) ($receipt['status'] ?? ''));
        if ($statusHex !== '0x1' && $statusHex !== '0x01') {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_TX_FAILED,
                'Transaction receipt status is not successful.',
                null,
                null,
                $txHash,
                ['receipt_status' => $statusHex]
            );
        }

        $blockNumberHex = (string) ($receipt['blockNumber'] ?? '');
        if ($blockNumberHex === '' || $blockNumberHex === '0x0') {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_PENDING,
                'Transaction is not yet mined into a block.',
                null,
                null,
                $txHash
            );
        }

        $txBlockNumber = (int) TokenAmountMath::hexToDec($this->hexString($blockNumberHex));
        $headHex       = $this->rpc->call('eth_blockNumber', [], $rpcUrl);
        $headNumber    = (int) TokenAmountMath::hexToDec($this->hexString($headHex));
        if ($headNumber < $txBlockNumber) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_REORG_OR_INCONSISTENT,
                'Canonical head is behind the receipt block number.',
                null,
                null,
                $txHash
            );
        }

        $confirmations = $headNumber - $txBlockNumber + 1;
        if ($confirmations < $requiredConfirmations) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_INSUFFICIENT_CONFIRMATIONS,
                'Only ' . $confirmations . ' confirmations; ' . $requiredConfirmations . ' required.',
                null,
                $confirmations,
                $txHash,
                [
                    'block_number' => $txBlockNumber,
                    'head_number'  => $headNumber,
                ]
            );
        }

        $matched = $this->sumDestinationTransfers($receipt, $token, $dest, $decimals, $scale);
        if ($matched['count'] === 0) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_WRONG_TOKEN_OR_DESTINATION,
                'No Transfer events from the configured token contract to the destination wallet were found.',
                null,
                $confirmations,
                $txHash,
                [
                    'token'       => $token,
                    'destination' => $dest,
                    'logs_scanned'=> $matched['logs_scanned'],
                ]
            );
        }

        $verifiedAmount = $matched['amount'];
        if (MoneyMath::compare($verifiedAmount, $expectedAmount, $scale) < 0) {
            return new DepositVerificationResult(
                DepositVerificationResult::STATUS_INSUFFICIENT_AMOUNT,
                'Verified transfer amount ' . $verifiedAmount . ' is below expected ' . $expectedAmount . ' USDT.',
                $verifiedAmount,
                $confirmations,
                $txHash
            );
        }

        return new DepositVerificationResult(
            DepositVerificationResult::STATUS_VERIFIED,
            'ERC-20 transfer verified on chain ' . $expectedChainId . '.',
            $verifiedAmount,
            $confirmations,
            $txHash,
            [
                'chain_id'          => $expectedChainId,
                'token_contract'    => $token,
                'destination'       => $dest,
                'block_number'      => $txBlockNumber,
                'block_hash'        => $rcBlock,
                'transfer_count'    => $matched['count'],
                'matching_policy'   => 'sum_transfers_to_destination_from_token_contract',
                'expected_amount'   => $expectedAmount,
            ]
        );
    }

    /**
     * @param array<string, mixed> $receipt
     * @return array{amount: string, count: int, logs_scanned: int}
     */
    private function sumDestinationTransfers(
        array $receipt,
        string $token,
        string $destination,
        int $decimals,
        int $displayScale
    ): array {
        $logs = $receipt['logs'] ?? [];
        if (! is_array($logs)) {
            throw new RuntimeException('Malformed receipt logs.');
        }

        $sumBase = '0';
        $count   = 0;
        $scanned = 0;

        foreach ($logs as $log) {
            if (! is_array($log)) {
                continue;
            }
            $scanned++;

            $logAddress = TokenAmountMath::normalizeAddress((string) ($log['address'] ?? ''));
            if ($logAddress !== $token) {
                continue;
            }

            $topics = $log['topics'] ?? [];
            if (! is_array($topics) || count($topics) < 3) {
                continue;
            }

            if (strtolower((string) $topics[0]) !== self::TRANSFER_TOPIC) {
                continue;
            }

            $to = TokenAmountMath::addressFromTopic((string) $topics[2]);
            if ($to !== $destination) {
                continue;
            }

            $data = (string) ($log['data'] ?? '0x0');
            $amountDec = TokenAmountMath::baseUnitsHexToDecimal($data, $decimals, max($displayScale, $decimals));
            // Keep high precision then normalize to display scale when summing via MoneyMath later.
            $sumBase = bcadd($sumBase, TokenAmountMath::hexToDec($this->hexString($data)), 0);
            $count++;
            unset($amountDec);
        }

        $amount = '0';
        if ($count > 0) {
            $divisor = bcpow('10', (string) $decimals, 0);
            $amount  = bcdiv($sumBase, $divisor, $displayScale);
            $amount  = MoneyMath::normalize($amount, $displayScale);
        }

        return [
            'amount'       => $amount,
            'count'        => $count,
            'logs_scanned' => $scanned,
        ];
    }

    /**
     * @param array<string, mixed> $network
     */
    private function resolveNetworkRpcUrl(array $network, Blockchain $config): ?string
    {
        if (! empty($network['resolved_rpc_url'])) {
            return (string) $network['resolved_rpc_url'];
        }

        $envKey = trim((string) ($network['rpc_url_env_key'] ?? ''));
        if ($envKey !== '') {
            // Only allow blockchain.* env keys for SSRF safety.
            if (! preg_match('/^blockchain\.[A-Za-z0-9_]+$/', $envKey)) {
                throw new RuntimeException('Network RPC env key is not allowed.');
            }
            $fromEnv = (string) env($envKey, '');
            if ($fromEnv !== '') {
                return $fromEnv;
            }
        }

        return $config->evmRpcUrl !== '' ? $config->evmRpcUrl : null;
    }

    private function hexString(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            throw new RuntimeException('Expected hex string from RPC.');
        }

        return $value;
    }
}
