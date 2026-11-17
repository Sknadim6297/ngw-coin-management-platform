<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7B daily mining simulation. Never credits wallets or mutates ownership ledger.
 * Results are always is_payable=0 / is_posted=0.
 */
class MiningSimulationService
{
    public function __construct(
        private readonly MiningRateService $rates = new MiningRateService(),
        private readonly MiningEligibilityService $eligibility = new MiningEligibilityService(),
        private readonly OpsJobLockService $locks = new OpsJobLockService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{
     *   run: array<string, mixed>,
     *   items: list<array<string, mixed>>,
     *   reused: bool
     * }
     */
    public function simulateDay(string $calculationDate, ?int $actorUserId = null, int $batchLimit = 500): array
    {
        $calculationDate = $this->normalizeDate($calculationDate);
        $batchLimit      = max(1, min(2000, $batchLimit));
        $idempotencyKey  = 'mining-sim:' . $calculationDate;

        $existing = db_connect()->table('mining_simulation_runs')
            ->where('idempotency_key', $idempotencyKey)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            $items = db_connect()->table('mining_simulation_items')
                ->where('run_ref', $existing['run_ref'])
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            return ['run' => $existing, 'items' => $items, 'reused' => true];
        }

        $owner = 'mining-sim-' . ($actorUserId ?? 0) . '-' . getmypid();
        if (! $this->locks->acquire('mining_simulate', $owner, 900)) {
            throw new RuntimeException('Another mining simulation holds the lock.');
        }

        try {
            $rate = $this->rates->approvedForDate($calculationDate);
            $runRef = 'MSR-' . str_replace('-', '', $calculationDate) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $now    = date('Y-m-d H:i:s');
            $scale  = $this->scale();

            db_connect()->table('mining_simulation_runs')->insert([
                'run_ref'               => $runRef,
                'calculation_date'      => $calculationDate,
                'idempotency_key'       => $idempotencyKey,
                'status'                => 'completed',
                'is_dry_run'            => 1,
                'is_payable'            => 0,
                'is_posted'             => 0,
                'rate_ref'              => $rate['rate_ref'] ?? null,
                'member_count'          => 0,
                'eligible_member_count' => 0,
                'total_eligible_coins'  => MoneyMath::normalize('0', $scale),
                'total_preview_amount'  => MoneyMath::normalize('0', $scale),
                'error_count'           => 0,
                'created_by'            => $actorUserId,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

            $members = db_connect()->table('member_profiles')
                ->select('user_id, member_id')
                ->where('is_root_sponsor', 0)
                ->orderBy('id', 'ASC')
                ->limit($batchLimit)
                ->get()
                ->getResultArray();

            $items = [];
            $eligibleMembers = 0;
            $totalEligible   = MoneyMath::normalize('0', $scale);
            $totalPreview    = MoneyMath::normalize('0', $scale);
            $errors          = 0;

            foreach ($members as $member) {
                $userId = (int) $member['user_id'];
                try {
                    $eval = $this->eligibility->evaluate($userId, $calculationDate, $rate);
                    $preview = MoneyMath::normalize('0', $scale);
                    $status  = 'skipped';

                    if ($eval['eligible'] && $rate !== null) {
                        $preview = $this->rates->previewAmount($eval['eligible_coins'], $rate, $calculationDate);
                        $status  = 'simulated';
                        $eligibleMembers++;
                        $totalEligible = MoneyMath::add($totalEligible, $eval['eligible_coins'], $scale);
                        $totalPreview  = MoneyMath::add($totalPreview, $preview, $scale);
                    }

                    $itemRef = $runRef . '-' . $userId;
                    $item = [
                        'run_ref'           => $runRef,
                        'item_ref'          => $itemRef,
                        'member_user_id'    => $userId,
                        'member_id'         => (string) $eval['member_id'],
                        'calculation_date'  => $calculationDate,
                        'rate_ref'          => $rate['rate_ref'] ?? null,
                        'applied_percent'   => $rate['rate_percent'] ?? MoneyMath::normalize('0', $scale),
                        'rate_basis'        => $rate['rate_basis'] ?? null,
                        'total_owned'       => $eval['total_owned'],
                        'available_coins'   => $eval['available'],
                        'locked_coins'      => $eval['locked'],
                        'eligible_coins'    => $eval['eligible_coins'],
                        'ineligible_coins'  => $eval['ineligible_coins'],
                        'preview_amount'    => $preview,
                        'eligible'          => $eval['eligible'] ? 1 : 0,
                        'reason_codes_json' => json_encode($eval['reason_codes']),
                        'status'            => $status,
                        'is_payable'        => 0,
                        'is_posted'         => 0,
                        'created_at'        => $now,
                    ];
                    db_connect()->table('mining_simulation_items')->insert($item);
                    db_connect()->table('mining_eligibility_audit_events')->insert([
                        'member_user_id'    => $userId,
                        'member_id'         => (string) $eval['member_id'],
                        'calculation_date'  => $calculationDate,
                        'eligible'          => $eval['eligible'] ? 1 : 0,
                        'eligible_coins'    => $eval['eligible_coins'],
                        'reason_codes_json' => json_encode($eval['reason_codes']),
                        'rate_ref'          => $rate['rate_ref'] ?? null,
                        'source'            => 'simulation',
                        'created_at'        => $now,
                    ]);
                    $items[] = $item;
                } catch (\Throwable $e) {
                    $errors++;
                    log_message('error', 'Mining simulation item failed for user {id}: {msg}', [
                        'id'  => $userId,
                        'msg' => $e->getMessage(),
                    ]);
                }
            }

            $status = $errors > 0 ? ($items === [] ? 'failed' : 'partial') : 'completed';
            db_connect()->table('mining_simulation_runs')->where('run_ref', $runRef)->update([
                'status'                => $status,
                'member_count'          => count($members),
                'eligible_member_count' => $eligibleMembers,
                'total_eligible_coins'  => $totalEligible,
                'total_preview_amount'  => $totalPreview,
                'error_count'           => $errors,
                'error_message'         => $errors > 0 ? 'One or more member simulations failed.' : null,
                'updated_at'            => date('Y-m-d H:i:s'),
            ]);

            $this->audit->log('mining.simulation_completed', $actorUserId, 'mining_simulation_run', $runRef, [
                'calculation_date' => $calculationDate,
                'eligible_members' => $eligibleMembers,
                'preview_total'    => $totalPreview,
                'is_payable'       => false,
                'is_posted'        => false,
            ]);

            $run = db_connect()->table('mining_simulation_runs')->where('run_ref', $runRef)->get()->getRowArray();

            return ['run' => $run ?: [], 'items' => $items, 'reused' => false];
        } finally {
            $this->locks->release('mining_simulate', $owner);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRun(?string $date = null): ?array
    {
        $builder = db_connect()->table('mining_simulation_runs')->orderBy('id', 'DESC');
        if ($date !== null) {
            $builder->where('calculation_date', $this->normalizeDate($date));
        }

        return $builder->get()->getRowArray() ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForMember(int $memberUserId, int $limit = 30): array
    {
        return db_connect()->table('mining_simulation_items')
            ->where('member_user_id', $memberUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOwnedItem(int $memberUserId, string $itemRef): ?array
    {
        $row = db_connect()->table('mining_simulation_items')
            ->where('member_user_id', $memberUserId)
            ->where('item_ref', $itemRef)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * Admin dashboard aggregates (simulation only).
     *
     * @return array{
     *   eligible_members: int,
     *   ineligible_members: int,
     *   total_eligible_coins: string,
     *   today_preview: string,
     *   total_preview: string,
     *   pending_rate_approvals: int,
     *   simulation_errors: int,
     *   last_run: ?array<string, mixed>,
     *   current_rate: ?array<string, mixed>,
     *   actual_mining_status: string
     * }
     */
    public function adminSnapshot(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $scale = $this->scale();
        $rate  = $this->rates->approvedForDate($today);
        $run   = $this->latestRun($today);

        $totalPreview = db_connect()->table('mining_simulation_items')
            ->selectSum('preview_amount', 'qty')
            ->get()
            ->getRowArray();

        $errorRuns = (int) db_connect()->table('mining_simulation_runs')
            ->whereIn('status', ['failed', 'partial'])
            ->countAllResults();

        $memberTotal = (int) db_connect()->table('member_profiles')->where('is_root_sponsor', 0)->countAllResults();
        $eligibleMembers = (int) ($run['eligible_member_count'] ?? 0);

        return [
            'eligible_members'       => $eligibleMembers,
            'ineligible_members'     => max(0, $memberTotal - $eligibleMembers),
            'total_eligible_coins'   => MoneyMath::normalize((string) ($run['total_eligible_coins'] ?? '0'), $scale),
            'today_preview'          => MoneyMath::normalize((string) ($run['total_preview_amount'] ?? '0'), $scale),
            'total_preview'          => MoneyMath::normalize((string) ($totalPreview['qty'] ?? '0'), $scale),
            'pending_rate_approvals' => $this->rates->pendingApprovalCount(),
            'simulation_errors'      => $errorRuns,
            'last_run'               => $this->latestRun(),
            'current_rate'           => $rate,
            'actual_mining_status'   => 'Actual Mining: Not Activated',
        ];
    }

    /**
     * Member preview for a date (does not persist unless simulateDay already ran).
     *
     * @return array{eligibility: array<string, mixed>, preview_amount: string, rate: ?array<string, mixed>, configured: bool}
     */
    public function memberPreview(int $memberUserId, string $calculationDate): array
    {
        $calculationDate = $this->normalizeDate($calculationDate);
        $rate = $this->rates->approvedForDate($calculationDate);
        $eval = $this->eligibility->evaluate($memberUserId, $calculationDate, $rate);
        $preview = MoneyMath::normalize('0', $this->scale());
        if ($eval['eligible'] && $rate !== null) {
            $preview = $this->rates->previewAmount($eval['eligible_coins'], $rate, $calculationDate);
        }

        return [
            'eligibility'    => $eval,
            'preview_amount' => $preview,
            'rate'           => $rate,
            'configured'     => $rate !== null,
        ];
    }

    public function memberPreviewTotal(int $memberUserId): string
    {
        $row = db_connect()->table('mining_simulation_items')
            ->selectSum('preview_amount', 'qty')
            ->where('member_user_id', $memberUserId)
            ->get()
            ->getRowArray();

        return MoneyMath::normalize((string) ($row['qty'] ?? '0'), $this->scale());
    }

    private function normalizeDate(string $date): string
    {
        $date = trim($date);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt === false || $dt->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid calculation date.');
        }

        return $date;
    }

    private function scale(): int
    {
        return ($this->config ?? config('Platform'))->coinQuantityScale;
    }
}
