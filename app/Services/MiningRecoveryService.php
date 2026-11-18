<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Models\MemberProfileModel;
use Config\Platform;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 14 — Dual-control mining recovery proposals & Phase 13B-backed execution.
 * Never uses legacy mining:daily for posting; never backdates effective dates.
 */
class MiningRecoveryService
{
    public function __construct(
        private readonly ?Platform $config = null,
        private readonly ?MiningRateService $rates = null,
        private readonly ?MiningEligibleOwnershipSnapshotService $snapshots = null,
        private readonly ?MiningWalletService $wallets = null,
    ) {
    }

    /**
     * Read-only assessment of expected vs posted vs missing mining credits for one IST business date.
     *
     * @return array<string, mixed>
     */
    public function assessDate(
        string $businessDate,
        MiningCalculationPolicy $policy,
        ?string $effectiveStartDate = null,
        bool $allowIncompleteDay = false
    ): array {
        $db    = db_connect();
        $rates = $this->rates ?? new MiningRateService();
        $snaps = $this->snapshots ?? new MiningEligibleOwnershipSnapshotService($this->config);
        $wallets = $this->wallets ?? new MiningWalletService(new AuditLogger(), $this->config);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            throw new InvalidArgumentException('Business date must be YYYY-MM-DD.');
        }

        $rate = $rates->approvedForDate($businessDate);
        if ($rate === null) {
            throw new RuntimeException('No approved mining rate covers ' . $businessDate . '.');
        }

        if ($effectiveStartDate !== null && strcmp($businessDate, $effectiveStartDate) < 0) {
            return [
                'business_date'              => $businessDate,
                'before_effective_date'      => true,
                'effective_start_date'       => $effectiveStartDate,
                'rate_ref'                   => $rate['rate_ref'],
                'policy_version'             => $policy->version(),
                'eligible_members'           => 0,
                'blocked_members'            => 0,
                'total_eligible_coins'       => '0.00000000',
                'total_expected_coins'       => '0.00000000',
                'existing_posted_coins'      => '0.00000000',
                'missing_coins'              => '0.00000000',
                'inventory_available_before' => $this->inventoryAvailable(),
                'items'                      => [],
            ];
        }

        $cutoff = $snaps->cutoff($businessDate, $policy, null, $allowIncompleteDay);
        $divisor = $rates->monthDayDivisor($businessDate, (string) ($rate['effective_month'] ?? ''), $policy->divisorMode);
        $profiles = (new MemberProfileModel())->excludeRootSponsors()->orderBy('user_id', 'ASC')->findAll();
        $userIds  = array_map(static fn ($p): int => (int) $p->user_id, $profiles);
        $profileByUid = [];
        foreach ($profiles as $p) {
            $profileByUid[(int) $p->user_id] = $p;
        }

        $batchSnap = $snaps->snapshotBatch($userIds, $cutoff['cutoff_utc'], $policy);

        $eligibleMembers = 0;
        $blockedMembers  = 0;
        $totEligible     = '0.00000000';
        $totExpected     = '0.00000000';
        $totPosted       = '0.00000000';
        $totMissing      = '0.00000000';
        $items           = [];

        foreach ($userIds as $uid) {
            $p    = $profileByUid[$uid];
            $snap = $batchSnap[$uid] ?? ['status' => 'ZERO', 'eligible' => '0.00000000', 'evidence_ref' => null];
            $posted = $wallets->postedAmountForDate($uid, $businessDate);
            $expected = '0.00000000';

            if (($snap['status'] ?? '') === MiningEligibleOwnershipSnapshotService::STATUS_BLOCKED) {
                $blockedMembers++;
            } elseif (($p->account_status ?? '') === 'active'
                && ($snap['status'] ?? '') === MiningEligibleOwnershipSnapshotService::STATUS_ELIGIBLE
                && bccomp((string) $snap['eligible'], '0', 8) > 0
            ) {
                $eligibleMembers++;
                $expected = MiningMath::dailyAmount(
                    (string) $snap['eligible'],
                    (string) $rate['rate_percent'],
                    $divisor,
                    (int) ($rate['precision_scale'] ?? 8),
                    $policy->rounding
                );
                $totEligible = bcadd($totEligible, (string) $snap['eligible'], 8);
                $totExpected = bcadd($totExpected, $expected, 8);
            }

            $totPosted = bcadd($totPosted, $posted, 8);
            $diff      = bcsub($expected, $posted, 8);
            $missing   = bccomp($diff, '0', 8) > 0 ? $diff : '0.00000000';
            $totMissing = bcadd($totMissing, $missing, 8);

            $items[] = [
                'business_date'        => $businessDate,
                'member_user_id'       => $uid,
                'member_id'            => (string) $p->member_id,
                'account_status'       => (string) $p->account_status,
                'eligible_coins'       => (string) ($snap['eligible'] ?? '0.00000000'),
                'expected_coins'       => $expected,
                'already_posted_coins' => $posted,
                'missing_coins'        => $missing,
                'evidence_ref'         => $snap['evidence_ref'] ?? null,
                'snapshot_status'      => (string) ($snap['status'] ?? 'ZERO'),
            ];
        }

        // Compare against mining_wallet_ledger & mining_wallets totals directly
        $walletSum = $db->tableExists('mining_wallets')
            ? (string) (($db->table('mining_wallets')->selectSum('available_balance', 's')->get()->getRowArray()['s']) ?? '0')
            : '0';
        $ledgerSum = $db->tableExists('mining_wallet_ledger')
            ? (string) (($db->table('mining_wallet_ledger')->selectSum('amount', 's')->where('direction', 'credit')->get()->getRowArray()['s']) ?? '0')
            : '0';

        $invAvailable = $this->inventoryAvailable();

        return [
            'business_date'              => $businessDate,
            'before_effective_date'      => false,
            'effective_start_date'       => $effectiveStartDate,
            'cutoff_utc'                 => $cutoff['cutoff_utc'],
            'rate_ref'                   => $rate['rate_ref'],
            'rate_percent'               => $rate['rate_percent'],
            'divisor'                    => $divisor,
            'policy_version'             => $policy->version(),
            'eligible_members'           => $eligibleMembers,
            'blocked_members'            => $blockedMembers,
            'total_eligible_coins'       => $totEligible,
            'total_expected_coins'       => $totExpected,
            'existing_posted_coins'      => $totPosted,
            'missing_coins'              => $totMissing,
            'wallet_total_coins'         => bcadd($walletSum, '0', 8),
            'ledger_credit_total_coins'  => bcadd($ledgerSum, '0', 8),
            'inventory_available_before' => $invAvailable,
            'reserve_sufficient'         => bccomp($invAvailable, $totMissing, 8) >= 0,
            'items'                      => $items,
        ];
    }

    /**
     * Create a dual-control mining recovery proposal (never posts credits).
     *
     * @return array<string, mixed>
     */
    public function createProposal(
        string $businessDate,
        MiningCalculationPolicy $policy,
        int $actorUserId,
        string $supplySource = 'reserve_inventory_tracked',
        ?string $effectiveStartDate = null,
        ?string $notes = null,
        bool $allowIncompleteDay = false
    ): array {
        $db = db_connect();
        if (! $db->tableExists('mining_recovery_proposals')) {
            throw new RuntimeException('Missing table mining_recovery_proposals (apply migration 995001).');
        }

        $assess = $this->assessDate($businessDate, $policy, $effectiveStartDate, $allowIncompleteDay);
        if (! empty($assess['before_effective_date'])) {
            throw new InvalidArgumentException('Date ' . $businessDate . ' precedes effective start date ' . $effectiveStartDate . '. Refusing to backdate.');
        }
        if (bccomp((string) $assess['missing_coins'], '0', 8) <= 0) {
            throw new InvalidArgumentException('No missing mining credits owed for ' . $businessDate . ' under current policy.');
        }
        if (empty($assess['reserve_sufficient'])) {
            throw new RuntimeException('Insufficient inventory reserve for proposed mining recovery.');
        }

        $ref = 'MRP-' . str_replace('-', '', $businessDate) . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now = date('Y-m-d H:i:s');

        $db->transBegin();
        try {
            $db->table('mining_recovery_proposals')->insert([
                'proposal_ref'               => $ref,
                'business_date'              => $businessDate,
                'policy_version'             => $assess['policy_version'],
                'rate_ref'                   => $assess['rate_ref'],
                'supply_source'              => $supplySource,
                'total_eligible_members'     => $assess['eligible_members'],
                'total_eligible_coins'       => $assess['total_eligible_coins'],
                'total_expected_coins'       => $assess['total_expected_coins'],
                'existing_posted_coins'      => $assess['existing_posted_coins'],
                'missing_coins'              => $assess['missing_coins'],
                'inventory_available_before' => $assess['inventory_available_before'],
                'status'                     => 'draft',
                'created_by'                 => $actorUserId,
                'notes'                      => $notes !== null ? mb_substr(trim($notes), 0, 500) : null,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);

            foreach ($assess['items'] as $item) {
                $db->table('mining_recovery_proposal_items')->insert([
                    'proposal_ref'         => $ref,
                    'business_date'        => $businessDate,
                    'member_user_id'       => $item['member_user_id'],
                    'member_id'            => $item['member_id'],
                    'eligible_coins'       => $item['eligible_coins'],
                    'expected_coins'       => $item['expected_coins'],
                    'already_posted_coins' => $item['already_posted_coins'],
                    'missing_coins'        => $item['missing_coins'],
                    'evidence_ref'         => $item['evidence_ref'],
                    'snapshot_status'      => $item['snapshot_status'],
                    'created_at'           => $now,
                ]);
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Failed to create mining recovery proposal.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }

            throw $e;
        }

        (new AuditLogger())->log('mining.recovery_proposal_created', $actorUserId, 'mining_recovery_proposal', $ref, [
            'business_date' => $businessDate,
            'missing_coins' => $assess['missing_coins'],
        ]);

        return $this->findByRef($ref) ?? [];
    }

    /**
     * Dual-control approval (self-approval blocked).
     *
     * @return array<string, mixed>
     */
    public function approveProposal(string $proposalRef, int $approverUserId): array
    {
        $db  = db_connect();
        $row = $db->table('mining_recovery_proposals')->where('proposal_ref', $proposalRef)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Mining recovery proposal not found: ' . $proposalRef);
        }
        if (($row['status'] ?? '') !== 'draft') {
            throw new InvalidArgumentException('Only draft mining recovery proposals can be approved.');
        }
        if ((int) $row['created_by'] === $approverUserId) {
            throw new InvalidArgumentException('Self-approval of mining recovery proposal is forbidden.');
        }

        $now = date('Y-m-d H:i:s');
        $db->table('mining_recovery_proposals')
            ->where('proposal_ref', $proposalRef)
            ->where('status', 'draft')
            ->update([
                'status'      => 'approved',
                'approved_by' => $approverUserId,
                'approved_at' => $now,
                'updated_at'  => $now,
            ]);

        (new AuditLogger())->log('mining.recovery_proposal_approved', $approverUserId, 'mining_recovery_proposal', $proposalRef, []);

        return $this->findByRef($proposalRef) ?? [];
    }

    /**
     * Execute an approved mining recovery proposal through the Phase 13B guarded MiningPostingRunnerService.
     *
     * @param array<string, mixed> $runnerOpts
     * @return array<string, mixed>
     */
    public function executeProposal(string $proposalRef, array $runnerOpts, int $actorUserId): array
    {
        $db  = db_connect();
        $row = $db->table('mining_recovery_proposals')->where('proposal_ref', $proposalRef)->get()->getRowArray();
        if ($row === null) {
            throw new InvalidArgumentException('Mining recovery proposal not found.');
        }
        if (($row['status'] ?? '') === 'executed') {
            return ['proposal' => $this->findByRef($proposalRef), 'duplicate' => true, 'run' => null];
        }
        if (($row['status'] ?? '') !== 'approved') {
            throw new RuntimeException('Mining recovery proposal must be approved before execution (status=' . ($row['status'] ?? 'unknown') . ').');
        }

        $activePolicy = MiningCalculationPolicy::fromPlatform($this->config);
        if ((string) ($row['policy_version'] ?? '') !== $activePolicy->version()) {
            throw new RuntimeException(sprintf(
                'Policy mismatch on recovery proposal %s (%s vs active %s). Create a new proposal under the approved policy.',
                $proposalRef,
                (string) ($row['policy_version'] ?? 'unset'),
                $activePolicy->version()
            ));
        }

        $runner = new MiningPostingRunnerService(null, null, $this->config);
        $run    = $runner->run(array_merge($runnerOpts, [
            'date'    => (string) $row['business_date'],
            'execute' => true,
            'actor'   => $actorUserId,
        ]));

        if (($run['status'] ?? '') === 'completed' && ($run['reconcile_status'] ?? '') === 'OK') {
            $now = date('Y-m-d H:i:s');
            $db->table('mining_recovery_proposals')->where('proposal_ref', $proposalRef)->update([
                'status'      => 'executed',
                'executed_by' => $actorUserId,
                'executed_at' => $now,
                'run_ref'     => $run['run_ref'] ?? null,
                'updated_at'  => $now,
            ]);
        }

        return ['proposal' => $this->findByRef($proposalRef), 'duplicate' => false, 'run' => $run];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByRef(string $proposalRef): ?array
    {
        $db  = db_connect();
        $row = $db->table('mining_recovery_proposals')->where('proposal_ref', $proposalRef)->get()->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['items'] = $db->table('mining_recovery_proposal_items')
            ->where('proposal_ref', $proposalRef)
            ->orderBy('member_user_id', 'ASC')
            ->get()
            ->getResultArray();

        return $row;
    }

    private function inventoryAvailable(): string
    {
        $db = db_connect();
        if (! $db->tableExists('coin_inventory')) {
            return '0.00000000';
        }
        $row = $db->table('coin_inventory')->selectSum('available_balance', 's')->get()->getRowArray();

        return bcadd((string) ($row['s'] ?? '0'), '0', 8);
    }
}
