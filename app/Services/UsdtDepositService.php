<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\Blockchain\DepositVerificationProviderInterface;
use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\Blockchain\NotConfiguredDepositVerificationProvider;
use App\Libraries\MoneyMath;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberProfileModel;
use App\Models\UsdtDepositEventModel;
use App\Models\UsdtDepositRequestModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * USDT deposit request + evidence workflow (Phase 4A).
 * Does not credit coins or invent blockchain verification success.
 */
class UsdtDepositService
{
    public const STATUS_DRAFT        = 'draft';
    public const STATUS_SUBMITTED    = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';
    public const STATUS_VERIFIED     = 'verified';
    public const STATUS_REJECTED     = 'rejected';
    public const STATUS_CANCELLED    = 'cancelled';

    public function __construct(
        private readonly UsdtDepositRequestModel $deposits = new UsdtDepositRequestModel(),
        private readonly UsdtDepositEventModel $events = new UsdtDepositEventModel(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly UsdtPaymentNetworkService $networks = new UsdtPaymentNetworkService(),
        private readonly EvidenceUploadService $evidence = new EvidenceUploadService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?DepositVerificationProviderInterface $verifier = null,
        private readonly ?Platform $config = null
    ) {
    }

    private function verifier(): DepositVerificationProviderInterface
    {
        return $this->verifier ?? new NotConfiguredDepositVerificationProvider();
    }

    /**
     * @return array{deposit: array<string, mixed>, duplicate: bool}
     */
    public function submitRequest(
        int $memberUserId,
        int $networkId,
        string $amountUsdt,
        string $txHash,
        string $idempotencyKey,
        ?UploadedFile $evidenceFile = null
    ): array {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Submission key is required.');
        }

        $existing = $this->deposits->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return ['deposit' => $existing, 'duplicate' => true];
        }

        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $network = $this->networks->find($networkId);
        if ($network === null) {
            throw new InvalidArgumentException('Payment network not found.');
        }

        $this->networks->assertDepositAllowed($network, $amountUsdt);
        $normalizedHash = $this->networks->assertValidTxHash($txHash, $network);

        if ($this->deposits->findByNormalizedTxHash($normalizedHash) !== null) {
            throw new InvalidArgumentException('This transaction hash was already submitted.');
        }

        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $amount = MoneyMath::normalize($amountUsdt, $scale);
        $ref    = $this->generateDepositRef();
        $now    = date('Y-m-d H:i:s');

        $evidenceMeta = null;
        if ($evidenceFile !== null && $evidenceFile->getError() !== UPLOAD_ERR_NO_FILE) {
            $evidenceMeta = $this->evidence->storeDepositEvidence($evidenceFile, $ref);
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $id = $this->deposits->insert([
                'deposit_ref'                 => $ref,
                'member_user_id'              => $memberUserId,
                'member_id'                   => $profile->member_id,
                'network_id'                  => (int) $network['id'],
                'network_key'                 => $network['network_key'],
                'expected_amount_usdt'        => $amount,
                'destination_wallet_snapshot' => $network['destination_wallet'],
                'tx_hash'                     => trim($txHash),
                'tx_hash_normalized'          => $normalizedHash,
                'evidence_stored_name'        => $evidenceMeta['stored_name'] ?? null,
                'evidence_original_name'      => $evidenceMeta['original_name'] ?? null,
                'evidence_mime'               => $evidenceMeta['mime'] ?? null,
                'evidence_size'               => $evidenceMeta['size'] ?? null,
                'status'                      => self::STATUS_SUBMITTED,
                'submitted_at'                => $now,
                'idempotency_key'             => $idempotencyKey,
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to create deposit request.');
            }

            $this->recordEvent((int) $id, $ref, $memberUserId, 'deposit.submitted', null, self::STATUS_SUBMITTED, [
                'network_key' => $network['network_key'],
                'amount'      => $amount,
                'has_evidence'=> $evidenceMeta !== null,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Deposit submission failed.');
            }

            $db->transCommit();

            $row = $this->deposits->find($id);
            $this->audit->log('usdt.deposit_submitted', $memberUserId, 'usdt_deposit_request', $ref, [
                'amount'      => $amount,
                'network_key' => $network['network_key'],
            ]);

            return ['deposit' => $row, 'duplicate' => false];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($evidenceMeta !== null) {
                $this->evidence->deleteIfExists($evidenceMeta['stored_name']);
            }

            if ($this->isDuplicateKeyException($e)) {
                $dup = $this->deposits->findByIdempotencyKey($idempotencyKey)
                    ?? $this->deposits->findByNormalizedTxHash($normalizedHash);
                if ($dup !== null && (int) $dup['member_user_id'] === $memberUserId
                    && $dup['idempotency_key'] === $idempotencyKey) {
                    return ['deposit' => $dup, 'duplicate' => true];
                }

                throw new InvalidArgumentException('This transaction hash was already submitted.');
            }

            throw $e;
        }
    }

    /**
     * @return array{deposit: array<string, mixed>}
     */
    public function cancel(int $memberUserId, string $depositRef): array
    {
        return $this->transition(
            $depositRef,
            $memberUserId,
            [self::STATUS_DRAFT, self::STATUS_SUBMITTED],
            self::STATUS_CANCELLED,
            'deposit.cancelled',
            false,
            null,
            null
        );
    }

    /**
     * @return array{deposit: array<string, mixed>}
     */
    public function markUnderReview(string $depositRef, int $adminUserId, ?string $notes = null): array
    {
        return $this->transition(
            $depositRef,
            $adminUserId,
            [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW],
            self::STATUS_UNDER_REVIEW,
            'deposit.under_review',
            true,
            $notes,
            null
        );
    }

    /**
     * @return array{deposit: array<string, mixed>}
     */
    public function reject(string $depositRef, int $adminUserId, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        return $this->transition(
            $depositRef,
            $adminUserId,
            [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW],
            self::STATUS_REJECTED,
            'deposit.rejected',
            true,
            null,
            $reason
        );
    }

    /**
     * Record admin review notes without claiming blockchain verification.
     *
     * @return array{deposit: array<string, mixed>}
     */
    public function recordReviewNotes(string $depositRef, int $adminUserId, string $notes): array
    {
        $notes = trim($notes);
        if ($notes === '') {
            throw new InvalidArgumentException('Review notes are required.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockDeposit($depositRef);
            if (! in_array($row['status'], [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW], true)) {
                throw new InvalidArgumentException('Review notes can only be recorded for submitted or under-review deposits.');
            }

            $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update([
                'review_notes' => $notes,
                'reviewed_by'  => $adminUserId,
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $adminUserId,
                'deposit.review_notes',
                $row['status'],
                $row['status'],
                ['notes_length' => strlen($notes)]
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to save review notes.');
            }

            $db->transCommit();

            return ['deposit' => $this->deposits->find((int) $row['id'])];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Attempt trusted verification. Screenshots / manual claims cannot force verified.
     *
     * @return array{deposit: array<string, mixed>, result: DepositVerificationResult}
     */
    public function attemptVerification(string $depositRef, int $adminUserId): array
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockDeposit($depositRef);
            if (! in_array($row['status'], [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW], true)) {
                throw new InvalidArgumentException('Only submitted or under-review deposits can be verified.');
            }

            $network = $this->networks->find((int) $row['network_id']);
            if ($network === null) {
                throw new InvalidArgumentException('Payment network not found.');
            }

            $result = $this->verifier()->verify($row, $network);

            $attemptCount = (int) ($row['verification_attempt_count'] ?? 0) + 1;
            $nowAttempt   = date('Y-m-d H:i:s');
            $nextAfter    = null;
            if (! $result->isVerified() && $result->isRetryable()) {
                $chainCfg = config('Blockchain');
                $base = max(1, (int) ($chainCfg->verificationRetryBackoffSeconds ?? 60));
                $maxB = max($base, (int) ($chainCfg->verificationRetryMaxBackoffSeconds ?? 3600));
                $wait = (int) min($maxB, $base * (2 ** max(0, $attemptCount - 1)));
                $nextAfter = date('Y-m-d H:i:s', time() + $wait);
            }

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $adminUserId,
                'deposit.verification_attempted',
                $row['status'],
                $row['status'],
                [
                    'provider'       => $this->verifier()->providerKey(),
                    'status'         => $result->status,
                    'message'        => $result->message,
                    'attempt_count'  => $attemptCount,
                    'retryable'      => $result->isRetryable(),
                ]
            );

            if (! $result->isVerified()) {
                // Stay under_review when provider is unavailable / not configured.
                $newStatus = self::STATUS_UNDER_REVIEW;
                $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update([
                    'status'                     => $newStatus,
                    'verification_method'        => $this->verifier()->providerKey(),
                    'verification_reference'     => $result->providerReference,
                    'last_verification_status'   => $result->status,
                    'last_verification_message'  => mb_substr($result->message, 0, 2000),
                    'last_verification_at'       => $nowAttempt,
                    'verification_attempt_count' => $attemptCount,
                    'next_verification_after'    => $nextAfter,
                    'reviewed_by'                => $adminUserId,
                    'review_notes'               => trim(($row['review_notes'] ?? '') . "\n" . $result->message),
                    'updated_at'                 => $nowAttempt,
                ]);

                if ($row['status'] !== $newStatus) {
                    $this->recordEvent(
                        (int) $row['id'],
                        $row['deposit_ref'],
                        $adminUserId,
                        'deposit.under_review',
                        $row['status'],
                        $newStatus,
                        ['reason' => 'verification_not_confirmed']
                    );
                }

                if ($db->transStatus() === false) {
                    $db->transRollback();
                    throw new RuntimeException('Verification attempt failed to save.');
                }

                $db->transCommit();

                return [
                    'deposit' => $this->deposits->find((int) $row['id']),
                    'result'  => $result,
                ];
            }

            // Trusted provider confirmed — mark verified (Phase 4B path).
            $now = date('Y-m-d H:i:s');
            $verifiedAmount = $result->verifiedAmountUsdt
                ?? MoneyMath::normalize((string) $row['expected_amount_usdt'], ($this->config ?? config('Platform'))->usdtScale);

            $details = $result->details;
            $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update([
                'status'                     => self::STATUS_VERIFIED,
                'verification_method'        => $this->verifier()->providerKey(),
                'verification_reference'     => $result->providerReference,
                'verified_amount_usdt'       => $verifiedAmount,
                'verified_chain_id'          => isset($details['chain_id']) ? (int) $details['chain_id'] : null,
                'verified_token_contract'    => $details['token_contract'] ?? null,
                'verified_destination'       => $details['destination'] ?? null,
                'verified_block_number'      => isset($details['block_number']) ? (int) $details['block_number'] : null,
                'verified_block_hash'        => $details['block_hash'] ?? null,
                'verified_confirmations'     => $result->confirmations,
                'last_verification_status'   => DepositVerificationResult::STATUS_VERIFIED,
                'last_verification_message'  => mb_substr($result->message, 0, 2000),
                'last_verification_at'       => $now,
                'verification_attempt_count' => $attemptCount,
                'next_verification_after'    => null,
                'integrity_flag'             => 'ok',
                'integrity_checked_at'       => $now,
                'verified_at'                => $now,
                'reviewed_by'                => $adminUserId,
                'payment_consumption_status' => 'available',
                'updated_at'                 => $now,
            ]);

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $adminUserId,
                'deposit.verified',
                $row['status'],
                self::STATUS_VERIFIED,
                [
                    'provider'      => $this->verifier()->providerKey(),
                    'amount'        => $verifiedAmount,
                    'confirmations' => $result->confirmations,
                    'block_number'  => $details['block_number'] ?? null,
                ]
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to mark deposit verified.');
            }

            $db->transCommit();

            $updated = $this->deposits->find((int) $row['id']);
            $this->audit->log('usdt.deposit_verified', $adminUserId, 'usdt_deposit_request', $row['deposit_ref'], [
                'provider' => $this->verifier()->providerKey(),
            ]);

            return ['deposit' => $updated, 'result' => $result];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Explicitly blocked: screenshots / hash alone cannot verify payment.
     * Use manuallyApprovePayment() after independent explorer/wallet confirmation.
     */
    public function rejectManualVerificationClaim(string $depositRef, int $adminUserId): never
    {
        $this->recordEventForRef(
            $depositRef,
            $adminUserId,
            'deposit.manual_verify_blocked',
            ['reason' => 'screenshot_or_hash_alone_insufficient']
        );

        throw new InvalidArgumentException(
            'A screenshot or submitted TXN ID alone cannot approve payment. Use Approve Payment after independent confirmation.'
        );
    }

    /**
     * Admin manual payment approval after independent explorer/wallet checks.
     * Does not credit coins — only marks deposit verified (then purchase approve credits).
     *
     * @param array{
     *   confirm_independent?: bool|string,
     *   confirm_network?: bool|string,
     *   confirm_success?: bool|string,
     *   verified_amount_usdt?: string,
     *   confirmed_wallet?: string,
     *   confirmed_token_contract?: string,
     *   confirmed_confirmations?: int|string,
     *   verification_evidence?: string,
     *   link_purchase_ref?: ?string
     * } $data
     * @return array{deposit: array<string, mixed>, linked_purchase_ref: ?string}
     */
    public function manuallyApprovePayment(string $depositRef, int $adminUserId, array $data): array
    {
        if (empty($data['confirm_independent'])) {
            throw new InvalidArgumentException(
                'Confirm that you independently checked this transaction on a trusted explorer or wallet.'
            );
        }
        if (empty($data['confirm_network'])) {
            throw new InvalidArgumentException('Confirm the payment was on BEP20 / BSC.');
        }
        if (empty($data['confirm_success'])) {
            throw new InvalidArgumentException('Confirm the transaction succeeded with enough confirmations.');
        }

        $evidence = trim((string) ($data['verification_evidence'] ?? ''));
        if ($evidence === '' || strlen($evidence) < 8) {
            throw new InvalidArgumentException('Verification evidence is required (explorer link or review notes).');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockDeposit($depositRef);
            if (! in_array($row['status'], [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW], true)) {
                throw new InvalidArgumentException('Only pending payments can be approved.');
            }

            $txHash = trim((string) ($row['tx_hash'] ?? ''));
            if ($txHash === '') {
                throw new InvalidArgumentException('Deposit has no transaction hash to approve.');
            }

            $normalized = strtolower((string) ($row['tx_hash_normalized'] ?? $txHash));
            $dup = $this->deposits
                ->where('tx_hash_normalized', $normalized)
                ->where('id !=', (int) $row['id'])
                ->whereIn('status', [self::STATUS_VERIFIED, self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW])
                ->first();
            if ($dup !== null) {
                throw new InvalidArgumentException('This transaction hash is already used by another payment.');
            }

            $network = $this->networks->find((int) $row['network_id']);
            if ($network === null) {
                throw new InvalidArgumentException('Payment network not found.');
            }

            $scale = ($this->config ?? config('Platform'))->usdtScale;
            $expected = MoneyMath::normalize((string) $row['expected_amount_usdt'], $scale);
            $verifiedAmount = MoneyMath::normalize(
                (string) ($data['verified_amount_usdt'] ?? $expected),
                $scale
            );
            if (! MoneyMath::isPositive($verifiedAmount, $scale)) {
                throw new InvalidArgumentException('Verified amount must be greater than zero.');
            }
            if (MoneyMath::compare($verifiedAmount, $expected, $scale) !== 0) {
                throw new InvalidArgumentException(
                    'Verified amount (' . $verifiedAmount . ') must exactly match expected amount (' . $expected . ').'
                );
            }

            $snapshotWallet = trim((string) ($row['destination_wallet_snapshot'] ?? ''));
            $confirmedWallet = trim((string) ($data['confirmed_wallet'] ?? ''));
            if ($confirmedWallet === '') {
                throw new InvalidArgumentException('Confirmed receiving wallet is required.');
            }
            if ($snapshotWallet !== '' && strcasecmp($confirmedWallet, $snapshotWallet) !== 0) {
                throw new InvalidArgumentException(
                    'Confirmed wallet does not match the deposit receiving wallet snapshot.'
                );
            }
            if (service('paymentQrService')->isBurnOrNullWallet($confirmedWallet)) {
                throw new InvalidArgumentException('Burn or null wallets cannot be approved as payment destinations.');
            }

            $networkContract = trim((string) ($network['token_contract_address'] ?? ''));
            $confirmedContract = trim((string) ($data['confirmed_token_contract'] ?? $networkContract));
            if ($networkContract !== '' && strcasecmp($networkContract, 'pending') !== 0) {
                if ($confirmedContract === '' || strcasecmp($confirmedContract, $networkContract) !== 0) {
                    throw new InvalidArgumentException(
                        'Confirmed USDT token contract does not match the configured BEP20 contract.'
                    );
                }
            }

            $requiredConf = max(1, (int) ($network['required_confirmations'] ?? 1));
            $confirmations = (int) ($data['confirmed_confirmations'] ?? 0);
            if ($confirmations < $requiredConf) {
                throw new InvalidArgumentException(
                    'Confirmations must be at least ' . $requiredConf . ' for this network.'
                );
            }

            $now = date('Y-m-d H:i:s');
            $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update([
                'status'                     => self::STATUS_VERIFIED,
                'verification_method'        => 'MANUAL',
                'verification_reference'     => mb_substr($evidence, 0, 255),
                'verified_amount_usdt'       => $verifiedAmount,
                'verified_chain_id'          => (int) ($network['chain_id'] ?? 56),
                'verified_token_contract'    => $confirmedContract !== '' ? $confirmedContract : null,
                'verified_destination'       => $confirmedWallet,
                'verified_confirmations'     => $confirmations,
                'last_verification_status'   => DepositVerificationResult::STATUS_VERIFIED,
                'last_verification_message'  => mb_substr('MANUAL: ' . $evidence, 0, 2000),
                'last_verification_at'       => $now,
                'next_verification_after'    => null,
                'integrity_flag'             => 'ok',
                'integrity_checked_at'       => $now,
                'verified_at'                => $now,
                'reviewed_by'                => $adminUserId,
                'review_notes'               => trim((string) (($row['review_notes'] ?? '') . "\nMANUAL APPROVAL: " . $evidence)),
                'payment_consumption_status' => 'available',
                'updated_at'                 => $now,
            ]);

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $adminUserId,
                'deposit.verified_manual',
                $row['status'],
                self::STATUS_VERIFIED,
                [
                    'method'        => 'MANUAL',
                    'amount'        => $verifiedAmount,
                    'confirmations' => $confirmations,
                    'wallet'        => $confirmedWallet,
                ]
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to approve payment.');
            }

            $db->transCommit();

            $updated = $this->deposits->find((int) $row['id']);
            $this->audit->log('usdt.deposit_verified_manual', $adminUserId, 'usdt_deposit_request', $row['deposit_ref'], [
                'method' => 'MANUAL',
                'amount' => $verifiedAmount,
            ]);

            $linkedPurchase = null;
            $linkRef = trim((string) ($data['link_purchase_ref'] ?? ''));
            // Prefer explicit form link, then soft Buy Coins association on the deposit.
            if ($linkRef === '') {
                $linkRef = trim((string) ($row['linked_purchase_ref'] ?? ''));
            }
            if ($linkRef === '') {
                $match = model(CoinPurchaseRequestModel::class)
                    ->where('member_user_id', (int) $row['member_user_id'])
                    ->where('status', 'pending')
                    ->where('total_usdt', $verifiedAmount)
                    ->groupStart()
                        ->where('payment_deposit_ref', null)
                        ->orWhere('payment_deposit_ref', '')
                        ->orWhere('payment_deposit_ref', $row['deposit_ref'])
                    ->groupEnd()
                    ->orderBy('id', 'DESC')
                    ->first();
                $linkRef = $match['purchase_ref'] ?? '';
            }

            if ($linkRef !== '') {
                try {
                    service('usdtPaymentAllocationService')->linkDepositToPurchase(
                        (int) $row['member_user_id'],
                        $row['deposit_ref'],
                        $linkRef
                    );
                    $linkedPurchase = $linkRef;
                    $updated = $this->deposits->findByRef($row['deposit_ref']) ?? $updated;
                } catch (\Throwable $e) {
                    log_message('warning', 'Manual verify auto-link skipped: {msg}', ['msg' => $e->getMessage()]);
                }
            }

            return [
                'deposit'             => $updated,
                'linked_purchase_ref' => $linkedPurchase,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * Related pending purchase for admin review UI (exact amount match).
     *
     * @return array<string, mixed>|null
     */
    public function findMatchingPendingPurchase(array $deposit): ?array
    {
        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $amount = MoneyMath::normalize((string) ($deposit['expected_amount_usdt'] ?? '0'), $scale);

        return model(CoinPurchaseRequestModel::class)
            ->where('member_user_id', (int) $deposit['member_user_id'])
            ->where('status', 'pending')
            ->where('total_usdt', $amount)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Pending payment (submitted/under_review) matching a purchase for admin CTA.
     *
     * @return array<string, mixed>|null
     */
    public function findMatchingPendingPaymentForPurchase(array $purchase): ?array
    {
        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $amount = MoneyMath::normalize((string) ($purchase['total_usdt'] ?? '0'), $scale);

        $rows = $this->deposits
            ->where('member_user_id', (int) ($purchase['member_user_id'] ?? 0))
            ->whereIn('status', [self::STATUS_SUBMITTED, self::STATUS_UNDER_REVIEW])
            ->orderBy('id', 'DESC')
            ->findAll(20);

        foreach ($rows as $row) {
            $expected = MoneyMath::normalize((string) ($row['expected_amount_usdt'] ?? '0'), $scale);
            if (MoneyMath::compare($expected, $amount, $scale) === 0) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Phase 4C: re-check a previously verified deposit against the chain.
     * Never reverses ownership, inventory, or allocations — flags for admin investigation only.
     *
     * @return array{deposit: array<string, mixed>, result: DepositVerificationResult, flagged: bool}
     */
    public function recheckVerifiedDepositIntegrity(string $depositRef, int $adminUserId): array
    {
        $cfg = config('Blockchain');
        if (! ($cfg->allowVerifiedIntegrityRecheck ?? true)) {
            throw new InvalidArgumentException('Verified deposit integrity re-check is disabled.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockDeposit($depositRef);
            if ($row['status'] !== self::STATUS_VERIFIED) {
                throw new InvalidArgumentException('Integrity re-check applies only to verified deposits.');
            }

            $network = $this->networks->find((int) $row['network_id']);
            if ($network === null) {
                throw new InvalidArgumentException('Payment network not found.');
            }

            $result = $this->verifier()->verify($row, $network);
            $now    = date('Y-m-d H:i:s');
            $flagged = false;
            $flag    = 'ok';
            $notes   = null;

            if (! $result->isVerified()) {
                $flagged = true;
                $flag    = $result->status === DepositVerificationResult::STATUS_REORG_OR_INCONSISTENT
                    ? 'reorg_suspected'
                    : 'needs_investigation';
                $notes = 'Post-verify integrity check failed: ' . $result->status . ' — ' . $result->message
                    . ' Ledger records were NOT reversed automatically.';
            } else {
                $details = $result->details;
                $storedHash = strtolower((string) ($row['verified_block_hash'] ?? ''));
                $liveHash   = strtolower((string) ($details['block_hash'] ?? ''));
                if ($storedHash !== '' && $liveHash !== '' && $storedHash !== $liveHash) {
                    $flagged = true;
                    $flag    = 'reorg_suspected';
                    $notes   = 'Stored verified_block_hash no longer matches live receipt block hash. '
                        . 'Ledger records were NOT reversed automatically.';
                }
            }

            $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update([
                'integrity_flag'            => $flag,
                'integrity_checked_at'      => $now,
                'integrity_notes'           => $notes,
                'last_verification_status'  => $result->status,
                'last_verification_message' => mb_substr($result->message, 0, 2000),
                'last_verification_at'      => $now,
                'updated_at'                => $now,
            ]);

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $adminUserId,
                $flagged ? 'deposit.integrity_flagged' : 'deposit.integrity_ok',
                self::STATUS_VERIFIED,
                self::STATUS_VERIFIED,
                [
                    'provider' => $this->verifier()->providerKey(),
                    'status'   => $result->status,
                    'flag'     => $flag,
                    'message'  => $result->message,
                    'no_ledger_mutation' => true,
                ]
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to persist integrity re-check.');
            }

            $db->transCommit();

            if ($flagged) {
                $this->audit->log('usdt.deposit_integrity_flagged', $adminUserId, 'usdt_deposit_request', $depositRef, [
                    'flag' => $flag,
                ]);
            }

            return [
                'deposit' => $this->deposits->find((int) $row['id']),
                'result'  => $result,
                'flagged' => $flagged,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    public function findOwnedByMember(int $memberUserId, string $depositRef): ?array
    {
        $row = $this->deposits->findByRef($depositRef);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    public function findByRef(string $depositRef): ?array
    {
        return $this->deposits->findByRef($depositRef);
    }

    /**
     * Whether member has an available verified deposit with exact amount match.
     */
    public function hasVerifiedDepositCovering(int $memberUserId, string $amountUsdt): bool
    {
        return $this->findAvailableVerifiedDepositForAmount($memberUserId, $amountUsdt) !== null
            || $this->hasLinkedExactPayment($memberUserId, $amountUsdt);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findAvailableVerifiedDepositForAmount(int $memberUserId, string $amountUsdt): ?array
    {
        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $amount = MoneyMath::normalize($amountUsdt, $scale);

        $rows = $this->deposits
            ->where('member_user_id', $memberUserId)
            ->where('status', self::STATUS_VERIFIED)
            ->where('payment_consumption_status', 'available')
            ->findAll();

        foreach ($rows as $row) {
            $verified = MoneyMath::normalize((string) $row['verified_amount_usdt'], $scale);
            // Exact match only for Phase 4B reconciliation.
            if (MoneyMath::compare($verified, $amount, $scale) === 0) {
                return $row;
            }
        }

        return null;
    }

    private function hasLinkedExactPayment(int $memberUserId, string $amountUsdt): bool
    {
        $scale  = ($this->config ?? config('Platform'))->usdtScale;
        $amount = MoneyMath::normalize($amountUsdt, $scale);

        $purchase = model(CoinPurchaseRequestModel::class)
            ->where('member_user_id', $memberUserId)
            ->where('status', 'pending')
            ->where('payment_status', 'deposit_linked')
            ->where('total_usdt', $amount)
            ->first();

        return $purchase !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsFor(string $depositRef): array
    {
        $row = $this->deposits->findByRef($depositRef);
        if ($row === null) {
            return [];
        }

        return $this->events->forDeposit((int) $row['id']);
    }

    /**
     * @param list<string> $fromStatuses
     * @return array{deposit: array<string, mixed>}
     */
    private function transition(
        string $depositRef,
        int $actorUserId,
        array $fromStatuses,
        string $toStatus,
        string $eventType,
        bool $isAdmin,
        ?string $notes,
        ?string $rejectionReason
    ): array {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $this->lockDeposit($depositRef);

            if (! $isAdmin && (int) $row['member_user_id'] !== $actorUserId) {
                throw new InvalidArgumentException('Deposit request not found.');
            }

            if (! in_array($row['status'], $fromStatuses, true)) {
                throw new InvalidArgumentException(
                    'Invalid deposit status transition from ' . $row['status'] . ' to ' . $toStatus . '.'
                );
            }

            $now    = date('Y-m-d H:i:s');
            $update = [
                'status'     => $toStatus,
                'updated_at' => $now,
            ];

            if ($isAdmin) {
                $update['reviewed_by'] = $actorUserId;
            }

            if ($notes !== null && trim($notes) !== '') {
                $update['review_notes'] = trim($notes);
            }

            if ($rejectionReason !== null) {
                $update['rejection_reason'] = $rejectionReason;
            }

            // Clear soft Buy Coins association when deposit is rejected/cancelled (not hard allocation).
            if (in_array($toStatus, [self::STATUS_REJECTED, self::STATUS_CANCELLED], true)) {
                $softPurchase = trim((string) ($row['linked_purchase_ref'] ?? ''));
                if ($softPurchase !== '') {
                    $purchase = $db->table('coin_purchase_requests')
                        ->where('purchase_ref', $softPurchase)
                        ->get()
                        ->getRowArray();
                    $paymentStatus = (string) ($purchase['payment_status'] ?? 'unpaid');
                    if (
                        $purchase !== null
                        && in_array($paymentStatus, ['unpaid', ''], true)
                        && (string) ($purchase['payment_deposit_ref'] ?? '') === (string) $row['deposit_ref']
                    ) {
                        $db->table('coin_purchase_requests')->where('purchase_ref', $softPurchase)->update([
                            'payment_deposit_ref' => null,
                            'updated_at'          => $now,
                        ]);
                    }
                }
                $update['linked_purchase_ref'] = null;
            }

            $db->table('usdt_deposit_requests')->where('id', (int) $row['id'])->update($update);

            $this->recordEvent(
                (int) $row['id'],
                $row['deposit_ref'],
                $actorUserId,
                $eventType,
                $row['status'],
                $toStatus,
                $rejectionReason !== null ? ['rejection_reason' => $rejectionReason] : []
            );

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Deposit status update failed.');
            }

            $db->transCommit();

            $updated = $this->deposits->find((int) $row['id']);
            $this->audit->log('usdt.' . $eventType, $actorUserId, 'usdt_deposit_request', $depositRef, [
                'from' => $row['status'],
                'to'   => $toStatus,
            ]);

            return ['deposit' => $updated];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function lockDeposit(string $depositRef): array
    {
        $row = db_connect()->query(
            'SELECT * FROM usdt_deposit_requests WHERE deposit_ref = ? FOR UPDATE',
            [$depositRef]
        )->getRowArray();

        if ($row === null) {
            throw new InvalidArgumentException('Deposit request not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(
        int $depositId,
        string $depositRef,
        ?int $actorUserId,
        string $eventType,
        ?string $previousStatus,
        ?string $newStatus,
        array $metadata = []
    ): void {
        $this->events->insert([
            'deposit_id'      => $depositId,
            'deposit_ref'     => $depositRef,
            'actor_user_id'   => $actorUserId,
            'event_type'      => $eventType,
            'previous_status' => $previousStatus,
            'new_status'      => $newStatus,
            'metadata'        => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEventForRef(string $depositRef, int $actorUserId, string $eventType, array $metadata): void
    {
        $row = $this->deposits->findByRef($depositRef);
        if ($row === null) {
            throw new InvalidArgumentException('Deposit request not found.');
        }

        $this->recordEvent(
            (int) $row['id'],
            $depositRef,
            $actorUserId,
            $eventType,
            $row['status'],
            $row['status'],
            $metadata
        );
    }

    private function generateDepositRef(): string
    {
        return 'DEP-' . strtoupper(bin2hex(random_bytes(8)));
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }
}
