<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\FinancialPolicyService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 12E focused policy hub tests (isolated test DB).
 *
 * @internal
 */
final class Phase12EFinancialPolicyTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;
    protected $seed        = '';

    private int $adminUserId;
    private string $adminPassword = 'AdminPass123!';

    protected function setUp(): void
    {
        parent::setUp();

        $platform = config('Platform');
        $platform->adminEmail = 'admin@test.local';
        $platform->adminPassword = $this->adminPassword;
        $platform->adminName = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'NGW';
        $platform->memberIdStartNumber = 981234;
        $platform->memberIdPadding = 6;
        $platform->miningPostingEnabled = false;
        $platform->miningP2pEnabled = false;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->coinSellingEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $this->clearSupportView();
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $this->clearSupportView();
        $platform = config('Platform');
        $platform->miningPostingEnabled = false;
        $platform->miningP2pEnabled = false;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->coinSellingEnabled = false;
        Services::reset(true);
        parent::tearDown();
    }

    private function clearSupportView(): void
    {
        $ref = new \ReflectionClass(service('impersonationService'));
        $prop = $ref->getProperty('activeContext');
        $prop->setAccessible(true);
        $prop->setValue(service('impersonationService'), null);
    }

    private function loginAdmin(): void
    {
        $user = model(UserModel::class)->findById($this->adminUserId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    public function testMiningPolicyValidationPreview(): void
    {
        $svc = service('financialPolicyService');
        $m = $svc->miningPreview('100', '3', 'fixed_30');
        $this->assertSame('0.10000000', $m['daily_earning']);
        $this->assertTrue($m['illustrative_only']);

        $this->expectException(InvalidArgumentException::class);
        $svc->miningPreview('100', '3', 'invalid_mode');
    }

    public function testCommissionPlanPreviewValidation(): void
    {
        $c = service('financialPolicyService')->commissionPreview('100', '10', '5', '3');
        $this->assertSame('10.00000000', $c['l1']);
        $this->assertSame('5.00000000', $c['l2']);
        $this->assertSame('3.00000000', $c['l3']);
        $this->assertSame('18.00000000', $c['total']);
        $this->assertTrue($c['not_an_approval']);
    }

    public function testP2pWithdrawalSellingSnapshotsUnresolved(): void
    {
        $svc = service('financialPolicyService');
        $p2p = $svc->currentSnapshot(FinancialPolicyService::DOMAIN_P2P);
        $this->assertNull($p2p['min_transfer']);
        $this->assertFalse((bool) $p2p['execution_enabled']);

        $wd = $svc->currentSnapshot(FinancialPolicyService::DOMAIN_WITHDRAWAL);
        $this->assertFalse((bool) $wd['mining_wallet_withdrawable']);
        $this->assertFalse((bool) $wd['execution_enabled']);

        $sell = $svc->currentSnapshot(FinancialPolicyService::DOMAIN_SELLING);
        $this->assertFalse((bool) $sell['execution_enabled']);
        $this->assertSame(1000, (int) $sell['lock_days']);
    }

    public function testMemberIdAndLockPreviewsDoNotMutate(): void
    {
        $dbName = Database::connect()->getDatabase();
        $this->assertNotSame('coin_platform', $dbName);

        $snap = service('financialPolicyService')->currentSnapshot(FinancialPolicyService::DOMAIN_MEMBER_ID);
        $this->assertArrayHasKey('before', $snap);
        $this->assertArrayHasKey('after_projected', $snap);
        $this->assertFalse((bool) ($snap['executed'] ?? false));

        $beforeSeq = db_connect()->table('member_id_sequences')->where('prefix', 'NGW')->get()->getRowArray();
        service('financialPolicyService')->currentSnapshot(FinancialPolicyService::DOMAIN_PURCHASE);
        $afterSeq = db_connect()->table('member_id_sequences')->where('prefix', 'NGW')->get()->getRowArray();
        $this->assertSame($beforeSeq['last_number'] ?? null, $afterSeq['last_number'] ?? null);
    }

    public function testDraftCreateSubmitAndSelfApprovalRejected(): void
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            $this->markTestSkipped('financial_policy_versions missing');
        }

        $this->loginAdmin();
        $svc = service('financialPolicyService');
        $draft = $svc->createDraft(
            FinancialPolicyService::DOMAIN_MINING,
            'Mining draft 12E',
            [
                'monthly_percent' => null,
                'day_divisor_mode' => 'fixed_30',
                'earning_asset' => null,
            ],
            $this->adminUserId,
            date('Y-m-d'),
            'unresolved percent'
        );
        $this->assertSame('draft', $draft['status']);
        $this->assertSame(1, (int) $draft['version_no']);

        $pending = $svc->submitForApproval($draft['policy_ref'], $this->adminUserId, $this->adminPassword);
        $this->assertSame('pending_approval', $pending['status']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Self-approval');
        $svc->approve($draft['policy_ref'], $this->adminUserId, $this->adminPassword);
    }

    public function testApprovedPoliciesImmutableAndVersioning(): void
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            $this->markTestSkipped('financial_policy_versions missing');
        }

        $this->loginAdmin();
        $svc = service('financialPolicyService');
        $d1 = $svc->createDraft(
            FinancialPolicyService::DOMAIN_COMMISSION,
            'Commission v1',
            ['level1_percent' => '10', 'level2_percent' => '5', 'level3_percent' => '3', 'calculation_base' => null],
            $this->adminUserId,
            date('Y-m-d')
        );
        $d2 = $svc->createDraft(
            FinancialPolicyService::DOMAIN_COMMISSION,
            'Commission v2',
            ['level1_percent' => '10', 'level2_percent' => '5', 'level3_percent' => '3', 'calculation_base' => null],
            $this->adminUserId
        );
        $this->assertSame(2, (int) $d2['version_no']);

        db_connect()->table('financial_policy_versions')->where('policy_ref', $d1['policy_ref'])->update([
            'status' => FinancialPolicyService::STATUS_APPROVED,
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Only draft');
        $svc->updateDraft($d1['policy_ref'], ['level1_percent' => '11'], $this->adminUserId);
    }

    public function testEffectiveDateValidation(): void
    {
        $this->loginAdmin();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Effective date');
        service('financialPolicyService')->createDraft(
            FinancialPolicyService::DOMAIN_P2P,
            'Bad date',
            ['min_transfer' => null],
            $this->adminUserId,
            '17-11-2026'
        );
    }

    public function testSupportViewRestrictions(): void
    {
        if (! db_connect()->tableExists('financial_policy_versions')) {
            $this->markTestSkipped('financial_policy_versions missing');
        }

        service('impersonationService')->setActiveContext([
            'admin_user_id'  => $this->adminUserId,
            'target_user_id' => $this->adminUserId,
            'read_only'      => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Support View');
        try {
            service('financialPolicyService')->createDraft(
                FinancialPolicyService::DOMAIN_P2P,
                'Should fail',
                ['min_transfer' => null],
                $this->adminUserId
            );
        } finally {
            $this->clearSupportView();
        }
    }

    public function testKillSwitchesRemainOffAndReadiness(): void
    {
        $dash = service('financialPolicyService')->readinessDashboard();
        $this->assertTrue($dash['all_kill_switches_off']);
        $this->assertFalse((bool) $dash['kill_switches']['miningPostingEnabled']);
        $this->assertFalse((bool) $dash['kill_switches']['miningP2pEnabled']);
        $this->assertFalse((bool) $dash['kill_switches']['commissionPostingEnabled']);
        $this->assertFalse((bool) $dash['kill_switches']['commissionWithdrawalEnabled']);
        $this->assertFalse((bool) $dash['kill_switches']['commissionBlockchainPayoutEnabled']);
        $this->assertFalse((bool) $dash['kill_switches']['coinSellingEnabled']);

        service('productionSafetyGate')->assertKillSwitchesOff();
        $this->assertArrayHasKey('blockchain_verifier', $dash['summary']);
        $this->assertSame('BLOCKED', $dash['summary']['blockchain_verifier']);
    }

    public function testProductionDatabaseIsolation(): void
    {
        $dbName = Database::connect()->getDatabase();
        $this->assertNotSame('coin_platform', $dbName);
        $this->assertStringContainsString('test', strtolower($dbName));
    }
}
