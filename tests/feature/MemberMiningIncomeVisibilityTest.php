<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Mining\MiningWalletPresenter;
use App\Models\MemberProfileModel;
use App\Services\ImpersonationService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Member Panel visibility of ACTUAL posted mining income (read-only).
 * Ledger rows are seeded directly into the TEST database only to model already-posted credits.
 *
 * @internal
 */
final class MemberMiningIncomeVisibilityTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private string $adminPassword = 'AdminPass123!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringContainsString('test', (string) db_connect()->getDatabase(), 'Must run on the test database only.');

        $platform                         = config('Platform');
        $platform->adminEmail             = 'admin@test.local';
        $platform->adminPassword          = $this->adminPassword;
        $platform->adminName              = 'Test Admin';
        $platform->rootSponsorId          = 'ROOT0001';
        $platform->memberIdPrefix         = 'CPM';
        $platform->memberIdStartNumber    = 1;
        $platform->miningPostingEnabled   = false;
        $platform->miningAutoPostEnabled  = false;
        $platform->miningP2pEnabled       = false;
        $platform->miningBusinessTimezone = 'Asia/Kolkata';
        $platform->miningEarningAsset     = 'COIN';

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $admin = model(UserModel::class)->findById($this->adminUserId);
        $admin->fill(['password' => $this->adminPassword]);
        model(UserModel::class)->save($admin);

        if (auth()->loggedIn()) {
            auth()->logout();
        }
    }

    protected function tearDown(): void
    {
        service('superglobals')->setCookieArray([]);
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    /**
     * @return array{user_id: int, member_id: string}
     */
    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('mmiv' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'mmiv-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'MMIV ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'mmiv' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return ['user_id' => (int) $result['user']->id, 'member_id' => (string) $result['member_id']];
    }

    /** Seeds an already-posted daily mining credit (test DB fixture). */
    private function seedCredit(array $m, string $businessDate, string $amount, string $ref, string $createdAtUtc = '2026-11-19 20:31:00'): void
    {
        $db = db_connect();
        service('miningWalletService')->ensureWallet($m['user_id'], $m['member_id']);
        $wallet  = $db->table('mining_wallets')->where('member_user_id', $m['user_id'])->get()->getRowArray();
        $balance = bcadd((string) $wallet['available_balance'], $amount, 8);
        $db->table('mining_wallet_ledger')->insert([
            'entry_ref'       => $ref,
            'member_user_id'  => $m['user_id'],
            'member_id'       => $m['member_id'],
            'asset_code'      => 'COIN',
            'entry_type'      => 'daily_mining_credit',
            'direction'       => 'credit',
            'amount'          => $amount,
            'balance_after'   => $balance,
            'business_date'   => $businessDate,
            'reference_type'  => 'mining_daily',
            'reference_id'    => 'RUN-' . $businessDate,
            'idempotency_key' => 'mmiv-' . $m['user_id'] . '-' . $businessDate . '-' . $ref,
            'created_at'      => $createdAtUtc,
        ]);
        $db->table('mining_wallets')->where('member_user_id', $m['user_id'])->update([
            'available_balance' => $balance,
            'total_credited'    => bcadd((string) $wallet['total_credited'], $amount, 8),
        ]);
    }

    private function loginMember(array $m): void
    {
        $this->post('login', ['member_id' => $m['member_id'], 'password' => 'Password123!'])->assertRedirect();
    }

    /** 2026-11-20 01:30 IST (still 2026-11-19 in UTC) — proves IST business dates are used. */
    private function istNow(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-19 20:00:00', new DateTimeZone('UTC'));
    }

    private function ledgerCounts(): array
    {
        $db = db_connect();

        return [
            $db->table('mining_wallets')->countAllResults(),
            $db->table('mining_wallet_ledger')->countAllResults(),
            (string) ($db->query('SELECT COALESCE(SUM(available_balance),0) s FROM mining_wallets')->getRow()->s ?? '0'),
        ];
    }

    public function testPostedCreditAndMissingDayUseIstBusinessDatesWithEightDecimals(): void
    {
        $m = $this->registerMember('posted');
        // 2026-11-19 posted 0.1; 2026-11-18 missing (never posted).
        $this->seedCredit($m, '2026-11-19', '0.10000000', 'MWL-POSTED-1119');

        $s = service('memberMiningIncomeService')->summary($m['user_id'], $this->istNow());
        $this->assertTrue($s['available']);
        $this->assertSame('2026-11-20', $s['today_date']);
        $this->assertSame('2026-11-19', $s['yesterday_date']);
        $this->assertSame('0.00000000', $s['today']);
        $this->assertFalse($s['today_posted']);
        $this->assertSame('0.10000000', $s['yesterday']);
        $this->assertTrue($s['yesterday_posted']);
        $this->assertSame('0.10000000', $s['total_earned']);
        $this->assertSame('0.10000000', $s['balance']);
        $this->assertSame(1, $s['credit_count']);
        $this->assertSame('2026-11-19', $s['last_credit_date']);
        $this->assertSame('2026-11-20 02:01:00', $s['last_credit_at'], 'Credited timestamp is shown in IST');

        // The missing day is a gap, not a fabricated row.
        $missing = service('memberMiningIncomeService')->summary(
            $m['user_id'],
            new DateTimeImmutable('2026-11-18 20:00:00', new DateTimeZone('UTC'))
        );
        $this->assertSame('2026-11-19', $missing['today_date']);
        $this->assertSame('2026-11-18', $missing['yesterday_date']);
        $this->assertSame('0.00000000', $missing['yesterday']);
        $this->assertFalse($missing['yesterday_posted']);

        $kpis = MiningWalletPresenter::walletKpis($s, 'member');
        $byKey = array_column($kpis, null, 'key');
        $this->assertSame('0.10000000', $byKey['mining_yesterday']['value']);
        $this->assertTrue($byKey['mining_yesterday']['exact']);
        $this->assertStringContainsString('2026-11-19 (IST) · Posted', $byKey['mining_yesterday']['note']);
        $this->assertStringContainsString('No credit posted', $byKey['mining_today']['note']);
    }

    public function testZeroCreditMemberShowsExplicitZeroNotUnavailable(): void
    {
        $m = $this->registerMember('zero');
        $s = service('memberMiningIncomeService')->summary($m['user_id']);
        $this->assertTrue($s['available']);
        $this->assertSame('0.00000000', $s['total_earned']);
        $this->assertSame('0.00000000', $s['balance']);
        $this->assertSame(0, $s['credit_count']);
        $this->assertNull($s['last_credit_date']);

        $kpis = array_column(service('memberDashboardService')->kpis($m['user_id']), null, 'key');
        $this->assertSame('ok', $kpis['my_today_mining']['status']);
        $this->assertSame('0.00000000', $kpis['my_today_mining']['value']);
        $this->assertStringContainsString('No credit posted', $kpis['my_today_mining']['note']);
        $this->assertSame('0.00000000', $kpis['my_total_mining']['value']);

        $h = service('memberMiningIncomeService')->history($m['user_id']);
        $this->assertTrue($h['available']);
        $this->assertSame([], $h['rows']);
        $this->assertSame(0, $h['total']);
    }

    public function testUnavailableLedgerReportsErrorNotZero(): void
    {
        $m = $this->registerMember('broken');
        $this->seedCredit($m, '2026-11-19', '0.10000000', 'MWL-BROKEN-1');
        $db = db_connect();
        $db->query('ALTER TABLE ' . $db->prefixTable('mining_wallet_ledger') . ' CHANGE amount amount_broken DECIMAL(24,8) NOT NULL');

        try {
            $s = service('memberMiningIncomeService')->summary($m['user_id']);
            $this->assertFalse($s['available']);
            $this->assertNotNull($s['error']);

            $h = service('memberMiningIncomeService')->history($m['user_id']);
            $this->assertFalse($h['available']);

            $t = service('memberMiningIncomeService')->trend($m['user_id']);
            $this->assertFalse($t['available']);

            $kpis = array_column(service('memberDashboardService')->kpis($m['user_id']), null, 'key');
            foreach (['my_today_mining', 'my_yesterday_mining', 'my_total_mining', 'my_mining_balance'] as $k) {
                $this->assertSame('error', $kpis[$k]['status'], $k);
                $this->assertNull($kpis[$k]['value'], $k);
            }
            // Other dashboard metrics still render.
            $this->assertSame('ok', $kpis['my_total_coins']['status']);

            $cards = MiningWalletPresenter::walletKpis($s, 'member');
            foreach ($cards as $c) {
                $this->assertSame('error', $c['status']);
            }
        } finally {
            $db->query('ALTER TABLE ' . $db->prefixTable('mining_wallet_ledger') . ' CHANGE amount_broken amount DECIMAL(24,8) NOT NULL');
        }
    }

    public function testHistoryPaginationShowsOnlyPostedCreditsNewestFirst(): void
    {
        $m     = $this->registerMember('pager');
        $start = new DateTimeImmutable('2026-10-01');
        for ($i = 0; $i < 45; $i++) {
            $this->seedCredit($m, $start->modify("+{$i} days")->format('Y-m-d'), '0.01000000', sprintf('MWL-PG-%03d', $i));
        }
        // A non-credit wallet entry is excluded from "earnings" but listed in "all".
        db_connect()->table('mining_wallet_ledger')->insert([
            'entry_ref' => 'MWL-P2P-OUT', 'member_user_id' => $m['user_id'], 'member_id' => $m['member_id'],
            'asset_code' => 'COIN', 'entry_type' => 'p2p_debit', 'direction' => 'debit', 'amount' => '0.00500000',
            'balance_after' => '0.44500000', 'business_date' => '2026-11-14', 'idempotency_key' => 'mmiv-p2p-out',
            'created_at' => '2026-11-14 10:00:00',
        ]);

        $svc = service('memberMiningIncomeService');
        $p1  = $svc->history($m['user_id'], 1);
        $this->assertSame(45, $p1['total']);
        $this->assertSame(3, $p1['pages']);
        $this->assertCount(20, $p1['rows']);
        $this->assertSame('MWL-PG-044', $p1['rows'][0]['entry_ref']);
        $this->assertSame('2026-11-14', $p1['rows'][0]['business_date']);
        $this->assertSame('0.01000000', $p1['rows'][0]['amount']);
        $this->assertSame('Posted', $p1['rows'][0]['status']);

        $p3 = $svc->history($m['user_id'], 3);
        $this->assertCount(5, $p3['rows']);
        $this->assertSame('MWL-PG-000', $p3['rows'][4]['entry_ref']);

        $clamped = $svc->history($m['user_id'], 99);
        $this->assertSame(3, $clamped['page']);

        $all = $svc->history($m['user_id'], 1, 'all');
        $this->assertSame(46, $all['total']);
        $this->assertContains('MWL-P2P-OUT', array_column($all['rows'], 'entry_ref'));
        $this->assertNotContains('MWL-P2P-OUT', array_column($p1['rows'], 'entry_ref'));

        $s = $svc->summary($m['user_id']);
        $this->assertSame('0.45000000', $s['total_earned'], 'Lifetime earned counts credits only');
        $this->assertSame(45, $s['credit_count']);

        $this->loginMember($m);
        $page = $this->withSession()->get('member/mining/history?page=2');
        $page->assertOK();
        $page->assertSee('MWL-PG-024');
        $page->assertDontSee('MWL-PG-044');
        $page->assertSee('Page 2 of 3');
        $page->assertSee('Business Date (IST)');
    }

    public function testMemberCannotSeeAnotherMembersMiningIncome(): void
    {
        $a = $this->registerMember('ownera');
        $b = $this->registerMember('ownerb');
        $this->seedCredit($a, '2026-11-19', '0.10000000', 'MWL-SECRET-A');
        $this->seedCredit($b, '2026-11-19', '0.20000000', 'MWL-OWN-B');

        $this->assertSame('0.00000000', service('memberMiningIncomeService')->summary(0)['total_earned']);
        $this->assertFalse(service('memberMiningIncomeService')->summary(0)['available']);

        $this->loginMember($b);
        foreach ([
            'member/mining?user_id=' . $a['user_id'] . '&member_id=' . $a['member_id'],
            'member/mining/history?user_id=' . $a['user_id'] . '&member_user_id=' . $a['user_id'] . '&scope=all',
            'member/dashboard?user_id=' . $a['user_id'],
        ] as $url) {
            $page = $this->withSession()->get($url);
            $page->assertOK();
            $page->assertDontSee('MWL-SECRET-A');
            $page->assertDontSee($a['member_id']);
        }
        $this->withSession()->get('member/mining/history')->assertSee('MWL-OWN-B');
    }

    public function testMemberPagesRenderActualMiningAndAreReadOnly(): void
    {
        $m = $this->registerMember('pages');
        $this->seedCredit($m, '2026-11-19', '0.10000000', 'MWL-PAGE-1119');
        $before = $this->ledgerCounts();

        $this->loginMember($m);
        $wallet = $this->withSession()->get('member/mining');
        $wallet->assertOK();
        $wallet->assertSee('Mining Wallet');
        $wallet->assertSee('Total Mining Earned');
        $wallet->assertSee('0.10000000');
        $wallet->assertSee('MWL-PAGE-1119');
        $wallet->assertSee('ESTIMATE ONLY');
        $wallet->assertDontSee('Actual Credits Not Activated');
        $wallet->assertSee('Mining History');

        $history = $this->withSession()->get('member/mining/history');
        $history->assertOK();
        $history->assertSee('MWL-PAGE-1119');
        $history->assertSee('2026-11-20 02:01:00');
        $history->assertDontSee('Legacy simulation');

        $dash = $this->withSession()->get('member/dashboard');
        $dash->assertOK();
        $dash->assertSee("Today's Mining (Actual)");
        $dash->assertSee('Actual Mining');
        $dash->assertSee('Posted Mining Credits');
        $dash->assertDontSee('Actual Mining: Not Activated');

        $this->assertSame($before, $this->ledgerCounts(), 'Viewing pages must never write mining data');
    }

    public function testEmptyHistoryShowsEmptyState(): void
    {
        $m = $this->registerMember('empty');
        $this->loginMember($m);
        $page = $this->withSession()->get('member/mining/history');
        $page->assertOK();
        $page->assertSee('No posted mining credits yet');
    }

    public function testUnavailableLedgerShowsErrorStateOnMemberPages(): void
    {
        $m  = $this->registerMember('pageerr');
        $db = db_connect();
        $db->query('ALTER TABLE ' . $db->prefixTable('mining_wallet_ledger') . ' CHANGE amount amount_broken DECIMAL(24,8) NOT NULL');

        try {
            $this->loginMember($m);
            $wallet = $this->withSession()->get('member/mining');
            $wallet->assertOK();
            $wallet->assertSee('Mining earnings are unavailable right now');

            $history = $this->withSession()->get('member/mining/history');
            $history->assertOK();
            $history->assertSee('Mining history is unavailable right now');
            $history->assertDontSee('No posted mining credits yet');

            $dash = $this->withSession()->get('member/dashboard');
            $dash->assertOK();
            $dash->assertSee('Mining earnings unavailable');
        } finally {
            $db->query('ALTER TABLE ' . $db->prefixTable('mining_wallet_ledger') . ' CHANGE amount_broken amount DECIMAL(24,8) NOT NULL');
        }
    }

    public function testNavigationExposesMiningWalletAndHistory(): void
    {
        $m = $this->registerMember('nav');
        $this->loginMember($m);
        $page = $this->withSession()->get('member/wallet');
        $page->assertOK();
        $page->assertSee(site_url('member/mining'));
        $page->assertSee(site_url('member/mining/history'));
        $page->assertSee('Mining Wallet');
        $page->assertDontSee('Mining Simulation');
    }

    public function testAdminSupportViewShowsSelectedMemberNotAdmin(): void
    {
        $target = $this->registerMember('svtarget');
        $this->seedCredit($target, '2026-11-19', '0.10000000', 'MWL-TARGET-1119');
        $adminProfile = model(MemberProfileModel::class)->findByUserId($this->adminUserId);
        $this->seedCredit(
            ['user_id' => $this->adminUserId, 'member_id' => (string) $adminProfile->member_id],
            '2026-11-19',
            '7.77777777',
            'MWL-ADMIN-OWN'
        );

        $page = MiningWalletPresenter::walletPage($target['user_id'], 'support-view');
        $this->assertSame('0.10000000', $page['summary']['total_earned']);
        $this->assertSame(site_url('support-view/mining/history'), $page['kpis'][0]['href']);

        $dash = service('memberDashboardService')->build($target['user_id'], 'support-view');
        $this->assertSame('0.10000000', $dash['mining_summary']['total_earned']);
        $this->assertStringStartsWith(site_url('support-view/'), (string) array_column($dash['kpis'], null, 'key')['my_today_mining']['href']);

        $before = $this->ledgerCounts();
        auth()->login(model(UserModel::class)->findById($this->adminUserId));
        $grant   = service('impersonationService')->createGrant($this->adminUserId, $target['member_id'], $this->adminPassword);
        $claimed = service('impersonationService')->claimGrant($grant['token'], $this->adminUserId);
        service('superglobals')->setCookie(ImpersonationService::COOKIE_NAME, $claimed['raw_token']);

        $wallet = $this->withSession()->get('support-view/mining');
        $wallet->assertStatus(200);
        $wallet->assertSee('MWL-TARGET-1119');
        $wallet->assertSee('0.10000000');
        $wallet->assertDontSee('MWL-ADMIN-OWN');
        $wallet->assertDontSee('7.77777777');
        $wallet->assertSee(site_url('support-view/mining/history'));

        $history = $this->withSession()->get('support-view/mining/history?scope=all');
        $history->assertStatus(200);
        $history->assertSee('MWL-TARGET-1119');
        $history->assertDontSee('MWL-ADMIN-OWN');

        $this->assertSame($before, $this->ledgerCounts());
        $this->assertGreaterThan(0, db_connect()->table('audit_logs')
            ->where('action', 'admin.impersonation_member_viewed')
            ->like('metadata', 'mining_history')
            ->countAllResults());
    }
}
