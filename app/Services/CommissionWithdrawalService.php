<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11D commission-only USDT withdrawal architecture.
 * Request / reserve / approve / payout evidence / settle prepared; execution remains OFF.
 */
class CommissionWithdrawalService
{
    public const STATUS_DRAFT                 = 'draft';
    public const STATUS_PENDING_ADMIN_REVIEW  = 'pending_admin_review';
    public const STATUS_APPROVED              = 'approved';
    public const STATUS_REJECTED              = 'rejected';
    public const STATUS_PAYOUT_IN_PROGRESS    = 'payout_in_progress';
    public const STATUS_PAYOUT_SUBMITTED      = 'payout_submitted';
    public const STATUS_PAID                  = 'paid';
    public const STATUS_FAILED                = 'failed';
    public const STATUS_CANCELLED             = 'cancelled';

    /** Legacy aliases retained in ENUM for older rows. */
    public const STATUS_SUBMITTED    = 'submitted';
    public const STATUS_UNDER_REVIEW = 'under_review';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_DRAFT                => [self::STATUS_PENDING_ADMIN_REVIEW, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED            => [self::STATUS_UNDER_REVIEW, self::STATUS_PENDING_ADMIN_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_UNDER_REVIEW         => [self::STATUS_PENDING_ADMIN_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_PENDING_ADMIN_REVIEW => [self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_CANCELLED],
        self::STATUS_APPROVED             => [self::STATUS_PAYOUT_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_PAYOUT_IN_PROGRESS   => [self::STATUS_PAYOUT_SUBMITTED, self::STATUS_FAILED],
        self::STATUS_PAYOUT_SUBMITTED     => [self::STATUS_PAID, self::STATUS_FAILED],
        self::STATUS_REJECTED             => [],
        self::STATUS_PAID                 => [],
        self::STATUS_FAILED               => [],
        self::STATUS_CANCELLED            => [],
    ];

    public function __construct(
        private readonly CommissionWalletService $wallets = new CommissionWalletService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Short member-facing status label. Never reports "Active" unless every required gate is satisfied.
     */
    public function activationStatus(): string
    {
        $status = $this->status();
        if (! empty($status['execution_authorized'])) {
            return 'Active (manual payout)';
        }

        $settings = [];
        try {
            $settings = service('commissionWithdrawalSettingsService')->current();
        } catch (\Throwable) {
            $settings = ['emergency_unlock' => false, 'requests_enabled' => false];
        }

        if (empty($settings['emergency_unlock'])) {
            return 'Commission Withdrawals Not Activated';
        }
        if (! $this->feePolicyApproved()) {
            return 'Awaiting fee / minimum policy approval';
        }
        if ($this->wallets->blockchainPayoutEnabled()) {
            return 'Blocked — automatic blockchain payout must stay OFF';
        }
        if (empty($settings['requests_enabled'])) {
            return 'Awaiting Admin request activation';
        }

        return 'Not available';
    }

    /**
     * @return array{
     *   withdrawal_enabled: bool,
     *   blockchain_payout_enabled: bool,
     *   commission_posting_enabled: bool,
     *   mining_posting_enabled: bool,
     *   mining_p2p_enabled: bool,
     *   execution_authorized: bool,
     *   fee_policy_status: string,
     *   fee_amount: ?string,
     *   minimum_amount: ?string,
     *   minimum_withdrawal_status: string,
     *   blockers: list<string>
     * }
     */
    public function status(): array
    {
        $cfg      = $this->config ?? config('Platform');
        $settings = service('commissionWithdrawalSettingsService')->current();
        $feeOk    = (bool) $settings['policy_approved'];
        $wdOn     = $this->wallets->withdrawalEnabled();
        $chainOn  = $this->wallets->blockchainPayoutEnabled();
        $blockers = [];

        if (! $settings['emergency_unlock']) {
            $blockers[] = 'Emergency unlock OFF (Platform.commissionWithdrawalEnabled).';
        }
        if (! $settings['requests_enabled']) {
            $blockers[] = 'Admin withdrawal request activation is OFF.';
        }
        if (! $feeOk) {
            $blockers[] = 'Withdrawal fee / minimum pending Admin configuration and approval.';
        }
        if ($chainOn) {
            $blockers[] = 'commissionBlockchainPayoutEnabled=true (automatic blockchain payout must remain OFF).';
        }

        $authorized = $wdOn && $feeOk && ! $chainOn;
        if ($authorized) {
            $blockers = [];
        }

        return [
            'withdrawal_enabled'         => $wdOn,
            'blockchain_payout_enabled'  => $chainOn,
            'commission_posting_enabled' => (bool) $cfg->commissionPostingEnabled,
            'mining_posting_enabled'     => (bool) $cfg->miningPostingEnabled,
            'mining_p2p_enabled'         => (bool) $cfg->miningP2pEnabled,
            'execution_authorized'       => $authorized,
            'fee_policy_status'          => (string) $settings['policy_status'],
            'fee_amount'                 => $settings['fee_usdt'],
            'minimum_amount'             => $settings['minimum_usdt'],
            'minimum_withdrawal_status'  => $feeOk ? 'approved' : 'pending_business_decision',
            'settings'                   => $settings,
            'blockers'                   => $blockers,
        ];
    }

    /** Fee + minimum must both be explicitly configured by Admin and marked approved — never invent amounts. */
    public function feePolicyApproved(): bool
    {
        try {
            return service('commissionWithdrawalSettingsService')->policyApproved();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @throws RuntimeException when execution is not fully authorized
     */
    public function assertExecutionAuthorized(string $action = 'withdrawal'): void
    {
        $cfg = $this->config ?? config('Platform');
        if (! (bool) $cfg->commissionWithdrawalEnabled
            && ! service('financialSandboxService')->allows('commission_withdrawal')
        ) {
            throw new RuntimeException('Commission Withdrawals Not Activated. ' . ucfirst($action) . ' is blocked server-side.');
        }
        if (! $this->feePolicyApproved()) {
            throw new RuntimeException(
                'Withdrawal fee / minimum policy is not approved. Admin must configure and approve fee and minimum before withdrawals can run.'
            );
        }
        if ($this->wallets->blockchainPayoutEnabled()) {
            throw new RuntimeException(
                'Automatic blockchain payout must remain disabled. Turn commissionBlockchainPayoutEnabled OFF before authorizing manual withdrawal settlement.'
            );
        }
        if (! $this->wallets->withdrawalEnabled()) {
            throw new RuntimeException(
                'Admin withdrawal request activation is OFF. ' . ucfirst($action) . ' is blocked until Admin enables requests.'
            );
        }
    }

    /**
     * Read-only preview (no writes / no reservation).
     *
     * @return array<string, mixed>
     */
    public function preview(int $memberUserId, string $amount): array
    {
        $this->assertNotImpersonation();
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }
        if ($profile->isInactive()) {
            throw new InvalidArgumentException('Inactive accounts cannot withdraw.');
        }

        $qty = MoneyMath::normalize(trim($amount), 8);
        if (! MoneyMath::isPositive($qty, 8)) {
            throw new InvalidArgumentException('Withdrawal amount must be greater than zero.');
        }

        $wallet = trim((string) $profile->usdt_wallet);
        $this->assertRegisteredWallet($profile, $wallet);

        $summary  = $this->wallets->withdrawableSummary($memberUserId);
        $feeCalc  = $this->feeBreakdown($qty);
        $status   = $this->status();
        $blockers = [];
        if (! $this->wallets->withdrawalEnabled()) {
            $blockers[] = 'withdrawal_kill_switch_off';
        }
        if (! $this->feePolicyApproved()) {
            $blockers[] = 'fee_minimum_pending_business_decision';
        }
        if ($this->wallets->blockchainPayoutEnabled()) {
            $blockers[] = 'automatic_blockchain_payout_must_stay_off';
        }
        if (MoneyMath::compare($qty, $summary['available_balance'], 8) === 1) {
            $blockers[] = 'insufficient_posted_balance';
        }
        if ($feeCalc['minimum'] !== null && MoneyMath::compare($qty, $feeCalc['minimum'], 8) === -1) {
            $blockers[] = 'below_minimum_withdrawal';
        }
        if ($feeCalc['fee'] !== null && MoneyMath::compare($feeCalc['net'], '0', 8) !== 1) {
            $blockers[] = 'net_payout_not_positive_after_fee';
        }

        $sufficient = MoneyMath::compare($qty, $summary['available_balance'], 8) <= 0;
        $canExecute = $status['execution_authorized']
            && $sufficient
            && ! in_array('below_minimum_withdrawal', $blockers, true)
            && ! in_array('net_payout_not_positive_after_fee', $blockers, true);

        return [
            'amount'              => $qty,
            'fee_amount'          => $feeCalc['fee'],
            'net_amount'          => $feeCalc['net'],
            'fee_policy_status'   => $status['fee_policy_status'],
            'minimum_amount'      => $feeCalc['minimum'],
            'available_balance'   => $summary['available_balance'],
            'reserved_balance'    => $summary['reserved_balance'],
            'total_posted'        => $summary['total_posted_credits'],
            'destination_address' => $wallet,
            'destination_masked'  => $this->maskAddress($wallet),
            'network'             => 'BEP20',
            'sufficient'          => $sufficient,
            'can_execute'         => $canExecute,
            'blockers'            => $blockers,
            'note'                => $canExecute
                ? 'Preview ready — confirming will reserve posted commission USDT and send the request for admin review. Mining and purchased coins are not withdrawable.'
                : 'PREVIEW ONLY — submission remains blocked until withdrawal gates are satisfied. Mining and coin balances are not withdrawable.',
        ];
    }

    /**
     * @return array{fee: ?string, minimum: ?string, net: string}
     */
    private function feeBreakdown(string $amount): array
    {
        if (! $this->feePolicyApproved()) {
            return ['fee' => null, 'minimum' => null, 'net' => $amount];
        }
        $settings = service('commissionWithdrawalSettingsService')->current();
        $fee = MoneyMath::normalize((string) $settings['fee_usdt'], 8);
        $min = MoneyMath::normalize((string) $settings['minimum_usdt'], 8);
        $net = MoneyMath::sub($amount, $fee, 8);

        return ['fee' => $fee, 'minimum' => $min, 'net' => $net];
    }

    private function assertAmountAgainstPolicy(string $amount): void
    {
        if (! $this->feePolicyApproved()) {
            return;
        }
        $calc = $this->feeBreakdown($amount);
        if ($calc['minimum'] !== null && MoneyMath::compare($amount, $calc['minimum'], 8) === -1) {
            throw new InvalidArgumentException(
                'Amount is below the approved minimum withdrawal of ' . $calc['minimum'] . ' USDT.'
            );
        }
        if ($calc['fee'] !== null && MoneyMath::compare($calc['net'], '0', 8) !== 1) {
            throw new InvalidArgumentException(
                'Amount must exceed the approved withdrawal fee of ' . $calc['fee'] . ' USDT.'
            );
        }
    }

    /**
     * Draft create remains validation-only architecture (no reserve). Prefer preview while OFF.
     *
     * @param array{amount: string, currency?: string, network?: string, destination_address?: string, idempotency_key?: string} $data
     * @return array<string, mixed>
     */
    public function createDraft(int $memberUserId, string $memberId, array $data): array
    {
        $this->assertNotImpersonation();
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null || (string) $profile->member_id !== $memberId) {
            throw new InvalidArgumentException('Member profile mismatch.');
        }
        if ($profile->isInactive()) {
            throw new InvalidArgumentException('Inactive accounts cannot withdraw.');
        }

        $amount = MoneyMath::normalize((string) ($data['amount'] ?? ''), 8);
        if (! MoneyMath::isPositive($amount, 8)) {
            throw new InvalidArgumentException('Withdrawal amount must be positive.');
        }
        $this->assertAmountAgainstPolicy($amount);

        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USDT')));
        if ($currency !== 'USDT') {
            throw new InvalidArgumentException('Only USDT withdrawals are supported.');
        }

        $network = strtoupper(trim((string) ($data['network'] ?? 'BEP20')));
        $allowed = ($this->config ?? config('Platform'))->commissionWithdrawalNetworks;
        if (! in_array($network, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported or unconfigured withdrawal network.');
        }

        // Destination must be the registered profile wallet — never an arbitrary address.
        $address = trim((string) $profile->usdt_wallet);
        $submitted = trim((string) ($data['destination_address'] ?? $address));
        if ($submitted !== '' && strcasecmp($submitted, $address) !== 0) {
            throw new InvalidArgumentException('Destination must match the registered USDT wallet. Arbitrary addresses are not allowed.');
        }
        $this->assertRegisteredWallet($profile, $address);

        $account = $this->wallets->getAccount($memberUserId, $currency);
        if (MoneyMath::compare($amount, (string) ($account['available_balance'] ?? '0'), 8) === 1) {
            throw new InvalidArgumentException('Amount exceeds available posted commission balance (simulation is not withdrawable).');
        }

        $idem = trim((string) ($data['idempotency_key'] ?? ''));
        if ($idem === '') {
            $idem = 'wd-draft:' . $memberUserId . ':' . bin2hex(random_bytes(8));
        }

        // Idempotent resume: an existing draft with the same key is returned, never duplicated.
        $existing = db_connect()->table('commission_withdrawal_requests')
            ->where('idempotency_key', $idem)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            if ((int) $existing['member_user_id'] !== $memberUserId) {
                throw new InvalidArgumentException('Duplicate withdrawal idempotency key.');
            }

            return $this->findByRef((string) $existing['withdrawal_ref']) ?? $existing;
        }

        $fees     = $this->feeBreakdown($amount);
        $settings = service('commissionWithdrawalSettingsService')->current();
        $ref      = 'CWD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now      = date('Y-m-d H:i:s');

        try {
            db_connect()->table('commission_withdrawal_requests')->insert([
                'withdrawal_ref'                  => $ref,
                'member_user_id'                  => $memberUserId,
                'member_id'                       => $memberId,
                'amount'                          => $amount,
                'fee_amount'                      => $fees['fee'],
                'net_amount'                      => $fees['net'],
                'fee_policy_status'               => (string) $settings['policy_status'],
                'currency'                        => $currency,
                'network'                         => $network,
                'destination_address'             => $address,
                'destination_address_snapshot'    => $address,
                'wallet_changed_at_snapshot'      => $profile->usdt_wallet_changed_at ?? null,
                'status'                          => self::STATUS_DRAFT,
                'requested_at'                    => null,
                'idempotency_key'                 => $idem,
                'is_reserved'                     => 0,
                'is_paid'                         => 0,
                'address_change_review_required'  => 0,
                'created_at'                      => $now,
                'updated_at'                      => $now,
            ]);
        } catch (\Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw new InvalidArgumentException('Duplicate withdrawal idempotency key.');
            }
            throw $e;
        }

        $this->recordEvent($ref, 'created', $memberUserId, null, self::STATUS_DRAFT, [
            'amount' => $amount,
            'note'   => 'draft_only_no_reservation',
        ]);
        $this->audit->log('commission.withdrawal_draft_created', $memberUserId, 'commission_withdrawal', $ref, [
            'amount' => $amount,
        ]);

        return $this->findByRef($ref) ?? ['withdrawal_ref' => $ref];
    }

    /**
     * Submit + reserve. Kill-switch, approved fee/minimum policy, and blockchain-auto-OFF are required.
     *
     * @return array<string, mixed>
     */
    public function submit(string $withdrawalRef, int $memberUserId, string $password = ''): array
    {
        $this->assertNotImpersonation();
        $this->assertRateLimit($memberUserId);

        $row = $this->findOwned($withdrawalRef, $memberUserId);
        if ($row === null) {
            throw new InvalidArgumentException('Withdrawal not found.');
        }

        $user = auth()->user();
        if ($user === null || (int) $user->id !== $memberUserId) {
            throw new InvalidArgumentException('Authenticated member mismatch.');
        }
        if (! $this->verifyPassword($user, $password)) {
            $this->audit->log('commission.withdrawal_denied', $memberUserId, 'commission_withdrawal', $withdrawalRef, [
                'reason' => 'reauth_failed',
            ]);
            throw new InvalidArgumentException('Password confirmation failed.');
        }

        try {
            $this->assertExecutionAuthorized('submission');
        } catch (RuntimeException $e) {
            $this->audit->log('commission.withdrawal_denied', $memberUserId, 'commission_withdrawal', $withdrawalRef, [
                'reason'  => 'execution_not_authorized',
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->assertAmountAgainstPolicy((string) $row['amount']);

        return $this->performSubmitWithReserve($withdrawalRef, $memberUserId);
    }

    /**
     * Future authorized submit+reserve path (tests may call when kill-switch temporarily ON).
     *
     * @return array<string, mixed>
     */
    public function performSubmitWithReserve(string $withdrawalRef, int $memberUserId): array
    {
        // perform* paths check emergency unlock only; public APIs also require Admin request activation.
        $this->assertEmergencyUnlock('Submission');

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM commission_withdrawal_requests WHERE withdrawal_ref = ? FOR UPDATE',
                [$withdrawalRef]
            )->getRowArray();
            if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
                throw new InvalidArgumentException('Withdrawal not found.');
            }
            $this->assertTransition((string) $row['status'], self::STATUS_PENDING_ADMIN_REVIEW);
            if ((int) $row['is_reserved'] === 1) {
                throw new InvalidArgumentException('Withdrawal is already reserved.');
            }

            $profile = $this->profiles->findByUserId($memberUserId);
            if ($profile === null) {
                throw new InvalidArgumentException('Member profile not found.');
            }
            $currentWallet = trim((string) $profile->usdt_wallet);
            if (strcasecmp($currentWallet, (string) $row['destination_address_snapshot']) !== 0) {
                $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                    'address_change_review_required' => 1,
                    'updated_at'                     => date('Y-m-d H:i:s'),
                ]);
                throw new InvalidArgumentException(
                    'Registered wallet changed after request creation. Explicit secure review is required; payout destination will not be silently updated.'
                );
            }

            $this->wallets->reserveForWithdrawal($memberUserId, (string) $row['member_id'], [
                'amount'           => (string) $row['amount'],
                'reference_type'   => 'commission_withdrawal',
                'reference_id'     => $withdrawalRef,
                'idempotency_key'  => 'wd-reserve:' . $withdrawalRef,
                'created_by'       => $memberUserId,
            ]);

            $now = date('Y-m-d H:i:s');
            $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'       => self::STATUS_PENDING_ADMIN_REVIEW,
                'requested_at' => $now,
                'is_reserved'  => 1,
                'updated_at'   => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Withdrawal submit failed.');
            }
            $db->transCommit();

            $this->recordEvent($withdrawalRef, 'submitted', $memberUserId, (string) $row['status'], self::STATUS_PENDING_ADMIN_REVIEW, null);
            $this->audit->log('commission.withdrawal_submitted', $memberUserId, 'commission_withdrawal', $withdrawalRef, [
                'amount' => $row['amount'],
            ]);

            return $this->findByRef($withdrawalRef) ?? [];
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
    public function approve(string $withdrawalRef, int $approverUserId, string $password = ''): array
    {
        $this->assertAdminActor($approverUserId, $password, 'commission.withdrawal.review');
        $this->assertExecutionAuthorized('approval');

        return $this->performApprove($withdrawalRef, $approverUserId);
    }

    /**
     * @return array<string, mixed>
     */
    public function performApprove(string $withdrawalRef, int $approverUserId): array
    {
        $this->assertEmergencyUnlock('Approval mutations');

        $row = $this->requireRow($withdrawalRef);
        $this->assertTransition((string) $row['status'], self::STATUS_APPROVED);
        if ((int) $row['is_reserved'] !== 1) {
            throw new InvalidArgumentException('Cannot approve a withdrawal without an active reservation.');
        }
        if ((int) ($row['address_change_review_required'] ?? 0) === 1) {
            throw new InvalidArgumentException('Address-change review required before approval.');
        }

        $now = date('Y-m-d H:i:s');
        db_connect()->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
            'status'      => self::STATUS_APPROVED,
            'reviewed_at' => $now,
            'reviewer_id' => $approverUserId,
            'updated_at'  => $now,
        ]);
        $this->recordEvent($withdrawalRef, 'approved', $approverUserId, (string) $row['status'], self::STATUS_APPROVED, null);
        $this->audit->log('commission.withdrawal_approved', $approverUserId, 'commission_withdrawal', $withdrawalRef, []);

        return $this->findByRef($withdrawalRef) ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function reject(string $withdrawalRef, int $approverUserId, string $reason, string $password = ''): array
    {
        $this->assertAdminActor($approverUserId, $password, 'commission.withdrawal.review');
        $this->assertExecutionAuthorized('rejection');

        return $this->performRejectWithRelease($withdrawalRef, $approverUserId, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function performRejectWithRelease(string $withdrawalRef, int $approverUserId, string $reason): array
    {
        $this->assertEmergencyUnlock('Rejection mutations that alter reserves');
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Rejection reason is required.');
        }

        return $this->performReleaseTerminal($withdrawalRef, $approverUserId, self::STATUS_REJECTED, $reason, 'rejected');
    }

    /**
     * @return array<string, mixed>
     */
    public function performCancelWithRelease(string $withdrawalRef, int $actorUserId): array
    {
        $this->assertEmergencyUnlock('Cancellation mutations that alter reserves');

        return $this->performReleaseTerminal($withdrawalRef, $actorUserId, self::STATUS_CANCELLED, 'cancelled', 'cancelled');
    }

    /**
     * Record manual payout TX hash (admin reauth) and settle to PAID.
     *
     * @param array{tx_hash: string, paid_amount?: string, payment_timestamp?: string, network_key?: string} $data
     * @return array<string, mixed>
     */
    public function recordManualPayout(string $withdrawalRef, int $adminUserId, array $data, string $password = ''): array
    {
        $this->assertAdminActor($adminUserId, $password, 'commission.withdrawal.pay');
        $this->assertExecutionAuthorized('manual payout recording');

        return $this->performRecordManualPayout($withdrawalRef, $adminUserId, $data);
    }

    /**
     * Record TX hash with admin reauth and settle to PAID in one step (no EVM verifier gate).
     *
     * @param array{tx_hash: string, paid_amount?: string, payment_timestamp?: string, network_key?: string} $data
     * @return array<string, mixed>
     */
    public function performRecordManualPayout(string $withdrawalRef, int $adminUserId, array $data): array
    {
        $this->assertEmergencyUnlock('Manual payout recording');
        if ($this->wallets->blockchainPayoutEnabled()) {
            throw new RuntimeException('Automatic blockchain payout remains disabled.');
        }

        $row = $this->requireRow($withdrawalRef);
        $status = (string) $row['status'];
        if (! in_array($status, [self::STATUS_APPROVED, self::STATUS_PAYOUT_IN_PROGRESS, self::STATUS_PAYOUT_SUBMITTED], true)) {
            throw new InvalidArgumentException('Withdrawal must be approved before recording payout.');
        }
        if ((int) ($row['is_paid'] ?? 0) === 1 || $status === self::STATUS_PAID) {
            throw new InvalidArgumentException('Withdrawal is already paid.');
        }

        $txHash = trim((string) ($data['tx_hash'] ?? ''));
        if ($txHash === '') {
            throw new InvalidArgumentException('Transaction ID is required.');
        }
        // No format validation — accept any non-empty payout reference the admin enters.
        if (strlen($txHash) > 80) {
            throw new InvalidArgumentException('Transaction ID is too long (max 80 characters).');
        }
        $txHashNormalized = strtolower($txHash);

        $existingEvidence = db_connect()->table('commission_withdrawal_payout_evidence')
            ->where('withdrawal_ref', $withdrawalRef)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();

        $dup = db_connect()->table('commission_withdrawal_payout_evidence')
            ->where('tx_hash_normalized', $txHashNormalized)
            ->get()
            ->getRowArray();
        if ($dup !== null && (string) ($dup['withdrawal_ref'] ?? '') !== $withdrawalRef) {
            throw new InvalidArgumentException('Duplicate payout transaction hash.');
        }

        // Paid amount is taken from the withdrawal itself — admin only enters TX ID + password.
        $paidSource = trim((string) ($data['paid_amount'] ?? ''));
        if ($paidSource === '' || ! is_numeric($paidSource)) {
            $net = trim((string) ($row['net_amount'] ?? ''));
            $paidSource = ($net !== '' && is_numeric($net))
                ? $net
                : (string) ($row['amount'] ?? '0');
        }
        $paidAmount = MoneyMath::normalize($paidSource, 8);

        $network = service('usdtPaymentNetworkService')->findByKey((string) ($data['network_key'] ?? 'bep20'))
            ?? service('usdtPaymentNetworkService')->findByKey('BEP20');

        $evidenceRef = (string) ($existingEvidence['evidence_ref'] ?? ('CWE-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)))));
        $now = date('Y-m-d H:i:s');

        if ($existingEvidence === null) {
            db_connect()->table('commission_withdrawal_payout_evidence')->insert([
                'evidence_ref'            => $evidenceRef,
                'withdrawal_ref'          => $withdrawalRef,
                'member_id'               => (string) $row['member_id'],
                'approved_amount'         => (string) $row['amount'],
                'paid_amount'             => $paidAmount,
                'token_contract'          => $network['token_contract_address'] ?? null,
                'blockchain_network'      => (string) $row['network'],
                'destination_address'     => (string) ($row['destination_address_snapshot'] ?? $row['destination_address']),
                'tx_hash'                 => $txHash,
                'tx_hash_normalized'      => $txHashNormalized,
                'tx_status'               => 'recorded',
                'verification_status'     => 'admin_confirmed',
                'verification_method'     => 'admin_manual_confirm',
                'verification_message'    => 'Marked PAID by admin after recording transaction ID.',
                'payment_timestamp'       => $data['payment_timestamp'] ?? $now,
                'recorded_by'             => $adminUserId,
                'verified_by'             => $adminUserId,
                'verified_at'             => $now,
                'created_at'              => $now,
                'updated_at'              => $now,
            ]);
        } else {
            db_connect()->table('commission_withdrawal_payout_evidence')->where('id', (int) $existingEvidence['id'])->update([
                'tx_hash'              => $txHash,
                'tx_hash_normalized'   => $txHashNormalized,
                'paid_amount'          => $paidAmount,
                'tx_status'            => 'recorded',
                'verification_status'  => 'admin_confirmed',
                'verification_method'  => 'admin_manual_confirm',
                'verification_message' => 'Marked PAID by admin after recording transaction ID.',
                'verified_by'          => $adminUserId,
                'verified_at'          => $now,
                'updated_at'           => $now,
            ]);
            $evidenceRef = (string) $existingEvidence['evidence_ref'];
        }

        $from = $status;
        if ($from === self::STATUS_APPROVED) {
            $this->assertTransition($from, self::STATUS_PAYOUT_IN_PROGRESS);
            db_connect()->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'           => self::STATUS_PAYOUT_IN_PROGRESS,
                'payout_reference' => $txHash,
                'updated_at'       => $now,
            ]);
            $this->recordEvent($withdrawalRef, 'payout_in_progress', $adminUserId, $from, self::STATUS_PAYOUT_IN_PROGRESS, [
                'evidence_ref' => $evidenceRef,
            ]);
            $from = self::STATUS_PAYOUT_IN_PROGRESS;
        }

        if ($from === self::STATUS_PAYOUT_IN_PROGRESS) {
            $this->assertTransition($from, self::STATUS_PAYOUT_SUBMITTED);
            db_connect()->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'              => self::STATUS_PAYOUT_SUBMITTED,
                'payout_reference'    => $txHash,
                'paid_amount'         => $paidAmount,
                'verification_status' => 'admin_confirmed',
                'updated_at'          => $now,
            ]);
            $this->recordEvent($withdrawalRef, 'payout_submitted', $adminUserId, $from, self::STATUS_PAYOUT_SUBMITTED, [
                'evidence_ref' => $evidenceRef,
                'tx_hash'      => $txHash,
            ]);
            $from = self::STATUS_PAYOUT_SUBMITTED;
        } else {
            db_connect()->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'payout_reference'    => $txHash,
                'paid_amount'         => $paidAmount,
                'verification_status' => 'admin_confirmed',
                'updated_at'          => $now,
            ]);
        }

        $this->audit->log('commission.withdrawal_payout_recorded', $adminUserId, 'commission_withdrawal', $withdrawalRef, [
            'evidence_ref' => $evidenceRef,
            'tx_hash'      => $txHash,
        ]);

        // Settle reserved commission → PAID on admin confirmation (EVM verifier not required).
        $this->assertTransition($from, self::STATUS_PAID);

        $db = db_connect();
        $db->transBegin();
        try {
            $locked = $db->query(
                'SELECT * FROM commission_withdrawal_requests WHERE withdrawal_ref = ? FOR UPDATE',
                [$withdrawalRef]
            )->getRowArray();
            if ($locked === null) {
                throw new InvalidArgumentException('Withdrawal not found.');
            }
            if ((string) $locked['status'] === self::STATUS_PAID || (int) ($locked['is_paid'] ?? 0) === 1) {
                $db->transCommit();

                return $this->findByRef($withdrawalRef) ?? [];
            }

            $this->wallets->settleWithdrawal((int) $locked['member_user_id'], (string) $locked['member_id'], [
                'amount'          => (string) $locked['amount'],
                'reference_type'  => 'commission_withdrawal',
                'reference_id'    => $withdrawalRef,
                'idempotency_key' => 'wd-settle:' . $withdrawalRef,
                'created_by'      => $adminUserId,
            ]);

            $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'              => self::STATUS_PAID,
                'is_paid'             => 1,
                'paid_at'             => $now,
                'paid_amount'         => $paidAmount,
                'payout_reference'    => $txHash,
                'verification_status' => 'admin_confirmed',
                'updated_at'          => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('PAID settlement failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }

        $this->recordEvent($withdrawalRef, 'paid', $adminUserId, $from, self::STATUS_PAID, [
            'evidence_ref' => $evidenceRef,
            'tx_hash'      => $txHash,
            'method'       => 'admin_manual_confirm',
        ]);
        $this->audit->log('commission.withdrawal_paid', $adminUserId, 'commission_withdrawal', $withdrawalRef, [
            'evidence_ref' => $evidenceRef,
            'tx_hash'      => $txHash,
            'method'       => 'admin_manual_confirm',
        ]);

        return $this->findByRef($withdrawalRef) ?? [];
    }


    /**
     * Independent blockchain verification then single PAID settlement. Never marks paid on admin confirmation alone.
     *
     * @return array<string, mixed>
     */
    public function verifyAndMarkPaid(string $withdrawalRef, int $adminUserId, string $password = ''): array
    {
        $this->assertAdminActor($adminUserId, $password, 'commission.withdrawal.pay');
        $this->assertExecutionAuthorized('payout verification');

        return $this->performVerifyAndMarkPaid($withdrawalRef, $adminUserId);
    }

    /**
     * Verifies on-chain evidence then settles once. Refuses PAID without verified result.
     *
     * @return array<string, mixed>
     */
    public function performVerifyAndMarkPaid(string $withdrawalRef, int $adminUserId): array
    {
        $this->assertEmergencyUnlock('PAID settlement');

        $row = $this->requireRow($withdrawalRef);
        $this->assertTransition((string) $row['status'], self::STATUS_PAID);

        $evidence = db_connect()->table('commission_withdrawal_payout_evidence')
            ->where('withdrawal_ref', $withdrawalRef)
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        if ($evidence === null) {
            throw new InvalidArgumentException('PAID requires payout evidence. Transaction hash alone is insufficient.');
        }

        $verify = service('commissionPayoutVerificationService')->verifyEvidence($evidence, $row);
        db_connect()->table('commission_withdrawal_payout_evidence')->where('id', (int) $evidence['id'])->update([
            'verification_status'      => $verify['status'],
            'verification_method'      => $verify['method'],
            'verification_message'     => $verify['message'],
            'verification_details_json'=> json_encode($verify['details'], JSON_THROW_ON_ERROR),
            'block_number'             => $verify['block_number'] ?? null,
            'confirmations'            => $verify['confirmations'] ?? null,
            'tx_status'                => $verify['tx_status'] ?? $evidence['tx_status'],
            'verified_by'              => $adminUserId,
            'verified_at'              => date('Y-m-d H:i:s'),
            'updated_at'               => date('Y-m-d H:i:s'),
        ]);

        if (($verify['status'] ?? '') !== 'verified') {
            if (($verify['status'] ?? '') === 'failed' || ($verify['status'] ?? '') === 'tx_failed') {
                db_connect()->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                    'status'              => self::STATUS_FAILED,
                    'verification_status' => $verify['status'],
                    'updated_at'          => date('Y-m-d H:i:s'),
                ]);
                $this->recordEvent($withdrawalRef, 'verification_failed', $adminUserId, (string) $row['status'], self::STATUS_FAILED, $verify);
            }
            throw new RuntimeException('Payout verification did not confirm payment: ' . ($verify['message'] ?? 'blocked'));
        }

        $db = db_connect();
        $db->transBegin();
        try {
            $locked = $db->query(
                'SELECT * FROM commission_withdrawal_requests WHERE withdrawal_ref = ? FOR UPDATE',
                [$withdrawalRef]
            )->getRowArray();
            if ($locked === null || (string) $locked['status'] === self::STATUS_PAID) {
                throw new InvalidArgumentException('Withdrawal already paid or missing.');
            }
            if ((int) $locked['is_paid'] === 1) {
                throw new InvalidArgumentException('Withdrawal already settled.');
            }

            $this->wallets->settleWithdrawal((int) $locked['member_user_id'], (string) $locked['member_id'], [
                'amount'          => (string) $locked['amount'],
                'reference_type'  => 'commission_withdrawal',
                'reference_id'    => $withdrawalRef,
                'idempotency_key' => 'wd-settle:' . $withdrawalRef,
                'created_by'      => $adminUserId,
            ]);

            $now = date('Y-m-d H:i:s');
            $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'              => self::STATUS_PAID,
                'is_paid'             => 1,
                'paid_at'             => $now,
                'paid_amount'         => $evidence['paid_amount'] ?? $locked['amount'],
                'verification_status' => 'verified',
                'updated_at'          => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('PAID settlement failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }

        $this->recordEvent($withdrawalRef, 'paid', $adminUserId, (string) $row['status'], self::STATUS_PAID, [
            'evidence_ref' => $evidence['evidence_ref'],
        ]);
        $this->audit->log('commission.withdrawal_paid', $adminUserId, 'commission_withdrawal', $withdrawalRef, [
            'evidence_ref' => $evidence['evidence_ref'],
        ]);

        return $this->findByRef($withdrawalRef) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $ref): ?array
    {
        $row = db_connect()->table('commission_withdrawal_requests')
            ->where('withdrawal_ref', $ref)
            ->get()
            ->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['events'] = db_connect()->table('commission_withdrawal_events')
            ->where('withdrawal_ref', $ref)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
        $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);
        $row['evidence'] = [];
        if (db_connect()->tableExists('commission_withdrawal_payout_evidence')) {
            $row['evidence'] = db_connect()->table('commission_withdrawal_payout_evidence')
                ->where('withdrawal_ref', $ref)
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOwned(string $ref, int $memberUserId): ?array
    {
        $row = $this->findByRef($ref);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForMember(int $memberUserId, int $limit = 50): array
    {
        $rows = db_connect()->table('commission_withdrawal_requests')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
        foreach ($rows as &$row) {
            $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function adminList(array $filters = [], int $limit = 50): array
    {
        $b = db_connect()->table('commission_withdrawal_requests')->orderBy('id', 'DESC');
        if (! empty($filters['status'])) {
            $b->where('status', (string) $filters['status']);
        }
        if (! empty($filters['member_q'])) {
            $q = trim((string) $filters['member_q']);
            $b->groupStart()->like('member_id', $q)->orLike('withdrawal_ref', $q)->groupEnd();
        }
        $rows = $b->limit(max(1, min(200, $limit)))->get()->getResultArray();
        foreach ($rows as &$row) {
            $row['destination_masked'] = $this->maskAddress((string) $row['destination_address']);
            $profile = $this->profiles->findByMemberId((string) $row['member_id']);
            $row['member_name'] = $profile->member_name ?? '';
        }

        return $rows;
    }

    public function countByStatus(?string $status = null): int
    {
        $b = db_connect()->table('commission_withdrawal_requests');
        if ($status !== null && $status !== '') {
            $b->where('status', $status);
        }

        return $b->countAllResults();
    }

    /**
     * @return array<string, int>
     */
    public function adminStatusCounts(): array
    {
        $statuses = [
            self::STATUS_PENDING_ADMIN_REVIEW,
            self::STATUS_APPROVED,
            self::STATUS_PAYOUT_IN_PROGRESS,
            self::STATUS_PAYOUT_SUBMITTED,
            self::STATUS_PAID,
            self::STATUS_REJECTED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_DRAFT,
        ];
        $out = [];
        foreach ($statuses as $st) {
            $out[$st] = $this->countByStatus($st);
        }
        $out['total'] = $this->countByStatus(null);

        return $out;
    }

    public function maskAddress(string $address): string
    {
        $len = strlen($address);
        if ($len <= 10) {
            return str_repeat('*', $len);
        }

        return substr($address, 0, 6) . str_repeat('*', max(4, $len - 10)) . substr($address, -4);
    }

    public function assertTransition(string $from, string $to): void
    {
        $allowed = self::TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new InvalidArgumentException('Invalid withdrawal status transition: ' . $from . ' → ' . $to);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function performReleaseTerminal(
        string $withdrawalRef,
        int $actorUserId,
        string $toStatus,
        string $reason,
        string $eventType
    ): array {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM commission_withdrawal_requests WHERE withdrawal_ref = ? FOR UPDATE',
                [$withdrawalRef]
            )->getRowArray();
            if ($row === null) {
                throw new InvalidArgumentException('Withdrawal not found.');
            }
            $this->assertTransition((string) $row['status'], $toStatus);

            if ((int) $row['is_reserved'] === 1) {
                $releaseKey = 'wd-release:' . $withdrawalRef;
                $existing = $db->table('commission_wallet_ledger_entries')
                    ->where('idempotency_key', $releaseKey)
                    ->get()
                    ->getRowArray();
                if ($existing === null) {
                    $this->wallets->releaseForWithdrawal((int) $row['member_user_id'], (string) $row['member_id'], [
                        'amount'          => (string) $row['amount'],
                        'reference_type'  => 'commission_withdrawal',
                        'reference_id'    => $withdrawalRef,
                        'idempotency_key' => $releaseKey,
                        'created_by'      => $actorUserId,
                    ]);
                }
            }

            $now = date('Y-m-d H:i:s');
            $db->table('commission_withdrawal_requests')->where('withdrawal_ref', $withdrawalRef)->update([
                'status'           => $toStatus,
                'rejection_reason' => substr($reason, 0, 500),
                'reviewed_at'      => $now,
                'reviewer_id'      => $actorUserId,
                'is_reserved'      => 0,
                'updated_at'       => $now,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Withdrawal terminal release failed.');
            }
            $db->transCommit();

            $this->recordEvent($withdrawalRef, $eventType, $actorUserId, (string) $row['status'], $toStatus, [
                'reason' => $reason,
            ]);
            $this->audit->log('commission.withdrawal_' . $eventType, $actorUserId, 'commission_withdrawal', $withdrawalRef, [
                'reason' => $reason,
            ]);

            return $this->findByRef($withdrawalRef) ?? [];
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
    private function requireRow(string $ref): array
    {
        $row = db_connect()->table('commission_withdrawal_requests')
            ->where('withdrawal_ref', $ref)
            ->get()
            ->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Withdrawal not found.');
        }

        return $row;
    }

    /**
     * Emergency unlock only (Platform.commissionWithdrawalEnabled / sandbox).
     * Does not grant Admin operational request activation.
     */
    private function assertEmergencyUnlock(string $action): void
    {
        $cfg = $this->config ?? config('Platform');
        if (! (bool) $cfg->commissionWithdrawalEnabled
            && ! service('financialSandboxService')->allows('commission_withdrawal')
        ) {
            throw new RuntimeException('Withdrawals Not Activated. ' . $action . ' is blocked server-side.');
        }
    }

    private function assertRegisteredWallet(object $profile, string $address): void
    {
        if ($address === '') {
            throw new InvalidArgumentException('Registered USDT wallet is required before withdrawal.');
        }
        $pattern = ($this->config ?? config('Platform'))->bep20Pattern;
        if (! preg_match($pattern, $address)) {
            throw new InvalidArgumentException('Invalid BEP20 destination address.');
        }

        // Treat empty wallet as unverified; profile wallet is the confirmed destination.
        $cooldown = $profile->usdt_wallet_cooldown_until ?? null;
        if ($cooldown !== null && $cooldown !== '' && strtotime((string) $cooldown) > time()) {
            // Recent change is allowed for preview but flagged — submit path requires review if address drifts.
        }
    }

    private function assertNotImpersonation(): void
    {
        if (service('impersonationService')->activeContext() !== null) {
            throw new RuntimeException('Withdrawals are not allowed from Admin support-view impersonation.');
        }
        // Web-only URI guard — ignore stale request paths under CLI/PHPUnit.
        if (! is_cli()) {
            $uri = uri_string();
            if (str_starts_with($uri, 'support-view')) {
                throw new RuntimeException('Withdrawals are not allowed from Admin support-view.');
            }
        }
    }

    private function assertAdminActor(int $adminUserId, string $password, string $permission = 'commission.withdrawal.review'): void
    {
        $this->assertNotImpersonation();
        $user = auth()->user();
        if ($user === null || (int) $user->id !== $adminUserId) {
            throw new InvalidArgumentException('Authenticated admin mismatch.');
        }
        if (! $user->can($permission)) {
            throw new InvalidArgumentException('Missing ' . $permission . ' permission.');
        }
        if (! $this->verifyPassword($user, $password)) {
            throw new InvalidArgumentException('Password confirmation failed.');
        }
    }

    private function assertRateLimit(int $memberUserId): void
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $count = db_connect()->table('commission_withdrawal_requests')
            ->where('member_user_id', $memberUserId)
            ->where('created_at >=', $since)
            ->countAllResults();
        if ($count >= 20) {
            throw new InvalidArgumentException('Withdrawal rate limit exceeded. Try again later.');
        }
    }

    private function verifyPassword(object $user, string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }
        try {
            $hash = (string) ($user->password_hash ?? '');
            if ($hash !== '' && service('passwords')->verify($password, $hash)) {
                return true;
            }
            // Shield email_password identities store the hash in secret2/secret.
            $identity = db_connect()->table('auth_identities')
                ->where('user_id', (int) $user->id)
                ->where('type', 'email_password')
                ->get()->getRowArray();
            $secret = (string) ($identity['secret2'] ?? $identity['secret'] ?? '');

            return $secret !== '' && service('passwords')->verify($password, $secret);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function recordEvent(
        string $ref,
        string $type,
        ?int $actor,
        ?string $from,
        ?string $to,
        ?array $payload
    ): void {
        db_connect()->table('commission_withdrawal_events')->insert([
            'withdrawal_ref' => $ref,
            'event_type'     => $type,
            'actor_user_id'  => $actor,
            'from_status'    => $from,
            'to_status'      => $to,
            'payload_json'   => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
    }
}
