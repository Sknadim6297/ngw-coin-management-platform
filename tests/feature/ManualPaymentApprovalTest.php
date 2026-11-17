<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Platform;

/**
 * Focused tests: admin manual USDT payment approve/reject + purchase credit.
 *
 * @internal
 */
final class ManualPaymentApprovalTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;

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
        $platform->requireVerifiedDepositForApproval = true;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'manual pay test price');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'manual pay stock', null, 'man-stock-' . bin2hex(random_bytes(3)));

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

    private function register(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('man' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'man' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'Man ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'man' . $suffix . '@example.com',
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

    /**
     * @return array{network: array<string, mixed>, wallet: string, contract: string}
     */
    private function enableBep20(): array
    {
        $wallet   = '0x' . str_repeat('a', 40);
        $contract = '0x' . str_repeat('b', 40);
        service('paymentDetailsService')->save([
            'token_contract_address'   => $contract,
            'receiving_wallet_address' => $wallet,
            'recipient_name'           => 'Treasury',
            'min_deposit_usdt'         => '5',
            'instructions'             => 'Pay BEP20',
            'is_enabled'               => true,
            'remove_qr'                => false,
        ], $this->adminUserId);

        return [
            'network'  => service('paymentDetailsService')->ensureBep20(),
            'wallet'   => $wallet,
            'contract' => $contract,
        ];
    }

    /**
     * @return array{member: array<string, mixed>, purchase: array<string, mixed>, deposit: array<string, mixed>, setup: array<string, mixed>}
     */
    private function createPendingPayment(string $suffix, string $amount = '5.00000000', ?string $txHash = null): array
    {
        $setup  = $this->enableBep20();
        $member = $this->register($suffix);
        $created = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            $amount,
            null,
            'man-pur-' . $suffix . '-' . bin2hex(random_bytes(2))
        );
        $tx = $txHash ?? ('0x' . str_pad(bin2hex(random_bytes(32)), 64, '0'));
        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $setup['network']['id'],
            $amount,
            $tx,
            'man-dep-' . $suffix . '-' . bin2hex(random_bytes(2)),
            null
        );

        return [
            'member'   => $member,
            'purchase' => $created['request'],
            'deposit'  => $dep['deposit'],
            'setup'    => $setup,
            'tx'       => $tx,
        ];
    }

    private function approvePayload(array $setup, string $amount, array $overrides = []): array
    {
        return array_merge([
            'confirm_independent'      => true,
            'confirm_network'          => true,
            'confirm_success'          => true,
            'verified_amount_usdt'     => $amount,
            'confirmed_wallet'         => $setup['wallet'],
            'confirmed_token_contract' => $setup['contract'],
            'confirmed_confirmations'  => 20,
            'verification_evidence'    => 'https://bscscan.com/tx/example-checked',
        ], $overrides);
    }

    public function testManualApprovePaymentThenPurchaseCreditsOnce(): void
    {
        $ctx = $this->createPendingPayment('ok1');
        $result = service('usdtDepositService')->manuallyApprovePayment(
            $ctx['deposit']['deposit_ref'],
            $this->adminUserId,
            $this->approvePayload($ctx['setup'], '5.00000000', [
                'link_purchase_ref' => $ctx['purchase']['purchase_ref'],
            ])
        );

        $this->assertSame('verified', $result['deposit']['status']);
        $this->assertSame('MANUAL', $result['deposit']['verification_method']);
        $this->assertSame($ctx['purchase']['purchase_ref'], $result['linked_purchase_ref']);

        $approval = service('coinPurchaseService')->approveRequest(
            $ctx['purchase']['purchase_ref'],
            $this->adminUserId
        );
        $this->assertTrue($approval['activated']);
        $this->assertSame('active', model(MemberProfileModel::class)->findByUserId($ctx['member']['user_id'])->account_status);
        $this->assertSame('5.00000000', (string) ($approval['ownership']['available'] ?? ''));

        $dep = service('usdtDepositService')->findByRef($ctx['deposit']['deposit_ref']);
        $this->assertSame('consumed', $dep['payment_consumption_status']);
        $alloc = model(\App\Models\UsdtPaymentAllocationModel::class)
            ->where('purchase_ref', $ctx['purchase']['purchase_ref'])
            ->first();
        $this->assertSame('consumed', $alloc['status'] ?? null);

        $this->expectException(\InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($ctx['purchase']['purchase_ref'], $this->adminUserId);
    }

    public function testRejectPaymentDoesNotCredit(): void
    {
        $ctx = $this->createPendingPayment('rej1');
        service('usdtDepositService')->reject(
            $ctx['deposit']['deposit_ref'],
            $this->adminUserId,
            'Invalid TXN on explorer'
        );
        $dep = service('usdtDepositService')->findByRef($ctx['deposit']['deposit_ref']);
        $this->assertSame('rejected', $dep['status']);

        $this->expectException(\InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($ctx['purchase']['purchase_ref'], $this->adminUserId);
    }

    public function testDuplicateTxHashBlockedOnSubmit(): void
    {
        $ctx = $this->createPendingPayment('dup1');
        $this->expectException(\InvalidArgumentException::class);
        service('usdtDepositService')->submitRequest(
            $ctx['member']['user_id'],
            (int) $ctx['setup']['network']['id'],
            '5.00000000',
            $ctx['tx'],
            'man-dep-dup2-' . bin2hex(random_bytes(2)),
            null
        );
    }

    public function testIncorrectAmountRejected(): void
    {
        $ctx = $this->createPendingPayment('amt1');
        $this->expectException(\InvalidArgumentException::class);
        service('usdtDepositService')->manuallyApprovePayment(
            $ctx['deposit']['deposit_ref'],
            $this->adminUserId,
            $this->approvePayload($ctx['setup'], '5.00000000', [
                'verified_amount_usdt' => '6.00000000',
            ])
        );
    }

    public function testIncorrectWalletRejected(): void
    {
        $ctx = $this->createPendingPayment('wal1');
        $this->expectException(\InvalidArgumentException::class);
        service('usdtDepositService')->manuallyApprovePayment(
            $ctx['deposit']['deposit_ref'],
            $this->adminUserId,
            $this->approvePayload($ctx['setup'], '5.00000000', [
                'confirmed_wallet' => '0x' . str_repeat('c', 40),
            ])
        );
    }

    public function testKillSwitchesRemainOff(): void
    {
        /** @var Platform $p */
        $p = config('Platform');
        $this->assertFalse($p->commissionPostingEnabled);
        $this->assertFalse($p->commissionWithdrawalEnabled);
    }
}
