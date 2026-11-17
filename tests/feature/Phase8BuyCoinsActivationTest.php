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
 * Phase 8 focused tests: activation, buy-coins quote, payment settings dual-control.
 *
 * @internal
 */
final class Phase8BuyCoinsActivationTest extends CIUnitTestCase
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
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->makeUser('p8apr', 'finance_approver');

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'phase8 price');
        service('coinInventoryService')->addInventory('10000', $this->adminUserId, 'phase8 stock', null, 'p8-stock-' . bin2hex(random_bytes(3)));

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

    private function makeUser(string $suffix, string $group): int
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

    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('p8' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p8' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P8 ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'p8' . $suffix . '@example.com',
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

    public function testRegistrationRemainsInactive(): void
    {
        $member = $this->register('inact');
        $status = service('memberActivationService')->statusForMember($member['user_id']);
        $this->assertFalse($status['is_active']);
        $this->assertSame('NO_APPROVED_PURCHASE', $status['reason_code']);
        $this->assertSame('inactive', $member['profile']->account_status);
    }

    public function testBuyCoinsUsdtQuote(): void
    {
        $quote = service('coinPriceService')->quoteByUsdtAmount('10.00000000');
        $this->assertSame('10.00000000', $quote['usdt_amount']);
        $this->assertSame('1.00000000', $quote['coin_price_usdt']);
        $this->assertSame('10.00000000', $quote['coin_quantity']);
    }

    public function testMinimumPurchaseValidation(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        service('coinPriceService')->quoteByUsdtAmount('1.00000000');
    }

    public function testInsufficientInventoryBlocksRequest(): void
    {
        // Drain inventory close to empty by setting tiny stock via direct update after locking context.
        db_connect()->table('coin_inventory')->where('id', 1)->update(['available_balance' => '1.00000000']);
        $member = $this->register('invlow');
        $this->expectException(\InvalidArgumentException::class);
        service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '10.00000000',
            null,
            'p8-inv-' . bin2hex(random_bytes(3))
        );
    }

    public function testPaymentDestinationSelfApprovalBlocked(): void
    {
        $network = service('usdtPaymentNetworkService')->create([
            'network_key'           => 'p8evm' . bin2hex(random_bytes(2)),
            'network_name'          => 'Phase8 EVM',
            'verifier_type'         => 'evm_erc20',
            'chain_id'              => 1,
            'token_contract_address'=> '0x' . str_repeat('1', 40),
            'destination_wallet'    => '0x' . str_repeat('2', 40),
            'is_enabled'            => 0,
            'min_deposit_usdt'      => '5',
            'max_deposit_usdt'      => '100000',
        ], $this->adminUserId);

        $proposal = service('paymentDestinationChangeService')->propose([
            'network_id'            => (int) $network['id'],
            'to_destination_wallet' => '0x' . str_repeat('3', 40),
        ], $this->adminUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('paymentDestinationChangeService')->approve($proposal['proposal_ref'], $this->adminUserId);
    }

    public function testDestinationChangeApprovalDoesNotAlterDepositSnapshot(): void
    {
        $network = service('usdtPaymentNetworkService')->create([
            'network_key'           => 'p8snap' . bin2hex(random_bytes(2)),
            'network_name'          => 'Phase8 Snap',
            'verifier_type'         => 'none',
            'destination_wallet'    => '0x' . str_repeat('a', 40),
            'is_enabled'            => 1,
            'min_deposit_usdt'      => '5',
            'max_deposit_usdt'      => '100000',
        ], $this->adminUserId);

        $member = $this->register('snapa');
        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $network['id'],
            '10.00000000',
            '0x' . str_repeat('b', 64),
            'p8-dep-' . bin2hex(random_bytes(3)),
            null
        );
        $snapshot = $dep['deposit']['destination_wallet_snapshot'];

        $proposal = service('paymentDestinationChangeService')->propose([
            'network_id'            => (int) $network['id'],
            'to_destination_wallet' => '0x' . str_repeat('c', 40),
        ], $this->adminUserId);
        service('paymentDestinationChangeService')->approve($proposal['proposal_ref'], $this->approverUserId);

        $fresh = service('usdtDepositService')->findByRef($dep['deposit']['deposit_ref']);
        $this->assertSame($snapshot, $fresh['destination_wallet_snapshot']);
        $updatedNetwork = service('usdtPaymentNetworkService')->find((int) $network['id']);
        $this->assertSame('0x' . str_repeat('c', 40), $updatedNetwork['destination_wallet']);
    }

    public function testPurchaseApprovalActivatesAndCreditsOnce(): void
    {
        $member = $this->register('act1');
        $created = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '10.00000000',
            null,
            'p8-buy-' . bin2hex(random_bytes(3))
        );
        $ref = $created['request']['purchase_ref'];

        $beforeOwn = db_connect()->table('member_coin_ownership_transactions')->countAllResults();
        $result = service('coinPurchaseService')->approveRequest($ref, $this->adminUserId);
        $this->assertTrue($result['activated']);

        $profile = model(MemberProfileModel::class)->findByUserId($member['user_id']);
        $this->assertSame('active', $profile->account_status);
        $this->assertSame($ref, $profile->activation_purchase_ref);
        $this->assertNotEmpty($profile->activated_at);

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertSame('10.00000000', MoneyMath::normalize($summary['balance'], 8));

        // Duplicate approval rejected / no second credit
        try {
            service('coinPurchaseService')->approveRequest($ref, $this->adminUserId);
            $this->fail('Expected duplicate approval to fail');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $afterOwn = db_connect()->table('member_coin_ownership_transactions')->countAllResults();
        $this->assertSame($beforeOwn + 1, $afterOwn);

        // Already active stays active (idempotent activation)
        $status = service('memberActivationService')->statusForMember($member['user_id']);
        $this->assertSame('ACTIVATION_COMPLETE', $status['reason_code']);
    }

    public function testUnverifiedPaymentDoesNotActivate(): void
    {
        $member = $this->register('unv');
        service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '10.00000000',
            null,
            'p8-unv-' . bin2hex(random_bytes(3))
        );
        $status = service('memberActivationService')->statusForMember($member['user_id']);
        $this->assertFalse($status['is_active']);
        $this->assertNotSame('ACTIVATION_COMPLETE', $status['reason_code']);
    }

    public function testMemberCannotViewOtherPayment(): void
    {
        $a = $this->register('owna');
        $b = $this->register('ownb');
        $created = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $a['user_id'],
            '10.00000000',
            null,
            'p8-own-' . bin2hex(random_bytes(3))
        );
        $other = service('coinPurchaseService')->findOwnedByMember($b['user_id'], $created['request']['purchase_ref']);
        $this->assertNull($other);
    }

    public function testKillSwitchesRemainOff(): void
    {
        $this->assertFalse(config('Platform')->commissionPostingEnabled);
        $this->assertFalse(config('Platform')->commissionWithdrawalEnabled);
        $this->assertFalse(config('Platform')->commissionBlockchainPayoutEnabled);
    }

    public function testUnauthorizedMemberBlockedFromPaymentDetails(): void
    {
        $member = $this->register('nopay');
        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('admin/payment-details')->assertRedirect();
    }

    public function testPaymentDetailsSaveGoesLiveImmediately(): void
    {
        $svc = service('paymentDetailsService');
        $network = $svc->ensureBep20();
        $this->assertSame('bep20', $network['network_key']);

        $walletA = '0x' . str_repeat('d', 40);
        $walletB = '0x' . str_repeat('e', 40);
        $contract = '0x' . str_repeat('f', 40);

        $result = $svc->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $walletA,
            'recipient_name'           => 'NGW Treasury',
            'min_deposit_usdt'         => '5.00000000',
            'instructions'             => 'Send USDT on BEP20 only.',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);
        $this->assertNull($result['proposal']);

        $live = $svc->ensureBep20();
        $this->assertSame($walletA, $live['destination_wallet']);
        $this->assertSame('NGW Treasury', $live['recipient_name'] ?? null);
        $this->assertSame(1, (int) $live['is_enabled']);

        // Member payment depends on Admin enablement, not on-chain verifier config.
        config('Blockchain')->depositVerifier = 'not_configured';
        config('Blockchain')->evmRpcUrl = '';
        $this->assertNotNull($svc->liveForMembers());
        $this->assertSame('ok', $svc->paymentReadiness()['code']);

        // Subsequent wallet change requires dual-control; live wallet stays A.
        $result2 = $svc->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $walletB,
            'recipient_name'           => 'NGW Treasury Updated',
            'min_deposit_usdt'         => '10.00000000',
            'instructions'             => 'Updated instructions.',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);
        $this->assertNotNull($result2['proposal']);
        $fresh = $svc->ensureBep20();
        $this->assertSame($walletA, $fresh['destination_wallet']);
        $this->assertSame('NGW Treasury Updated', $fresh['recipient_name']);
        $this->assertSame('10.00000000', $fresh['min_deposit_usdt']);
        $this->assertNotNull($svc->pendingDestinationChange());
    }

    public function testMemberCannotUseDisabledBep20(): void
    {
        service('paymentDetailsService')->ensureBep20();
        $this->assertNull(service('paymentDetailsService')->liveForMembers());

        $member = $this->register('nobep');
        $this->post('login', [
            'member_id' => $member['member_id'],
            'password'  => 'Password123!',
        ])->assertRedirect();
        $this->withSession()->get('member/buy-coins')->assertOK();
    }

    public function testInventoryAndPriceBlockCodes(): void
    {
        db_connect()->table('coin_inventory')->where('id', 1)->update([
            'available_balance' => '0.00000000',
        ]);
        $ctx = service('coinPurchaseService')->purchasePageContext();
        $this->assertFalse($ctx['can_purchase']);
        $this->assertSame('inventory_unavailable', $ctx['block_code']);
        $this->assertStringContainsString('out of stock', strtolower((string) $ctx['block_reason']));

        db_connect()->table('coin_inventory')->where('id', 1)->update([
            'available_balance' => '100.00000000',
            'total_added'       => '100.00000000',
        ]);
        db_connect()->table('coin_settings')->where('id', 1)->update([
            'current_price_usdt' => null,
            'price_updated_at'   => null,
        ]);
        $ctx2 = service('coinPurchaseService')->purchasePageContext();
        $this->assertFalse($ctx2['can_purchase']);
        $this->assertSame('price_unavailable', $ctx2['block_code']);

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'restore for test');
        $ctx3 = service('coinPurchaseService')->purchasePageContext();
        $this->assertTrue($ctx3['can_purchase']);
        $this->assertSame('Purchase Available', $ctx3['status_label']);
        $quote = service('coinPriceService')->quoteByUsdtAmount('10.00000000');
        $this->assertSame('10.00000000', $quote['coin_quantity']);
    }
}
