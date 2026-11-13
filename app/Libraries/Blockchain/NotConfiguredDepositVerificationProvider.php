<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

/**
 * Safe default provider. Never returns successful verification.
 */
final class NotConfiguredDepositVerificationProvider implements DepositVerificationProviderInterface
{
    public function providerKey(): string
    {
        return 'not_configured';
    }

    public function verify(array $deposit, array $network): DepositVerificationResult
    {
        return new DepositVerificationResult(
            DepositVerificationResult::STATUS_NOT_CONFIGURED,
            'Blockchain verification provider is not configured. Deposits cannot be marked verified until a trusted provider is integrated (Phase 4B).',
            null,
            null,
            null,
            [
                'network_key' => $network['network_key'] ?? null,
                'deposit_ref' => $deposit['deposit_ref'] ?? null,
            ]
        );
    }
}
