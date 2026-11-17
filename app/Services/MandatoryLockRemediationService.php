<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 12D — controlled LOCK1000 remediation for an approved purchase missing mandatory lock.
 * Default dry-run. Production execution requires ProductionSafetyGate authorization.
 */
class MandatoryLockRemediationService
{
    public function __construct(
        private readonly CoinLockingService $locking = new CoinLockingService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ProductionSafetyGate $gate = new ProductionSafetyGate()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(string $purchaseRef, ?string $dbGroup = null): array
    {
        $purchaseRef = trim($purchaseRef);
        if ($purchaseRef === '') {
            throw new InvalidArgumentException('purchase_ref is required.');
        }

        $info = $this->gate->inspect($dbGroup);
        $db   = Database::connect($info['group'], false);

        $purchase = $db->table('coin_purchase_requests')
            ->where('purchase_ref', $purchaseRef)
            ->get()
            ->getRowArray();
        if ($purchase === null) {
            throw new InvalidArgumentException('Purchase not found: ' . $purchaseRef);
        }

        $userId   = (int) ($purchase['member_user_id'] ?? 0);
        $memberId = (string) ($purchase['member_id'] ?? '');
        $qty      = MoneyMath::normalize((string) ($purchase['coin_quantity'] ?? '0'), $this->scale());
        $status   = (string) ($purchase['status'] ?? '');
        $reviewed = (string) ($purchase['reviewed_at'] ?? '');

        $ownership = $db->table('member_coin_balances')
            ->where('member_user_id', $userId)
            ->get()
            ->getRowArray();

        $existingLock = null;
        if ($db->tableExists('member_coin_locks')) {
            $existingLock = $db->table('member_coin_locks')
                ->where('purchase_ref', $purchaseRef)
                ->where('lock_kind', CoinLockingService::KIND_MANDATORY_PURCHASE)
                ->get()
                ->getRowArray();
        }

        $avail    = MoneyMath::normalize((string) ($ownership['available_balance'] ?? '0'), $this->scale());
        $locked   = MoneyMath::normalize((string) ($ownership['locked_balance'] ?? '0'), $this->scale());
        $reserved = MoneyMath::normalize((string) ($ownership['reserved_balance'] ?? '0'), $this->scale());
        $balance  = MoneyMath::normalize((string) ($ownership['balance'] ?? '0'), $this->scale());

        $maturesAt = $reviewed !== ''
            ? date('Y-m-d H:i:s', strtotime($reviewed . ' +1000 days'))
            : null;

        $checks = [
            'purchase_approved' => $status === 'approved',
            'quantity_positive' => MoneyMath::isPositive($qty, $this->scale()),
            'ownership_present' => $ownership !== null,
            'reviewed_at_set'   => $reviewed !== '',
            'no_existing_mandatory_lock' => $existingLock === null,
            'sufficient_available' => MoneyMath::compare($avail, $qty, $this->scale()) >= 0,
            'total_matches_parts' => MoneyMath::compare(
                $balance,
                MoneyMath::add(MoneyMath::add($avail, $locked, $this->scale()), $reserved, $this->scale()),
                $this->scale()
            ) === 0,
        ];

        $ready = ! in_array(false, $checks, true);

        return [
            'database'     => $info['database'],
            'group'        => $info['group'],
            'is_live'      => $info['is_live'],
            'purchase_ref' => $purchaseRef,
            'member_id'    => $memberId,
            'member_user_id' => $userId,
            'purchase'     => [
                'status'       => $status,
                'coin_quantity'=> $qty,
                'reviewed_at'  => $reviewed,
                'total_usdt'   => $purchase['total_usdt'] ?? null,
            ],
            'existing_mandatory_lock' => $existingLock,
            'idempotency_key' => 'mandatory-lock:' . $purchaseRef,
            'checks'          => $checks,
            'ready'           => $ready,
            'before'          => [
                'available_balance' => $avail,
                'locked_balance'    => $locked,
                'reserved_balance'  => $reserved,
                'balance'           => $balance,
            ],
            'after_projected' => [
                'available_balance' => MoneyMath::sub($avail, $qty, $this->scale()),
                'locked_balance'    => MoneyMath::add($locked, $qty, $this->scale()),
                'reserved_balance'  => $reserved,
                'balance'           => $balance,
                'plan'              => CoinLockingService::MANDATORY_PLAN_CODE,
                'locked_at'         => $reviewed,
                'matures_at'        => $maturesAt,
                'duration_days'     => 1000,
            ],
            'note' => $ready
                ? 'Ready for remediation via CoinLockingService::lockMandatoryForPurchase (total owned unchanged).'
                : 'Ambiguous or inconsistent records — remediation refused until checks pass.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(
        string $purchaseRef,
        bool $dryRun,
        bool $allowProduction,
        ?string $confirmPhrase,
        int $actorUserId,
        ?string $dbGroup = null
    ): array {
        $this->gate->assertKillSwitchesOff();
        $preview = $this->preview($purchaseRef, $dbGroup);

        if (! $preview['ready']) {
            throw new RuntimeException('Remediation not ready: ' . ($preview['note'] ?? 'blocked'));
        }

        if ($dryRun) {
            $this->audit->log('coin.lock_mandatory_remediation_dry_run', $actorUserId, 'coin_purchase', $purchaseRef, [
                'database' => $preview['database'],
                'before'   => $preview['before'],
                'after'    => $preview['after_projected'],
            ]);

            return array_merge($preview, [
                'executed' => false,
                'dry_run'  => true,
            ]);
        }

        $this->gate->assertMutationAllowed(
            $allowProduction,
            $confirmPhrase,
            $dbGroup,
            'mandatory_lock_remediation'
        );

        // Ensure services use the intended connection group.
        if ($dbGroup !== null && $dbGroup !== '') {
            config('Database')->defaultGroup = $dbGroup;
            Database::connect($dbGroup, false);
            db_connect($dbGroup, false);
        }

        $result = $this->locking->lockMandatoryForPurchase(
            (int) $preview['member_user_id'],
            (string) $preview['member_id'],
            (string) $preview['purchase']['coin_quantity'],
            $purchaseRef,
            $actorUserId,
            (string) $preview['purchase']['reviewed_at'],
            true
        );

        $this->audit->log('coin.lock_mandatory_remediation_executed', $actorUserId, 'coin_purchase', $purchaseRef, [
            'database'   => $preview['database'],
            'lock_ref'   => $result['lock']['lock_ref'] ?? null,
            'duplicate'  => ! empty($result['duplicate']),
            'balance'    => $result['balance'] ?? null,
        ]);

        return array_merge($preview, [
            'executed'  => true,
            'dry_run'   => false,
            'result'    => [
                'lock_ref'  => $result['lock']['lock_ref'] ?? null,
                'duplicate' => ! empty($result['duplicate']),
                'balance'   => $result['balance'] ?? null,
            ],
        ]);
    }

    private function scale(): int
    {
        return max(0, (int) config('Platform')->coinQuantityScale);
    }
}
