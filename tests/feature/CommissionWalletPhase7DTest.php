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

/**
 * Phase 7D focused tests: snapshots, wallet isolation, posting/withdrawal kill-switches.
 *
 * @internal
 */
final class CommissionWalletPhase7DTest extends CIUnitTestCase
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

        $platform = config('Platform');
        $platform->adminEmail = 'admin@test.local';
        $platform->adminPassword = 'AdminPass123!';
        $platform->adminName = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->makeFinanceUser('7dapr', 'finance_approver');

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, '7d listing');
        service('coinInventoryService')->addInventory('20000', $this->adminUserId, '7d stock', null, '7d-stock-' . bin2hex(random_bytes(3)));

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

    private function makeFinanceUser(string $suffix, string $group): int
    {
        $users = model(UserModel::class);
        $user  = new User(['username' => 'u' . $suffix . bin2hex(random_bytes(2)), 'active' => 1]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->createEmailIdentity([
            'email'    => $suffix . bin2hex(random_bytes(2)) . '@test.local',
            'password' => 'Pass123456!',
        ]);
        $user->addGroup('admin');
        $user->addGroup($group);

        return (int) $user->id;
    }

    private function register(string $suffix, string $sponsorId = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('7d' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', '7d' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => '7D ' . $suffix,
            'sponsor_id'  => $sponsorId,
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => '7d' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);
        db_connect()->table('member_profiles')->where('user_id', (int) $result['user']->id)->update([
            'account_status' => 'active',
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
        ];
    }

    private function approvePurchase(array $member, string $qty = '200'): array
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            '7d-buy-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId);
        db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->update(['payment_status' => 'verified']);

        return db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $req['request']['purchase_ref'])
            ->get()
            ->getRowArray() ?: [];
    }

    private function approvePlan(): array
    {
        $op = $this->makeFinanceUser('7dop', 'finance_operator');
        $draft = service('commissionPlanService')->createDraft([
            'plan_name'                  => '7D Plan',
            'effective_from'             => date('Y-m-d'),
            'level_count'                => 3,
            'level_1_percent'            => '10',
            'level_2_percent'            => '5',
            'level_3_percent'            => '3',
            'eligible_purchase_policy'   => 'approved_only',
            'sponsor_eligibility_policy' => 'active_sponsors_only',
        ], $op);

        return service('commissionPlanService')->approve($draft['plan_ref'], $this->approverUserId);
    }

    public function testSnapshotCreatedOnApprovalAndDuplicatePrevented(): void
    {
        $sponsor = $this->register('snapsp');
        $buyer   = $this->register('snapbuy', $sponsor['member_id']);
        $purchase = $this->approvePurchase($buyer);

        $rows = service('purchaseSponsorSnapshotService')->snapshotsForPurchase($purchase['purchase_ref']);
        $this->assertCount(3, $rows);
        $this->assertSame('CAPTURED', $rows[0]['snapshot_status']);
        $this->assertSame($sponsor['member_id'], $rows[0]['sponsor_member_id']);

        $again = service('purchaseSponsorSnapshotService')->captureForApprovedPurchase($purchase, $this->adminUserId);
        $this->assertCount(3, $again);
        $this->assertSame(3, db_connect()->table('purchase_sponsor_snapshots')->where('purchase_ref', $purchase['purchase_ref'])->countAllResults());
    }

    public function testSnapshotImmutabilityGuard(): void
    {
        $buyer = $this->register('immut');
        $purchase = $this->approvePurchase($buyer);
        $this->expectException(\RuntimeException::class);
        service('purchaseSponsorSnapshotService')->rejectMutationAttempt($purchase['purchase_ref']);
    }

    public function testHistoricalSnapshotUnavailable(): void
    {
        $trust = service('purchaseSponsorSnapshotService')->evaluateTrust('NO-SUCH-PURCHASE');
        $this->assertFalse($trust['trusted']);
        $this->assertSame('HISTORICAL_SNAPSHOT_UNAVAILABLE', $trust['status']);
    }

    public function testCircularGenealogyMarkedMissing(): void
    {
        $a = $this->register('circa');
        $b = $this->register('circb', $a['member_id']);
        db_connect()->table('sponsor_relationships')->where('member_user_id', $a['user_id'])->update([
            'sponsor_user_id'   => $b['user_id'],
            'sponsor_member_id' => $b['member_id'],
        ]);
        $purchase = $this->approvePurchase($b);
        $rows = service('purchaseSponsorSnapshotService')->snapshotsForPurchase($purchase['purchase_ref']);
        $this->assertTrue((int) $rows[0]['is_missing'] === 1 || (int) $rows[1]['is_missing'] === 1);
        $trust = service('purchaseSponsorSnapshotService')->evaluateTrust($purchase['purchase_ref']);
        $this->assertFalse($trust['trusted']);
    }

    public function testWalletIsolationAndKillSwitches(): void
    {
        $member = $this->register('wiso');
        $acc = service('commissionWalletService')->ensureAccount($member['user_id'], $member['member_id']);
        $this->assertSame('0.00000000', MoneyMath::normalize((string) $acc['available_balance'], 8));
        $this->assertFalse(service('commissionWalletService')->postingEnabled());
        $this->assertFalse(service('commissionWalletService')->withdrawalEnabled());

        $this->expectException(\RuntimeException::class);
        service('commissionWalletService')->creditCommission($member['user_id'], $member['member_id'], [
            'entry_type'      => 'commission_credit',
            'amount'          => '10',
            'idempotency_key' => 'x',
        ]);
    }

    public function testWithdrawalDisabledAndSimulationNotWithdrawable(): void
    {
        $member = $this->register('wdblk');
        service('commissionWalletService')->ensureAccount($member['user_id'], $member['member_id']);

        $this->expectException(\InvalidArgumentException::class);
        service('commissionWithdrawalService')->createDraft($member['user_id'], $member['member_id'], [
            'amount'              => '1.00',
            'network'             => 'BEP20',
            'destination_address' => '0x' . str_repeat('a', 40),
        ]);
    }

    public function testWithdrawalSubmitBlocked(): void
    {
        $member = $this->register('wdsub');
        // Zero amount path blocked earlier; submit always blocked by kill-switch.
        $this->expectException(\RuntimeException::class);
        service('commissionWithdrawalService')->submit('CWD-NONE', $member['user_id']);
    }

    public function testPostingProposalDualControlNoPost(): void
    {
        $this->approvePlan();
        $sponsor = $this->register('posts');
        $buyer   = $this->register('postb', $sponsor['member_id']);
        $purchase = $this->approvePurchase($buyer);

        $op = $this->makeFinanceUser('prop', 'finance_operator');
        $draft = service('commissionPostingProposalService')->createDraft([
            'purchase_ref' => $purchase['purchase_ref'],
            'level'        => 1,
        ], $op);
        $pending = service('commissionPostingProposalService')->submit($draft['proposal_ref'], $op);

        $this->expectException(\InvalidArgumentException::class);
        service('commissionPostingProposalService')->approve($pending['proposal_ref'], $op);
    }

    public function testPostingApproveDoesNotCreditWallet(): void
    {
        $this->approvePlan();
        $sponsor = $this->register('apac');
        $buyer   = $this->register('apab', $sponsor['member_id']);
        $purchase = $this->approvePurchase($buyer);
        $op = $this->makeFinanceUser('apacop', 'finance_operator');

        $draft = service('commissionPostingProposalService')->createDraft([
            'purchase_ref' => $purchase['purchase_ref'],
            'level'        => 1,
        ], $op);
        service('commissionPostingProposalService')->submit($draft['proposal_ref'], $op);
        $approved = service('commissionPostingProposalService')->approve($draft['proposal_ref'], $this->approverUserId);

        $this->assertSame('approved', $approved['status']);
        $this->assertSame('0', (string) $approved['is_posted']);
        $this->assertSame('0', (string) $approved['is_executable']);

        $acc = service('commissionWalletService')->getAccount($sponsor['user_id']);
        $this->assertSame('0.00000000', MoneyMath::normalize((string) $acc['available_balance'], 8));
        $this->assertSame(0, db_connect()->table('commission_wallet_ledger_entries')->countAllResults());
    }

    public function testUnavailableSnapshotBlocksProposal(): void
    {
        $this->approvePlan();
        $this->expectException(\InvalidArgumentException::class);
        service('commissionPostingProposalService')->createDraft([
            'purchase_ref' => 'MISSING-REF',
            'level'        => 1,
        ], $this->adminUserId);
    }

    public function testReconciliationCleanAndMemberIsolation(): void
    {
        $a = $this->register('reca');
        $b = $this->register('recb');
        service('commissionWalletService')->ensureAccount($a['user_id'], $a['member_id']);
        service('commissionWalletService')->ensureAccount($b['user_id'], $b['member_id']);

        $result = service('commissionWalletReconciliationService')->run();
        $this->assertSame(0, $result['issue_count']);

        $this->assertSame([], service('commissionWalletService')->recentEntries($a['user_id']));
        $owned = service('commissionWithdrawalService')->findOwned('NOPE', $a['user_id']);
        $this->assertNull($owned);
    }

    public function testUnauthorizedMemberCannotOpenAdminWallet(): void
    {
        $member = $this->register('unauth');
        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('admin/commission-wallet')->assertRedirect();
    }

    public function testReserveBlockedByKillSwitch(): void
    {
        $member = $this->register('resblk');
        $this->expectException(\RuntimeException::class);
        service('commissionWalletService')->reserveForWithdrawal($member['user_id'], $member['member_id'], [
            'amount'          => '1',
            'idempotency_key' => 'r1',
        ]);
    }

    public function testNoOwnershipMutationFromCommissionServices(): void
    {
        $member = $this->register('owniso');
        $this->approvePurchase($member, '200');
        $before = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $ownCnt = db_connect()->table('member_coin_ownership_transactions')->countAllResults();

        service('commissionWalletService')->ensureAccount($member['user_id'], $member['member_id']);
        service('commissionWalletReconciliationService')->run();

        $after = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame($before['balance'], $after['balance']);
        $this->assertSame($ownCnt, db_connect()->table('member_coin_ownership_transactions')->countAllResults());
    }
}
