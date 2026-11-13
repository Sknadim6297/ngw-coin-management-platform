<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Trusted blockchain verification settings.
 * RPC URLs come from environment only — never from user input.
 */
class Blockchain extends BaseConfig
{
    /**
     * Active deposit verifier: not_configured | evm_erc20
     */
    public string $depositVerifier = 'not_configured';

    /**
     * Primary trusted EVM JSON-RPC HTTPS endpoint (env: blockchain.evmRpcUrl).
     */
    public string $evmRpcUrl = '';

    /**
     * Optional comma-separated host allowlist for SSRF protection.
     * Empty = derive host from evmRpcUrl only.
     */
    public string $rpcHostAllowlist = '';

    public int $rpcTimeoutSeconds = 10;

    public int $rpcConnectTimeoutSeconds = 5;

    public int $rpcMaxRetries = 2;

    public int $rpcRetryDelayMs = 250;

    /**
     * Soft client-side throttle window for outbound RPC calls (seconds).
     */
    public int $rpcMinIntervalMs = 100;

    /**
     * Amount comparison tolerance in USDT scale units (0 = exact for expected check uses >=).
     * Verification requires verified_amount >= expected_amount.
     * Purchase linking requires exact equality (no silent over/under payment).
     */
    public string $amountMatchMode = 'exact_link_ge_verify';

    /**
     * Phase 4C: max deposit verification attempts before manual ops review.
     */
    public int $verificationMaxAttempts = 8;

    /**
     * Base backoff seconds between deposit verification retries (doubles each attempt, capped).
     */
    public int $verificationRetryBackoffSeconds = 60;

    /**
     * Maximum backoff seconds between retries.
     */
    public int $verificationRetryMaxBackoffSeconds = 3600;

    /**
     * When true, allow admin-triggered integrity re-check of already-verified deposits.
     * Never auto-reverses ownership or inventory.
     */
    public bool $allowVerifiedIntegrityRecheck = true;
}
