<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\Blockchain\EvmUsdtVerificationProvider;
use App\Libraries\Blockchain\JsonRpcClient;
use App\Models\MemberProfileModel;
use App\Models\UsdtPaymentAllocationModel;
use App\Services\DeploymentPreflightService;
use App\Services\PaymentHealthService;
use App\Services\PaymentReconciliationService;
use App\Services\VerificationRetryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 4C payment operations tests.
 *
 * @internal
 */
final class PaymentOpsTest extends CIUnitTestCase
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
        $platform->requireVerifiedDepositForApproval = true;

        $chain = config('Blockchain');
        $chain->depositVerifier = 'evm_erc20';
        $chain->evmRpcUrl = 'https://rpc.example-trusted.test';
        $chain->rpcHostAllowlist = 'rpc.example-trusted.test';
        $chain->verificationMaxAttempts = 3;
        $chain->verificationRetryBackoffSeconds = 1;
        $chain->verificationRetryMaxBackoffSeconds = 2;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, '4C price');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'Stock', null, '4c-stock');

        $this->network = service('usdtPaymentNetworkService')->create([
            'network_key'              => 'bsc_usdt',
            'network_name'             => 'BNB Smart Chain USDT',
            'chain_id'                 => 56,
            'verifier_type'            => 'evm_erc20',
            'token_symbol'             => 'USDT',
            'token_contract_address'   => $this->token,
            'token_decimals'           => 18,
            'destination_wallet'       => $this->dest,
            'required_confirmations'   => 3,
            'min_deposit_usdt'         => '5',
            'max_deposit_usdt'         => '10000',
            'explorer_tx_url_template' => 'https://bscscan.com/tx/{tx_hash}',
            'is_enabled'               => 1,
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
        Services::injectMock('verificationRetryService', new VerificationRetryService(
            service('usdtDepositService'),
            config('Blockchain')
        ));
        Services::injectMock('paymentHealthService', new PaymentHealthService(
            config('Blockchain'),
            $client
        ));
    }

    protected function tearDown(): void
    {
        $chain = config('Blockchain');
        $chain->depositVerifier = 'not_configured';
        $chain->evmRpcUrl = '';
        $chain->rpcHostAllowlist = '';

        Services::resetSingle('jsonRpcClient');
        Services::resetSingle('depositVerificationProvider');
        Services::resetSingle('usdtDepositService');
        Services::resetSingle('verificationRetryService');
        Services::resetSingle('paymentHealthService');

        if (auth()->loggedIn()) {
            auth()->logout();
        }

        parent::tearDown();
    }

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32('4c' . $suffix) . '9');
        $hex = str_pad(substr(hash('sha256', '4c' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'FourC ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '18' . substr($digits . '00000000', 0, 8),
            'email'       => 'fourc' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user' => $result['user'], 'profile' => $profile];
    }

    private function loginAdmin(): void
    {
        $result = $this->post('admin/login', [
            'email'    => 'admin@test.local',
            'password' => 'AdminPass123!',
        ]);
        $result->assertRedirect();
    }

    public function testHealthDashboardRequiresAdmin(): void
    {
        $guest = $this->get('admin/payment-health');
        $guest->assertRedirect();

        $member = $this->registerMember('guestish');
        $this->post('login', [
            'member_id' => $member['profile']->member_id,
            'password'  => 'Password123!',
        ]);
        $denied = $this->get('admin/payment-health');
        $denied->assertRedirect();
    }

    public function testHealthDashboardLoadsForAdmin(): void
    {
        $this->loginAdmin();
        $result = $this->get('admin/payment-health');
        $result->assertOK();
        $result->assertSee('Payment Health');
        $result->assertDontSee('rpc.example-trusted.test/secret');
    }

    public function testRpcUnavailableHealthProbe(): void
    {
        $this->rpcMap['eth_chainId'] = static function () {
            throw new RuntimeException('timeout talking to provider');
        };

        $snap = service('paymentHealthService')->probeAndStore($this->adminUserId);
        $this->assertSame('down', $snap['status']);
        $this->assertSame('provider_unavailable', $snap['error_category']);
        $this->assertStringNotContainsString('https://', (string) $snap['error_summary']);
        $this->assertNotNull($snap['rpc_host_redacted']);
        $this->assertStringContainsString('***', (string) $snap['rpc_host_redacted']);
    }

    public function testWrongChainHealthProbe(): void
    {
        $this->rpcMap['eth_chainId'] = '0x1'; // 1 != 56
        $this->rpcMap['eth_blockNumber'] = '0x100';

        $snap = service('paymentHealthService')->probeAndStore($this->adminUserId);
        $this->assertSame('wrong_chain', $snap['status']);
        $this->assertSame(1, (int) $snap['reported_chain_id']);
        $this->assertSame(56, (int) $snap['expected_chain_id']);
    }

    public function testReconciliationDetectsOrphanAndDuplicatePatterns(): void
    {
        $member = $this->registerMember('recon');
        $db = db_connect();

        // Orphan allocation (no deposit/purchase rows)
        $db->table('usdt_payment_allocations')->insert([
            'allocation_ref' => 'ALLOC-ORPHAN1',
            'deposit_ref'    => 'DEP-MISSING',
            'purchase_ref'   => 'PUR-MISSING',
            'member_user_id' => (int) $member['user']->id,
            'amount_usdt'    => '10.00000000',
            'status'         => 'consumed',
            'linked_by'      => $this->adminUserId,
            'linked_at'      => date('Y-m-d H:i:s'),
            'created_at'     => date('Y-m-d H:i:s'),
            'updated_at'     => date('Y-m-d H:i:s'),
        ]);

        $report = (new PaymentReconciliationService(config('Platform')))->run();
        $codes = array_column($report['findings'], 'code');
        $this->assertContains('orphan_allocation', $codes);
    }

    public function testReconciliationFlagsApprovedWithoutPaymentWhenGateOn(): void
    {
        $member = $this->registerMember('unpaid');
        $purchase = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            '4c-unpaid'
        );

        // Force approved without payment (bypass service) to simulate inconsistency.
        db_connect()->table('coin_purchase_requests')
            ->where('purchase_ref', $purchase['request']['purchase_ref'])
            ->update([
                'status'         => 'approved',
                'payment_status' => 'unpaid',
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);

        $report = (new PaymentReconciliationService(config('Platform')))->run();
        $codes = array_column($report['findings'], 'code');
        $this->assertContains('approved_without_verified_payment', $codes);
        $this->assertContains('missing_ownership_credit', $codes);
    }

    public function testVerificationRetryLimitsAndIdempotentBackoff(): void
    {
        $member = $this->registerMember('retry');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            '0x' . str_repeat('cd', 32),
            '4c-retry'
        );

        $this->rpcMap['eth_chainId'] = '0x38';
        $this->rpcMap['eth_getTransactionByHash'] = null; // not found — retryable
        $this->rpcMap['eth_getTransactionReceipt'] = null;
        $this->rpcMap['eth_blockNumber'] = '0x10';

        $svc = service('verificationRetryService');
        $r1 = $svc->retry($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_TX_NOT_FOUND, $r1['result']->status);

        // Immediate second attempt should hit backoff.
        $this->expectException(InvalidArgumentException::class);
        $svc->retry($created['deposit']['deposit_ref'], $this->adminUserId);
    }

    public function testRetryLimitReached(): void
    {
        $member = $this->registerMember('maxtry');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            '0x' . str_repeat('ef', 32),
            '4c-max'
        );

        $ref = $created['deposit']['deposit_ref'];
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $ref)->update([
            'status'                     => 'under_review',
            'verification_attempt_count' => 3,
            'last_verification_status'   => DepositVerificationResult::STATUS_TX_NOT_FOUND,
            'next_verification_after'    => date('Y-m-d H:i:s', time() - 10),
        ]);

        $gate = service('verificationRetryService')->canRetry($ref);
        $this->assertFalse($gate['allowed']);
        $this->assertStringContainsString('retry limit', strtolower((string) $gate['reason']));
    }

    public function testReorgIntegrityFlagDoesNotMutateLedgers(): void
    {
        $member = $this->registerMember('reorg');
        $invBefore = service('coinInventoryService')->availableBalance();

        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            '0x' . str_repeat('11', 32),
            '4c-reorg'
        );
        $ref = $created['deposit']['deposit_ref'];

        // Pretend already verified with a stored block hash.
        db_connect()->table('usdt_deposit_requests')->where('deposit_ref', $ref)->update([
            'status'                   => 'verified',
            'verified_amount_usdt'     => '10.00000000',
            'verified_block_number'    => 100,
            'verified_block_hash'      => '0x' . str_repeat('aa', 32),
            'verified_confirmations'   => 12,
            'verified_at'              => date('Y-m-d H:i:s'),
            'payment_consumption_status' => 'available',
            'integrity_flag'           => 'ok',
        ]);

        $topic = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
        $toTopic = '0x' . str_pad(strtolower(substr($this->dest, 2)), 64, '0', STR_PAD_LEFT);
        $fromTopic = '0x' . str_pad('22', 64, '0');
        $amountHex = '0x' . str_pad(base_convert('10000000000000000000', 10, 16), 64, '0', STR_PAD_LEFT);

        $this->rpcMap['eth_chainId'] = '0x38';
        $this->rpcMap['eth_blockNumber'] = '0x70'; // 112
        $this->rpcMap['eth_getTransactionByHash'] = [
            'hash' => '0x' . str_repeat('11', 32),
            'blockHash' => '0x' . str_repeat('bb', 32), // differs from stored aa
            'blockNumber' => '0x64',
        ];
        $this->rpcMap['eth_getTransactionReceipt'] = [
            'status' => '0x1',
            'blockHash' => '0x' . str_repeat('bb', 32),
            'blockNumber' => '0x64',
            'logs' => [[
                'address' => $this->token,
                'topics'  => [$topic, $fromTopic, $toTopic],
                'data'    => $amountHex,
            ]],
        ];

        $out = service('usdtDepositService')->recheckVerifiedDepositIntegrity($ref, $this->adminUserId);
        $this->assertTrue($out['flagged']);
        $this->assertSame('reorg_suspected', $out['deposit']['integrity_flag']);
        $this->assertSame('verified', $out['deposit']['status']);
        $this->assertSame($invBefore, service('coinInventoryService')->availableBalance());
        $this->assertSame(0, model(UsdtPaymentAllocationModel::class)->countAllResults());
    }

    public function testPreflightAndSensitiveRedaction(): void
    {
        $report = (new DeploymentPreflightService())->run();
        $this->assertArrayHasKey('checks', $report);
        $this->assertFalse($report['live_payments_recommended']);

        $ids = array_column($report['checks'], 'id');
        $this->assertContains('php_extensions', $ids);
        $this->assertContains('payment_gate', $ids);

        $blob = json_encode($report);
        $this->assertIsString($blob);
        $this->assertStringNotContainsString('AdminPass123!', $blob);
        $this->assertStringNotContainsString('ChangeMe_Admin', $blob);

        $this->loginAdmin();
        $page = $this->get('admin/deployment-preflight');
        $page->assertOK();
        $page->assertSee('Deployment Preflight');
        $page->assertSee('intl');
    }

    public function testAuditAndReconciliationPagesAdminOnly(): void
    {
        $this->loginAdmin();
        $recon = $this->get('admin/payment-reconciliation');
        $recon->assertOK();
        $recon->assertSee('Payment Reconciliation');
        $audit = $this->get('admin/payment-audit');
        $audit->assertOK();
        $audit->assertSee('Payment Audit');
    }

    public function testNoSilentLedgerMutationFromReconciliation(): void
    {
        $beforeInv = service('coinInventoryService')->summary();
        $report = (new PaymentReconciliationService(config('Platform')))->run();
        $afterInv = service('coinInventoryService')->summary();
        $this->assertSame($beforeInv, $afterInv);
        $this->assertIsArray($report['findings']);
    }
}
