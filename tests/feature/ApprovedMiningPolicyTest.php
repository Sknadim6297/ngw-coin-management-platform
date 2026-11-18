<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Commands\CommissionsRecoverPlan;
use App\Libraries\CliArgs;
use App\Libraries\MiningCalculationPolicy;
use App\Libraries\MiningMath;
use App\Models\MemberProfileModel;
use App\Services\CommissionPostingRunnerService;
use App\Services\FinancialPolicyService;
use App\Services\MandatoryLockRemediationService;
use App\Services\MiningDailyEngineService;
use App\Services\MiningEligibilityService;
use App\Services\MiningEligibleOwnershipSnapshotService;
use App\Services\MiningPolicyWorksheetService;
use App\Services\MiningPostingRunnerService;
use App\Services\MiningProposalService;
use App\Services\MiningRecoveryService;
use App\Services\MiningSimulationService;
use App\Services\ProductionSafetyGate;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Platform;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Verifies the owner-approved mining rules (3% monthly, calendar_days, COIN, Asia/Kolkata,
 * exclude_approval_day, no compounding), consistency across eligibility / dry-run / simulation /
 * recovery / batch posting, PUR-10D7174B7FF83054 lock + recovery simulation, versioned policy
 * draft preparation (never auto-activated), and fail-closed safety guards.
 *
 * @internal
 */
final class ApprovedMiningPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ([
            'mining_recovery_proposal_items', 'mining_recovery_proposals',
            'mining_post_members', 'mining_post_day_runs',
            'mining_eligibility_audit_events', 'mining_simulation_items', 'mining_simulation_runs',
            'mining_daily_calculations', 'mining_daily_runs',
            'mining_wallet_ledger', 'mining_wallets', 'mining_rate_approval_events', 'mining_rate_configs',
            'commission_post_items', 'commission_post_batches', 'commission_calculation_revisions',
            'commission_purchase_calculations', 'commission_wallet_ledger_entries', 'commission_wallet_accounts',
            'commission_plan_approval_events', 'commission_plan_levels', 'commission_plans',
            'financial_policy_events', 'financial_policy_versions',
            'purchase_sponsor_snapshots', 'member_coin_lock_events', 'member_coin_locks', 'coin_locking_plans',
            'member_coin_ownership_transactions', 'member_coin_balances', 'coin_purchase_requests',
            'coin_inventory_transactions', 'coin_price_history',
            'sponsor_relationships', 'member_profiles', 'member_id_sequences',
            'auth_identities', 'auth_groups_users', 'auth_logins', 'users', 'audit_logs', 'ops_job_locks',
        ] as $tbl) {
            if ($db->tableExists($tbl)) {
                $db->query("TRUNCATE TABLE `$tbl`");
            }
        }
        if ($db->table('coin_settings')->countAllResults() === 0) {
            $db->query("INSERT INTO coin_settings (id, current_price_usdt, min_purchase_usdt, created_at, updated_at) VALUES (1, NULL, '5.00000000', NOW(), NOW())");
        }
        if ($db->table('coin_inventory')->countAllResults() === 0) {
            $db->query("INSERT INTO coin_inventory (id, available_balance, total_added, total_allocated, created_at, updated_at) VALUES (1, '0.00000000', '0.00000000', '0.00000000', NOW(), NOW())");
        }
        $db->query("UPDATE coin_inventory SET available_balance='0.00000000', total_added='0.00000000', total_allocated='0.00000000' WHERE id=1");
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $p                                       = config('Platform');
        $p->adminEmail                           = 'admin@test.local';
        $p->adminPassword                        = 'AdminPass123!';
        $p->adminName                            = 'Test Admin';
        $p->rootSponsorId                        = 'ROOT0001';
        $p->memberIdPrefix                       = 'NGW';
        $p->memberIdPadding                      = 6;
        $p->memberIdStartNumber                  = 1;
        $p->requireVerifiedDepositForApproval    = false;
        $p->mandatoryPurchaseLockEnabled         = true;
        $p->coinPlanLockDays                     = 1000;
        $p->miningBusinessTimezone               = 'Asia/Kolkata';
        $p->miningMonthDayDivisorMode            = 'calendar_days';
        $p->miningApprovalDayPolicy              = 'exclude_approval_day';
        $p->miningEarningAsset                   = 'COIN';
        $p->miningRoundingMode                   = 'down';
        $p->miningReservedCoinPolicy             = 'include';
        $p->miningMaturityPolicy                 = 'include_all';
        $p->miningPostingEnabled                 = false;
        $p->miningAutoPostEnabled                = false;
        $p->miningP2pEnabled                     = false;
        $p->commissionPostingEnabled             = false;
        $p->commissionAutoPostOnApprovalEnabled  = false;
        $p->commissionWithdrawalEnabled          = false;
        $p->commissionBlockchainPayoutEnabled    = false;
        $p->coinSellingEnabled                   = false;
        foreach ([
            'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus',
            'miningApprovalDayPolicyStatus', 'miningLockedCoinEligibilityStatus',
            'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus',
            'miningRoundingPolicyStatus', 'miningRewardSupplySourceStatus', 'miningCompoundingPolicyStatus',
        ] as $st) {
            $p->{$st} = 'pending_business_decision';
        }

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'approved mining test price');
        service('coinInventoryService')->addInventory('20000', $this->adminUserId, 'approved mining test stock', null, 'amp-stock-' . bin2hex(random_bytes(3)));
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'amp_approver', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity(['email' => 'amp.approver@test.local', 'password' => 'ApproverPass123!']);
        $user->addGroup('admin', 'finance_approver');

        return (int) $user->id;
    }

    /** @return array{user_id: int, member_id: string} */
    private function register(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) abs(crc32('amp' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'amp-' . $suffix), 0, 40), 40, '0');
        $res    = service('registrationService')->register([
            'member_name' => 'AMP ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'amp_' . $suffix . '@example.test',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return ['user_id' => (int) $res['user']->id, 'member_id' => (string) $res['member_id']];
    }

    private function buyApprove(array $member, string $coins, string $reviewedAtUtc, ?string $customRef = null): string
    {
        $req  = service('coinPurchaseService')->createRequest($member['user_id'], $coins, null, 'amp-' . $member['user_id'] . '-' . bin2hex(random_bytes(3)));
        $pRef = (string) $req['request']['purchase_ref'];
        service('coinPurchaseService')->approveRequest($pRef, $this->adminUserId);

        if ($customRef !== null) {
            db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['purchase_ref' => $customRef]);
            db_connect()->table('member_coin_ownership_transactions')->where('purchase_ref', $pRef)->update(['purchase_ref' => $customRef]);
            db_connect()->table('member_coin_locks')->where('purchase_ref', $pRef)->update(['purchase_ref' => $customRef]);
            db_connect()->table('purchase_sponsor_snapshots')->where('purchase_ref', $pRef)->update(['purchase_ref' => $customRef]);
            db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $pRef)->update(['purchase_ref' => $customRef]);
            $pRef = $customRef;
        }

        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['reviewed_at' => $reviewedAtUtc]);
        db_connect()->table('member_coin_ownership_transactions')->where('purchase_ref', $pRef)->update(['created_at' => $reviewedAtUtc]);
        $lock = db_connect()->table('member_coin_locks')->where('purchase_ref', $pRef)->get()->getRowArray();
        if ($lock !== null) {
            db_connect()->table('member_coin_ownership_transactions')->where('lock_ref', (string) $lock['lock_ref'])->update(['created_at' => $reviewedAtUtc]);
        }

        return $pRef;
    }

    private function approveMonthlyRate(string $month, string $percent = '3.00000000'): array
    {
        $draft = service('miningRateService')->createDraft([
            'effective_month' => $month,
            'rate_percent'    => $percent,
            'rate_basis'      => 'monthly',
            'notes'           => '3% monthly calendar days',
        ], $this->adminUserId);

        return service('miningRateService')->approve((string) $draft['rate_ref'], $this->approverUserId);
    }

    public function testPlatformDefaultsReflectApprovedRulesWhileKeepingAllSwitchesAndFlagsPending(): void
    {
        $fresh = new Platform();
        $this->assertSame('exclude_approval_day', $fresh->miningApprovalDayPolicy);
        $this->assertSame('calendar_days', $fresh->miningMonthDayDivisorMode);
        $this->assertSame('COIN', $fresh->miningEarningAsset);
        $this->assertSame('Asia/Kolkata', $fresh->miningBusinessTimezone);

        foreach ([
            'miningPostingEnabled', 'miningAutoPostEnabled', 'miningP2pEnabled',
            'commissionPostingEnabled', 'commissionAutoPostOnApprovalEnabled',
            'commissionWithdrawalEnabled', 'commissionBlockchainPayoutEnabled', 'coinSellingEnabled',
        ] as $sw) {
            $this->assertFalse((bool) $fresh->{$sw}, $sw);
        }

        foreach ([
            'miningMonthlyPercentStatus', 'miningDayDivisorStatus', 'miningDenominationStatus',
            'miningApprovalDayPolicyStatus', 'miningLockedCoinEligibilityStatus',
            'miningPostMaturityEligibilityStatus', 'miningPostSaleEligibilityStatus',
            'miningRoundingPolicyStatus', 'miningRewardSupplySourceStatus', 'miningCompoundingPolicyStatus',
        ] as $st) {
            $this->assertNotSame('approved', strtolower((string) $fresh->{$st}), $st);
        }

        (new ProductionSafetyGate())->assertKillSwitchesOff();
    }

    public function testExcludeApprovalDayIsConsistentAcrossEligibilitySimulationDryRunRecoveryAndBatchPosting(): void
    {
        $this->approveMonthlyRate('2026-11', '3.00000000');

        $m = $this->register('multi');
        // Purchase 1: 100 coins on 2026-11-16 10:00 UTC (15:30 IST on Nov 16) -> eligible from Nov 17 IST
        $this->buyApprove($m, '100', '2026-11-16 10:00:00');
        // Purchase 2: 500 coins on 2026-11-18 05:00 UTC (10:30 IST on Nov 18) -> excluded on Nov 18 IST, eligible Nov 19 IST
        $this->buyApprove($m, '500', '2026-11-18 05:00:00');

        $rate  = service('miningRateService')->approvedForDate('2026-11-18');
        $elig  = new MiningEligibilityService();
        $eval18 = $elig->evaluate($m['user_id'], '2026-11-18', $rate);
        $this->assertTrue($eval18['eligible']);
        $this->assertSame('2026-11-16', $eval18['approval_business_date']);
        $this->assertSame('2026-11-17', $eval18['mining_start_date']);
        $this->assertSame('100.00000000', $eval18['eligible_coins']);
        $this->assertSame('500.00000000', $eval18['ineligible_coins']);
        $this->assertContains('partial_approval_day_excluded', $eval18['reason_codes']);

        // Dry-run, Simulation, Recovery, and Snapshot all agree on 100 eligible coins -> 0.10000000 COIN on Nov 18
        $dry18 = (new MiningDailyEngineService())->dryRun('2026-11-18', 100, false);
        $this->assertSame('0.10000000', $dry18['totals']['total_expected_amount']);

        $sim18 = (new MiningSimulationService())->simulateDay('2026-11-18', $this->adminUserId, 100);
        $this->assertSame('100.00000000', $sim18['run']['total_eligible_coins']);
        $this->assertSame('0.10000000', $sim18['run']['total_preview_amount']);

        $policy = MiningCalculationPolicy::fromPlatform();
        $rec18  = (new MiningRecoveryService())->assessDate('2026-11-18', $policy);
        $this->assertSame('100.00000000', $rec18['total_eligible_coins']);
        $this->assertSame('0.10000000', $rec18['total_expected_coins']);

        // Next IST date (2026-11-19): full 600 coins eligible -> 0.60000000 COIN
        $eval19 = $elig->evaluate($m['user_id'], '2026-11-19', $rate);
        $this->assertSame('600.00000000', $eval19['eligible_coins']);
        $sim19 = (new MiningSimulationService())->simulateDay('2026-11-19', $this->adminUserId, 100);
        $this->assertSame('600.00000000', $sim19['run']['total_eligible_coins']);
        $this->assertSame('0.60000000', $sim19['run']['total_preview_amount']);
    }

    public function testCalendarDivisorsZeroCompoundingAndDuplicatePrevention(): void
    {
        // 28, 29, 30, 31 day months at 3% monthly (no rounding drift, exact bcmath)
        foreach ([
            ['2027-02', 28, '0.10714285', '0.10714286'],
            ['2028-02', 29, '0.10344827', '0.10344828'],
            ['2026-11', 30, '0.10000000', '0.10000000'],
            ['2026-12', 31, '0.09677419', '0.09677419'],
        ] as [$ym, $expectedDays, $down, $halfUp]) {
            $d = MiningMath::divisor('monthly', $ym . '-15', $ym, MiningMath::DIVISOR_CALENDAR);
            $this->assertSame($expectedDays, $d, $ym);
            $this->assertSame($down, MiningMath::dailyAmount('100', '3.00000000', $d, 8, MiningMath::ROUND_DOWN), $ym . ' down');
            $this->assertSame($halfUp, MiningMath::dailyAmount('100', '3.00000000', $d, 8, MiningMath::ROUND_HALF_UP), $ym . ' half_up');
        }

        // Asia/Kolkata boundary: 2026-11-16 18:29:59 UTC = 23:59:59 IST (16th) -> starts 2026-11-17
        // 2026-11-16 18:30:00 UTC = 00:00:00 IST (17th) -> starts 2026-11-18
        $this->approveMonthlyRate('2026-11', '3.00000000');
        $mBefore = $this->register('ist_before');
        $this->buyApprove($mBefore, '100', '2026-11-16 18:29:59');
        $mAfter = $this->register('ist_after');
        $this->buyApprove($mAfter, '100', '2026-11-16 18:30:00');

        $p = config('Platform');
        $p->miningPostingEnabled = true;
        try {
            $runner = new MiningPostingRunnerService();
            $r17 = $runner->run(['group' => 'tests', 'date' => '2026-11-17', 'execute' => true, 'actor' => $this->adminUserId]);
            $this->assertSame('completed', $r17['status']);
            $this->assertSame(1, $r17['credited']);
            $this->assertSame('0.10000000', $r17['total_credited']);

            // Duplicate run for 2026-11-17 credits 0 more rows
            $dup17 = $runner->run(['group' => 'tests', 'date' => '2026-11-17', 'execute' => true, 'actor' => $this->adminUserId]);
            $this->assertSame('completed', $dup17['status']);
            $this->assertSame(0, $dup17['credited']);
            $this->assertSame('0.10000000', $dup17['posted_total']);

            // Next day (2026-11-18) credits both mBefore and mAfter, and mBefore earns on principal ONLY (zero compounding: 100.00000000, not 100.10000000)
            $r18 = $runner->run(['group' => 'tests', 'date' => '2026-11-18', 'execute' => true, 'actor' => $this->adminUserId]);
            $this->assertSame('completed', $r18['status']);
            $this->assertSame(2, $r18['credited']);
            $this->assertSame('0.20000000', $r18['total_credited']);
            $this->assertSame('0.10000000', service('miningWalletService')->postedAmountForDate($mBefore['user_id'], '2026-11-18'));
            $this->assertSame('0.10000000', service('miningWalletService')->postedAmountForDate($mAfter['user_id'], '2026-11-18'));
            $this->assertSame('100.00000000', service('memberCoinOwnershipService')->summaryForUser($mBefore['user_id'])['balance']);
        } finally {
            $p->miningPostingEnabled = false;
        }
    }

    public function testPur10d7174b7ff83054MissingLockAndCommissionRecoveryPlanSimulation(): void
    {
        $this->approveMonthlyRate('2026-11', '3.00000000');

        // Reconstruct PUR-10D7174B7FF83054 exact state (approved 2026-11-17 06:45:44 UTC, missing LOCK1000 & missing calcs)
        config('Platform')->mandatoryPurchaseLockEnabled = false;
        $ngw1 = $this->register('ngw1');
        db_connect()->table('member_profiles')->where('user_id', $ngw1['user_id'])->update(['member_id' => 'NGW000001']);
        db_connect()->table('member_coin_balances')->where('member_user_id', $ngw1['user_id'])->update(['member_id' => 'NGW000001']);
        $ngw1['member_id'] = 'NGW000001';

        $pRef = $this->buyApprove($ngw1, '5', '2026-11-17 06:45:44', 'PUR-10D7174B7FF83054');
        db_connect()->table('coin_purchase_requests')->where('purchase_ref', $pRef)->update(['total_usdt' => '5.00000000', 'payment_status' => 'verified']);
        // Simulate pre-Phase11B missing calculation rows
        db_connect()->table('commission_purchase_calculations')->where('purchase_ref', $pRef)->delete();
        config('Platform')->mandatoryPurchaseLockEnabled = true;

        // 1. Mandatory lock dry-run preview shows 5 COIN -> LOCK1000, total owned remains 5.00000000
        $rem = new MandatoryLockRemediationService();
        $prev = $rem->execute($pRef, true, false, null, $this->adminUserId, 'tests');
        $this->assertTrue($prev['ready']);
        $this->assertFalse($prev['executed']);
        $this->assertSame('5.00000000', $prev['after_projected']['locked_balance']);
        $this->assertSame('0.00000000', $prev['after_projected']['available_balance']);
        $this->assertSame('5.00000000', $prev['after_projected']['balance']);

        // Execute lock on testing DB only and verify idempotent no-op on repeat + mining eligibility intact
        $exec = $rem->execute($pRef, false, false, null, $this->adminUserId, 'tests');
        $this->assertTrue($exec['executed']);
        $noop = $rem->execute($pRef, false, false, null, $this->adminUserId, 'tests');
        $this->assertTrue((bool) ($noop['safe_noop'] ?? false));

        // 2. Mining on 2026-11-17 IST = 0.00000000; on 2026-11-18 IST = 0.00500000 COIN/day (5 * 3% / 30)
        $policy = MiningCalculationPolicy::fromPlatform();
        $rec    = new MiningRecoveryService();
        $a17    = $rec->assessDate('2026-11-17', $policy);
        $this->assertSame('0.00000000', $a17['missing_coins']);
        $a18    = $rec->assessDate('2026-11-18', $policy);
        $this->assertSame('5.00000000', $a18['total_eligible_coins']);
        $this->assertSame('0.00500000', $a18['missing_coins']);

        // 3. Commission recovery dry-run with dual-approved 10/5/3 plan effective 2026-11-17
        $cDraft = service('commissionPlanService')->createDraft([
            'plan_name'       => '10/5/3 Plan',
            'effective_from'  => '2026-11-17',
            'level_count'     => 3,
            'level_1_percent' => '10',
            'level_2_percent' => '5',
            'level_3_percent' => '3',
        ], $this->adminUserId);
        $cPlan = service('commissionPlanService')->approve((string) $cDraft['plan_ref'], $this->approverUserId);

        $commPreview = (new CommissionPostingRunnerService())->generateSupersedingRevisions((string) $cPlan['plan_ref'], false, true, $this->adminUserId, $pRef);
        $this->assertSame('0.50000000', $commPreview['expected_total_usdt']);
        $this->assertSame(1, $commPreview['eligible_lines']);
        $this->assertSame(2, $commPreview['unallocated_lines']);
        $this->assertSame(0, $commPreview['revisions_created']);
    }

    public function testVersionedMiningPolicyDraftPreparationStaysDraftAndNeverAutoActivates(): void
    {
        $svc = new MiningProposalService();
        $out = $svc->prepareVersionedMiningDraft($this->adminUserId, '2026-11-18', true, 'tests');

        $this->assertSame('DRAFT_ONLY_NOT_ACTIVATED', $out['status']);
        $this->assertSame(FinancialPolicyService::STATUS_DRAFT, $out['policy']['status']);
        $this->assertSame('draft', $out['rate']['status']);
        $this->assertSame('3.00000000', $out['rate']['rate_percent']);

        $payload = json_decode((string) $out['policy']['payload_json'], true);
        $this->assertSame('exclude_approval_day', $payload['approval_day_policy']);
        $this->assertSame('calendar_days', $payload['month_day_divisor_mode']);
        $this->assertSame('COIN', $payload['earning_asset']);
        $this->assertSame('Asia/Kolkata', $payload['business_timezone']);
        $this->assertFalse($payload['compounding_enabled']);

        // Worksheet MUST still report all decisions unapproved (draft != approved/activated)
        $ws = (new MiningPolicyWorksheetService())->build();
        $this->assertSame(0, $ws['approved_count']);
        $this->assertSame(12, $ws['pending_count']);

        // Live DB draft preparation refused without explicit authorization
        $cfg = config('Database');
        $cfg->safetyprobe = array_merge($cfg->tests, ['database' => 'wwwzeont_ngw_test_db']);
        try {
            $this->expectException(RuntimeException::class);
            $svc->prepareVersionedMiningDraft($this->adminUserId, '2026-11-18', true, 'safetyprobe', false, null);
        } finally {
            unset($cfg->safetyprobe);
        }
    }

    public function testCommissionsRecoverPlanCliRequiresProductionAuthToPersistOnLiveDb(): void
    {
        $parsedBad = CliArgs::parse(['--group', 'default', '--persist'], CommissionsRecoverPlan::argSpec());
        $this->assertSame([], $parsedBad['errors']);

        $parsedGood = CliArgs::parse(['--group', 'default', '--persist', '--plan', 'CPL-123', '--allow-production', '--confirm', ProductionSafetyGate::CONFIRM_PHRASE], CommissionsRecoverPlan::argSpec());
        $this->assertSame([], $parsedGood['errors']);

        $cfg = config('Database');
        $cfg->safetyprobe = array_merge($cfg->tests, ['database' => 'wwwzeont_ngw_test_db']);
        try {
            $this->expectException(RuntimeException::class);
            (new ProductionSafetyGate())->assertMutationAllowed(false, null, 'safetyprobe', 'commission superseding revision persistence');
        } finally {
            unset($cfg->safetyprobe);
        }
    }
}
