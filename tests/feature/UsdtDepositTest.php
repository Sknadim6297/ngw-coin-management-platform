<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Libraries\Blockchain\DepositVerificationResult;
use App\Libraries\Blockchain\NotConfiguredDepositVerificationProvider;
use App\Models\MemberProfileModel;
use App\Models\UsdtDepositRequestModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use InvalidArgumentException;

/**
 * @internal
 */
final class UsdtDepositTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $networkId;
    private string $validTxHash;

    protected function setUp(): void
    {
        parent::setUp();

        $platform                 = config('Platform');
        $platform->adminEmail     = 'admin@test.local';
        $platform->adminPassword  = 'AdminPass123!';
        $platform->adminName      = 'Test Admin';
        $platform->rootSponsorId  = 'ROOT0001';
        $platform->memberIdPrefix = 'CPM';
        $platform->requireVerifiedDepositForApproval = true;

        // Isolate from EvmVerificationTest / shared Blockchain mutations.
        $chain = config('Blockchain');
        $chain->depositVerifier   = 'not_configured';
        $chain->evmRpcUrl         = '';
        $chain->rpcHostAllowlist  = '';
        Services::resetSingle('jsonRpcClient');
        Services::resetSingle('depositVerificationProvider');
        Services::resetSingle('usdtDepositService');

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('0.50', $this->adminUserId, 'Deposit tests');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'Stock', null, 'dep-stock');

        $network = service('usdtPaymentNetworkService')->create([
            'network_key'            => 'bsc_usdt',
            'network_name'           => 'BNB Smart Chain USDT',
            'token_symbol'           => 'USDT',
            'token_contract_address' => '0x55d398326f99059ff775485246999027b3197955',
            'token_decimals'         => 18,
            'destination_wallet'     => '0x1111111111111111111111111111111111111111',
            'required_confirmations' => 12,
            'min_deposit_usdt'       => '5',
            'max_deposit_usdt'       => '10000',
            'instructions'           => 'Send only BEP20 USDT.',
            'is_enabled'             => 1,
            'sort_order'             => 10,
        ], $this->adminUserId);

        $this->networkId   = (int) $network['id'];
        $this->validTxHash = '0x' . str_repeat('ab', 32);

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

    private function registerMember(string $suffix): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('y' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'dep' . $suffix), 0, 40), 40, '0');

        $result = service('registrationService')->register([
            'member_name' => 'Dep ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '16' . substr($digits . '00000000', 0, 8),
            'email'       => 'dep' . $suffix . '@example.com',
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

    private function makeTempUpload(string $contents, string $name, string $mime): FakeDepositUploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'evd');
        file_put_contents($path, $contents);

        return new FakeDepositUploadedFile($path, $name, $mime, strlen($contents), UPLOAD_ERR_OK);
    }

    public function testMemberCreatesDepositRequest(): void
    {
        $member = $this->registerMember('create');
        $result = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '25',
            $this->validTxHash,
            'dep-idem-1'
        );

        $this->assertFalse($result['duplicate']);
        $this->assertSame('submitted', $result['deposit']['status']);
        $this->assertSame('25.00000000', $result['deposit']['expected_amount_usdt']);
        $this->assertSame(strtolower($this->validTxHash), $result['deposit']['tx_hash_normalized']);
    }

    public function testGuestAccessDenied(): void
    {
        $result = $this->get('member/deposits/create');
        $result->assertRedirect();
    }

    public function testInvalidAmountRejected(): void
    {
        $member = $this->registerMember('badamt');
        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '1',
            '0x' . str_repeat('11', 32),
            'dep-badamt'
        );
    }

    public function testDisabledNetworkRejected(): void
    {
        $disabled = service('usdtPaymentNetworkService')->create([
            'network_key'        => 'eth_usdt',
            'network_name'       => 'Ethereum USDT',
            'destination_wallet' => '0x2222222222222222222222222222222222222222',
            'token_decimals'     => 6,
            'min_deposit_usdt'   => '5',
            'max_deposit_usdt'   => '1000',
            'is_enabled'         => 0,
        ], $this->adminUserId);

        $member = $this->registerMember('disnet');
        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            (int) $disabled['id'],
            '10',
            '0x' . str_repeat('22', 32),
            'dep-disnet'
        );
    }

    public function testInvalidTransactionHashRejected(): void
    {
        $member = $this->registerMember('badhash');
        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            'not-a-hash',
            'dep-badhash'
        );
    }

    public function testDuplicateTransactionHashRejected(): void
    {
        $a = $this->registerMember('dupa');
        $b = $this->registerMember('dupb');
        $hash = '0x' . str_repeat('cd', 32);

        service('usdtDepositService')->submitRequest((int) $a['user']->id, $this->networkId, '10', $hash, 'dep-dupa');

        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->submitRequest((int) $b['user']->id, $this->networkId, '10', $hash, 'dep-dupb');
    }

    public function testUnsafeUploadRejected(): void
    {
        $member = $this->registerMember('badfile');
        $php = $this->makeTempUpload('<?php echo 1;', 'shell.php', 'application/x-php');

        try {
            service('usdtDepositService')->submitRequest(
                (int) $member['user']->id,
                $this->networkId,
                '10',
                '0x' . str_repeat('33', 32),
                'dep-badfile',
                $php
            );
            $this->fail('Unsafe upload should be rejected.');
        } catch (InvalidArgumentException $e) {
            $this->assertNotSame('', $e->getMessage());
        }

        $count = model(UsdtDepositRequestModel::class)
            ->where('idempotency_key', 'dep-badfile')
            ->countAllResults();
        $this->assertSame(0, $count);
    }

    public function testEvidenceAccessRestricted(): void
    {
        $owner = $this->registerMember('evown');
        $other = $this->registerMember('evoth');

        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $file = $this->makeTempUpload($png, 'proof.png', 'image/png');

        $created = service('usdtDepositService')->submitRequest(
            (int) $owner['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('44', 32),
            'dep-evown',
            $file
        );

        $this->assertNotEmpty($created['deposit']['evidence_stored_name']);

        service('memberAuthService')->attempt($other['member_id'], 'Password123!');
        $result = $this->get('member/deposits/' . $created['deposit']['deposit_ref'] . '/evidence');
        $result->assertRedirect();
    }

    public function testMemberSeesOnlyOwnDeposits(): void
    {
        $a = $this->registerMember('owna');
        $b = $this->registerMember('ownb');
        $created = service('usdtDepositService')->submitRequest(
            (int) $a['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('55', 32),
            'dep-owna'
        );

        service('memberAuthService')->attempt($b['member_id'], 'Password123!');
        $result = $this->get('member/deposits/' . $created['deposit']['deposit_ref']);
        $result->assertRedirect();
    }

    public function testAdminCanReviewDeposits(): void
    {
        $member = $this->registerMember('review');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('66', 32),
            'dep-review'
        );

        $updated = service('usdtDepositService')->markUnderReview(
            $created['deposit']['deposit_ref'],
            $this->adminUserId,
            'Checking explorer'
        );

        $this->assertSame('under_review', $updated['deposit']['status']);
    }

    public function testRejectionRequiresReason(): void
    {
        $member = $this->registerMember('rej');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('77', 32),
            'dep-rej'
        );

        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->reject($created['deposit']['deposit_ref'], $this->adminUserId, '  ');
    }

    public function testScreenshotCannotVerifyPayment(): void
    {
        $member = $this->registerMember('manual');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('88', 32),
            'dep-manual'
        );

        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->rejectManualVerificationClaim(
            $created['deposit']['deposit_ref'],
            $this->adminUserId
        );
    }

    public function testUnconfiguredVerificationProviderFailsSafely(): void
    {
        $member = $this->registerMember('novfy');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('99', 32),
            'dep-novfy'
        );

        $result = service('usdtDepositService')->attemptVerification(
            $created['deposit']['deposit_ref'],
            $this->adminUserId
        );

        $this->assertFalse($result['result']->isVerified());
        $this->assertSame(DepositVerificationResult::STATUS_NOT_CONFIGURED, $result['result']->status);
        $this->assertSame('under_review', $result['deposit']['status']);
        $this->assertSame('not_configured', (new NotConfiguredDepositVerificationProvider())->providerKey());
    }

    public function testDepositSubmissionDoesNotCreditCoins(): void
    {
        $member = $this->registerMember('nocoin');
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('aa', 32),
            'dep-nocoin'
        );

        $holdings = service('memberCoinOwnershipService')->summaryForUser((int) $member['user']->id);
        $this->assertSame('0.00000000', $holdings['balance']);
        $this->assertSame('inactive', model(MemberProfileModel::class)->findByUserId((int) $member['user']->id)->account_status);
    }

    public function testDepositSubmissionDoesNotChangeInventory(): void
    {
        $before = service('coinInventoryService')->summary();
        $member = $this->registerMember('noinv');
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('bb', 32),
            'dep-noinv'
        );
        $after = service('coinInventoryService')->summary();

        $this->assertSame($before['available_balance'], $after['available_balance']);
        $this->assertSame($before['total_allocated'], $after['total_allocated']);
    }

    public function testInvalidStateTransitionsAreBlocked(): void
    {
        $member = $this->registerMember('state');
        $created = service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('cc', 32),
            'dep-state'
        );

        service('usdtDepositService')->reject(
            $created['deposit']['deposit_ref'],
            $this->adminUserId,
            'Invalid proof package'
        );

        $this->expectException(InvalidArgumentException::class);
        service('usdtDepositService')->markUnderReview(
            $created['deposit']['deposit_ref'],
            $this->adminUserId
        );
    }

    public function testPurchaseApprovalBlockedWithoutVerifiedDeposit(): void
    {
        $member = $this->registerMember('paygate');
        $purchase = service('coinPurchaseService')->createRequest(
            (int) $member['user']->id,
            '10',
            null,
            'pur-paygate'
        );

        // Deposit exists but is only submitted — not verified.
        service('usdtDepositService')->submitRequest(
            (int) $member['user']->id,
            $this->networkId,
            '10',
            '0x' . str_repeat('dd', 32),
            'dep-paygate'
        );

        $this->expectException(InvalidArgumentException::class);
        service('coinPurchaseService')->approveRequest(
            $purchase['request']['purchase_ref'],
            $this->adminUserId
        );
    }

    public function testProviderNotConfiguredConstant(): void
    {
        $provider = Services::depositVerificationProvider(false);
        $result = $provider->verify(
            ['deposit_ref' => 'DEP-TEST'],
            ['network_key' => 'bsc_usdt']
        );
        $this->assertSame('not_configured', $result->status);
        $this->assertFalse($result->isVerified());
    }
}

/**
 * Test double: UploadedFile that works outside PHP's is_uploaded_file() SAPI check.
 */
final class FakeDepositUploadedFile extends UploadedFile
{
    public function isValid(): bool
    {
        return is_file($this->getPathname()) && $this->getError() === UPLOAD_ERR_OK;
    }

    public function move(string $targetPath, ?string $name = null, bool $overwrite = false)
    {
        $targetPath = rtrim($targetPath, '\\/') . DIRECTORY_SEPARATOR;
        if (! is_dir($targetPath)) {
            mkdir($targetPath, 0750, true);
        }

        $name = $name ?? $this->getName();
        $destination = $targetPath . $name;
        if (! $overwrite && is_file($destination)) {
            throw new \RuntimeException('Destination exists.');
        }

        if (! rename($this->getPathname(), $destination) && ! copy($this->getPathname(), $destination)) {
            throw new \RuntimeException('Unable to move fake upload.');
        }

        @unlink($this->getPathname());

        $ref = new \ReflectionProperty(UploadedFile::class, 'hasMoved');
        $ref->setAccessible(true);
        $ref->setValue($this, true);

        $pathRef = new \ReflectionProperty(UploadedFile::class, 'path');
        $pathRef->setAccessible(true);
        $pathRef->setValue($this, $destination);

        $nameRef = new \ReflectionProperty(UploadedFile::class, 'name');
        $nameRef->setAccessible(true);
        $nameRef->setValue($this, basename($destination));

        return true;
    }
}
