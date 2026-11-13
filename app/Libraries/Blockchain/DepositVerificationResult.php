<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

/**
 * Immutable result from a blockchain deposit verification attempt.
 */
final class DepositVerificationResult
{
    public const STATUS_NOT_CONFIGURED              = 'not_configured';
    public const STATUS_UNAVAILABLE                 = 'verification_unavailable';
    public const STATUS_FAILED                      = 'failed';
    public const STATUS_PENDING                     = 'pending_confirmations';
    public const STATUS_TX_NOT_FOUND                = 'tx_not_found';
    public const STATUS_TX_FAILED                   = 'tx_failed';
    public const STATUS_WRONG_NETWORK               = 'wrong_network';
    public const STATUS_WRONG_TOKEN_OR_DESTINATION  = 'wrong_token_or_destination';
    public const STATUS_INSUFFICIENT_AMOUNT         = 'insufficient_amount';
    public const STATUS_INSUFFICIENT_CONFIRMATIONS  = 'insufficient_confirmations';
    public const STATUS_REORG_OR_INCONSISTENT       = 'reorg_or_inconsistent';
    public const STATUS_VERIFIED                    = 'verified';

    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?string $verifiedAmountUsdt = null,
        public readonly ?int $confirmations = null,
        public readonly ?string $providerReference = null,
        public readonly array $details = []
    ) {
    }

    public function isVerified(): bool
    {
        return $this->status === self::STATUS_VERIFIED;
    }

    public function isConfigured(): bool
    {
        return ! in_array($this->status, [
            self::STATUS_NOT_CONFIGURED,
            self::STATUS_UNAVAILABLE,
        ], true);
    }

    public function isRetryable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_INSUFFICIENT_CONFIRMATIONS,
            self::STATUS_TX_NOT_FOUND,
            self::STATUS_UNAVAILABLE,
            self::STATUS_REORG_OR_INCONSISTENT,
        ], true);
    }
}
