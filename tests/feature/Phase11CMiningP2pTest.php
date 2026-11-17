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
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 11C focused mining P2P tests (execution remains OFF).
 *
 * @internal
 */
final class Phase11CMiningP2pTest extends CIUnitTestCase
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

        $platform                                    = config('Platform');
        $platform->adminEmail                        = 'admin@test.local';
        $platform->adminPassword                     = 'AdminPass123!';
        $platform->adminName                         = 'Test Admin';
        $platform->rootSponsorId                     = 'ROOT0001';
        $platform->memberIdPrefix                    = 'CPM';
        $platform->memberIdStartNumber = 1;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->mandatoryPurchaseLockEnabled      = true;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
        $this->adminUserId = (int) model(MemberProfileModel::class)->findByMemberId('ROOT0001')->user_id;

        service('coinPriceService')->updatePrice('1.00', $this->adminUserId, 'p11c price');
        service('coinInventoryService')->addInventory(
            '20000',
            $this->adminUserId,
            'p11c stock',
            null,
            'p11c-stock-' . bin2hex(random_bytes(3))
        );

        if (auth()->loggedIn()) {
            auth()->logout();
        }
        service('impersonationService')->setActiveContext([]);
        // clear empty context → use null via reflection-safe reset
        $ref = new \ReflectionClass(service('impersonationService'));
        $prop = $ref->getProperty('activeContext');
        $prop->setAccessible(true);
        $prop->setValue(service('impersonationService'), null);
    }

    protected function tearDown(): void
    {
        if (auth()->loggedIn()) {
            auth()->logout();
        }
        $platform = config('Platform');
        $platform->miningP2pEnabled     = false;
        $platform->miningPostingEnabled = false;
        parent::tearDown();
    }

    /**
     * @return array{user_id: int, member_id: string, password: string}
     */
    private function register(string $suffix, string $sponsor = 'ROOT0001'): array
    {
        $digits = preg_replace('/\D/', '', (string) crc32($suffix) . (string) abs(crc32('p11c' . $suffix)));
        $hex    = str_pad(substr(hash('sha256', 'p11c-' . $suffix), 0, 40), 40, '0');
        $result = service('registrationService')->register([
            'member_name' => 'P11C ' . $suffix,
            'sponsor_id'  => $sponsor,
            'mobile'      => '17' . substr($digits . '00000000', 0, 8),
            'email'       => 'p11c' . $suffix . '@example.com',
            'usdt_wallet' => '0x' . $hex,
            'password'    => 'Password123!',
        ]);

        return [
            'user_id'   => (int) $result['user']->id,
            'member_id' => (string) $result['member_id'],
            'password'  => 'Password123!',
        ];
    }

    private function activate(array $member): void
    {
        $req = service('coinPurchaseService')->createRequest(
            $member['user_id'],
            '10',
            null,
            'p11c-act-' . $member['user_id'] . '-' . bin2hex(random_bytes(2))
        );
        service('coinPurchaseService')->approveRequest(
            $req['request']['purchase_ref'],
            $this->adminUserId,
            true,
            'p11c'
        );
    }

    private function seedPostedMining(int $userId, string $memberId, string $amount): void
    {
        $svc = service('miningWalletService');
        $svc->ensureWallet($userId, $memberId, 'COIN');
        db_connect()->table('mining_wallets')
            ->where('member_user_id', $userId)
            ->where('asset_code', 'COIN')
            ->update([
                'available_balance' => MoneyMath::normalize($amount, 8),
                'total_credited'    => MoneyMath::normalize($amount, 8),
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
    }

    private function loginAs(int $userId): void
    {
        $user = model(UserModel::class)->findById($userId);
        $this->assertNotNull($user);
        auth()->login($user);
    }

    public function testValidReceiverLookup(): void
    {
        $a = $this->register('looka');
        $b = $this->register('lookb');
        $this->activate($a);
        $this->activate($b);

        $recv = service('miningP2pTransferService')->lookupReceiver($b['member_id'], $a['user_id']);
        $this->assertSame($b['member_id'], $recv['member_id']);
        $this->assertArrayHasKey('member_name', $recv);
        $this->assertArrayNotHasKey('email', $recv);
        $this->assertArrayNotHasKey('mobile', $recv);
    }

    public function testInvalidReceiverRejected(): void
    {
        $a = $this->register('invrec');
        $this->expectException(InvalidArgumentException::class);
        service('miningP2pTransferService')->lookupReceiver('NOPE9999', $a['user_id']);
    }

    public function testSelfTransferRejected(): void
    {
        $a = $this->register('selfx');
        $this->activate($a);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Self-transfer');
        service('miningP2pTransferService')->lookupReceiver($a['member_id'], $a['user_id']);
    }

    public function testZeroAmountRejected(): void
    {
        $a = $this->register('zeroa');
        $b = $this->register('zerob');
        $this->activate($a);
        $this->activate($b);
        $this->expectException(InvalidArgumentException::class);
        service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '0');
    }

    public function testNegativeAmountRejected(): void
    {
        $a = $this->register('nega');
        $b = $this->register('negb');
        $this->activate($a);
        $this->activate($b);
        $this->expectException(InvalidArgumentException::class);
        service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '-1.5');
    }

    public function testInsufficientBalanceRejected(): void
    {
        $a = $this->register('insufa');
        $b = $this->register('insufb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '1.00000000');

        $preview = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '5.00000000');
        $this->assertFalse($preview['sufficient']);
        $this->assertContains('insufficient_posted_balance', $preview['blockers']);
    }

    public function testSimulationBalanceNotTransferable(): void
    {
        $a = $this->register('sima');
        $b = $this->register('simb');
        $this->activate($a);
        $this->activate($b);

        // Simulation never credits mining_wallets — available must stay zero.
        $summary = service('miningP2pTransferService')->memberWalletSummary($a['user_id']);
        $this->assertSame(0, MoneyMath::compare($summary['available_balance'], '0.00000000', 8));

        $preview = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '0.10000000');
        $this->assertFalse($preview['sufficient']);
        $this->assertFalse($preview['can_execute']);
    }

    public function testCommissionWalletNotTransferable(): void
    {
        $a = $this->register('coma');
        $b = $this->register('comb');
        $this->activate($a);
        $this->activate($b);

        if (db_connect()->tableExists('commission_wallets')) {
            service('commissionWalletService')->ensureAccount($a['user_id'], $a['member_id']);
            db_connect()->table('commission_wallets')
                ->where('member_user_id', $a['user_id'])
                ->update(['available_balance' => '100.00000000']);
        }

        $preview = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '1.00000000');
        $this->assertSame(0, MoneyMath::compare($preview['available_balance'], '0.00000000', 8));
        $this->assertFalse($preview['sufficient']);
    }

    public function testCoinOwnershipWalletNotTransferable(): void
    {
        $a = $this->register('owna');
        $b = $this->register('ownb');
        $this->activate($a);
        $this->activate($b);

        $ownership = service('memberCoinOwnershipService')->summaryForUser($a['user_id']);
        $this->assertSame(1, MoneyMath::compare((string) $ownership['balance'], '0', 8));

        $preview = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '1.00000000');
        $this->assertSame(0, MoneyMath::compare($preview['available_balance'], '0.00000000', 8));
    }

    public function testMiningWalletAssetConsistency(): void
    {
        $svc = service('miningP2pTransferService');
        $this->assertSame(service('miningWalletService')->assetCode(), $svc->assetCode());
        $status = $svc->status();
        $this->assertFalse($status['asset_approved']);
        $this->assertFalse($status['execution_authorized']);
    }

    public function testDisabledP2pBlocksExecution(): void
    {
        $a = $this->register('offa');
        $b = $this->register('offb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '10.00000000');
        $this->loginAs($a['user_id']);

        $this->assertFalse(service('miningP2pTransferService')->enabled());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('kill-switch');
        service('miningP2pTransferService')->execute(
            $a['user_id'],
            $b['member_id'],
            '1.00000000',
            $a['password'],
            'p11c-off-' . $a['user_id']
        );
    }

    public function testDuplicateTransferIdempotency(): void
    {
        $a = $this->register('idema');
        $b = $this->register('idemb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '10.00000000');
        $this->loginAs($a['user_id']);

        $key = 'p11c-idem-' . $a['user_id'];
        try {
            service('miningP2pTransferService')->execute(
                $a['user_id'],
                $b['member_id'],
                '1.00000000',
                $a['password'],
                $key
            );
            $this->fail('Expected kill-switch block');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kill-switch', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('idempotency');
        service('miningP2pTransferService')->execute(
            $a['user_id'],
            $b['member_id'],
            '1.00000000',
            $a['password'],
            $key
        );
    }

    public function testAtomicDebitCreditArchitecture(): void
    {
        $a = $this->register('atma');
        $b = $this->register('atmb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '8.00000000');
        service('miningWalletService')->ensureWallet($b['user_id'], $b['member_id'], 'COIN');

        $platform = config('Platform');
        $platform->miningP2pEnabled = true;

        $preview = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '3.50000000');
        $row = service('miningP2pTransferService')->performAtomicTransfer(
            $preview,
            'p11c-atomic-' . $a['user_id'],
            $a['user_id']
        );

        $this->assertSame('completed', $row['status']);
        $this->assertSame(0, MoneyMath::compare((string) $row['amount'], '3.50000000', 8));

        $senderBal = service('miningWalletService')->balanceFor($a['user_id']);
        $recvBal   = service('miningWalletService')->balanceFor($b['user_id']);
        $this->assertSame(0, MoneyMath::compare($senderBal['available_balance'], '4.50000000', 8));
        $this->assertSame(0, MoneyMath::compare($recvBal['available_balance'], '3.50000000', 8));

        $debit = db_connect()->table('mining_wallet_ledger')
            ->where('reference_id', $row['transfer_ref'])
            ->where('entry_type', 'p2p_debit')
            ->countAllResults();
        $credit = db_connect()->table('mining_wallet_ledger')
            ->where('reference_id', $row['transfer_ref'])
            ->where('entry_type', 'p2p_credit')
            ->countAllResults();
        $this->assertSame(1, $debit);
        $this->assertSame(1, $credit);

        $platform->miningP2pEnabled = false;
    }

    public function testConcurrentTransferSafetySequentialLocks(): void
    {
        $a = $this->register('cona');
        $b = $this->register('conb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '5.00000000');
        service('miningWalletService')->ensureWallet($b['user_id'], $b['member_id'], 'COIN');

        $platform = config('Platform');
        $platform->miningP2pEnabled = true;

        $p1 = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '3.00000000');
        service('miningP2pTransferService')->performAtomicTransfer($p1, 'p11c-con-1-' . $a['user_id'], $a['user_id']);

        $p2 = service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '3.00000000');
        $this->assertFalse($p2['sufficient']);

        $this->expectException(InvalidArgumentException::class);
        service('miningP2pTransferService')->performAtomicTransfer($p2, 'p11c-con-2-' . $a['user_id'], $a['user_id']);
    }

    public function testMemberOwnershipRestrictionsRejectRoot(): void
    {
        $a = $this->register('roota');
        $this->activate($a);
        $this->expectException(InvalidArgumentException::class);
        service('miningP2pTransferService')->lookupReceiver('ROOT0001', $a['user_id']);
    }

    public function testAdminImpersonationCannotTransfer(): void
    {
        $a = $this->register('impa');
        $b = $this->register('impb');
        $this->activate($a);
        $this->activate($b);

        service('impersonationService')->setActiveContext([
            'admin_user_id'    => $this->adminUserId,
            'target_user_id'   => $a['user_id'],
            'target_member_id' => $a['member_id'],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('support-view');
        service('miningP2pTransferService')->preview($a['user_id'], $b['member_id'], '1.00000000');
    }

    public function testAuditEventGeneration(): void
    {
        $a = $this->register('audita');
        $b = $this->register('auditb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '2.00000000');
        $this->loginAs($a['user_id']);

        try {
            service('miningP2pTransferService')->execute(
                $a['user_id'],
                $b['member_id'],
                '1.00000000',
                $a['password'],
                'p11c-audit-' . $a['user_id']
            );
        } catch (RuntimeException) {
            // expected kill-switch
        }

        $row = db_connect()->table('audit_logs')
            ->where('action', 'mining.p2p_denied')
            ->where('actor_user_id', $a['user_id'])
            ->orderBy('id', 'DESC')
            ->get()
            ->getRowArray();
        $this->assertNotNull($row);
    }

    public function testReconciliationDetectsMismatches(): void
    {
        $a = $this->register('reca');
        $b = $this->register('recb');
        $this->activate($a);
        $this->activate($b);

        db_connect()->table('mining_p2p_transfers')->insert([
            'transfer_ref'       => 'MPT-TEST-ORPHAN1',
            'sender_user_id'     => $a['user_id'],
            'sender_member_id'   => $a['member_id'],
            'receiver_user_id'   => $b['user_id'],
            'receiver_member_id' => $b['member_id'],
            'asset_code'         => 'COIN',
            'amount'             => '1.00000000',
            'status'             => 'completed',
            'idempotency_key'    => 'p11c-orphan-' . $a['user_id'],
            'created_at'         => date('Y-m-d H:i:s'),
            'completed_at'       => date('Y-m-d H:i:s'),
            'created_by'         => $a['user_id'],
        ]);

        $report = service('miningP2pTransferService')->reconcile(50);
        $types  = array_column($report['mismatches'], 'type');
        $this->assertContains('completed_missing_ledger_pair', $types);
    }

    public function testNoActualWalletWritesDuringDryRun(): void
    {
        $a = $this->register('drya');
        $b = $this->register('dryb');
        $this->activate($a);
        $this->activate($b);
        $this->seedPostedMining($a['user_id'], $a['member_id'], '7.00000000');
        $this->loginAs($a['user_id']);

        $before = service('miningWalletService')->balanceFor($a['user_id']);
        try {
            service('miningP2pTransferService')->execute(
                $a['user_id'],
                $b['member_id'],
                '2.00000000',
                $a['password'],
                'p11c-dry-' . $a['user_id']
            );
        } catch (RuntimeException) {
        }
        $after = service('miningWalletService')->balanceFor($a['user_id']);
        $this->assertSame($before['available_balance'], $after['available_balance']);
        $this->assertSame($before['total_debited'], $after['total_debited']);
    }

    public function testExistingMiningAndCommissionControlsRemainOff(): void
    {
        $cfg = config('Platform');
        $this->assertFalse($cfg->miningPostingEnabled);
        $this->assertFalse($cfg->miningP2pEnabled);
        $this->assertFalse($cfg->commissionPostingEnabled);
        $this->assertFalse($cfg->commissionWithdrawalEnabled);
        $this->assertFalse($cfg->commissionBlockchainPayoutEnabled);

        $status = service('miningP2pTransferService')->status();
        $this->assertFalse($status['mining_p2p_enabled']);
        $this->assertFalse($status['mining_posting_enabled']);
        $this->assertFalse($status['execution_authorized']);
    }

    public function testMemberP2pPageShowsDisabledState(): void
    {
        $a = $this->register('uipage');
        $this->activate($a);
        $this->post('login', [
            'member_id' => $a['member_id'],
            'password'  => $a['password'],
        ])->assertRedirect();

        $page = $this->withSession()->get('member/p2p');
        $page->assertOK();
        $page->assertSee('P2P Transfers Not Activated');
    }
}
