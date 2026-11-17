<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\Blockchain\EvmUsdtVerificationProvider;
use App\Libraries\Blockchain\JsonRpcClient;
use App\Models\FinancialAlertModel;
use App\Models\MemberProfileModel;
use App\Models\UsdtPaymentAllocationModel;
use App\Services\FinancialAlertService;
use App\Services\FinancialRemediationService;
use App\Services\PaymentIntegrityMonitorService;
use App\Services\UsdtPaymentAllocationService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 4D allocation lifecycle, monitor, alerts, dual-control tests.
 *
 * @internal
 */
final class PaymentAllocationLifecycleTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private array $network;
    private string $dest = '0x1111111111111111111111111111111111111111';
    private string $token = '0x55d398326f99059ff775485246999027b3197955';

    /** @var array<string, mixed> */
    private array $rpcMap = [];

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

        $chain = config('Blockchain');
        $chain->depositVerifier = 'evm_erc20';
        $chain->evmRpcUrl = 'https://rpc.example-trusted.test';
        $chain->rpcHostAllowlist = 'rpc.example-trusted.test';

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, '4D');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'Stock', null, '4d-stock');

        $this->network = service('usdtPaymentNetworkService')->create([
            'network_key'            => 'bsc_usdt',
            'network_name'           => 'BNB Smart Chain USDT',
            'chain_id'               => 56,
            'verifier_type'          => 'evm_erc20',
            'token_symbol'           => 'USDT',
            'token_contract_address' => $this->token,
            'token_decimals'         => 18,
            'destination_wallet'     => $this->dest,
            'required_confirmations' => 3,
            'min_deposit_usdt'       => '5',
            'max_deposit_usdt'       => '10000',
            'is_enabled'             => 1,
        ], $this->adminUserId);

        $client = new JsonRpcClient(config('Blockchain'), function (string $url, array $payload) {
            $method = $payload['method'];
            if (! array_key_exists($method, $this->rpcMap)) {
                throw new RuntimeException('Unexpected RPC method: ' . $method);
            }
            $handler = $this->rpcMap[$method];
            if (is_callable($handler)) {
                return json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => $payload['id'],
                    'result'  => $handler($payload['params'] ?? []),
                ], JSON_THROW_ON_ERROR);
            }

            return json_encode([
                'jsonrpc' => '2.0',
                'id'      => $payload['id'],
                'result'  => $handler,
            ], JSON_THROW_ON_ERROR);
        });

        Services::injectMock('jsonRpcClient', $client);
        Services::injectMock('depositVerificationProvider', new EvmUsdtVerificationProvider($client, config('Blockchain')));
        Services::injectMock('usdtDepositService', new \App\Services\UsdtDepositService(
            verifier: service('depositVerificationProvider')
        ));
    }

    protected function tearDown(): void
    {
        $chain = config('Blockchain');
        $chain->depositVerifier = 'not_configured';
        $chain->evmRpcUrl = '';
        Services::resetSingle('jsonRpcClient');
        Services::resetSingle('depositVerificationProvider');
        Services::resetSingle('usdtDepositService');
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        parent::tearDown();
    }

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32('4d' . $suffix) . '9');
        $hex = str_pad(substr(hash('sha256', '4d' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'FourD ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '19' . substr($digits . '00000000', 0, 8),
            'email'       => 'fourd' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return [
            'user'    => $result['user'],
            'profile' => model(MemberProfileModel::class)->findByUserId((int) $result['user']->id),
        ];
    }

    private function forceVerifiedDeposit(int $memberUserId, string $amount, string $suffix): array
    {
        $created = service('usdtDepositService')->submitRequest(
            $memberUserId,
            (int) $this->network['id'],
            $amount,
            '0x' . substr(hash('sha256', $suffix), 0, 64),
            '4d-dep-' . $suffix
        );
        $ref = $created['deposit']['deposit_ref'];
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $ref)->update([
            'status'                     => 'verified',
            'verified_amount_usdt'       => \App\Libraries\MoneyMath::normalize($amount, 8),
            'verified_at'                => date('Y-m-d H:i:s'),
            'payment_consumption_status' => 'available',
            'integrity_flag'             => 'ok',
        ]);

        return service('usdtDepositService')->findByRef($ref);
    }

    public function testCancelReleasesAllocationAndKeepsDepositVerified(): void
    {
        $member = $this->registerMember('cancel');
        $dep = $this->forceVerifiedDeposit((int) $member['user']->id, '5', 'cancel');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4d-c-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit_ref'],
            $purchase['request']['purchase_ref']
        );

        service('coinPurchaseService')->cancelRequest((int) $member['user']->id, $purchase['request']['purchase_ref']);

        $alloc = model(UsdtPaymentAllocationModel::class)->findByPurchaseRef($purchase['request']['purchase_ref']);
        $this->assertSame(UsdtPaymentAllocationService::STATUS_RELEASED, $alloc['status']);
        $this->assertNull($alloc['deposit_active_key']);

        $dep2 = service('usdtDepositService')->findByRef($dep['deposit_ref']);
        $this->assertSame('verified', $dep2['status']);
        $this->assertSame('available', $dep2['payment_consumption_status']);
        $this->assertSame($dep['verified_amount_usdt'], $dep2['verified_amount_usdt']);
    }

    public function testRejectReleasesAllocation(): void
    {
        $member = $this->registerMember('reject');
        $dep = $this->forceVerifiedDeposit((int) $member['user']->id, '5', 'reject');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4d-r-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit_ref'],
            $purchase['request']['purchase_ref']
        );

        service('coinPurchaseService')->rejectRequest($purchase['request']['purchase_ref'], $this->adminUserId, 'Incomplete docs');

        $alloc = model(UsdtPaymentAllocationModel::class)->findByPurchaseRef($purchase['request']['purchase_ref']);
        $this->assertSame('released', $alloc['status']);
    }

    public function testConsumedCannotBeReleasedAndReleasedCannotBeConsumed(): void
    {
        $member = $this->registerMember('consumed');
        $dep = $this->forceVerifiedDeposit((int) $member['user']->id, '5', 'cons');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4d-cons-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit_ref'],
            $purchase['request']['purchase_ref']
        );

        service('coinPurchaseService')->approveRequest($purchase['request']['purchase_ref'], $this->adminUserId);

        $this->expectException(InvalidArgumentException::class);
        service('usdtPaymentAllocationService')->releaseReservedAllocationForPurchase(
            $purchase['request']['purchase_ref'],
            $this->adminUserId,
            'should-fail'
        );
    }

    public function testDuplicateReleaseIsIdempotent(): void
    {
        $member = $this->registerMember('duprel');
        $dep = $this->forceVerifiedDeposit((int) $member['user']->id, '5', 'duprel');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4d-dup-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit_ref'],
            $purchase['request']['purchase_ref']
        );

        $db = db_connect();
        $db->transStart();
        $r1 = service('usdtPaymentAllocationService')->releaseReservedAllocationForPurchase(
            $purchase['request']['purchase_ref'],
            $this->adminUserId,
            'first'
        );
        $r2 = service('usdtPaymentAllocationService')->releaseReservedAllocationForPurchase(
            $purchase['request']['purchase_ref'],
            $this->adminUserId,
            'second'
        );
        $db->transComplete();

        $this->assertTrue($r1['released']);
        $this->assertTrue($r2['released']);
        $this->assertSame(1, model(UsdtPaymentAllocationModel::class)->where('purchase_ref', $purchase['request']['purchase_ref'])->countAllResults());
    }

    public function testReleasedAllocationCannotBeConsumed(): void
    {
        $member = $this->registerMember('relcons');
        $dep = $this->forceVerifiedDeposit((int) $member['user']->id, '5', 'relcons');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4d-rc-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit_ref'],
            $purchase['request']['purchase_ref']
        );
        service('coinPurchaseService')->cancelRequest((int) $member['user']->id, $purchase['request']['purchase_ref']);

        $dep2 = service('usdtDepositService')->findByRef($dep['deposit_ref']);
        $this->assertSame('available', $dep2['payment_consumption_status']);

        // Direct consume of released allocation row for cancelled purchase must fail.
        $db = db_connect();
        $db->transStart();
        try {
            service('usdtPaymentAllocationService')->consumeLinkedAllocationForApproval(
                $purchase['request']['purchase_ref'],
                $this->adminUserId
            );
            $this->fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Released', $e->getMessage());
        }
        $db->transRollback();
    }

    public function testMonitorIdempotentAndNoLedgerMutation(): void
    {
        $before = service('coinInventoryService')->summary();
        $svc = new PaymentIntegrityMonitorService();
        $r1 = $svc->run(20, 'test-owner-1');
        $r2 = $svc->run(20, 'test-owner-1');
        $after = service('coinInventoryService')->summary();

        $this->assertTrue($r1['ran']);
        $this->assertSame($before, $after);
        // Second run also completes (lock released) — idempotent regarding ledgers.
        $this->assertTrue($r2['ran'] || $r2['skipped']);
    }

    public function testAlertDedupAndAcknowledge(): void
    {
        $svc = service('financialAlertService');
        $a1 = $svc->raise(FinancialAlertService::TYPE_RPC_UNAVAILABLE, 'critical', 'RPC down', 'timeout https://secret.example/key=abc', 'rpc:unavailable');
        $a2 = $svc->raise(FinancialAlertService::TYPE_RPC_UNAVAILABLE, 'critical', 'RPC down', 'timeout again', 'rpc:unavailable');
        $this->assertSame($a1['alert_ref'], $a2['alert_ref']);
        $this->assertSame(2, (int) $a2['occurrence_count']);
        $this->assertStringNotContainsString('https://', $a2['message']);

        $ack = $svc->acknowledge($a2['alert_ref'], $this->adminUserId, 'investigating');
        $this->assertSame('acknowledged', $ack['status']);
    }

    public function testSameAdminCannotApproveOwnRemediation(): void
    {
        $rem = service('financialRemediationService');
        $p = $rem->propose(
            $this->adminUserId,
            'ledger_mismatch',
            'Investigate mismatch',
            'Found inventory skew',
            'screenshot ref',
            'coin_inventory',
            '1',
            ['allocated' => '1'],
            ['allocated' => '1'],
            'idem-4d-1'
        );

        $this->expectException(InvalidArgumentException::class);
        $rem->approve($p['proposal_ref'], $this->adminUserId, 'self approve', true);
    }

    public function testDualControlApproveMarksExecutionUnavailable(): void
    {
        $users = model(UserModel::class);
        $reviewer = new User(['username' => 'reviewer4d', 'active' => 1]);
        $users->save($reviewer);
        $reviewer = $users->findById($users->getInsertID());
        $reviewer->createEmailIdentity(['email' => 'reviewer4d@example.com', 'password' => 'Password123!']);
        $reviewer->addGroup('admin');

        $rem = service('financialRemediationService');
        $p = $rem->propose(
            $this->adminUserId,
            'allocation_anomaly',
            'Check orphan',
            'Orphan suspected',
            null,
            'usdt_payment_allocation',
            'PAY-X',
            [],
            [],
            'idem-4d-2'
        );

        $out = $rem->approve($p['proposal_ref'], (int) $reviewer->id, 'Reviewed', true);
        $this->assertSame(FinancialRemediationService::STATUS_EXECUTION_UNAVAILABLE, $out['status']);
        $this->assertSame(0, (int) $out['execution_allowed']);

        $this->expectException(InvalidArgumentException::class);
        $rem->execute($p['proposal_ref'], (int) $reviewer->id);
    }

    public function testFinancePagesRequireAdmin(): void
    {
        $guest = $this->get('admin/finance/alerts');
        $guest->assertRedirect();

        $login = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $login->assertRedirect();

        $page = $this->get('admin/finance/alerts');
        $page->assertOK();
        $page->assertSee('Financial Alerts');
    }
}
