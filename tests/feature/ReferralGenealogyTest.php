<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Phase 7A referral & genealogy.
 *
 * @internal
 */
final class ReferralGenealogyTest extends CIUnitTestCase
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

        $platform                = config('Platform');
        $platform->adminEmail    = 'admin@test.local';
        $platform->adminPassword = 'AdminPass123!';
        $platform->adminName     = 'Test Admin';
        $platform->rootSponsorId = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

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

    private function registerUnder(string $sponsorId, string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('r' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Ref ' . $suffix,
            'sponsor_id'  => $sponsorId,
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'ref' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
            'user_id'   => (int) $result['user']->id,
        ];
    }

    private function activate(array $member): void
    {
        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'ref price');
        service('coinInventoryService')->addInventory(
            '1000',
            $this->adminUserId,
            'ref stock',
            null,
            'ref-stock-' . bin2hex(random_bytes(3))
        );
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '20',
            null,
            'ref-buy-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest($req['request']['purchase_ref'], $this->adminUserId, true, 'activate');
    }

    public function testValidSponsorRegistration(): void
    {
        $a = $this->registerUnder('ROOT0001', 'valid1');
        $this->assertNotSame('', $a['member_id']);
        $rel = model(\App\Models\SponsorRelationshipModel::class)->findByMemberUserId($a['user_id']);
        $this->assertSame('ROOT0001', $rel['sponsor_member_id']);
    }

    public function testInvalidSponsorRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registerUnder('NOBODY999', 'badsponsor');
    }

    public function testSelfSponsorshipBlockedByValidator(): void
    {
        $result = service('sponsorValidator')->validate('ROOT0001', 'ROOT0001');
        $this->assertFalse($result['valid']);
        $this->assertSame('self', $result['reason']);
    }

    public function testCircularSponsorRelationshipDetection(): void
    {
        $a = $this->registerUnder('ROOT0001', 'cyclea');
        $this->activate($a);
        $b = $this->registerUnder($a['member_id'], 'cycleb');
        // Corrupt edge: make A sponsored by B (creates A↔B cycle).
        db_connect()->table('sponsor_relationships')
            ->where('member_user_id', $a['user_id'])
            ->update([
                'sponsor_user_id'   => $b['user_id'],
                'sponsor_member_id' => $b['member_id'],
            ]);

        $this->assertTrue(service('referralTeamService')->wouldCreateCycle($a['user_id'], $b['user_id']));
        $report = service('genealogyIntegrityService')->scan(50);
        $codes  = array_column($report['findings'], 'code');
        $this->assertContains('circular_sponsorship', $codes);
        $this->assertFalse($report['auto_repair']);
    }

    public function testDirectAndActiveInactiveCounts(): void
    {
        $sponsor = $this->registerUnder('ROOT0001', 'cnts');
        $this->activate($sponsor);
        $d1 = $this->registerUnder($sponsor['member_id'], 'd1');
        $d2 = $this->registerUnder($sponsor['member_id'], 'd2');
        $this->activate($d1);

        $counts = service('referralTeamService')->countsForSponsor($sponsor['user_id'], 3);
        $this->assertSame(2, $counts['direct']);
        $this->assertSame(2, $counts['total']);
        $this->assertSame(1, $counts['active']);
        $this->assertSame(1, $counts['inactive']);

        $list = service('referralTeamService')->directReferrals($sponsor['user_id'], ['status' => 'active']);
        $this->assertCount(1, $list['rows']);
        $this->assertSame($d1['member_id'], $list['rows'][0]['member_id']);
    }

    public function testLevelTraversalAndNoDuplicateCounting(): void
    {
        $s = $this->registerUnder('ROOT0001', 'lvls');
        $this->activate($s);
        $l1 = $this->registerUnder($s['member_id'], 'l1a');
        $this->activate($l1);
        $l2 = $this->registerUnder($l1['member_id'], 'l2a');
        $this->activate($l2);
        $l3 = $this->registerUnder($l2['member_id'], 'l3a');

        $counts = service('referralTeamService')->countsForSponsor($s['user_id'], 3);
        $this->assertSame(1, $counts['levels'][1]['total']);
        $this->assertSame(1, $counts['levels'][2]['total']);
        $this->assertSame(1, $counts['levels'][3]['total']);
        $this->assertSame(3, $counts['total']);

        $ids = service('referralTeamService')->collectDownlineUserIds($s['user_id']);
        $this->assertCount(3, $ids);
        $this->assertCount(3, array_unique($ids));
    }

    public function testMemberNetworkAccessAuthorization(): void
    {
        $a = $this->registerUnder('ROOT0001', 'autha');
        $b = $this->registerUnder('ROOT0001', 'authb');
        $this->activate($a);
        $child = $this->registerUnder($a['member_id'], 'authc');

        $this->post('login', [
            'member_id' => $b['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();

        $page = $this->withSession()->get('member/referrals');
        $page->assertOK();
        $page->assertDontSee($child['member_id']);

        $blocked = $this->withSession()->get('member/genealogy/children?parent_user_id=' . $a['user_id']);
        $this->assertSame(403, $blocked->response()->getStatusCode());
    }

    public function testAdminGenealogyAuthorization(): void
    {
        $member = $this->registerUnder('ROOT0001', 'admg');
        $guest  = $this->get('admin/referrals');
        $guest->assertRedirect();

        $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ])->assertRedirect();

        $page = $this->withSession()->get('admin/referrals?q=' . $member['member_id']);
        $page->assertOK();
        $page->assertSee($member['member_id']);

        $tree = $this->withSession()->get('admin/genealogy?member_id=' . $member['member_id']);
        $tree->assertOK();
        $tree->assertSee('Network Integrity Report');
        $tree->assertSee('Disabled');
    }

    public function testReferralLinkPrefillValidation(): void
    {
        $sponsor = $this->registerUnder('ROOT0001', 'prefill');
        $this->activate($sponsor);

        $page = $this->get('register?sponsor=' . $sponsor['member_id']);
        $page->assertOK();
        $page->assertSee($sponsor['member_id']);
    }

    public function testSponsorRelationshipPreservation(): void
    {
        $a = $this->registerUnder('ROOT0001', 'pres1');
        $before = model(\App\Models\SponsorRelationshipModel::class)->findByMemberUserId($a['user_id']);
        service('genealogyIntegrityService')->scan(20);
        $after = model(\App\Models\SponsorRelationshipModel::class)->findByMemberUserId($a['user_id']);
        $this->assertSame($before['sponsor_member_id'], $after['sponsor_member_id']);
        $this->assertSame($before['sponsor_user_id'], $after['sponsor_user_id']);
    }

    public function testOrphanSponsorDetection(): void
    {
        $m = $this->registerUnder('ROOT0001', 'orphan');
        db_connect()->table('sponsor_relationships')->where('member_user_id', $m['user_id'])->delete();
        $report = service('genealogyIntegrityService')->scan(50);
        $codes  = array_column($report['findings'], 'code');
        $this->assertContains('orphan_member', $codes);
    }

    public function testBoundedGenealogyTraversal(): void
    {
        $s = $this->registerUnder('ROOT0001', 'bound');
        $this->activate($s);
        $prev = $s;
        for ($i = 0; $i < 4; $i++) {
            $prev = $this->registerUnder($prev['member_id'], 'bd' . $i);
            $this->activate($prev);
        }

        $tree = service('referralTeamService')->subtree($s['user_id'], 2, 500);
        $maxLevel = 0;
        foreach ($tree['nodes'] as $node) {
            $maxLevel = max($maxLevel, (int) $node['level']);
        }
        $this->assertLessThanOrEqual(2, $maxLevel);
    }

    public function testDashboardKpiAccuracy(): void
    {
        $s = $this->registerUnder('ROOT0001', 'dashkpi');
        $this->activate($s);
        $d = $this->registerUnder($s['member_id'], 'dashd');
        $this->activate($d);
        $this->registerUnder($d['member_id'], 'dashl2');

        $kpis = service('memberDashboardService')->kpis($s['user_id']);
        $byKey = [];
        foreach ($kpis as $kpi) {
            $byKey[$kpi['key']] = $kpi;
        }
        $this->assertSame(1, $byKey['my_direct_referrals']['value']);
        $this->assertSame(2, $byKey['my_total_team']['value']);
        $this->assertSame(1, $byKey['my_level1_members']['value']);
        $this->assertSame(1, $byKey['my_level2_members']['value']);
        $this->assertSame(0, $byKey['my_level3_members']['value']);
    }

    public function testNoFinancialLedgerMutation(): void
    {
        $m = $this->registerUnder('ROOT0001', 'nomut');
        $this->activate($m);
        $before = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $m['user_id'])
            ->countAllResults();
        service('referralTeamService')->countsForSponsor($m['user_id'], 3);
        service('referralTeamService')->subtree($m['user_id'], 3, 50);
        service('genealogyIntegrityService')->scan(20);
        $after = db_connect()->table('member_coin_ownership_transactions')
            ->where('member_user_id', $m['user_id'])
            ->countAllResults();
        $this->assertSame($before, $after);
        $this->assertFalse(method_exists(service('genealogyIntegrityService'), 'repair'));
        $this->assertFalse(method_exists(service('referralTeamService'), 'reassignSponsor'));
    }
}
