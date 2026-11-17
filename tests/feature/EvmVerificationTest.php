<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\Blockchain\EvmUsdtVerificationProvider;
use App\Libraries\Blockchain\JsonRpcClient;
use App\Libraries\Blockchain\TokenAmountMath;
use App\Models\MemberProfileModel;
use App\Models\UsdtPaymentAllocationModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;
use RuntimeException;

/**
 * @internal
 */
final class EvmVerificationTest extends CIUnitTestCase
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
    private string $txHash;

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

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, '4B price');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'Stock', null, '4b-stock');

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

        $this->txHash = '0x' . str_repeat('ab', 32);

        // Inject mocked RPC client into shared services.
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
        $chain->rpcHostAllowlist = '';

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
        $digits = preg_replace('/\D/', '', (string) crc32('4b' . $suffix) . '9');
        $hex = str_pad(substr(hash('sha256', '4b' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'FourB ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'fourb' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return ['user' => $result['user'], 'profile' => $profile, 'member_id' => $profile->member_id];
    }

    private function topicAddress(string $address): string
    {
        $address = strtolower(str_replace('0x', '', $address));

        return '0x' . str_pad($address, 64, '0', STR_PAD_LEFT);
    }

    private function amountData(string $human, int $decimals = 18): string
    {
        $base = bcmul($human, bcpow('10', (string) $decimals, 0), 0);
        // convert dec to hex
        $hex = '';
        $n = $base;
        if ($n === '0') {
            return '0x' . str_pad('0', 64, '0', STR_PAD_LEFT);
        }
        while (bccomp($n, '0', 0) > 0) {
            $rem = (int) bcmod($n, '16');
            $hex = dechex($rem) . $hex;
            $n = bcdiv($n, '16', 0);
        }

        return '0x' . str_pad($hex, 64, '0', STR_PAD_LEFT);
    }

    /**
     * @param list<array{to:string,amount:string}> $transfers
     */
    private function stubValidChain(array $transfers, int $head = 110, int $txBlock = 100, string $status = '0x1', ?int $chainId = 56, ?string $tokenOverride = null): void
    {
        $token = $tokenOverride ?? $this->token;
        $logs = [];
        foreach ($transfers as $t) {
            $logs[] = [
                'address' => $token,
                'topics'  => [
                    EvmUsdtVerificationProvider::TRANSFER_TOPIC,
                    $this->topicAddress('0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
                    $this->topicAddress($t['to']),
                ],
                'data' => $this->amountData($t['amount']),
            ];
        }

        $blockHash = '0x' . str_repeat('11', 32);
        $this->rpcMap = [
            'eth_chainId' => '0x' . dechex($chainId ?? 56),
            'eth_getTransactionByHash' => [
                'hash'      => $this->txHash,
                'blockHash' => $blockHash,
                'blockNumber'=> '0x' . dechex($txBlock),
            ],
            'eth_getTransactionReceipt' => [
                'status'      => $status,
                'blockHash'   => $blockHash,
                'blockNumber' => '0x' . dechex($txBlock),
                'logs'        => $logs,
            ],
            'eth_blockNumber' => '0x' . dechex($head),
        ];
    }

    public function testValidVerifiedErc20Transfer(): void
    {
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']]);
        $member = $this->registerMember('ok');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $this->txHash,
            '4b-ok'
        );

        $result = service('usdtDepositService')->attemptVerification(
            $created['deposit']['deposit_ref'],
            $this->adminUserId
        );

        $this->assertTrue($result['result']->isVerified());
        $this->assertSame('verified', $result['deposit']['status']);
        $this->assertSame('10.00000000', $result['deposit']['verified_amount_usdt']);
        $this->assertSame(56, (int) $result['deposit']['verified_chain_id']);
        $this->assertSame(strtolower($this->token), strtolower((string) $result['deposit']['verified_token_contract']));
        $this->assertSame(strtolower($this->dest), strtolower((string) $result['deposit']['verified_destination']));
        $this->assertSame(100, (int) $result['deposit']['verified_block_number']);
        $this->assertGreaterThanOrEqual(3, (int) $result['deposit']['verified_confirmations']);
    }

    public function testWrongChainId(): void
    {
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], chainId: 1);
        $member = $this->registerMember('chain');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            '0x' . str_repeat('01', 32),
            '4b-chain'
        );
        $this->txHash = '0x' . str_repeat('01', 32);
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], chainId: 1);

        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_WRONG_NETWORK, $result['result']->status);
        $this->assertSame('under_review', $result['deposit']['status']);
    }

    public function testWrongTokenContract(): void
    {
        $wrong = '0x2222222222222222222222222222222222222222';
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], tokenOverride: $wrong);
        $member = $this->registerMember('token');
        $hash = '0x' . str_repeat('02', 32);
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-token'
        );
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], tokenOverride: $wrong);

        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_WRONG_TOKEN_OR_DESTINATION, $result['result']->status);
    }

    public function testWrongDestinationWallet(): void
    {
        $other = '0x3333333333333333333333333333333333333333';
        $hash = '0x' . str_repeat('03', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $other, 'amount' => '10']]);
        $member = $this->registerMember('dest');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-dest'
        );

        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_WRONG_TOKEN_OR_DESTINATION, $result['result']->status);
    }

    public function testFailedTransactionReceipt(): void
    {
        $hash = '0x' . str_repeat('04', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], status: '0x0');
        $member = $this->registerMember('fail');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-fail'
        );
        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_TX_FAILED, $result['result']->status);
    }

    public function testInsufficientConfirmations(): void
    {
        $hash = '0x' . str_repeat('05', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '10']], head: 101, txBlock: 100); // 2 confirmations < 3
        $member = $this->registerMember('conf');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-conf'
        );
        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_INSUFFICIENT_CONFIRMATIONS, $result['result']->status);
        $this->assertSame('under_review', $result['deposit']['status']);
    }

    public function testInsufficientAmount(): void
    {
        $hash = '0x' . str_repeat('06', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '4']]);
        $member = $this->registerMember('amt');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-amt'
        );
        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_INSUFFICIENT_AMOUNT, $result['result']->status);
    }

    public function testMultipleTransferEventsAreSummed(): void
    {
        $hash = '0x' . str_repeat('07', 32);
        $this->txHash = $hash;
        $this->stubValidChain([
            ['to' => $this->dest, 'amount' => '4'],
            ['to' => $this->dest, 'amount' => '6'],
            ['to' => '0x9999999999999999999999999999999999999999', 'amount' => '50'],
        ]);
        $member = $this->registerMember('multi');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-multi'
        );
        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertTrue($result['result']->isVerified());
        $this->assertSame('10.00000000', $result['deposit']['verified_amount_usdt']);
        $this->assertSame(2, $result['result']->details['transfer_count']);
    }

    public function testRpcTimeoutFailsSafely(): void
    {
        $client = new JsonRpcClient(config('Blockchain'), static function () {
            throw new RuntimeException('RPC transport failed: timeout');
        });
        $provider = new EvmUsdtVerificationProvider($client, config('Blockchain'));
        $result = $provider->verify(
            [
                'tx_hash_normalized'   => $this->txHash,
                'expected_amount_usdt' => '10.00000000',
                'destination_wallet_snapshot' => $this->dest,
            ],
            $this->network
        );
        $this->assertFalse($result->isVerified());
        $this->assertSame(DepositVerificationResult::STATUS_UNAVAILABLE, $result->status);
    }

    public function testMalformedRpcResponseFailsSafely(): void
    {
        $client = new JsonRpcClient(config('Blockchain'), static function () {
            return '{not-json';
        });
        $provider = new EvmUsdtVerificationProvider($client, config('Blockchain'));
        $result = $provider->verify(
            [
                'tx_hash_normalized'   => $this->txHash,
                'expected_amount_usdt' => '10.00000000',
                'destination_wallet_snapshot' => $this->dest,
            ],
            $this->network
        );
        $this->assertFalse($result->isVerified());
    }

    public function testChainReorganizationDetected(): void
    {
        $hash = '0x' . str_repeat('08', 32);
        $this->txHash = $hash;
        $this->rpcMap = [
            'eth_chainId' => '0x38',
            'eth_getTransactionByHash' => [
                'hash' => $hash,
                'blockHash' => '0x' . str_repeat('aa', 32),
                'blockNumber' => '0x64',
            ],
            'eth_getTransactionReceipt' => [
                'status' => '0x1',
                'blockHash' => '0x' . str_repeat('bb', 32),
                'blockNumber' => '0x64',
                'logs' => [],
            ],
            'eth_blockNumber' => '0x6e',
        ];
        $member = $this->registerMember('reorg');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $this->network['id'],
            '10',
            $hash,
            '4b-reorg'
        );
        $result = service('usdtDepositService')->attemptVerification($created['deposit']['deposit_ref'], $this->adminUserId);
        $this->assertSame(DepositVerificationResult::STATUS_REORG_OR_INCONSISTENT, $result['result']->status);
    }

    public function testCrossMemberPurchaseLinkingBlocked(): void
    {
        $this->stubValidChain([['to' => $this->dest, 'amount' => '5']]);
        $a = $this->registerMember('linka');
        $b = $this->registerMember('linkb');
        $hash = '0x' . str_repeat('09', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '5']]);

        $dep = service('usdtDepositService')->submitRequest((int) $a['user']->id, (int) $this->network['id'], '5', $hash, '4b-linka');
        service('usdtDepositService')->attemptVerification($dep['deposit']['deposit_ref'], $this->adminUserId);

        $purchase = service('coinPurchaseService')->createRequest((int) $b['user']->id, '10', null, '4b-pur-b');

        $this->expectException(InvalidArgumentException::class);
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $b['user']->id,
            $dep['deposit']['deposit_ref'],
            $purchase['request']['purchase_ref']
        );
    }

    public function testDoubleConsumptionBlocked(): void
    {
        $hash = '0x' . str_repeat('0a', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '5']]);
        $member = $this->registerMember('dbl');
        $dep = service('usdtDepositService')->submitRequest((int) $member['user']->id, (int) $this->network['id'], '5', $hash, '4b-dbl');
        service('usdtDepositService')->attemptVerification($dep['deposit']['deposit_ref'], $this->adminUserId);

        $p1 = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4b-p1');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit']['deposit_ref'],
            $p1['request']['purchase_ref']
        );
        service('coinPurchaseService')->approveRequest($p1['request']['purchase_ref'], $this->adminUserId);

        $p2 = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4b-p2');
        $this->expectException(InvalidArgumentException::class);
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit']['deposit_ref'],
            $p2['request']['purchase_ref']
        );
    }

    public function testAtomicApprovalConsumesPaymentAndCreditsOnce(): void
    {
        $hash = '0x' . str_repeat('0b', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '5']]);
        $member = $this->registerMember('atom');
        $dep = service('usdtDepositService')->submitRequest((int) $member['user']->id, (int) $this->network['id'], '5', $hash, '4b-atom');
        service('usdtDepositService')->attemptVerification($dep['deposit']['deposit_ref'], $this->adminUserId);

        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4b-atom-p');
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit']['deposit_ref'],
            $purchase['request']['purchase_ref']
        );

        $result = service('coinPurchaseService')->approveRequest($purchase['request']['purchase_ref'], $this->adminUserId);
        $this->assertSame('approved', $result['request']['status']);
        $this->assertSame('verified', $result['request']['payment_status']);
        $this->assertSame('10.00000000', $result['ownership']['balance']);
        $this->assertSame('990.00000000', $result['inventory']['available_balance']);

        $alloc = model(UsdtPaymentAllocationModel::class)->findByPurchaseRef($purchase['request']['purchase_ref']);
        $this->assertSame('consumed', $alloc['status']);
    }

    public function testNoApprovalWithoutPaymentCoverage(): void
    {
        $member = $this->registerMember('nopay');
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4b-nopay');
        $this->expectException(InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest($purchase['request']['purchase_ref'], $this->adminUserId);
    }

    public function testNoCoinCreditBeforeVerification(): void
    {
        $member = $this->registerMember('pre');
        $hash = '0x' . str_repeat('0c', 32);
        service('usdtDepositService')->submitRequest((int) $member['user']->id, (int) $this->network['id'], '5', $hash, '4b-pre');
        $this->assertSame('0.00000000', service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id)['balance']);
        $this->assertSame('1000.00000000', service('coinInventoryService')->availableBalance());
    }

    public function testOverpaymentLinkRejected(): void
    {
        $hash = '0x' . str_repeat('0d', 32);
        $this->txHash = $hash;
        $this->stubValidChain([['to' => $this->dest, 'amount' => '20']]);
        $member = $this->registerMember('over');
        $dep = service('usdtDepositService')->submitRequest((int) $member['user']->id, (int) $this->network['id'], '20', $hash, '4b-over');
        service('usdtDepositService')->attemptVerification($dep['deposit']['deposit_ref'], $this->adminUserId);
        $purchase = service('coinPurchaseService')->createRequest((int) $member['user']->id, '10', null, '4b-over-p');

        $this->expectException(InvalidArgumentException::class);
        service('usdtPaymentAllocationService')->linkDepositToPurchase(
            (int) $member['user']->id,
            $dep['deposit']['deposit_ref'],
            $purchase['request']['purchase_ref']
        );
    }

    public function testTokenAmountMathIntegerSafe(): void
    {
        $dec = TokenAmountMath::baseUnitsHexToDecimal('0x' . str_pad(dechex(5 * (10 ** 8)), 64, '0', STR_PAD_LEFT), 8, 8);
        $this->assertSame('5.00000000', $dec);
    }
}
