<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\PaymentQrService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Focused tests: Admin USDT QR upload, dual-control, demo guards, immutable snapshots.
 *
 * @internal
 */
final class PaymentQrSettingsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    private int $adminUserId;
    private int $approverUserId;
    private string $walletA;
    private string $walletB;

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
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled = false;
        $platform->commissionWithdrawalEnabled = false;
        $platform->commissionBlockchainPayoutEnabled = false;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;
        $this->approverUserId = $this->makeUser('qrapr', 'finance_approver');
        $this->walletA = '0x' . str_repeat('a', 40);
        $this->walletB = '0x' . str_repeat('b', 40);

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
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . abs(crc32('qr' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'qr' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'QR ' . $suffix,
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'qr' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);
        $profile = model(MemberProfileModel::class)->findByUserId((int) $result['user']->id);

        return [
            'user_id' => (int) $result['user']->id,
            'profile' => $profile,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function createLiveNetwork(string $keySuffix, bool $enabled = true): array
    {
        return service('usdtPaymentNetworkService')->create([
            'network_key'            => 'qr' . $keySuffix . bin2hex(random_bytes(2)),
            'network_name'           => 'QR Live ' . $keySuffix,
            'verifier_type'          => 'evm_erc20',
            'chain_id'               => 56,
            'token_contract_address' => '0x' . str_repeat('1', 40),
            'destination_wallet'     => $this->walletA,
            'is_enabled'             => $enabled ? 1 : 0,
            'display_status'         => $enabled ? 'live' : 'hidden',
            'min_deposit_usdt'       => '5',
            'max_deposit_usdt'       => '100000',
            'instructions'           => 'Send USDT BEP20 only',
        ], $this->adminUserId);
    }

    private function fakeUpload(string $contents, string $name, string $mime): FakeQrUploadedFile
    {
        $path = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . 'tmp_qr_' . bin2hex(random_bytes(4)) . '_' . $name;
        $dir  = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);

        return new FakeQrUploadedFile($path, $name, $mime, strlen($contents), UPLOAD_ERR_OK);
    }

    public function testGuestCannotAccessPaymentQrMedia(): void
    {
        $network = $this->createLiveNetwork('guest');
        $generated = service('paymentQrService')->generateFromWallet($this->walletA, 'qr_test');
        service('usdtPaymentNetworkService')->update((int) $network['id'], [
            'destination_wallet' => $this->walletA,
            'network_name'       => $network['network_name'],
            'is_enabled'         => 1,
            'display_status'     => 'live',
            'qr_stored_name'     => $generated['stored_name'],
            'qr_mime'            => $generated['mime'],
            'qr_source'          => $generated['source'],
            'qr_decoded_payload' => $generated['decoded_payload'],
            'qr_verified_at'     => date('Y-m-d H:i:s'),
            'min_deposit_usdt'   => '5',
            'max_deposit_usdt'   => '100000',
            'verifier_type'      => 'evm_erc20',
            'chain_id'           => 56,
            'token_contract_address' => '0x' . str_repeat('1', 40),
        ], $this->adminUserId);

        $result = $this->get('media/payment-qr/' . $network['network_key']);
        // Session filter redirects guests; controller also refuses unauthenticated access.
        $this->assertTrue(in_array($result->response()->getStatusCode(), [302, 401, 403], true));
    }

    public function testInvalidFileRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $file = $this->fakeUpload('%PDF-1.4 fake', 'notimage.pdf', 'application/pdf');
        service('paymentQrService')->storeUploadedQr($file, $this->walletA);
    }

    public function testUndecodableImageRejected(): void
    {
        // Minimal valid PNG that is not a QR
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $this->expectException(\InvalidArgumentException::class);
        $file = $this->fakeUpload((string) $png, 'blank.png', 'image/png');
        service('paymentQrService')->storeUploadedQr($file, $this->walletA);
    }

    public function testQrAddressMismatchRejected(): void
    {
        $generated = service('paymentQrService')->generateFromWallet($this->walletA, 'qr_mis');
        $path = service('paymentQrService')->absolutePath($generated['stored_name']);
        $bytes = (string) file_get_contents($path);
        $file = $this->fakeUpload($bytes, 'walletA.png', 'image/png');

        $this->expectException(\InvalidArgumentException::class);
        service('paymentQrService')->storeUploadedQr($file, $this->walletB);
    }

    public function testUnsupportedNetworkPayloadRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        service('paymentQrService')->assertPayloadMatchesWallet('bitcoin:bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', $this->walletA);
    }

    public function testGenerateAndDualApproveAppliesQrWithoutChangingSnapshots(): void
    {
        config('Blockchain')->depositVerifier = 'evm_erc20';

        $network = $this->createLiveNetwork('dual');
        $member = $this->register('snap1');
        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $network['id'],
            '10.00000000',
            '0x' . str_repeat('c', 64),
            'qr-dep-' . bin2hex(random_bytes(3)),
            null
        );
        $snapshot = $dep['deposit']['destination_wallet_snapshot'];

        $proposal = service('paymentDestinationChangeService')->propose([
            'network_id'            => (int) $network['id'],
            'to_destination_wallet' => $this->walletB,
            'instructions'          => 'Updated instructions',
            'display_enabled'       => true,
            'min_deposit_usdt'      => '5',
            'max_deposit_usdt'      => '50000',
            'qr_mode'               => 'generate',
            'currency_code'         => 'USDT',
        ], $this->adminUserId);

        $this->assertSame('pending', $proposal['status']);
        $this->assertSame('generate', $proposal['qr_mode']);
        $this->assertNotEmpty($proposal['qr_staging_stored_name']);

        service('paymentDestinationChangeService')->approve($proposal['proposal_ref'], $this->approverUserId);

        $updated = service('usdtPaymentNetworkService')->find((int) $network['id']);
        $this->assertSame($this->walletB, $updated['destination_wallet']);
        $this->assertNotEmpty($updated['qr_stored_name']);
        $this->assertSame(PaymentQrService::SOURCE_GENERATED, $updated['qr_source']);
        $this->assertNotEmpty($updated['qr_verified_at']);

        $fresh = service('usdtDepositService')->findByRef($dep['deposit']['deposit_ref']);
        $this->assertSame($snapshot, $fresh['destination_wallet_snapshot']);
        $this->assertSame($this->walletA, $fresh['destination_wallet_snapshot']);
    }

    public function testSelfApprovalStillBlocked(): void
    {
        $network = $this->createLiveNetwork('self');
        $proposal = service('paymentDestinationChangeService')->propose([
            'network_id'            => (int) $network['id'],
            'to_destination_wallet' => $this->walletB,
            'qr_mode'               => 'generate',
            'display_enabled'       => true,
        ], $this->adminUserId);

        $this->expectException(\InvalidArgumentException::class);
        service('paymentDestinationChangeService')->approve($proposal['proposal_ref'], $this->adminUserId);
    }

    public function testDemoBurnWalletExcludedFromMemberList(): void
    {
        config('Blockchain')->depositVerifier = 'evm_erc20';

        // Direct insert bypasses create() burn rejection to simulate legacy demo row.
        db_connect()->table('usdt_payment_networks')->insert([
            'network_key'          => 'ngw_demo_' . bin2hex(random_bytes(2)),
            'network_name'         => 'NGW Test EVM (Demo Only)',
            'verifier_type'        => 'evm_erc20',
            'chain_id'             => 56,
            'token_symbol'         => 'USDT',
            'token_decimals'       => 18,
            'token_contract_address' => '0x' . str_repeat('1', 40),
            'destination_wallet'   => '0x000000000000000000000000000000000000dEaD',
            'address_pattern'      => '/^0x[a-fA-F0-9]{40}$/',
            'tx_hash_pattern'      => '/^0x[a-fA-F0-9]{64}$/',
            'required_confirmations' => 12,
            'min_deposit_usdt'     => '5.00000000',
            'max_deposit_usdt'     => '100000.00000000',
            'is_enabled'           => 1,
            'is_demo'              => 1,
            'display_status'       => 'live',
            'sort_order'           => 1,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);

        $live = $this->createLiveNetwork('mem');
        $generated = service('paymentQrService')->generateFromWallet($this->walletA, 'qr_mem');
        service('usdtPaymentNetworkService')->update((int) $live['id'], [
            'destination_wallet'     => $this->walletA,
            'network_name'           => $live['network_name'],
            'is_enabled'             => 1,
            'display_status'         => 'live',
            'qr_stored_name'         => $generated['stored_name'],
            'qr_mime'                => $generated['mime'],
            'qr_source'              => $generated['source'],
            'qr_decoded_payload'     => $generated['decoded_payload'],
            'qr_verified_at'         => date('Y-m-d H:i:s'),
            'min_deposit_usdt'       => '5',
            'max_deposit_usdt'       => '100000',
            'verifier_type'          => 'evm_erc20',
            'chain_id'               => 56,
            'token_contract_address' => '0x' . str_repeat('1', 40),
        ], $this->adminUserId);

        $members = service('usdtPaymentNetworkService')->listEnabledForMembers();
        foreach ($members as $row) {
            $this->assertStringNotContainsStringIgnoringCase('demo', (string) $row['network_name']);
            $this->assertStringNotContainsStringIgnoringCase('dead', (string) $row['destination_wallet']);
            $this->assertFalse(service('paymentQrService')->isBurnOrNullWallet((string) $row['destination_wallet']));
        }
        $this->assertNotEmpty($members);
    }

    public function testVerifierNotConfiguredHidesLiveInstructions(): void
    {
        config('Blockchain')->depositVerifier = 'not_configured';
        $this->createLiveNetwork('novrf');
        $this->assertSame([], service('usdtPaymentNetworkService')->listEnabledForMembers());
    }

    public function testScreenshotEvidenceDoesNotCreditCoins(): void
    {
        config('Blockchain')->depositVerifier = 'evm_erc20';
        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'qr price');
        service('coinInventoryService')->addInventory('1000', $this->adminUserId, 'qr stock', null, 'qr-stock-' . bin2hex(random_bytes(3)));

        $network = $this->createLiveNetwork('nocredit');
        $member = $this->register('nocredit');
        $created = service('coinPurchaseService')->createRequestFromUsdtAmount(
            $member['user_id'],
            '10.00000000',
            null,
            'qr-buy-' . bin2hex(random_bytes(3))
        );

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        $file = $this->fakeUpload((string) $png, 'shot.png', 'image/png');

        $dep = service('usdtDepositService')->submitRequest(
            $member['user_id'],
            (int) $network['id'],
            '10.00000000',
            '0x' . str_repeat('d', 64),
            'qr-ev-' . bin2hex(random_bytes(3)),
            $file
        );

        $this->assertNotSame('verified', $dep['deposit']['status']);
        $purchase = service('coinPurchaseService')->findOwnedByMember($member['user_id'], $created['request']['purchase_ref']);
        $this->assertSame('pending', $purchase['status']);
        $this->assertNotSame('paid', $purchase['payment_status'] ?? 'unpaid');

        $summary = service('memberCoinOwnershipService')->summaryForUser($member['user_id']);
        $this->assertTrue(
            ($summary['available_balance'] ?? '0') === '0.00000000'
            || (float) ($summary['available_balance'] ?? 0) === 0.0
        );
    }

    public function testProposeRejectsDemoNetwork(): void
    {
        $key = 'demo_block_' . bin2hex(random_bytes(2));
        db_connect()->table('usdt_payment_networks')->insert([
            'network_key'          => $key,
            'network_name'         => 'NGW Test EVM (Demo Only)',
            'verifier_type'        => 'evm_erc20',
            'chain_id'             => 56,
            'token_symbol'         => 'USDT',
            'token_decimals'       => 18,
            'token_contract_address' => '0x' . str_repeat('1', 40),
            'destination_wallet'   => '0x000000000000000000000000000000000000dEaD',
            'address_pattern'      => '/^0x[a-fA-F0-9]{40}$/',
            'tx_hash_pattern'      => '/^0x[a-fA-F0-9]{64}$/',
            'required_confirmations' => 12,
            'min_deposit_usdt'     => '5.00000000',
            'max_deposit_usdt'     => '100000.00000000',
            'is_enabled'           => 0,
            'is_demo'              => 1,
            'display_status'       => 'hidden',
            'sort_order'           => 99,
            'created_at'           => date('Y-m-d H:i:s'),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
        $id = (int) db_connect()->insertID();
        $this->assertGreaterThan(0, $id);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Demo');
        service('paymentDestinationChangeService')->propose([
            'network_id'            => $id,
            'to_destination_wallet' => $this->walletA,
            'qr_mode'               => 'generate',
        ], $this->adminUserId);
    }

    public function testMatchingUploadAccepted(): void
    {
        $generated = service('paymentQrService')->generateFromWallet($this->walletA, 'qr_ok');
        $bytes = (string) file_get_contents(service('paymentQrService')->absolutePath($generated['stored_name']));
        $file = $this->fakeUpload($bytes, 'match.png', 'image/png');
        $stored = service('paymentQrService')->storeUploadedQr($file, $this->walletA, 'qr_up');
        $this->assertSame(PaymentQrService::SOURCE_UPLOADED, $stored['source']);
        $this->assertNotEmpty($stored['stored_name']);
    }
}

/**
 * Test double: UploadedFile that works outside PHP's is_uploaded_file() SAPI check.
 */
final class FakeQrUploadedFile extends UploadedFile
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
