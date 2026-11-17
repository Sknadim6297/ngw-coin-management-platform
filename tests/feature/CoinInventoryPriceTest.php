<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\MoneyMath;
use App\Models\MemberProfileModel;
use App\Services\CoinInventoryService;
use App\Services\CoinPriceService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * @internal
 */
final class CoinInventoryPriceTest extends CIUnitTestCase
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
        $platform->memberIdStartNumber = 1;

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

    public function testAdminCanAddInventory(): void
    {
        $result = service('coinInventoryService')->addInventory(
            '100.50000000',
            $this->adminUserId,
            'Batch A',
            'Initial stock',
            'idem-add-1'
        );

        $this->assertFalse($result['duplicate']);
        $this->assertSame('100.50000000', $result['inventory']['available_balance']);
        $this->assertSame('100.50000000', $result['inventory']['total_added']);
        $this->assertSame('0.00000000', $result['inventory']['total_allocated']);
        $this->assertSame('ADMIN_INVENTORY_ADD', $result['transaction']['type']);
    }

    public function testInvalidInventoryQuantityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        service('coinInventoryService')->addInventory('0', $this->adminUserId);
    }

    public function testInventoryBalanceUpdatesCorrectly(): void
    {
        $svc = service('coinInventoryService');
        $svc->addInventory('10', $this->adminUserId, null, null, 'bal-1');
        $svc->addInventory('2.25', $this->adminUserId, null, null, 'bal-2');

        $this->assertSame('12.25000000', $svc->availableBalance());
        $this->assertSame('12.25000000', $svc->summary()['total_added']);
    }

    public function testDuplicateSubmissionCannotDoubleCredit(): void
    {
        $svc = service('coinInventoryService');
        $first  = $svc->addInventory('50', $this->adminUserId, 'ref', null, 'dup-key-1');
        $second = $svc->addInventory('50', $this->adminUserId, 'ref', null, 'dup-key-1');

        $this->assertFalse($first['duplicate']);
        $this->assertTrue($second['duplicate']);
        $this->assertSame('50.00000000', $svc->availableBalance());
        $this->assertSame($first['transaction']['transaction_ref'], $second['transaction']['transaction_ref']);
    }

    public function testPriceCanBeConfiguredAndChangedRepeatedly(): void
    {
        $svc = service('coinPriceService');

        $first = $svc->updatePrice('0.50', $this->adminUserId, 'Initial listing');
        $this->assertSame('0.50000000', $first['settings']['current_price_usdt']);

        $second = $svc->updatePrice('0.75', $this->adminUserId, 'Market adjustment');
        $this->assertSame('0.75000000', $second['settings']['current_price_usdt']);

        $history = $svc->priceHistory();
        $this->assertCount(2, $history);
        $this->assertSame('0.75000000', $history[0]['new_price_usdt']);
        $this->assertSame('0.50000000', $history[0]['previous_price_usdt']);
        $this->assertSame('0.50000000', $history[1]['new_price_usdt']);
        $this->assertNull($history[1]['previous_price_usdt']);
    }

    public function testPriceHistoryRemainsUnchangedAfterLaterUpdate(): void
    {
        $svc = service('coinPriceService');
        $svc->updatePrice('0.50', $this->adminUserId, 'Initial');
        $firstId = (int) $svc->priceHistory()[0]['id'];
        $firstSnapshot = $svc->priceHistory()[0];

        $svc->updatePrice('0.90', $this->adminUserId, 'Later change');

        $stillFirst = model(\App\Models\CoinPriceHistoryModel::class)->find($firstId);
        $this->assertSame($firstSnapshot['new_price_usdt'], $stillFirst['new_price_usdt']);
        $this->assertSame($firstSnapshot['reason'], $stillFirst['reason']);
        $this->assertSame($firstSnapshot['effective_at'], $stillFirst['effective_at']);
    }

    public function testInvalidPriceIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        service('coinPriceService')->updatePrice('-1', $this->adminUserId, 'Bad');
    }

    public function testHistoricalPriceSnapshotCalculationsRemainStable(): void
    {
        $svc = service('coinPriceService');
        $svc->updatePrice('0.50', $this->adminUserId, 'Launch');

        $quote = $svc->previewPurchase('5');
        $this->assertSame('0.50000000', $quote['coin_price_usdt']);
        $this->assertSame('10.00000000', $quote['coin_quantity']);

        // Admin changes live price later.
        $svc->updatePrice('0.75', $this->adminUserId, 'Increase');

        // Snapshot math still uses frozen purchase price.
        $frozenQty = $svc->quantityFromSnapshot('5', '0.50');
        $this->assertSame('10.00000000', $frozenQty);
        $this->assertSame('0.75000000', $svc->getCurrentPrice());
    }

    public function testInsufficientInventoryIsDetected(): void
    {
        service('coinInventoryService')->addInventory('1', $this->adminUserId, null, null, 'low-stock');

        $this->assertFalse(service('coinInventoryService')->hasSufficientInventory('2'));
        $this->assertTrue(service('coinInventoryService')->hasSufficientInventory('1'));

        $this->expectException(InvalidArgumentException::class);
        service('coinInventoryService')->assertSufficient('2');
    }

    public function testCorrectionCannotCreateNegativeBalance(): void
    {
        service('coinInventoryService')->addInventory('5', $this->adminUserId, null, null, 'corr-base');

        $this->expectException(InvalidArgumentException::class);
        service('coinInventoryService')->correctInventory(
            '6',
            'debit',
            $this->adminUserId,
            'Too large debit'
        );
    }

    public function testConcurrentStyleUpdatesDoNotGoNegative(): void
    {
        $svc = service('coinInventoryService');
        $svc->addInventory('10', $this->adminUserId, null, null, 'conc-base');

        // Sequential locked corrections simulating contention.
        $svc->correctInventory('4', 'debit', $this->adminUserId, 'First debit', null, 'conc-1');
        $svc->correctInventory('4', 'debit', $this->adminUserId, 'Second debit', null, 'conc-2');

        $this->assertSame('2.00000000', $svc->availableBalance());
        $this->assertFalse(MoneyMath::isNegative($svc->availableBalance()));

        $this->expectException(InvalidArgumentException::class);
        $svc->correctInventory('3', 'debit', $this->adminUserId, 'Would go negative', null, 'conc-3');
    }

    public function testMemberCannotAccessAdminCoinRoutes(): void
    {
        service('registrationService')->register([
            'member_name' => 'Coin Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1212121212',
            'email'       => 'coinmember@example.com',
            'usdt_wallet' => '0x3333333333333333333333333333333333333333',
            'password'    => 'Password123!',
        ]);

        service('memberAuthService')->attempt('CPM000001', 'Password123!');

        $result = $this->get('admin/coins');
        $result->assertRedirect();
    }

    public function testMemberDashboardShowsConfiguredPrice(): void
    {
        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Visible price');
        service('coinInventoryService')->addInventory('25', $this->adminUserId, null, null, 'dash-stock');

        service('registrationService')->register([
            'member_name' => 'Dash Member',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1313131313',
            'email'       => 'dashmember@example.com',
            'usdt_wallet' => '0x4444444444444444444444444444444444444444',
            'password'    => 'Password123!',
        ]);

        service('memberAuthService')->attempt('CPM000001', 'Password123!');

        $result = $this->get('member/dashboard');
        $result->assertStatus(200);
        $result->assertSee('0.50000000');
        $result->assertSee('5.00000000');
        $result->assertSee('Buy Coins');
        $result->assertDontSee('Coming in Step 3');
    }

    public function testAdminCoinOverviewPageLoads(): void
    {
        $login = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $login->assertRedirect();

        $result = $this->withSession()->get('admin/coins');
        $result->assertOK();
        $result->assertSee('Coin Management');
        $result->assertSee('Total Coins Added');
    }

    public function testNoPurchaseApprovalOrActivationInStep2(): void
    {
        service('registrationService')->register([
            'member_name' => 'Still Inactive',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1414141414',
            'email'       => 'stillinactive@example.com',
            'usdt_wallet' => '0x5555555555555555555555555555555555555555',
            'password'    => 'Password123!',
        ]);

        service('coinInventoryService')->addInventory('100', $this->adminUserId, null, null, 'no-activate');
        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Price only');

        $profile = model(MemberProfileModel::class)->findByMemberId('CPM000001');
        $this->assertSame('inactive', $profile->account_status);
        $this->assertSame('0.00000000', service('coinInventoryService')->summary()['total_allocated']);
    }

    public function testPreviewRejectsBelowMinimumPurchase(): void
    {
        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Min check');

        $this->expectException(InvalidArgumentException::class);
        service('coinPriceService')->previewPurchase('4.99');
    }
}
