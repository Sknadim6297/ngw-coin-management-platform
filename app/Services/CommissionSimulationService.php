<?php

declare(strict_types=1);

namespace App\Services;

use App\Libraries\MoneyMath;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7C purchase commission simulation. Never credits wallets or posts payouts.
 * Formula: preview = purchase_amount × level_percent / 100 (BCMath, scale 8, truncate).
 * Always is_payable=0 / is_posted=0.
 */
class CommissionSimulationService
{
    public const EXPORT_MAX_ROWS = 5000;

    public function __construct(
        private readonly CommissionPlanService $plans = new CommissionPlanService(),
        private readonly CommissionEligibilityService $eligibility = new CommissionEligibilityService(),
        private readonly OpsJobLockService $locks = new OpsJobLockService(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Simulate commissions for purchases approved on calculation_date.
     *
     * @return array{run: array<string, mixed>, items: list<array<string, mixed>>, reused: bool}
     */
    public function simulateDay(string $calculationDate, ?int $actorUserId = null, int $batchLimit = 500): array
    {
        $calculationDate = $this->normalizeDate($calculationDate);
        $batchLimit      = max(1, min(2000, $batchLimit));
        $idempotencyKey  = 'commission-sim:' . $calculationDate;

        $existing = db_connect()->table('commission_simulation_runs')
            ->where('idempotency_key', $idempotencyKey)
            ->get()
            ->getRowArray();
        if ($existing !== null) {
            $items = db_connect()->table('commission_simulation_items')
                ->where('run_ref', $existing['run_ref'])
                ->orderBy('id', 'ASC')
                ->get()
                ->getResultArray();

            return ['run' => $existing, 'items' => $items, 'reused' => true];
        }

        $owner = 'commission-sim-' . ($actorUserId ?? 0) . '-' . getmypid();
        if (! $this->locks->acquire('commission_simulate', $owner, 900)) {
            throw new RuntimeException('Another commission simulation holds the lock.');
        }

        try {
            $plan   = $this->plans->findApplicablePlan($calculationDate);
            $runRef = 'CSR-' . str_replace('-', '', $calculationDate) . '-' . strtoupper(bin2hex(random_bytes(3)));
            $now    = date('Y-m-d H:i:s');
            $scale  = $this->scale();

            db_connect()->table('commission_simulation_runs')->insert([
                'run_ref'                    => $runRef,
                'calculation_date'           => $calculationDate,
                'idempotency_key'            => $idempotencyKey,
                'plan_ref'                   => $plan['plan_ref'] ?? null,
                'status'                     => 'completed',
                'is_dry_run'                 => 1,
                'is_payable'                 => 0,
                'is_posted'                  => 0,
                'purchase_count'             => 0,
                'eligible_purchase_count'    => 0,
                'ineligible_purchase_count'  => 0,
                'item_count'                 => 0,
                'total_preview_amount'       => MoneyMath::normalize('0', $scale),
                'level1_preview'             => MoneyMath::normalize('0', $scale),
                'level2_preview'             => MoneyMath::normalize('0', $scale),
                'level3_preview'             => MoneyMath::normalize('0', $scale),
                'error_count'                => 0,
                'created_by'                 => $actorUserId,
                'created_at'                 => $now,
                'updated_at'                 => $now,
            ]);

            $purchases = db_connect()->table('coin_purchase_requests')
                ->select('purchase_ref, member_user_id, member_id, total_usdt, status, payment_status, reviewed_at, requested_at')
                ->where('status', 'approved')
                ->where('DATE(COALESCE(reviewed_at, requested_at)) =', $calculationDate)
                ->orderBy('id', 'ASC')
                ->limit($batchLimit)
                ->get()
                ->getResultArray();

            // Normalize aliases used by eligibility (USDT is the purchase currency).
            foreach ($purchases as &$p) {
                $p['currency'] = 'USDT';
                $p['user_id']  = (int) ($p['member_user_id'] ?? 0);
            }
            unset($p);

            $items           = [];
            $eligiblePurch   = 0;
            $ineligiblePurch = 0;
            $totalPreview    = MoneyMath::normalize('0', $scale);
            $levelTotals     = [
                1 => MoneyMath::normalize('0', $scale),
                2 => MoneyMath::normalize('0', $scale),
                3 => MoneyMath::normalize('0', $scale),
            ];
            $errors = 0;

            foreach ($purchases as $purchase) {
                try {
                    $eval = $this->eligibility->evaluatePurchase($purchase, $plan);
                    $this->eligibility->recordAudit($eval, 'simulation');

                    if ($eval['purchase_eligible']) {
                        $eligiblePurch++;
                    } else {
                        $ineligiblePurch++;
                    }

                    foreach ($eval['levels'] as $lvl) {
                        $level   = (int) $lvl['level'];
                        $percent = $lvl['percent'] !== null ? MoneyMath::normalize((string) $lvl['percent'], $scale) : MoneyMath::normalize('0', $scale);
                        $amount  = MoneyMath::normalize((string) $eval['purchase_amount'], $scale);
                        $preview = MoneyMath::normalize('0', $scale);
                        $status  = 'skipped';
                        $eligible = ! empty($lvl['eligible']);

                        if ($eligible && $eval['purchase_eligible'] && $plan !== null) {
                            // Truncate to scale (BCMath); explicit policy: no banker's round.
                            $preview = MoneyMath::div(
                                MoneyMath::mul($amount, $percent, $scale + 4),
                                '100',
                                $scale
                            );
                            $status = 'simulated';
                            $totalPreview = MoneyMath::add($totalPreview, $preview, $scale);
                            if (isset($levelTotals[$level])) {
                                $levelTotals[$level] = MoneyMath::add($levelTotals[$level], $preview, $scale);
                            }
                        }

                        $beneficiaryUserId = (int) ($lvl['sponsor_user_id'] ?? 0);
                        $beneficiaryMid    = (string) ($lvl['sponsor_member_id'] ?? '');
                        if ($beneficiaryUserId <= 0) {
                            // Still record a skipped placeholder only when purchase had a plan attempt
                            // and level percent existed — keep audit trail without inventing beneficiaries.
                            if (! $eval['purchase_eligible'] && ($lvl['sponsor_user_id'] ?? null) === null) {
                                continue;
                            }
                            // Missing sponsor: record skip with beneficiary 0 omitted — use purchaser as sentinel? No.
                            // Store item with beneficiary_user_id = 0 not allowed (unsigned). Skip insert for missing sponsor.
                            if (($lvl['sponsor_user_id'] ?? null) === null) {
                                continue;
                            }
                        }

                        $itemKey = ($plan['plan_ref'] ?? 'NONE') . ':' . $eval['purchase_ref'] . ':L' . $level . ':' . $beneficiaryUserId;
                        $itemRef = $runRef . '-L' . $level . '-' . substr(hash('sha256', $itemKey), 0, 12);

                        $dup = db_connect()->table('commission_simulation_items')
                            ->where('idempotency_key', $itemKey)
                            ->countAllResults();
                        if ($dup > 0) {
                            continue;
                        }

                        $item = [
                            'run_ref'                => $runRef,
                            'item_ref'               => $itemRef,
                            'idempotency_key'        => $itemKey,
                            'purchase_ref'           => $eval['purchase_ref'],
                            'purchaser_user_id'      => $eval['purchaser_user_id'],
                            'purchaser_member_id'    => $eval['purchaser_member_id'],
                            'beneficiary_user_id'    => $beneficiaryUserId,
                            'beneficiary_member_id'  => $beneficiaryMid,
                            'level'                  => $level,
                            'plan_ref'               => (string) ($eval['plan_ref'] ?? ($plan['plan_ref'] ?? '')),
                            'purchase_amount'        => $amount,
                            'currency'               => $eval['currency'],
                            'percent'                => $percent,
                            'preview_amount'         => $preview,
                            'calculation_date'       => $calculationDate,
                            'eligible'               => $eligible ? 1 : 0,
                            'reason_codes_json'      => json_encode($lvl['reason_codes'] ?? [], JSON_THROW_ON_ERROR),
                            'status'                 => $status,
                            'is_payable'             => 0,
                            'is_posted'              => 0,
                            'created_at'             => $now,
                        ];
                        db_connect()->table('commission_simulation_items')->insert($item);
                        $items[] = $item;
                    }
                } catch (\Throwable $e) {
                    $errors++;
                    log_message('error', 'Commission simulation failed for purchase {ref}: {msg}', [
                        'ref' => $purchase['purchase_ref'] ?? '',
                        'msg' => $e->getMessage(),
                    ]);
                }
            }

            $status = $errors > 0 ? ($items === [] ? 'failed' : 'partial') : 'completed';
            db_connect()->table('commission_simulation_runs')->where('run_ref', $runRef)->update([
                'status'                    => $status,
                'purchase_count'            => count($purchases),
                'eligible_purchase_count'   => $eligiblePurch,
                'ineligible_purchase_count' => $ineligiblePurch,
                'item_count'                => count($items),
                'total_preview_amount'      => $totalPreview,
                'level1_preview'            => $levelTotals[1],
                'level2_preview'            => $levelTotals[2],
                'level3_preview'            => $levelTotals[3],
                'error_count'               => $errors,
                'error_message'             => $errors > 0 ? 'One or more purchase simulations failed.' : null,
                'updated_at'                => date('Y-m-d H:i:s'),
            ]);

            $this->audit->log('commission.simulation_completed', $actorUserId, 'commission_simulation_run', $runRef, [
                'calculation_date' => $calculationDate,
                'preview_total'    => $totalPreview,
                'is_payable'       => false,
                'is_posted'        => false,
            ]);

            $run = db_connect()->table('commission_simulation_runs')->where('run_ref', $runRef)->get()->getRowArray();

            return ['run' => $run ?: [], 'items' => $items, 'reused' => false];
        } finally {
            $this->locks->release('commission_simulate', $owner);
        }
    }

    /**
     * Preview amount for one eligible level (pure calc helper).
     */
    public function previewAmount(string $purchaseAmount, string $percent): string
    {
        $scale = $this->scale();

        return MoneyMath::div(
            MoneyMath::mul(MoneyMath::normalize($purchaseAmount, $scale), MoneyMath::normalize($percent, $scale), $scale + 4),
            '100',
            $scale
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestRun(?string $date = null): ?array
    {
        $builder = db_connect()->table('commission_simulation_runs')->orderBy('id', 'DESC');
        if ($date !== null) {
            $builder->where('calculation_date', $this->normalizeDate($date));
        }

        return $builder->get()->getRowArray() ?: null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historyForBeneficiary(int $beneficiaryUserId, int $limit = 50): array
    {
        return db_connect()->table('commission_simulation_items')
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)))
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOwnedItem(int $beneficiaryUserId, string $itemRef): ?array
    {
        $row = db_connect()->table('commission_simulation_items')
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('item_ref', $itemRef)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>
     */
    public function memberSnapshot(int $beneficiaryUserId, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $scale = $this->scale();

        $todayRow = db_connect()->table('commission_simulation_items')
            ->selectSum('preview_amount', 'qty')
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('calculation_date', $today)
            ->where('status', 'simulated')
            ->get()
            ->getRowArray();

        $totalRow = db_connect()->table('commission_simulation_items')
            ->selectSum('preview_amount', 'qty')
            ->where('beneficiary_user_id', $beneficiaryUserId)
            ->where('status', 'simulated')
            ->get()
            ->getRowArray();

        $levels = [];
        for ($i = 1; $i <= 3; $i++) {
            $lr = db_connect()->table('commission_simulation_items')
                ->selectSum('preview_amount', 'qty')
                ->where('beneficiary_user_id', $beneficiaryUserId)
                ->where('level', $i)
                ->where('status', 'simulated')
                ->get()
                ->getRowArray();
            $levels[$i] = MoneyMath::normalize((string) ($lr['qty'] ?? '0'), $scale);
        }

        return [
            'today_preview'  => MoneyMath::normalize((string) ($todayRow['qty'] ?? '0'), $scale),
            'total_preview'  => MoneyMath::normalize((string) ($totalRow['qty'] ?? '0'), $scale),
            'level1_preview' => $levels[1],
            'level2_preview' => $levels[2],
            'level3_preview' => $levels[3],
            'actual_status'  => 'Actual Commission: Not Activated',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function adminSnapshot(?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $scale = $this->scale();
        $plan  = $this->plans->findApplicablePlan($today);
        $run   = $this->latestRun($today);

        $approvedPurchases = (int) db_connect()->table('coin_purchase_requests')
            ->where('status', 'approved')
            ->countAllResults();

        $totalPreview = db_connect()->table('commission_simulation_items')
            ->selectSum('preview_amount', 'qty')
            ->where('status', 'simulated')
            ->get()
            ->getRowArray();

        $levelTotals = [];
        for ($i = 1; $i <= 3; $i++) {
            $lr = db_connect()->table('commission_simulation_items')
                ->selectSum('preview_amount', 'qty')
                ->where('level', $i)
                ->where('status', 'simulated')
                ->get()
                ->getRowArray();
            $levelTotals[$i] = MoneyMath::normalize((string) ($lr['qty'] ?? '0'), $scale);
        }

        $errorRuns = (int) db_connect()->table('commission_simulation_runs')
            ->whereIn('status', ['failed', 'partial'])
            ->countAllResults();

        return [
            'approved_purchases'        => $approvedPurchases,
            'eligible_purchases'        => (int) ($run['eligible_purchase_count'] ?? 0),
            'ineligible_purchases'      => (int) ($run['ineligible_purchase_count'] ?? 0),
            'today_preview'             => MoneyMath::normalize((string) ($run['total_preview_amount'] ?? '0'), $scale),
            'total_preview'             => MoneyMath::normalize((string) ($totalPreview['qty'] ?? '0'), $scale),
            'level1_preview'            => $levelTotals[1],
            'level2_preview'            => $levelTotals[2],
            'level3_preview'            => $levelTotals[3],
            'active_plan'               => $plan,
            'pending_plan_approvals'    => $this->plans->pendingApprovalCount(),
            'simulation_errors'         => $errorRuns,
            'last_run'                  => $this->latestRun(),
            'actual_commission_status'  => 'Actual Commission: Not Activated',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function searchHistory(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $builder = $this->historyBuilder($filters);

        return $builder->orderBy('id', 'DESC')
            ->limit(max(1, min(200, $limit)), max(0, $offset))
            ->get()
            ->getResultArray();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function countHistory(array $filters = []): int
    {
        return $this->historyBuilder($filters)->countAllResults();
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function exportCsv(array $filters = [], int $limit = self::EXPORT_MAX_ROWS): string
    {
        $limit = max(1, min(self::EXPORT_MAX_ROWS, $limit));
        $rows  = $this->historyBuilder($filters)
            ->orderBy('id', 'DESC')
            ->limit($limit)
            ->get()
            ->getResultArray();

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open CSV buffer.');
        }

        fputcsv($handle, [
            'item_ref', 'run_ref', 'calculation_date', 'purchase_ref', 'purchaser_member_id',
            'beneficiary_member_id', 'level', 'plan_ref', 'purchase_amount', 'currency',
            'percent', 'preview_amount', 'eligible', 'status', 'is_payable', 'is_posted',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $this->csvCell((string) ($row['item_ref'] ?? '')),
                $this->csvCell((string) ($row['run_ref'] ?? '')),
                $this->csvCell((string) ($row['calculation_date'] ?? '')),
                $this->csvCell((string) ($row['purchase_ref'] ?? '')),
                $this->csvCell((string) ($row['purchaser_member_id'] ?? '')),
                $this->csvCell((string) ($row['beneficiary_member_id'] ?? '')),
                $this->csvCell((string) ($row['level'] ?? '')),
                $this->csvCell((string) ($row['plan_ref'] ?? '')),
                $this->csvCell((string) ($row['purchase_amount'] ?? '')),
                $this->csvCell((string) ($row['currency'] ?? '')),
                $this->csvCell((string) ($row['percent'] ?? '')),
                $this->csvCell((string) ($row['preview_amount'] ?? '')),
                $this->csvCell((string) ($row['eligible'] ?? '')),
                $this->csvCell((string) ($row['status'] ?? '')),
                $this->csvCell((string) ($row['is_payable'] ?? '0')),
                $this->csvCell((string) ($row['is_posted'] ?? '0')),
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    /**
     * Commission preview trend for dashboards (simulation only).
     *
     * @return array{available: bool, labels: list<string>, values: list<string>, reason?: string}
     */
    public function previewTrend(int $days = 14, ?int $beneficiaryUserId = null): array
    {
        $days  = max(1, min(90, $days));
        $scale = $this->scale();
        $labels = [];
        $values = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime('-' . $i . ' days'));
            $labels[] = $date;
            $b = db_connect()->table('commission_simulation_items')
                ->selectSum('preview_amount', 'qty')
                ->where('calculation_date', $date)
                ->where('status', 'simulated');
            if ($beneficiaryUserId !== null) {
                $b->where('beneficiary_user_id', $beneficiaryUserId);
            }
            $row = $b->get()->getRowArray();
            $values[] = MoneyMath::normalize((string) ($row['qty'] ?? '0'), $scale);
        }

        return ['available' => true, 'labels' => $labels, 'values' => $values];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function historyBuilder(array $filters)
    {
        $b = db_connect()->table('commission_simulation_items');

        if (! empty($filters['date_from'])) {
            $b->where('calculation_date >=', $this->normalizeDate((string) $filters['date_from']));
        }
        if (! empty($filters['date_to'])) {
            $b->where('calculation_date <=', $this->normalizeDate((string) $filters['date_to']));
        }
        if (! empty($filters['plan_ref'])) {
            $b->where('plan_ref', trim((string) $filters['plan_ref']));
        }
        if (isset($filters['level']) && $filters['level'] !== '' && $filters['level'] !== null) {
            $b->where('level', (int) $filters['level']);
        }
        if (! empty($filters['member_q'])) {
            $q = trim((string) $filters['member_q']);
            $b->groupStart()
                ->like('beneficiary_member_id', $q)
                ->orLike('purchaser_member_id', $q)
                ->groupEnd();
        }
        if (! empty($filters['purchase_ref'])) {
            $b->like('purchase_ref', trim((string) $filters['purchase_ref']));
        }
        if (isset($filters['eligible']) && $filters['eligible'] !== '' && $filters['eligible'] !== null) {
            $b->where('eligible', (int) $filters['eligible'] ? 1 : 0);
        }
        if (! empty($filters['status'])) {
            $b->where('status', trim((string) $filters['status']));
        }

        return $b;
    }

    private function csvCell(string $value): string
    {
        $trimmed = ltrim($value);
        if ($trimmed !== '' && in_array($trimmed[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
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
