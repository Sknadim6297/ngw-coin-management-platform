<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

/**
 * Extensible blockchain verification contract for Phase 4B.
 */
interface DepositVerificationProviderInterface
{
    public function providerKey(): string;

    /**
     * @param array<string, mixed> $deposit
     * @param array<string, mixed> $network
     */
    public function verify(array $deposit, array $network): DepositVerificationResult;
}
