<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use RuntimeException;

/**
 * Phase 11B focused commission engine tests (posting remains OFF).
 *
 * @internal
 */
final class Phase11BCommissionEngineTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $platform                                    = config('Platform');
        $platform->adminEmail                        = 'admin@test.local';
        $platform->adminPassword                     = 'AdminPass123!';
        $platform->adminName                         = 'Test Admin';
        $platform->rootSponsorId                     = 'ROOT0001';
        $platform->memberIdPrefix                    = 'CPM';
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->miningPostingEnabled              = false;
        $platform->mandatoryPurchaseLockEnabled      = true;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId    = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->createApprover();

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p11b price');
        service('coinInventoryService')->addInventory(
            '20000',
            $this->adminUserId,
            'p11b stock',
            null,
            'p11b-stock-' . bin2hex(random_bytes(3))
        );

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    private function createApprover(): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'p11bapprover', 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => 'p11b.approver@test.local',
            'password' => 'ApproverPass123!',
        ]);
        $user->addGroup('admin');
        $user->addGroup('finance_approver');

        return (int) $user->id;
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function register(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p11b' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p11b-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P11B ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'p11b' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
        ];
    }

    private function approvePlan1053(string $from = '2020-01-01'): array
    {
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'        => 'P11B Default',
            'effective_from'   => $from,
            'effective_to'     => '',
            'level_count'      => 3,
            'level_1_percent'  => '10.00000000',
            'level_2_percent'  => '5.00000000',
            'level_3_percent'  => '3.00000000',
            'notes'            => 'p11b',
        ], $this->adminUserId);

        return service('commissionPlanService')->approve($draft['plan_ref'], $this->approverUserId);
    }

    private function buyApprove(array $member, string $qty = '100'): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p11b-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        $out = service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $this->adminUserId,
            true,
            'p11b'
        );

        return [
            'purchase_ref' => (string) $req['request']['purchase_ref'],
            'result'       => $out,
        ];
    }

    public function testLevelRatesTenFiveThreeAndIllustrativeMath(): void
    {
        $plan = $this->approvePlan1053();
        $this->assertSame('10.00000000', $plan['levels'][1]);
        $this->assertSame('5.00000000', $plan['levels'][2]);
        $this->assertSame('3.00000000', $plan['levels'][3]);

        $sim = service('commissionSimulationService');
        $this->assertSame('10.00000000', $sim->previewAmount('100.00000000', '10.00000000'));
        $this->assertSame('5.00000000', $sim->previewAmount('100.00000000', '5.00000000'));
        $this->assertSame('3.00000000', $sim->previewAmount('100.00000000', '3.00000000'));
    }

    public function testPurchaseTriggeredCalcUsesSnapshotsAndIsIdempotent(): void
    {
        $this->approvePlan1053();
        $l1 = $this->register('l1');
        $this->buyApprove($l1, '10'); // activate so L1 can sponsor
        $buyer = $this->register('buyer', $l1['member_id']);

        $buy = $this->buyApprove($buyer, '100');
        $calcs = service('commissionPurchaseEngineService')->calculationsForPurchase($buy['purchase_ref']);
        $this->assertCount(3, $calcs);

        $byLevel = [];
        foreach ($calcs as $c) {
            $byLevel[(int) $c['sponsor_level']] = $c;
        }
        $this->assertSame('CALCULATED_NOT_POSTED', $byLevel[1]['status']);
        $this->assertSame($l1['member_id'], $byLevel[1]['beneficiary_member_id']);
        $this->assertSame(0, MoneyMath::compare((string) $byLevel[1]['expected_amount_usdt'], '10.00000000', 8));
        // L2 under active L1 is ROOT sponsor (captured); L3 may be unallocated.
        $this->assertContains($byLevel[2]['status'], ['CALCULATED_NOT_POSTED', 'UNALLOCATED']);

        // Idempotent re-calc
        service('commissionPurchaseEngineService')->calculateForPurchase($buy['purchase_ref'], true, $this->adminUserId);
        $again = service('commissionPurchaseEngineService')->calculationsForPurchase($buy['purchase_ref']);
        $this->assertCount(3, $again);

        // No wallet credits
        $entries = db_connect()->table('commission_wallet_ledger_entries')->countAllResults();
        $this->assertSame(0, $entries);
    }

    public function testRejectedPurchaseProducesNoCommission(): void
    {
        $this->approvePlan1053();
        $member = $this->register('rej');
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '50',
            null,
            'p11b-rej-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->rejectRequest($req['request']['purchase_ref'], $this->adminUserId, 'nope');

        $result = service('commissionPurchaseEngineService')->calculateForPurchase(
            $req['request']['purchase_ref'],
            true,
            $this->adminUserId
        );
        foreach ($result['lines'] as $line) {
            $this->assertSame('INELIGIBLE', $line['status']);
            $this->assertSame('0.00000000', $line['expected_amount_usdt']);
        }
    }

    public function testPostingBlockedAndMiningWalletUnaffected(): void
    {
        $this->assertFalse(service('commissionWalletService')->postingEnabled());
        $blocked = false;
        try {
            service('commissionPurchaseEngineService')->postPurchase('ANY');
        } catch (RuntimeException $e) {
            $blocked = true;
        }
        $this->assertTrue($blocked);

        $member = $this->register('mineiso');
        $before = service('miningWalletService')->balanceFor($member['user_id']);
        $this->approvePlan1053();
        $this->buyApprove($member, '20');
        $after = service('miningWalletService')->balanceFor($member['user_id']);
        $this->assertSame($before['available_balance'], $after['available_balance']);
    }

    public function testEffectiveDatedPlanSelection(): void
    {
        $old = service('commissionPlanService')->createDraft([
            'plan_name'       => 'Old',
            'effective_from'  => '2026-01-01',
            'effective_to'    => '2026-01-31',
            'level_count'     => 3,
            'level_1_percent' => '10.00000000',
            'level_2_percent' => '5.00000000',
            'level_3_percent' => '3.00000000',
        ], $this->adminUserId);
        service('commissionPlanService')->approve($old['plan_ref'], $this->approverUserId);

        $new = service('commissionPlanService')->createDraft([
            'plan_name'       => 'New',
            'effective_from'  => '2026-02-01',
            'effective_to'    => '',
            'level_count'     => 3,
            'level_1_percent' => '12.00000000',
            'level_2_percent' => '6.00000000',
            'level_3_percent' => '4.00000000',
        ], $this->adminUserId);
        service('commissionPlanService')->approve($new['plan_ref'], $this->approverUserId);

        $jan = service('commissionPlanService')->findApplicablePlan('2026-01-15');
        $feb = service('commissionPlanService')->findApplicablePlan('2026-02-15');
        $this->assertSame('10.00000000', $jan['levels'][1]);
        $this->assertSame('12.00000000', $feb['levels'][1]);
    }

    public function testReconcileAndStatus(): void
    {
        $status = service('commissionPurchaseEngineService')->status();
        $this->assertFalse($status['commission_posting_enabled']);
        $report = service('commissionPurchaseEngineService')->reconcile(null, 50);
        $this->assertArrayHasKey('mismatches', $report);
        $this->assertStringContainsString('OFF', $report['note']);
    }

    public function testMemberCannotSeeOtherCommissionCalcsViaServiceScope(): void
    {
        $this->approvePlan1053();
        $a = $this->register('owna');
        $this->buyApprove($a, '10');
        $b = $this->register('ownb', $a['member_id']);
        $this->buyApprove($b, '40');

        $forA = service('commissionPurchaseEngineService')->calculationsForBeneficiary($a['user_id'], 20);
        $forB = service('commissionPurchaseEngineService')->calculationsForBeneficiary($b['user_id'], 20);
        foreach ($forA as $row) {
            $this->assertSame($a['user_id'], (int) $row['beneficiary_user_id']);
        }
        foreach ($forB as $row) {
            $this->assertSame($b['user_id'], (int) $row['beneficiary_user_id']);
        }
        $this->assertNotEmpty($forA);
    }
}
