<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use App\Models\CoinLockingPlanModel;
use App\Models\MemberCoinLockModel;
use App\Models\MemberProfileModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 5 coin locking lifecycle. No ROI, interest, or automatic rewards.
 */
class CoinLockingService
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_MATURED   = 'matured';
    public const STATUS_UNLOCKED  = 'unlocked';
    public const STATUS_CANCELLED = 'cancelled';

    public const KIND_VOLUNTARY          = 'voluntary';
    public const KIND_MANDATORY_PURCHASE = 'mandatory_purchase';

    public const MANDATORY_PLAN_CODE = 'LOCK1000';

    public function __construct(
        private readonly CoinLockingPlanModel $plans = new CoinLockingPlanModel(),
        private readonly MemberCoinLockModel $locks = new MemberCoinLockModel(),
        private readonly MemberCoinOwnershipService $ownership = new MemberCoinOwnershipService(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly OpsJobLockService $jobLocks = new OpsJobLockService(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlans(bool $enabledOnly = false): array
    {
        return $enabledOnly ? $this->plans->listEnabled() : $this->plans->listAll();
    }

    public function findPlan(int $planId): ?array
    {
        return $this->plans->find($planId);
    }

    /**
     * @return array<string, mixed>
     */
    public function createPlan(array $input, int $actorUserId): array
    {
        $scale = $this->scale();
        $code  = strtoupper(trim((string) ($input['plan_code'] ?? '')));
        $name  = trim((string) ($input['name'] ?? ''));
        $desc  = trim((string) ($input['description'] ?? ''));
        $min   = MoneyMath::normalize((string) ($input['min_quantity'] ?? '0'), $scale);
        $maxRaw = trim((string) ($input['max_quantity'] ?? ''));
        $max   = $maxRaw === '' ? null : MoneyMath::normalize($maxRaw, $scale);
        $days  = (int) ($input['duration_days'] ?? 0);

        if ($code === '' || ! preg_match('/^[A-Z0-9_-]{3,40}$/', $code)) {
            throw new InvalidArgumentException('Plan code must be 3–40 chars: A-Z, 0-9, _ or -.');
        }
        if ($name === '') {
            throw new InvalidArgumentException('Plan name is required.');
        }
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException('Lock duration must be between 1 and 3650 days.');
        }
        if (! MoneyMath::isPositive($min, $scale)) {
            throw new InvalidArgumentException('Minimum lock quantity must be greater than zero.');
        }
        if ($max !== null && MoneyMath::compare($max, $min, $scale) < 0) {
            throw new InvalidArgumentException('Maximum quantity must be >= minimum quantity.');
        }
        if ($this->plans->findByCode($code) !== null) {
            throw new InvalidArgumentException('Plan code already exists.');
        }

        $now = date('Y-m-d H:i:s');
        $id  = $this->plans->insert([
            'plan_code'          => $code,
            'name'               => $name,
            'description'        => $desc !== '' ? $desc : null,
            'min_quantity'       => $min,
            'max_quantity'       => $max,
            'duration_days'      => $days,
            'is_enabled'         => 0, // disabled until explicitly enabled
            'allow_early_unlock' => 0,
            'created_by'         => $actorUserId,
            'updated_by'         => $actorUserId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ], true);

        if ($id === false) {
            throw new RuntimeException('Unable to create locking plan.');
        }

        $plan = $this->plans->find($id);
        $this->audit->log('coin.lock_plan_created', $actorUserId, 'coin_locking_plan', (string) $code, [
            'plan_id' => $id,
            'duration_days' => $days,
        ]);

        return $plan;
    }

    public function setPlanEnabled(int $planId, bool $enabled, int $actorUserId): array
    {
        $plan = $this->plans->find($planId);
        if ($plan === null) {
            throw new InvalidArgumentException('Locking plan not found.');
        }

        $this->plans->update($planId, [
            'is_enabled' => $enabled ? 1 : 0,
            'updated_by' => $actorUserId,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $updated = $this->plans->find($planId);
        $this->audit->log(
            $enabled ? 'coin.lock_plan_enabled' : 'coin.lock_plan_disabled',
            $actorUserId,
            'coin_locking_plan',
            (string) $plan['plan_code'],
            ['plan_id' => $planId]
        );

        return $updated;
    }

    /**
     * Ensure the system LOCK1000 plan exists and is enabled (Phase 11A mandatory locks).
     *
     * @return array<string, mixed>
     */
    public function ensureMandatoryPlan(int $actorUserId = 0): array
    {
        $days = (int) (($this->config ?? config('Platform'))->coinPlanLockDays);
        if ($days < 1 || $days > 3650) {
            throw new InvalidArgumentException(
                'Invalid mandatory lock duration configuration (coinPlanLockDays must be 1–3650).'
            );
        }
        $existing = $this->plans->findByCode(self::MANDATORY_PLAN_CODE);
        if ($existing === null) {
            $existing = $this->createPlan([
                'plan_code'     => self::MANDATORY_PLAN_CODE,
                'name'          => 'Mandatory 1000-Day Purchase Lock',
                'description'   => 'System plan for Coin Plan mandatory locks after purchase approval.',
                'min_quantity'  => '0.00000001',
                'max_quantity'  => '',
                'duration_days' => $days,
            ], $actorUserId > 0 ? $actorUserId : 1);
        }

        if ((int) ($existing['is_enabled'] ?? 0) !== 1) {
            $existing = $this->setPlanEnabled((int) $existing['id'], true, $actorUserId > 0 ? $actorUserId : 1);
        }

        if ((int) ($existing['duration_days'] ?? 0) !== $days) {
            // Do not rewrite historical plan duration silently; new locks use plan row as stored.
            // Report mismatch via audit only.
            $this->audit->log('coin.lock_plan_duration_mismatch', $actorUserId, 'coin_locking_plan', self::MANDATORY_PLAN_CODE, [
                'expected_days' => $days,
                'actual_days'   => (int) ($existing['duration_days'] ?? 0),
            ]);
        }

        return $existing;
    }

    /**
     * Create a mandatory 1000-day lock for an approved purchase (idempotent by purchase_ref).
     * When $manageTransaction=false, caller must own the DB transaction.
     *
     * @return array{lock: array<string, mixed>, balance: array<string, string|null>, duplicate?: bool}
     */
    public function lockMandatoryForPurchase(
        int $memberUserId,
        string $memberId,
        string $quantity,
        string $purchaseRef,
        int $actorUserId,
        ?string $lockedAt = null,
        bool $manageTransaction = true
    ): array {
        $purchaseRef = trim($purchaseRef);
        if ($purchaseRef === '') {
            throw new InvalidArgumentException('Purchase reference is required for mandatory lock.');
        }

        $existing = $this->locks->findByPurchaseRef($purchaseRef);
        if ($existing !== null) {
            return [
                'lock'      => $existing,
                'balance'   => $this->ownership->summaryForUser($memberUserId),
                'duplicate' => true,
            ];
        }

        $idem = 'mandatory-lock:' . $purchaseRef;
        $byIdem = $this->locks->findByIdempotencyKey($idem);
        if ($byIdem !== null) {
            return [
                'lock'      => $byIdem,
                'balance'   => $this->ownership->summaryForUser($memberUserId),
                'duplicate' => true,
            ];
        }

        $plan     = $this->ensureMandatoryPlan($actorUserId);
        $scale    = $this->scale();
        $qty      = MoneyMath::normalize($quantity, $scale);
        $lockedAt = $lockedAt !== null && $lockedAt !== '' ? $lockedAt : date('Y-m-d H:i:s');
        $days     = (int) ($plan['duration_days'] ?? 0);
        if ($days < 1) {
            throw new InvalidArgumentException('Mandatory lock plan duration is invalid.');
        }
        $maturesAt = date('Y-m-d H:i:s', strtotime($lockedAt . ' +' . $days . ' days'));

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Mandatory lock quantity must be greater than zero.');
        }

        $db = db_connect();
        if ($manageTransaction) {
            $db->transBegin();
        }

        try {
            $again = $db->query(
                'SELECT * FROM member_coin_locks WHERE purchase_ref = ? FOR UPDATE',
                [$purchaseRef]
            )->getRowArray();
            if ($again !== null) {
                if ($manageTransaction) {
                    $db->transCommit();
                }

                return [
                    'lock'      => $again,
                    'balance'   => $this->ownership->summaryForUser($memberUserId),
                    'duplicate' => true,
                ];
            }

            $lockRef = 'LCK-M-' . strtoupper(substr(hash('sha256', $purchaseRef), 0, 16));
            $own = $this->ownership->applyLock(
                $memberUserId,
                $memberId,
                $qty,
                $lockRef,
                $actorUserId,
                'own-lock:' . $idem,
                false
            );

            if (! empty($own['duplicate'])) {
                $existingLock = $this->locks->findByIdempotencyKey($idem) ?? $this->locks->findByPurchaseRef($purchaseRef);
                if ($existingLock !== null) {
                    if ($manageTransaction) {
                        $db->transCommit();
                    }

                    return [
                        'lock'      => $existingLock,
                        'balance'   => $this->ownership->summaryForUser($memberUserId),
                        'duplicate' => true,
                    ];
                }
            }

            $id = $this->locks->insert([
                'lock_ref'               => $lockRef,
                'member_user_id'         => $memberUserId,
                'member_id'              => $memberId,
                'purchase_ref'           => $purchaseRef,
                'lock_kind'              => self::KIND_MANDATORY_PURCHASE,
                'plan_id'                => (int) $plan['id'],
                'plan_code'              => (string) $plan['plan_code'],
                'plan_name'              => (string) $plan['name'],
                'quantity'               => $qty,
                'duration_days'          => $days,
                'status'                 => self::STATUS_ACTIVE,
                'locked_at'              => $lockedAt,
                'matures_at'             => $maturesAt,
                'ownership_lock_txn_ref' => $own['transaction']['transaction_ref'] ?? null,
                'idempotency_key'        => $idem,
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to create mandatory purchase lock.');
            }

            $lock = $this->locks->find($id);
            $this->recordEvent($lockRef, 'lock.mandatory_created', $actorUserId, 'Mandatory purchase lock', [
                'purchase_ref' => $purchaseRef,
                'quantity'     => $qty,
                'matures_at'   => $maturesAt,
                'days'         => $days,
            ]);

            if ($manageTransaction) {
                if ($db->transStatus() === false) {
                    $db->transRollback();
                    throw new RuntimeException('Mandatory lock failed.');
                }
                $db->transCommit();
            }

            $this->audit->log('coin.lock_mandatory_created', $actorUserId, 'member_coin_lock', $lockRef, [
                'purchase_ref' => $purchaseRef,
                'quantity'     => $qty,
                'days'         => $days,
            ]);

            return [
                'lock'      => $lock,
                'balance'   => $own['balance'] ?? $this->ownership->summaryForUser($memberUserId),
                'duplicate' => false,
            ];
        } catch (\Throwable $e) {
            if ($manageTransaction && $db->transDepth > 0) {
                $db->transRollback();
            }
            if ($this->isDuplicateKeyException($e)) {
                $existingLock = $this->locks->findByPurchaseRef($purchaseRef) ?? $this->locks->findByIdempotencyKey($idem);
                if ($existingLock !== null) {
                    return [
                        'lock'      => $existingLock,
                        'balance'   => $this->ownership->summaryForUser($memberUserId),
                        'duplicate' => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * Read-only preview of approved purchases missing mandatory locks (no writes).
     *
     * @return list<array{purchase_ref: string, member_id: string, coin_quantity: string, reviewed_at: ?string}>
     */
    public function previewMissingMandatoryLocks(int $limit = 200): array
    {
        if (! db_connect()->tableExists('member_coin_locks')) {
            return [];
        }

        $rows = db_connect()->query(
            'SELECT p.purchase_ref, p.member_id, p.coin_quantity, p.reviewed_at
             FROM coin_purchase_requests p
             LEFT JOIN member_coin_locks l
               ON l.purchase_ref = p.purchase_ref AND l.lock_kind = ?
             WHERE p.status = ?
               AND l.id IS NULL
             ORDER BY p.id ASC
             LIMIT ' . (int) max(1, min(2000, $limit)),
            [self::KIND_MANDATORY_PURCHASE, 'approved']
        )->getResultArray();

        return $rows;
    }

    /**
     * @return array{lock: array<string, mixed>, balance: array<string, string|null>, duplicate?: bool}
     */
    public function lockCoins(
        int $memberUserId,
        int $planId,
        string $quantity,
        ?string $idempotencyKey = null
    ): array {
        $scale   = $this->scale();
        $qty     = MoneyMath::normalize($quantity, $scale);
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        if (! MoneyMath::isPositive($qty, $scale)) {
            throw new InvalidArgumentException('Lock quantity must be greater than zero.');
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->locks->findByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return [
                    'lock'      => $existing,
                    'balance'   => $this->ownership->summaryForUser($memberUserId),
                    'duplicate' => true,
                ];
            }
        }

        $plan = $this->plans->find($planId);
        if ($plan === null || ! (int) $plan['is_enabled']) {
            throw new InvalidArgumentException('Selected locking plan is not available.');
        }

        $min = MoneyMath::normalize((string) $plan['min_quantity'], $scale);
        if (MoneyMath::compare($qty, $min, $scale) < 0) {
            throw new InvalidArgumentException('Quantity is below the plan minimum.');
        }
        if ($plan['max_quantity'] !== null && $plan['max_quantity'] !== '') {
            $max = MoneyMath::normalize((string) $plan['max_quantity'], $scale);
            if (MoneyMath::compare($qty, $max, $scale) > 0) {
                throw new InvalidArgumentException('Quantity exceeds the plan maximum.');
            }
        }

        $db = db_connect();
        $db->transBegin();

        try {
            // Re-check plan under transaction (enabled flag).
            $planRow = $db->query('SELECT * FROM coin_locking_plans WHERE id = ? FOR UPDATE', [$planId])->getRowArray();
            if ($planRow === null || ! (int) $planRow['is_enabled']) {
                throw new InvalidArgumentException('Selected locking plan is not available.');
            }

            $lockRef   = 'LCK-' . strtoupper(bin2hex(random_bytes(8)));
            $lockedAt  = date('Y-m-d H:i:s');
            $maturesAt = date('Y-m-d H:i:s', strtotime('+' . (int) $planRow['duration_days'] . ' days'));
            $memberId  = (string) $profile->member_id;

            $own = $this->ownership->applyLock(
                $memberUserId,
                $memberId,
                $qty,
                $lockRef,
                $memberUserId,
                $idempotencyKey !== null && $idempotencyKey !== '' ? 'own-lock:' . $idempotencyKey : null,
                false
            );

            if (! empty($own['duplicate'])) {
                $db->transRollback();
                $existing = $idempotencyKey ? $this->locks->findByIdempotencyKey($idempotencyKey) : null;
                if ($existing !== null) {
                    return [
                        'lock'      => $existing,
                        'balance'   => $this->ownership->summaryForUser($memberUserId),
                        'duplicate' => true,
                    ];
                }
                throw new RuntimeException('Duplicate lock detected without lock row.');
            }

            $id = $this->locks->insert([
                'lock_ref'                => $lockRef,
                'member_user_id'          => $memberUserId,
                'member_id'               => $memberId,
                'plan_id'                 => (int) $planRow['id'],
                'plan_code'               => (string) $planRow['plan_code'],
                'plan_name'               => (string) $planRow['name'],
                'quantity'                => $qty,
                'duration_days'           => (int) $planRow['duration_days'],
                'status'                  => self::STATUS_ACTIVE,
                'locked_at'               => $lockedAt,
                'matures_at'              => $maturesAt,
                'ownership_lock_txn_ref'  => $own['transaction']['transaction_ref'],
                'idempotency_key'         => $idempotencyKey !== null && $idempotencyKey !== '' ? $idempotencyKey : null,
            ], true);

            if ($id === false) {
                throw new RuntimeException('Unable to create coin lock.');
            }

            $lock = $this->locks->find($id);
            $this->recordEvent($lockRef, 'lock.created', $memberUserId, 'Coins locked under plan ' . $planRow['plan_code'], [
                'quantity' => $qty,
                'matures_at' => $maturesAt,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Coin lock failed.');
            }
            $db->transCommit();

            $this->audit->log('coin.lock_created', $memberUserId, 'member_coin_lock', $lockRef, [
                'quantity' => $qty,
                'plan_code' => $planRow['plan_code'],
            ]);

            return [
                'lock'      => $lock,
                'balance'   => $own['balance'],
                'duplicate' => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            if ($idempotencyKey && $this->isDuplicateKeyException($e)) {
                $existing = $this->locks->findByIdempotencyKey($idempotencyKey);
                if ($existing !== null) {
                    return [
                        'lock'      => $existing,
                        'balance'   => $this->ownership->summaryForUser($memberUserId),
                        'duplicate' => true,
                    ];
                }
            }

            throw $e;
        }
    }

    /**
     * @return array{lock: array<string, mixed>, balance: array<string, string|null>, duplicate?: bool}
     */
    public function unlockCoins(int $memberUserId, string $lockRef, ?string $idempotencyKey = null): array
    {
        $lockRef = trim($lockRef);
        if ($lockRef === '') {
            throw new InvalidArgumentException('Lock reference is required.');
        }

        $unlockKey = $idempotencyKey !== null && $idempotencyKey !== ''
            ? $idempotencyKey
            : 'unlock:' . $lockRef;

        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM member_coin_locks WHERE lock_ref = ? FOR UPDATE',
                [$lockRef]
            )->getRowArray();

            if ($row === null) {
                throw new InvalidArgumentException('Lock not found.');
            }
            if ((int) $row['member_user_id'] !== $memberUserId) {
                throw new InvalidArgumentException('You cannot unlock another member\'s coins.');
            }
            if ($row['status'] === self::STATUS_UNLOCKED) {
                $db->transCommit();

                return [
                    'lock'      => $row,
                    'balance'   => $this->ownership->summaryForUser($memberUserId),
                    'duplicate' => true,
                ];
            }
            if ($row['status'] === self::STATUS_CANCELLED) {
                throw new InvalidArgumentException('Cancelled locks cannot be unlocked.');
            }
            if ($row['status'] === self::STATUS_ACTIVE) {
                // Mandatory purchase locks never allow early unlock.
                if (($row['lock_kind'] ?? self::KIND_VOLUNTARY) === self::KIND_MANDATORY_PURCHASE) {
                    throw new InvalidArgumentException('Mandatory purchase locks cannot be unlocked before maturity.');
                }
                // Voluntary early unlock disabled by default.
                $plan = $this->plans->find((int) $row['plan_id']);
                $allowEarly = $plan !== null && (int) ($plan['allow_early_unlock'] ?? 0) === 1;
                if (! $allowEarly) {
                    throw new InvalidArgumentException('This lock has not matured yet. Early unlock is disabled.');
                }
            }
            if ($row['status'] !== self::STATUS_MATURED && $row['status'] !== self::STATUS_ACTIVE) {
                throw new InvalidArgumentException('Lock is not eligible for unlock.');
            }

            if ($row['unlock_idempotency_key'] !== null && $row['unlock_idempotency_key'] !== '') {
                $db->transCommit();

                return [
                    'lock'      => $row,
                    'balance'   => $this->ownership->summaryForUser($memberUserId),
                    'duplicate' => true,
                ];
            }

            $own = $this->ownership->applyUnlock(
                $memberUserId,
                (string) $row['member_id'],
                (string) $row['quantity'],
                $lockRef,
                $memberUserId,
                'own-' . $unlockKey,
                false
            );

            $now = date('Y-m-d H:i:s');
            $db->table('member_coin_locks')->where('id', (int) $row['id'])->update([
                'status'                    => self::STATUS_UNLOCKED,
                'unlocked_at'               => $now,
                'ownership_unlock_txn_ref'  => $own['transaction']['transaction_ref'],
                'unlock_idempotency_key'    => $unlockKey,
                'updated_at'                => $now,
            ]);

            $this->recordEvent($lockRef, 'lock.unlocked', $memberUserId, 'Locked coins restored to available balance', [
                'quantity' => $row['quantity'],
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unlock failed.');
            }
            $db->transCommit();

            $updated = $this->locks->findByRef($lockRef);
            $this->audit->log('coin.lock_unlocked', $memberUserId, 'member_coin_lock', $lockRef, [
                'quantity' => $row['quantity'],
            ]);

            return [
                'lock'      => $updated,
                'balance'   => $own['balance'],
                'duplicate' => false,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * Mark active locks past matures_at as matured. No rewards.
     *
     * @return array{ran: bool, skipped: bool, reason: ?string, matured: int, scanned: int}
     */
    public function checkMaturity(int $limit = 100, ?string $owner = null): array
    {
        $owner = $owner ?? ('cli:' . gethostname() . ':' . getmypid());
        $limit = max(1, min(500, $limit));

        if (! $this->jobLocks->acquire('coins_check_maturity', $owner, 600)) {
            return [
                'ran'     => false,
                'skipped' => true,
                'reason'  => 'Another maturity check holds the lock.',
                'matured' => 0,
                'scanned' => 0,
            ];
        }

        $matured = 0;
        $scanned = 0;

        try {
            $now  = date('Y-m-d H:i:s');
            $rows = db_connect()->table('member_coin_locks')
                ->where('status', self::STATUS_ACTIVE)
                ->where('matures_at <=', $now)
                ->orderBy('id', 'ASC')
                ->limit($limit)
                ->get()
                ->getResultArray();

            $scanned = count($rows);
            foreach ($rows as $row) {
                if ($this->markMatured((string) $row['lock_ref'])) {
                    $matured++;
                }
            }

            return [
                'ran'     => true,
                'skipped' => false,
                'reason'  => null,
                'matured' => $matured,
                'scanned' => $scanned,
            ];
        } finally {
            $this->jobLocks->release('coins_check_maturity', $owner);
        }
    }

    public function markMatured(string $lockRef): bool
    {
        $db = db_connect();
        $db->transBegin();

        try {
            $row = $db->query(
                'SELECT * FROM member_coin_locks WHERE lock_ref = ? FOR UPDATE',
                [$lockRef]
            )->getRowArray();

            if ($row === null) {
                $db->transRollback();

                return false;
            }

            if ($row['status'] === self::STATUS_MATURED || $row['status'] === self::STATUS_UNLOCKED) {
                $db->transCommit();

                return false; // idempotent no-op
            }

            if ($row['status'] !== self::STATUS_ACTIVE) {
                $db->transRollback();

                return false;
            }

            if (strtotime((string) $row['matures_at']) > time()) {
                $db->transRollback();

                return false;
            }

            $now = date('Y-m-d H:i:s');
            $db->table('member_coin_locks')->where('id', (int) $row['id'])->update([
                'status'     => self::STATUS_MATURED,
                'matured_at' => $now,
                'updated_at' => $now,
            ]);

            $this->recordEvent($lockRef, 'lock.matured', null, 'Lock reached maturity; eligible for unlock (no rewards)');

            if ($db->transStatus() === false) {
                $db->transRollback();

                return false;
            }
            $db->transCommit();

            return true;
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    public function findLockByRef(string $lockRef): ?array
    {
        return $this->locks->findByRef($lockRef);
    }

    public function findOwnedLock(int $memberUserId, string $lockRef): ?array
    {
        $row = $this->locks->findByRef($lockRef);
        if ($row === null || (int) $row['member_user_id'] !== $memberUserId) {
            return null;
        }

        return $row;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, pager: mixed}
     */
    public function searchLocks(?string $status = null, ?int $memberUserId = null, int $perPage = 25): array
    {
        if ($status !== null && $status !== '') {
            $this->locks->where('status', $status);
        }
        if ($memberUserId !== null) {
            $this->locks->where('member_user_id', $memberUserId);
        }
        $this->locks->orderBy('id', 'DESC');

        return [
            'rows'  => $this->locks->paginate($perPage),
            'pager' => $this->locks->pager,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function eventsForLock(string $lockRef): array
    {
        return db_connect()->table('member_coin_lock_events')
            ->where('lock_ref', $lockRef)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return array{
     *   summary: array<string, string|null>,
     *   active: list<array<string, mixed>>,
     *   matured: list<array<string, mixed>>,
     *   recent: list<array<string, mixed>>
     * }
     */
    public function memberDashboardSnapshot(int $memberUserId): array
    {
        return [
            'summary' => $this->ownership->summaryForUser($memberUserId),
            'active'  => $this->locks->forMember($memberUserId, self::STATUS_ACTIVE, 10),
            'matured' => $this->locks->forMember($memberUserId, self::STATUS_MATURED, 10),
            'recent'  => $this->locks->forMember($memberUserId, null, 10),
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function recordEvent(string $lockRef, string $type, ?int $actorUserId, string $detail, array $metadata = []): void
    {
        db_connect()->table('member_coin_lock_events')->insert([
            'lock_ref'      => $lockRef,
            'event_type'    => $type,
            'actor_user_id' => $actorUserId,
            'detail'        => mb_substr($detail, 0, 500),
            'metadata'      => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }

    private function isDuplicateKeyException(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate') || str_contains($message, 'unique');
    }
}
