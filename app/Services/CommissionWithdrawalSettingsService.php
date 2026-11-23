<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin-controlled commission withdrawal fee / minimum / request activation.
 * Never invents amounts. Never writes production .env kill-switches.
 *
 * Operational enable (requests_enabled) is Admin-controlled.
 * Emergency unlock remains Platform::commissionWithdrawalEnabled (deployer / .env).
 */
class CommissionWithdrawalSettingsService
{
    public const POLICY_PENDING  = 'pending_business_decision';
    public const POLICY_APPROVED = 'approved';

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   minimum_usdt: ?string,
     *   fee_usdt: ?string,
     *   policy_status: string,
     *   requests_enabled: bool,
     *   notes: ?string,
     *   updated_by: ?int,
     *   updated_at: ?string,
     *   policy_approved: bool,
     *   emergency_unlock: bool,
     *   operational_ready: bool,
     *   can_activate_requests: bool,
     *   blockers: list<string>
     * }
     */
    public function current(): array
    {
        $row = $this->row();
        $min = $row['minimum_usdt'] !== null && $row['minimum_usdt'] !== ''
            ? MoneyMath::normalize((string) $row['minimum_usdt'], 8)
            : null;
        $fee = $row['fee_usdt'] !== null && $row['fee_usdt'] !== ''
            ? MoneyMath::normalize((string) $row['fee_usdt'], 8)
            : null;
        $policy     = strtolower(trim((string) ($row['policy_status'] ?? self::POLICY_PENDING)));
        $approved   = $this->isPolicyApproved($policy, $min, $fee);
        $requestsOn = (int) ($row['requests_enabled'] ?? 0) === 1;
        $emergency  = (bool) (($this->config ?? config('Platform'))->commissionWithdrawalEnabled);
        $chainOn    = (bool) (($this->config ?? config('Platform'))->commissionBlockchainPayoutEnabled);

        $blockers = [];
        if (! $approved) {
            $blockers[] = 'Fee / minimum policy is not approved with valid amounts.';
        }
        if (! $emergency) {
            $blockers[] = 'Emergency unlock is OFF (Platform.commissionWithdrawalEnabled). Deployer must enable it in server config before Admin can activate requests.';
        }
        if ($chainOn) {
            $blockers[] = 'Automatic blockchain payout must remain OFF.';
        }
        if (! $requestsOn) {
            $blockers[] = 'Admin request activation is OFF.';
        }

        return [
            'minimum_usdt'           => $min,
            'fee_usdt'               => $fee,
            'policy_status'          => $policy !== '' ? $policy : self::POLICY_PENDING,
            'requests_enabled'       => $requestsOn,
            'notes'                  => $row['notes'] !== null ? (string) $row['notes'] : null,
            'updated_by'             => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'updated_at'             => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'policy_approved'        => $approved,
            'emergency_unlock'       => $emergency,
            'operational_ready'      => $approved && $emergency && ! $chainOn,
            'can_activate_requests'  => $approved && $emergency && ! $chainOn,
            'blockers'               => $blockers,
        ];
    }

    public function policyApproved(): bool
    {
        $c = $this->current();

        return $c['policy_approved'];
    }

    public function requestsEnabled(): bool
    {
        return $this->current()['requests_enabled'];
    }

    /**
     * Save Admin-configured amounts and optional request activation.
     * Activation is refused unless policy is valid and emergency unlock is ON.
     *
     * @param array{minimum_usdt?: string, fee_usdt?: string, approve_policy?: bool, requests_enabled?: bool, notes?: string} $input
     * @return array<string, mixed>
     */
    public function update(array $input, int $adminUserId): array
    {
        if ($adminUserId <= 0) {
            throw new InvalidArgumentException('Admin actor is required.');
        }

        $before = $this->current();

        $minRaw = array_key_exists('minimum_usdt', $input) ? trim((string) $input['minimum_usdt']) : ($before['minimum_usdt'] ?? '');
        $feeRaw = array_key_exists('fee_usdt', $input) ? trim((string) $input['fee_usdt']) : ($before['fee_usdt'] ?? '');

        if ($minRaw === '' || $feeRaw === '') {
            throw new InvalidArgumentException('Minimum withdrawal and fee must both be provided. Do not leave either blank.');
        }

        try {
            $min = MoneyMath::normalize($minRaw, 8);
            $fee = MoneyMath::normalize($feeRaw, 8);
        } catch (\Throwable) {
            throw new InvalidArgumentException('Minimum and fee must be valid decimal USDT amounts (8 places).');
        }

        if (MoneyMath::compare($min, '0', 8) !== 1) {
            throw new InvalidArgumentException('Minimum withdrawal must be greater than zero.');
        }
        if (MoneyMath::compare($fee, '0', 8) === -1) {
            throw new InvalidArgumentException('Withdrawal fee cannot be negative.');
        }
        if (MoneyMath::compare($fee, $min, 8) === 1) {
            throw new InvalidArgumentException('Withdrawal fee cannot exceed the minimum withdrawal amount.');
        }

        $approve = ! empty($input['approve_policy']) || strtolower((string) ($input['policy_status'] ?? '')) === self::POLICY_APPROVED;
        $policy  = $approve ? self::POLICY_APPROVED : self::POLICY_PENDING;

        $wantRequests = ! empty($input['requests_enabled']);
        if ($wantRequests) {
            if ($policy !== self::POLICY_APPROVED || ! $this->isPolicyApproved($policy, $min, $fee)) {
                throw new InvalidArgumentException('Cannot activate withdrawal requests until fee/minimum policy is approved with valid amounts.');
            }
            if (! (bool) (($this->config ?? config('Platform'))->commissionWithdrawalEnabled)) {
                throw new InvalidArgumentException(
                    'Cannot activate withdrawal requests while the emergency unlock (Platform.commissionWithdrawalEnabled) is OFF. Ask the deployer to enable it in server configuration first.'
                );
            }
            if ((bool) (($this->config ?? config('Platform'))->commissionBlockchainPayoutEnabled)) {
                throw new InvalidArgumentException('Cannot activate withdrawal requests while automatic blockchain payout is ON. It must stay OFF for manual BEP20 settlement.');
            }
        }

        $notes = array_key_exists('notes', $input) ? trim((string) $input['notes']) : ($before['notes'] ?? null);
        if ($notes === '') {
            $notes = null;
        }

        $now = date('Y-m-d H:i:s');
        $new = [
            'minimum_usdt'     => $min,
            'fee_usdt'         => $fee,
            'policy_status'    => $policy,
            'requests_enabled' => $wantRequests ? 1 : 0,
            'notes'            => $notes,
            'updated_by'       => $adminUserId,
            'updated_at'       => $now,
        ];

        $db = db_connect();
        $db->transBegin();
        try {
            $this->ensureRow();
            $db->table('commission_withdrawal_settings')->where('id', 1)->update($new);
            $db->table('commission_withdrawal_settings_audit')->insert([
                'actor_user_id' => $adminUserId,
                'action'        => 'updated',
                'old_json'      => json_encode([
                    'minimum_usdt'     => $before['minimum_usdt'],
                    'fee_usdt'         => $before['fee_usdt'],
                    'policy_status'    => $before['policy_status'],
                    'requests_enabled' => $before['requests_enabled'],
                ], JSON_THROW_ON_ERROR),
                'new_json'      => json_encode([
                    'minimum_usdt'     => $min,
                    'fee_usdt'         => $fee,
                    'policy_status'    => $policy,
                    'requests_enabled' => (bool) $wantRequests,
                ], JSON_THROW_ON_ERROR),
                'created_at'    => $now,
            ]);
            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to save withdrawal settings.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }

        $this->audit->log('commission.withdrawal_settings_updated', $adminUserId, 'commission_withdrawal_settings', '1', [
            'minimum_usdt'     => $min,
            'fee_usdt'         => $fee,
            'policy_status'    => $policy,
            'requests_enabled' => (bool) $wantRequests,
        ]);

        return $this->current();
    }

    /**
     * Test/helper: apply settings without HTTP (still audited).
     */
    public function applyForTests(string $minimum, string $fee, bool $requestsEnabled, int $actorUserId = 1): array
    {
        return $this->update([
            'minimum_usdt'     => $minimum,
            'fee_usdt'         => $fee,
            'approve_policy'   => true,
            'requests_enabled' => $requestsEnabled,
        ], $actorUserId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function auditTrail(int $limit = 50): array
    {
        if (! db_connect()->tableExists('commission_withdrawal_settings_audit')) {
            return [];
        }

        return db_connect()->table('commission_withdrawal_settings_audit')
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(): array
    {
        $this->ensureRow();
        $row = db_connect()->table('commission_withdrawal_settings')->where('id', 1)->get()->getRowArray();

        return $row ?? [
            'minimum_usdt' => null, 'fee_usdt' => null, 'policy_status' => self::POLICY_PENDING,
            'requests_enabled' => 0, 'notes' => null, 'updated_by' => null, 'updated_at' => null,
        ];
    }

    private function ensureRow(): void
    {
        $db = db_connect();
        if (! $db->tableExists('commission_withdrawal_settings')) {
            throw new RuntimeException('commission_withdrawal_settings table is missing. Run migrations.');
        }
        $exists = $db->table('commission_withdrawal_settings')->where('id', 1)->countAllResults();
        if ($exists === 0) {
            $now = date('Y-m-d H:i:s');
            $db->table('commission_withdrawal_settings')->insert([
                'id' => 1, 'minimum_usdt' => null, 'fee_usdt' => null,
                'policy_status' => self::POLICY_PENDING, 'requests_enabled' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function isPolicyApproved(string $policy, ?string $min, ?string $fee): bool
    {
        if ($policy !== self::POLICY_APPROVED || $min === null || $fee === null) {
            return false;
        }

        return MoneyMath::compare($min, '0', 8) === 1 && MoneyMath::compare($fee, '0', 8) >= 0;
    }
}
