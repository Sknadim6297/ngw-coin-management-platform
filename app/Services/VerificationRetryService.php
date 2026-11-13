<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Blockchain\DepositVerificationResult;
use Config\Blockchain;
use InvalidArgumentException;

/**
 * Phase 4C: controlled, idempotent verification retries with backoff.
 * Does not invent success; delegates to UsdtDepositService state machine.
 */
class VerificationRetryService
{
    /** @var list<string> */
    private const TERMINAL_STATUSES = [
        DepositVerificationResult::STATUS_FAILED,
        DepositVerificationResult::STATUS_TX_FAILED,
        DepositVerificationResult::STATUS_WRONG_NETWORK,
        DepositVerificationResult::STATUS_WRONG_TOKEN_OR_DESTINATION,
        DepositVerificationResult::STATUS_INSUFFICIENT_AMOUNT,
        DepositVerificationResult::STATUS_VERIFIED,
    ];

    public function __construct(
        private readonly ?UsdtDepositService $deposits = null,
        private readonly ?Blockchain $blockchain = null
    ) {
    }

    /**
     * @return array{
     *   allowed: bool,
     *   reason: ?string,
     *   attempt_count: int,
     *   max_attempts: int,
     *   next_verification_after: ?string,
     *   deposit: ?array<string, mixed>
     * }
     */
    public function canRetry(string $depositRef): array
    {
        $cfg     = $this->blockchain ?? config('Blockchain');
        $deposit = ($this->deposits ?? service('usdtDepositService'))->findByRef($depositRef);
        $max     = (int) $cfg->verificationMaxAttempts;

        if ($deposit === null) {
            return $this->gate(false, 'Deposit not found.', 0, $max, null, null);
        }

        $attempts = (int) ($deposit['verification_attempt_count'] ?? 0);
        $nextAfter = $deposit['next_verification_after'] ?? null;
        $status = (string) $deposit['status'];

        if (! in_array($status, [UsdtDepositService::STATUS_SUBMITTED, UsdtDepositService::STATUS_UNDER_REVIEW], true)) {
            return $this->gate(false, 'Deposit is not in a retryable state (' . $status . ').', $attempts, $max, $nextAfter, $deposit);
        }

        if ($attempts >= $max) {
            return $this->gate(false, 'Verification retry limit reached. Escalate for manual investigation.', $attempts, $max, $nextAfter, $deposit);
        }

        $lastStatus = (string) ($deposit['last_verification_status'] ?? '');
        if ($lastStatus !== '' && in_array($lastStatus, self::TERMINAL_STATUSES, true)) {
            return $this->gate(false, 'Last verification result is not retryable (' . $lastStatus . ').', $attempts, $max, $nextAfter, $deposit);
        }

        if ($nextAfter !== null && $nextAfter !== '' && strtotime((string) $nextAfter) > time()) {
            return $this->gate(false, 'Backoff window active until ' . $nextAfter . '.', $attempts, $max, (string) $nextAfter, $deposit);
        }

        return $this->gate(true, null, $attempts, $max, $nextAfter !== null ? (string) $nextAfter : null, $deposit);
    }

    /**
     * @return array{deposit: array<string, mixed>, result: DepositVerificationResult, retry: array<string, mixed>}
     */
    public function retry(string $depositRef, int $adminUserId): array
    {
        $gate = $this->canRetry($depositRef);
        if (! $gate['allowed']) {
            throw new InvalidArgumentException($gate['reason'] ?? 'Verification retry not allowed.');
        }

        $result = ($this->deposits ?? service('usdtDepositService'))->attemptVerification($depositRef, $adminUserId);

        return [
            'deposit' => $result['deposit'],
            'result'  => $result['result'],
            'retry'   => $this->canRetry($depositRef),
        ];
    }

    public function computeNextBackoffAt(int $attemptCount): string
    {
        $cfg  = $this->blockchain ?? config('Blockchain');
        $base = max(1, (int) $cfg->verificationRetryBackoffSeconds);
        $max  = max($base, (int) $cfg->verificationRetryMaxBackoffSeconds);
        $exp  = (int) min($max, $base * (2 ** max(0, $attemptCount - 1)));

        return date('Y-m-d H:i:s', time() + $exp);
    }

    /**
     * @param array<string, mixed>|null $deposit
     * @return array{
     *   allowed: bool,
     *   reason: ?string,
     *   attempt_count: int,
     *   max_attempts: int,
     *   next_verification_after: ?string,
     *   deposit: ?array<string, mixed>
     * }
     */
    private function gate(
        bool $allowed,
        ?string $reason,
        int $attempts,
        int $max,
        ?string $nextAfter,
        ?array $deposit
    ): array {
        return [
            'allowed'                 => $allowed,
            'reason'                  => $reason,
            'attempt_count'           => $attempts,
            'max_attempts'            => $max,
            'next_verification_after' => $nextAfter,
            'deposit'                 => $deposit,
        ];
    }
}
