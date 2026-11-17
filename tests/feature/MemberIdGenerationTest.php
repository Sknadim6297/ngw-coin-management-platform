<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberProfileModel;
use App\Services\SponsorValidator;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Member ID policy: NGW981234, NGW981235, … (existing IDs unchanged).
 *
 * @internal
 */
final class MemberIdGenerationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;

    protected function setUp(): void
    {
        parent::setUp();

        putenv('platform.adminEmail=admin@test.local');
        putenv('platform.adminPassword=AdminPass123!');
        putenv('platform.adminName=Test Admin');
        putenv('platform.rootSponsorId=ROOT0001');
        putenv('platform.memberIdPrefix=NGW');
        putenv('platform.memberIdStartNumber=981234');

        $platform                      = config('Platform');
        $platform->adminEmail          = 'admin@test.local';
        $platform->adminPassword       = 'AdminPass123!';
        $platform->adminName           = 'Test Admin';
        $platform->rootSponsorId       = 'ROOT0001';
        $platform->memberIdPrefix      = 'NGW';
        $platform->memberIdPadding     = 6;
        $platform->memberIdStartNumber = 981234;

        $this->seed(\App\Database\Seeds\RootSponsorSeeder::class);
    }

    public function testFirstMemberIdIsNgw981234(): void
    {
        $generator = service('memberIdGenerator');

        $this->assertSame('NGW981234', $generator->previewNext());
        $this->assertSame('NGW981234', $generator->generate());
        $this->assertSame('NGW981235', $generator->previewNext());
        $this->assertSame('NGW981235', $generator->generate());
        $this->assertSame('NGW981236', $generator->generate());
    }

    public function testExistingLowNgwIdUnchangedAndNextUsesStartFloor(): void
    {
        $this->plantProfile('NGW000001', 'Legacy Member');
        db_connect()->table('member_id_sequences')->where('prefix', 'NGW')->update([
            'last_number' => 1,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $before = model(MemberProfileModel::class)->findByMemberId('NGW000001');
        $this->assertNotNull($before);
        $this->assertSame('NGW000001', (string) $before->member_id);

        $next = service('memberIdGenerator')->generate();
        $this->assertSame('NGW981234', $next);

        $after = model(MemberProfileModel::class)->findByMemberId('NGW000001');
        $this->assertSame('NGW000001', (string) $after->member_id);
    }

    public function testNextIdFollowsHighestExistingNumericId(): void
    {
        $this->plantProfile('NGW981240', 'High Member');
        db_connect()->table('member_id_sequences')->where('prefix', 'NGW')->update([
            'last_number' => 0,
            'updated_at'  => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame('NGW981241', service('memberIdGenerator')->generate());
    }

    public function testRegistrationAssignsSequentialUniqueIds(): void
    {
        $a = service('registrationService')->register([
            'member_name' => 'Member A',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '1111111111',
            'email'       => 'ngw-a@example.com',
            'usdt_wallet' => '0xaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'password'    => 'Password123!',
        ], null, 'admin');

        $b = service('registrationService')->register([
            'member_name' => 'Member B',
            'sponsor_id'  => 'ROOT0001',
            'mobile'      => '2222222222',
            'email'       => 'ngw-b@example.com',
            'usdt_wallet' => '0xbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'password'    => 'Password123!',
        ], null, 'admin');

        $this->assertSame('NGW981234', $a['member_id']);
        $this->assertSame('NGW981235', $b['member_id']);
        $this->assertNotSame($a['member_id'], $b['member_id']);

        // Sponsor / referral lookup resolves the sequential Member ID.
        $lookup = service('sponsorValidator')->validate('NGW981234');
        $this->assertFalse($lookup['valid']);
        $this->assertSame(SponsorValidator::REASON_INACTIVE, $lookup['reason']);
        $this->assertSame('NGW981234', (string) $lookup['profile']->member_id);
    }

    public function testSequentialGeneratesAreUniqueUnderRepeatedAllocation(): void
    {
        $generator = service('memberIdGenerator');
        $ids       = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = $generator->generate();
        }

        $this->assertSame(['NGW981234', 'NGW981235', 'NGW981236', 'NGW981237', 'NGW981238'], $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function testRootSponsorIdIsNotConsumedAsNumericSequence(): void
    {
        $this->assertNotNull(model(MemberProfileModel::class)->findByMemberId('ROOT0001'));
        $this->assertSame('NGW981234', service('memberIdGenerator')->generate());
    }

    public function testConcurrentStyleAllocationsNeverDuplicate(): void
    {
        // Nested-safe allocations inside one outer transaction still advance uniquely.
        $db = db_connect();
        $db->transBegin();
        $first  = service('memberIdGenerator')->generate();
        $second = service('memberIdGenerator')->generate();
        $db->transCommit();

        $this->assertSame('NGW981234', $first);
        $this->assertSame('NGW981235', $second);
        $this->assertNotSame($first, $second);
    }

    private function plantProfile(string $memberId, string $name): void
    {
        $users = model(UserModel::class);
        $user  = new User([
            'username' => $memberId,
            'active'   => 1,
        ]);
        $users->save($user);
        $userId = (int) $users->getInsertID();

        model(MemberProfileModel::class)->insert([
            'user_id'         => $userId,
            'member_id'       => $memberId,
            'member_name'     => $name,
            'mobile'          => '900000' . substr((string) $userId, -4),
            'usdt_wallet'     => '0x' . str_pad(dechex($userId), 40, '0', STR_PAD_LEFT),
            'account_status'  => 'active',
            'is_root_sponsor' => 0,
        ]);
    }
}
