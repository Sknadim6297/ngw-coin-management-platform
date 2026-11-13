<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 6 member wallet & transaction management.
 *
 * @internal
 */
final class MemberWalletTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;

    protected function setUp(): void
    {
        parent::setUp();

        $platform                 = config('Platform');
        $platform->adminEmail     = 'admin@test.local';
        $platform->adminPassword  = 'AdminPass123!';
        $platform->adminName      = 'Test Admin';
        $platform->rootSponsorId  = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Wallet test listing');
        service('coinInventoryService')->addInventory('5000', $this->adminUserId, 'Stock', null, 'wallet-stock-' . bin2hex(random_bytes(4)));

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

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('w' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Wallet ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'wallet' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
            'user_id'   => (int) $result['user']->id,
            'email'     => 'wallet' . $suffix . '@example.com',
        ];
    }

    private function creditCoins(array $member, string $qty = '100'): string
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'wallet-buy-' . $member['user_id'] . '-' . $qty . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'wallet test credit');

        return (string) $req['request']['purchase_ref'];
    }

    private function enabledPlan(): array
    {
        $plan = service('coinLockingService')->createPlan([
            'plan_code'     => 'W' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6)),
            'name'          => 'Wallet Lock Plan',
            'description'   => 'Phase 6',
            'min_quantity'  => '1',
            'max_quantity'  => '1000',
            'duration_days' => 30,
        ], $this->adminUserId);

        return service('coinLockingService')->setPlanEnabled((int) $plan['id'], true, $this->adminUserId);
    }

    public function testWalletBalancesMatchOwnershipLedger(): void
    {
        $member = $this->registerMember('balmatch');
        $this->creditCoins($member, '40');

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $wallet  = service('memberWalletService')->overview($member['user_id']);
        $recon   = service('walletReconciliationService')->reconcile($member['user_id'], $member['member_id']);

        $this->assertSame($summary['balance'], $wallet['balance']);
        $this->assertSame($summary['available'], $wallet['available']);
        $this->assertSame($summary['locked'], $wallet['locked']);
        $this->assertSame(
            $summary['balance'],
            MoneyMath::add($summary['available'], $summary['locked'], 8)
        );
        $codes = array_column($recon['findings'], 'code');
        $this->assertContains('wallet_ok', $codes);
        $this->assertFalse($recon['auto_repair']);
    }

    public function testLockUnlockDoesNotChangeTotalOwnership(): void
    {
        $member = $this->registerMember('locktot');
        $this->creditCoins($member, '80');
        $before = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $plan   = $this->enabledPlan();

        $lock = service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '25');
        $mid  = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame($before['balance'], $mid['balance']);

        $ref = $lock['lock']['lock_ref'];
        db_connect()->table('member_coin_locks')->where('lock_ref', $ref)->update([
            'matures_at' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->assertTrue(service('coinLockingService')->markMatured($ref));
        service('coinLockingService')->unlockCoins($member['user_id'], $ref);
        $after = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame($before['balance'], $after['balance']);
        $this->assertSame($before['available'], $after['available']);
    }

    public function testPurchaseCreditsIncreaseAvailableCoins(): void
    {
        $member = $this->registerMember('creditup');
        $before = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->creditCoins($member, '25');
        $after = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);

        $this->assertSame(
            MoneyMath::add($before['available'], '25.00000000', 8),
            $after['available']
        );
        $this->assertSame($before['locked'], $after['locked']);
    }

    public function testMemberCannotViewAnotherWallet(): void
    {
        $a = $this->registerMember('ownera');
        $b = $this->registerMember('ownerb');
        $this->creditCoins($a, '10');
        $this->creditCoins($b, '20');

        $txn = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $a['user_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($txn);

        $this->post('login', [
            'member_id' => $b['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();

        $blocked = $this->withSession()->get('member/wallet/transactions/' . $txn['transaction_ref']);
        $blocked->assertRedirectTo(site_url('member/wallet/transactions'));

        $own = $this->withSession()->get('member/wallet');
        $own->assertOK();
        $own->assertSee('20.00000000');
        $own->assertDontSee($txn['transaction_ref']);
    }

    public function testAdminWalletLookupAuthorized(): void
    {
        $member = $this->registerMember('adminlook');
        $this->creditCoins($member, '15');

        $guest = $this->get('admin/wallets');
        $guest->assertRedirect();

        $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ])->assertRedirect();

        $page = $this->withSession()->get('admin/wallets?member_id=' . $member['member_id']);
        $page->assertOK();
        $page->assertSee($member['member_id']);
        $page->assertSee('15.00000000');
        $page->assertDontSee('Edit Balance');
        $page->assertDontSee('Adjust Balance');

        $recon = $this->withSession()->get('admin/wallets/reconcile?member_id=' . $member['member_id']);
        $recon->assertOK();
        $recon->assertSee('Wallet Reconciliation');
        $recon->assertSee('Disabled');
    }

    public function testTransactionFiltersWork(): void
    {
        $member = $this->registerMember('filters');
        $this->creditCoins($member, '50');
        $plan = $this->enabledPlan();
        service('coinLockingService')->lockCoins($member['user_id'], (int) $plan['id'], '10');

        $lockRows = service('memberWalletService')->history($member['user_id'], ['type' => 'LOCK']);
        $this->assertNotEmpty($lockRows['rows']);
        foreach ($lockRows['rows'] as $row) {
            $this->assertSame('LOCK', $row['type']);
        }

        $creditRows = service('memberWalletService')->history($member['user_id'], ['type' => 'PURCHASE_CREDIT']);
        $this->assertNotEmpty($creditRows['rows']);
        foreach ($creditRows['rows'] as $row) {
            $this->assertSame('PURCHASE_CREDIT', $row['type']);
        }

        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $lockPage = $this->withSession()->get('member/wallet/transactions?type=LOCK');
        $lockPage->assertOK();
        $lockPage->assertSee('LOCK');
        $creditPage = $this->withSession()->get('member/wallet/transactions?type=PURCHASE_CREDIT');
        $creditPage->assertOK();
        $creditPage->assertSee('PURCHASE_CREDIT');
    }

    public function testCsvExportIsSafe(): void
    {
        $member = $this->registerMember('csvsafe');
        $this->creditCoins($member, '20');

        // Inject a formula-like source ref via direct metadata is not needed;
        // ensure export prefixes dangerous cells if present in a synthetic row.
        $csv = service('memberWalletService')->buildCsv([[
            'transaction_ref'    => '=HYPERLINK("http://evil")',
            'created_at'         => date('Y-m-d H:i:s'),
            'type'               => 'PURCHASE_CREDIT',
            'direction'          => 'credit',
            'quantity'           => '1.00000000',
            'previous_balance'   => '0.00000000',
            'new_balance'        => '1.00000000',
            'previous_available' => '0.00000000',
            'new_available'      => '1.00000000',
            'previous_locked'    => '0.00000000',
            'new_locked'         => '0.00000000',
            'purchase_ref'       => '+CMD',
            'lock_ref'           => '',
            'member_id'          => $member['member_id'],
        ]]);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString("'+CMD", $csv);

        $other = $this->registerMember('othercsv');
        $this->creditCoins($other, '20');

        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $export = $this->withSession()->get('member/wallet/transactions/export');
        $export->assertOK();
        $body = $export->response()->getBody();
        $this->assertStringContainsString('text/csv', strtolower($export->response()->getHeaderLine('Content-Type')));
        $this->assertStringContainsString('PURCHASE_CREDIT', $body);
        $this->assertStringContainsString($member['member_id'], $body);
        $this->assertStringNotContainsString($other['member_id'], $body);
    }

    public function testReconciliationDetectsBalanceDrift(): void
    {
        $member = $this->registerMember('drift');
        $this->creditCoins($member, '30');

        db_connect()->table('member_coin_balances')
            ->where('member_user_id', $member['user_id'])
            ->update([
                'available_balance' => '99.00000000',
                'balance'           => '99.00000000',
                'locked_balance'    => '0.00000000',
            ]);

        $report = service('walletReconciliationService')->reconcile($member['user_id'], $member['member_id']);
        $codes  = array_column($report['findings'], 'code');
        $this->assertContains('cache_ledger_drift', $codes);
        $this->assertFalse($report['auto_repair']);

        // Ensure service does not silently repair.
        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('99.00000000', $summary['available']);
    }

    public function testNoAutomaticLedgerRepairMethod(): void
    {
        $this->assertFalse(method_exists(service('walletReconciliationService'), 'repair'));
        $this->assertFalse(method_exists(service('walletReconciliationService'), 'autoRepair'));
        $this->assertFalse(method_exists(service('memberWalletService'), 'adjustBalance'));
        $this->assertFalse(method_exists(service('memberWalletService'), 'setBalance'));
    }

    public function testHoldingsRedirectsToWallet(): void
    {
        $member = $this->registerMember('holdredir');
        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('member/purchases/holdings')->assertRedirectTo(site_url('member/wallet'));
    }
}
