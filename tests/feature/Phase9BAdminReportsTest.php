<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 9B focused: admin reports totals, filters, CSV, permissions.
 *
 * @internal
 */
final class Phase9BAdminReportsTest extends CIUnitTestCase
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

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p9b price');
        service('coinInventoryService')->addInventory('1500', $this->adminUserId, 'p9b stock', null, 'p9b-stock-' . bin2hex(random_bytes(3)));

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

    /**
     * @return array{user_id: int, member_id: string, profile: object}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p9b' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p9b-' . $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'P9B ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '19' . substr($digits . '00000000', 0, 8),
            'email'       => 'p9b' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => $profile->member_id,
            'profile'   => $profile,
        ];
    }

    private function loginAdmin(): void
    {
        $user = model(UserModel::class)->findById($this->adminUserId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    private function creditCoins(array $member, string $qty): string
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            $qty,
            null,
            'p9b-buy-' . $member['user_id'] . '-' . $qty . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'p9b');

        return (string) $req['request']['purchase_ref'];
    }

    /**
     * @param list<array<string, mixed>> $kpis
     */
    private function kpiByKey(array $kpis, string $key): array
    {
        foreach ($kpis as $kpi) {
            if (($kpi['key'] ?? null) === $key) {
                return $kpi;
            }
        }
        $this->fail('Missing KPI: ' . $key);
    }

    public function testReportsPageAndLegacyRedirect(): void
    {
        $guest = $this->get('admin/reports');
        $guest->assertRedirect();

        $this->loginAdmin();
        $page = $this->withSession()->get('admin/reports');
        $page->assertOK();
        $page->assertSee('Overview');
        $page->assertSee('Members');
        $page->assertSee('Total Members');
        $page->assertSee('Pending Deposits');

        $legacy = $this->withSession()->get('admin/coming-soon/reports');
        $legacy->assertRedirectTo(site_url('admin/reports'));
    }

    public function testOverviewTotalsMatchDatabase(): void
    {
        $inactive = $this->registerMember('ovinc');
        $active   = $this->registerMember('ovact');
        $this->creditCoins($active, '30');

        $pending = service('coinPurchaseService')->createRequest(
            $inactive['user_id'],
            '10',
            null,
            'p9b-pend-' . bin2hex(random_bytes(2))
        );
        $this->assertSame('pending', $pending['request']['status']);

        $overview = service('adminReportsService')->overviewReport();
        $profiles = model(MemberProfileModel::class);

        $this->assertSame($profiles->countByStatus(), $this->kpiByKey($overview['kpis'], 'rpt_total_members')['value']);
        $this->assertSame($profiles->countByStatus('active'), $this->kpiByKey($overview['kpis'], 'rpt_active_members')['value']);
        $this->assertSame($profiles->countByStatus('inactive'), $this->kpiByKey($overview['kpis'], 'rpt_inactive_members')['value']);

        $inv = service('coinInventoryService')->summary();
        $this->assertSame($inv['total_added'], $this->kpiByKey($overview['kpis'], 'rpt_coins_added')['value']);
        $this->assertSame($inv['available_balance'], $this->kpiByKey($overview['kpis'], 'rpt_available_inv')['value']);
        $this->assertSame($inv['total_allocated'], $this->kpiByKey($overview['kpis'], 'rpt_allocated')['value']);
        $this->assertSame(1, $this->kpiByKey($overview['kpis'], 'rpt_approved_purchases')['value']);
        $this->assertSame(1, $this->kpiByKey($overview['kpis'], 'rpt_pending_purchases')['value']);

        // Pending deposits must not inflate verified USDT revenue.
        $this->assertSame('0.00000000', $this->kpiByKey($overview['kpis'], 'rpt_verified_usdt')['value']);
    }

    public function testMemberAndDateFilters(): void
    {
        $a = $this->registerMember('filta');
        $b = $this->registerMember('filtb');
        $this->creditCoins($a, '12');

        $filtered = service('adminReportsService')->membersReport([
            'member_id' => $a['member_id'],
            'status'    => 'active',
        ]);
        $this->assertSame(1, $filtered['total']);
        $this->assertSame($a['member_id'], $filtered['rows'][0]['member_id']);
        $this->assertSame('12.00000000', $filtered['rows'][0]['owned_coins']);

        $inactiveOnly = service('adminReportsService')->membersReport(['status' => 'inactive']);
        $ids = array_column($inactiveOnly['rows'], 'member_id');
        $this->assertContains($b['member_id'], $ids);
        $this->assertNotContains($a['member_id'], $ids);

        $today = date('Y-m-d');
        $purchases = service('adminReportsService')->purchasesReport([
            'date_from' => $today,
            'date_to'   => $today,
            'status'    => 'approved',
        ]);
        $this->assertGreaterThanOrEqual(1, $purchases['total']);
        foreach ($purchases['rows'] as $row) {
            $this->assertSame('approved', $row['status']);
        }
    }

    public function testPurchasePaymentStatusSeparation(): void
    {
        $member = $this->registerMember('sepstat');
        $this->creditCoins($member, '8');
        service('coinPurchaseService')->createRequest($member['user_id'], '5', null, 'p9b-sep-pend-' . bin2hex(random_bytes(2)));

        $approved = service('adminReportsService')->purchasesReport(['status' => 'approved']);
        $pending  = service('adminReportsService')->purchasesReport(['status' => 'pending']);
        foreach ($approved['rows'] as $row) {
            $this->assertSame('approved', $row['status']);
        }
        foreach ($pending['rows'] as $row) {
            $this->assertSame('pending', $row['status']);
        }

        $wallet   = '0x' . str_repeat('a', 40);
        $contract = '0x' . str_repeat('b', 40);
        service('paymentDetailsService')->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $wallet,
            'recipient_name'           => 'Treasury',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'Pay',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);
        $network = service('paymentDetailsService')->ensureBep20();
        service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $network['id'],
            '9.00000000',
            '0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'),
            'p9b-dep-' . bin2hex(random_bytes(2)),
            null
        );

        $payments = service('adminReportsService')->paymentsReport(['status' => 'submitted'], false);
        $this->assertGreaterThanOrEqual(1, $payments['total']);
        foreach ($payments['rows'] as $row) {
            $this->assertSame('submitted', $row['status']);
            $this->assertArrayNotHasKey('tx_hash', $row);
            $this->assertArrayNotHasKey('destination_wallet_snapshot', $row);
        }

        $sensitive = service('adminReportsService')->paymentsReport(['status' => 'submitted'], true);
        $this->assertArrayHasKey('tx_hash', $sensitive['rows'][0]);
    }

    public function testInventoryLedgerReconciliation(): void
    {
        $member = $this->registerMember('recon');
        $this->creditCoins($member, '20');

        $report = service('adminReportsService')->inventoryReport();
        $inv    = service('coinInventoryService')->summary();
        $own    = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);

        $this->assertTrue($report['reconciled']);
        $this->assertSame($inv['total_added'], $this->kpiByKey($report['kpis'], 'inv_added')['value']);
        $this->assertSame($inv['total_allocated'], $this->kpiByKey($report['kpis'], 'inv_alloc')['value']);
        $this->assertSame($own['balance'], '20.00000000');
        $this->assertNotEmpty($report['rows']);
    }

    public function testCsvExportAndFormulaInjectionGuard(): void
    {
        $member = $this->registerMember('csvexp');
        $this->creditCoins($member, '7');

        $csv = service('adminReportsService')->exportCsv('purchases', ['status' => 'approved']);
        $this->assertStringContainsString('purchase_ref', $csv);
        $this->assertStringContainsString($member['member_id'], $csv);
        $this->assertStringContainsString('7.00000000', $csv);

        model(MemberProfileModel::class)->update((int) $member['profile']->id, [
            'member_name' => '=1+1',
        ]);
        $memberCsv = service('adminReportsService')->exportCsv('members', [
            'member_id' => $member['member_id'],
        ]);
        $this->assertStringContainsString("'=1+1", $memberCsv);

        $this->loginAdmin();
        $export = $this->withSession()->get('admin/reports/export?tab=overview');
        $export->assertOK();
        $this->assertStringContainsString('text/csv', (string) $export->response()->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Total Members', $export->response()->getBody());
    }

    public function testRolePermissionsAndNoMemberLeakage(): void
    {
        $member = $this->registerMember('noleak');
        $this->creditCoins($member, '5');

        $user = model(UserModel::class)->findById($member['user_id']);
        auth()->login($user);
        $blocked = $this->withSession()->get('admin/reports');
        $blocked->assertRedirect();

        $exportBlocked = $this->withSession()->get('admin/reports/export?tab=members');
        $exportBlocked->assertRedirect();
    }

    public function testSimulationsNotCountedAsRealIncome(): void
    {
        $mining = service('adminReportsService')->miningReport();
        $comms  = service('adminReportsService')->commissionsReport();

        $this->assertSame('unavailable', $this->kpiByKey($mining['kpis'], 'mine_actual')['status']);
        $this->assertNull($this->kpiByKey($mining['kpis'], 'mine_actual')['value']);
        $this->assertStringContainsString('Not Activated', (string) $this->kpiByKey($mining['kpis'], 'mine_actual')['reason']);

        $this->assertSame('unavailable', $this->kpiByKey($comms['kpis'], 'comm_actual')['status']);
        $this->assertNull($this->kpiByKey($comms['kpis'], 'comm_actual')['value']);
        $this->assertFalse($comms['posting_enabled']);

        $csvMine = service('adminReportsService')->exportCsv('mining');
        $this->assertStringContainsString('SIMULATION_ONLY', $csvMine);
        $this->assertStringContainsString('ACTUAL_POSTED', $csvMine);

        $this->loginAdmin();
        $page = $this->withSession()->get('admin/reports?tab=mining');
        $page->assertOK();
        $page->assertSee('Simulation preview only');
        $page->assertSee('not activated');
    }

    public function testWalletAndReferralTabs(): void
    {
        $member = $this->registerMember('wref');
        $this->creditCoins($member, '6');

        $wallets = service('adminReportsService')->walletsReport(['member_id' => $member['member_id']]);
        $this->assertGreaterThanOrEqual(1, $wallets['total']);

        $refs = service('adminReportsService')->referralsReport(['sponsor' => 'ROOT0001']);
        $this->assertGreaterThanOrEqual(1, $refs['total']);
        $this->assertNotNull($refs['levels']);

        $this->loginAdmin();
        $wPage = $this->withSession()->get('admin/reports?tab=wallets');
        $wPage->assertOK();
        $wPage->assertSee('Matching rows');
        $rPage = $this->withSession()->get('admin/reports?tab=referrals');
        $rPage->assertOK();
        $rPage->assertSee('Direct Relationships');
    }
}
