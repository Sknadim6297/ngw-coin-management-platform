<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\CoinInventoryTransactionModel;
use App\Models\CoinPurchaseRequestModel;
use App\Models\MemberCoinOwnershipTransactionModel;
use App\Models\MemberProfileModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * @internal
 */
final class CoinPurchaseTest extends CIUnitTestCase
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
        // Phase 4A payment gate: Phase 3 suite exercises legacy unpaid approval path.
        $platform->requireVerifiedDepositForApproval = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Test listing');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'Stock', null, 'stock-base');

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

    private function registerMember(string $emailSuffix = 'a'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($emailSuffix) . (string) abs(crc32('x' . $emailSuffix)));
        $hex    = str_pad(substr(hash('sha256', $emailSuffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Buyer ' . $emailSuffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '15' . substr($digits . '00000000', 0, 8),
            'email'       => 'buyer' . $emailSuffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user'      => $result['user'],
            'profile'   => $profile,
            'member_id' => $profile->member_id,
        ];
    }

    public function testAuthenticatedMemberCreatesPurchaseRequest(): void
    {
        $member = $this->registerMember('create');
        service('memberAuthService')->attempt($member['member_id'], 'Password123!');

        $result = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '20',
            'Please review',
            'idem-create-1'
        );

        $this->assertFalse($result['duplicate']);
        $this->assertSame('pending', $result['request']['status']);
        $this->assertSame('20.00000000', $result['request']['coin_quantity']);
        $this->assertSame('0.50000000', $result['request']['price_usdt_snapshot']);
        $this->assertSame('10.00000000', $result['request']['total_usdt']);
        $this->assertSame('1000.00000000', service('coinInventoryService')->availableBalance());
    }

    public function testGuestCannotCreatePurchaseRequestViaHttp(): void
    {
        $result = $this->post('member/purchases', [
            'quantity'        => '10',
            'confirm'         => '1',
            'idempotency_key' => 'guest-key',
        ]);
        $result->assertRedirect();
    }

    public function testInvalidQuantityIsRejected(): void
    {
        $member = $this->registerMember('badqty');
        $this->expectException(InvalidArgumentException::class);
        service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '0',
            null,
            'idem-bad-qty'
        );
    }

    public function testClientSuppliedPriceCannotOverrideServerPricing(): void
    {
        $member = $this->registerMember('pricehack');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-price-1'
        );

        // Even if a client tried to post a different price, service ignores it.
        $this->assertSame('0.50000000', $created['request']['price_usdt_snapshot']);
        $this->assertSame('5.00000000', $created['request']['total_usdt']);

        service('coinPriceService')->updatePrice('0.90', $this->adminUserId, 'Later bump');
        $still = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('0.50000000', $still['price_usdt_snapshot']);
        $this->assertSame('5.00000000', $still['total_usdt']);
    }

    public function testPurchaseStoresImmutablePriceSnapshot(): void
    {
        $member = $this->registerMember('snap');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '12',
            null,
            'idem-snap-1'
        );

        service('coinPriceService')->updatePrice('2.00', $this->adminUserId, 'After request');
        $row = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('0.50000000', $row['price_usdt_snapshot']);
        $this->assertSame('6.00000000', $row['total_usdt']);
    }

    public function testMemberCannotViewAnotherMembersPurchase(): void
    {
        $a = $this->registerMember('ownera');
        $b = $this->registerMember('ownerb');

        $created = service('coinPurchaseService')->createRequest(
            (int) $a['user']->id,
            '10',
            null,
            'idem-idor-1'
        );

        service('memberAuthService')->attempt($b['member_id'], 'Password123!');
        $result = $this->get('member/purchases/' . $created['request']['purchase_ref']);
        $result->assertRedirect();
    }

    public function testOrdinaryMemberCannotApprovePurchases(): void
    {
        $member = $this->registerMember('noadmin');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-noadmin-1'
        );

        service('memberAuthService')->attempt($member['member_id'], 'Password123!');
        $result = $this->post('admin/purchases/' . $created['request']['purchase_ref'] . '/approve', [
            'confirm' => '1',
        ]);
        $result->assertRedirect();

        $row = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('pending', $row['status']);
        $this->assertSame('0.00000000', service('coinInventoryService')->summary()['total_allocated']);
    }

    public function testAdminCanApprovePendingRequest(): void
    {
        $member = $this->registerMember('approve');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-approve-1'
        );

        $result = service('coinPurchaseService')->approveRequest(
            $created['request']['purchase_ref'],
            $this->adminUserId
        );

        $this->assertSame('approved', $result['request']['status']);
        $this->assertTrue($result['activated']);
        $this->assertSame('990.00000000', $result['inventory']['available_balance']);
        $this->assertSame('10.00000000', $result['inventory']['total_allocated']);
        $this->assertSame('10.00000000', $result['ownership']['balance']);

        $profile = model(MemberProfileModel::class)->findByUserId((int) $member['user']->id);
        $this->assertSame('active', $profile->account_status);
    }

    public function testApprovalDeductsInventoryExactlyOnce(): void
    {
        $member = $this->registerMember('once');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '15',
            null,
            'idem-once-1'
        );

        service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);

        $ledger = model(CoinInventoryTransactionModel::class)
            ->where('type', 'MEMBER_PURCHASE_ALLOCATION')
            ->where('reference', $created['request']['purchase_ref'])
            ->findAll();

        $this->assertCount(1, $ledger);
        $this->assertSame('15.00000000', $ledger[0]['quantity']);
        $this->assertSame('985.00000000', service('coinInventoryService')->availableBalance());
    }

    public function testApprovalCreatesOwnershipRecord(): void
    {
        $member = $this->registerMember('own');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-own-1'
        );

        service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);

        $own = model(MemberCoinOwnershipTransactionModel::class)
            ->findByPurchaseRef($created['request']['purchase_ref']);

        $this->assertNotNull($own);
        $this->assertSame('PURCHASE_CREDIT', $own['type']);
        $this->assertSame('10.00000000', $own['quantity']);
        $this->assertSame($created['request']['purchase_ref'], $own['purchase_ref']);
        $this->assertNotEmpty($own['inventory_transaction_ref']);
    }

    public function testDoubleApprovalIsRejected(): void
    {
        $member = $this->registerMember('double');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-double-1'
        );

        service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);

        $this->expectException(InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);
    }

    public function testConcurrentStyleApprovalsCannotOversellInventory(): void
    {
        // Only 25 coins left after reducing stock via correction-style scarcity.
        service('coinInventoryService')->correctInventory(
            '975',
            'debit',
            $this->adminUserId,
            'Reduce for scarcity test',
            null,
            'scarce-1'
        );
        $this->assertSame('25.00000000', service('coinInventoryService')->availableBalance());

        $a = $this->registerMember('oversella');
        $b = $this->registerMember('oversellb');

        $reqA = service('coinPurchaseService')->createRequest((int) $a['user']->id, '20', null, 'idem-ov-a');
        $reqB = service('coinPurchaseService')->createRequest((int) $b['user']->id, '20', null, 'idem-ov-b');

        service('coinPurchaseService')->approveRequest($reqA['request']['purchase_ref'], $this->adminUserId);
        $this->assertSame('5.00000000', service('coinInventoryService')->availableBalance());

        try {
            service('coinPurchaseService')->approveRequest($reqB['request']['purchase_ref'], $this->adminUserId);
            $this->fail('Second approval should fail due to insufficient inventory.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $stillPending = model(CoinPurchaseRequestModel::class)->findByRef($reqB['request']['purchase_ref']);
        $this->assertSame('pending', $stillPending['status']);
        $this->assertSame('5.00000000', service('coinInventoryService')->availableBalance());
        $this->assertFalse(MoneyMath::isNegative(service('coinInventoryService')->availableBalance()));
    }

    public function testInsufficientInventoryPreventsApproval(): void
    {
        service('coinInventoryService')->correctInventory(
            '1000',
            'debit',
            $this->adminUserId,
            'Empty stock',
            null,
            'empty-1'
        );

        $member = $this->registerMember('nostock');
        // Request creation also checks inventory — seed tiny stock then deplete after request.
        service('coinInventoryService')->addInventory('10', $this->adminUserId, null, null, 'temp-stock');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-nostock-1'
        );
        service('coinInventoryService')->correctInventory(
            '10',
            'debit',
            $this->adminUserId,
            'Drain after request',
            null,
            'drain-1'
        );

        $this->expectException(InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);
    }

    public function testRejectionDoesNotDeductInventory(): void
    {
        $member = $this->registerMember('reject');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-reject-1'
        );

        $before = service('coinInventoryService')->summary();
        service('coinPurchaseService')->rejectRequest(
            $created['request']['purchase_ref'],
            $this->adminUserId,
            'Incomplete internal review'
        );
        $after = service('coinInventoryService')->summary();

        $this->assertSame($before['available_balance'], $after['available_balance']);
        $this->assertSame($before['total_allocated'], $after['total_allocated']);
        $row = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('rejected', $row['status']);
    }

    public function testCancellationDoesNotDeductInventory(): void
    {
        $member = $this->registerMember('cancel');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-cancel-1'
        );

        $before = service('coinInventoryService')->availableBalance();
        service('coinPurchaseService')->cancelRequest((int) $member['user']->id, $created['request']['purchase_ref']);
        $this->assertSame($before, service('coinInventoryService')->availableBalance());

        $row = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('cancelled', $row['status']);
    }

    public function testDatabaseFailureRollsBackApprovalChanges(): void
    {
        $member = $this->registerMember('rollback');
        $created = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'idem-rb-1'
        );

        // Force failure by approving with a quantity larger than inventory via direct DB tamper
        // of the request after creation, then attempting approval.
        db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $created['request']['purchase_ref'])
            ->update(['coin_quantity' => '5000.00000000']);

        $beforeInv = service('coinInventoryService')->summary();
        $beforeOwn = service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id);

        try {
            service('coinPurchaseService')->approveRequest($created['request']['purchase_ref'], $this->adminUserId);
            $this->fail('Approval should fail and roll back.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Insufficient', $e->getMessage());
        }

        $row = model(CoinPurchaseRequestModel::class)->findByRef($created['request']['purchase_ref']);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['inventory_transaction_ref']);
        $this->assertSame($beforeInv['available_balance'], service('coinInventoryService')->availableBalance());
        $this->assertSame($beforeOwn['balance'], service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id)['balance']);
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByUserId((int) $member['user']->id)->account_status);
    }

    public function testPurchaseHistoryFiltersAndPagination(): void
    {
        $member = $this->registerMember('hist');
        service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, 'idem-hist-1');
        $r2 = service('coinPurchaseService')->createRequest((int) $member['user']->id, '12', null, 'idem-hist-2');
        service('coinPurchaseService')->rejectRequest($r2['request']['purchase_ref'], $this->adminUserId, 'Not accepted');

        service('memberAuthService')->attempt($member['member_id'], 'Password123!');

        $pendingReq = model(CoinPurchaseRequestModel::class)
            ->where('idempotency_key', 'idem-hist-1')
            ->first();

        $all = $this->get('member/purchases');
        $all->assertOK();
        $all->assertSee($pendingReq['purchase_ref']);
        $all->assertSee($r2['request']['purchase_ref']);

        $pending = $this->get('member/purchases?status=pending');
        $pending->assertOK();
        $pending->assertSee($pendingReq['purchase_ref']);
        $pending->assertDontSee($r2['request']['purchase_ref']);

        $rejected = $this->get('member/purchases?status=rejected');
        $rejected->assertOK();
        $rejected->assertSee($r2['request']['purchase_ref']);
        $rejected->assertDontSee($pendingReq['purchase_ref']);
    }

    public function testDuplicateSubmissionDoesNotCreateSecondRequest(): void
    {
        $member = $this->registerMember('dup');
        $first  = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, 'idem-dup-same');
        $second = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, 'idem-dup-same');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame($first['request']['purchase_ref'], $second['request']['purchase_ref']);

        $count = model(CoinPurchaseRequestModel::class)
            ->where('member_user_id', (int) $member['user']->id)
            ->countAllResults();
        $this->assertSame(1, $count);
    }

    public function testHttpMemberPurchaseAndAdminApprovalFlow(): void
    {
        $member = $this->registerMember('http');
        service('memberAuthService')->attempt($member['member_id'], 'Password123!');

        $buyPage = $this->get('member/purchases/buy');
        $buyPage->assertOK();
        $buyPage->assertSee('Submit Purchase Request');

        $submit = $this->post('member/purchases', [
            'quantity'        => '10',
            'member_note'     => 'HTTP flow',
            'confirm'         => '1',
            'idempotency_key' => 'http-buy-1',
        ]);
        $submit->assertRedirect();

        $req = model(CoinPurchaseRequestModel::class)->where('idempotency_key', 'http-buy-1')->first();
        $this->assertNotNull($req);

        if (auth()->loggedIn()) {
            auth()->logout();
        }

        $login = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $login->assertRedirect();

        $approve = $this->withSession()->post('admin/purchases/' . $req['purchase_ref'] . '/approve', [
            'confirm' => '1',
        ]);
        $approve->assertRedirect();

        $updated = model(CoinPurchaseRequestModel::class)->findByRef($req['purchase_ref']);
        $this->assertSame('approved', $updated['status']);
        $this->assertSame('990.00000000', service('coinInventoryService')->availableBalance());
    }
}
