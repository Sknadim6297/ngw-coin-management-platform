<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use App\Models\MemberProfileModel;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Database\Seeder;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Platform;
use RuntimeException;

/**
 * Creates the Admin + Root Sponsor identity from environment configuration.
 * Does not hardcode production credentials.
 */
class RootSponsorSeeder extends Seeder
{
    public function run(): void
    {
        /** @var Platform $config */
        $config = config('Platform');

        // Prefer Config\Platform values so tests/installers can override safely.
        $email    = trim($config->adminEmail !== '' ? $config->adminEmail : (string) env('platform.adminEmail'));
        $password = $config->adminPassword !== '' ? $config->adminPassword : (string) env('platform.adminPassword');
        $name     = trim($config->adminName !== '' ? $config->adminName : (string) env('platform.adminName', 'Platform Admin'));
        $rootId   = trim($config->rootSponsorId !== '' ? $config->rootSponsorId : (string) env('platform.rootSponsorId', 'ROOT0001'));


        if ($email === '' || $password === '' || $rootId === '') {
            throw new RuntimeException(
                'RootSponsorSeeder requires platform.adminEmail, platform.adminPassword and platform.rootSponsorId in .env'
            );
        }

        if (strlen($password) < 8) {
            throw new RuntimeException('platform.adminPassword must be at least 8 characters.');
        }

        $users    = model(UserModel::class);
        $profiles = model(MemberProfileModel::class);

        $existingProfile = $profiles->findByMemberId($rootId);
        if ($existingProfile !== null) {
            // Informational only — never print during PHPUnit (ENVIRONMENT=testing).
            if ($this->shouldAnnounce()) {
                CLI::write("Root sponsor {$rootId} already exists. Skipping.");
            }

            return;
        }

        $existingUser = $users->findByCredentials(['email' => $email]);
        if ($existingUser !== null) {
            $user = $existingUser;
        } else {
            $user = new User([
                'username' => $rootId,
                'active'   => 1,
            ]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());
            $user->createEmailIdentity([
                'email'    => $email,
                'password' => $password,
            ]);
        }

        if (! $user->inGroup('admin')) {
            $user->addGroup('admin');
        }
        if (! $user->inGroup('member')) {
            $user->addGroup('member');
        }

        // Phase 4E migration strategy: seed root admin with operator+approver finance roles
        // (not finance.* on the admin group). Existing admins should be assigned explicitly.
        foreach (['finance_operator', 'finance_approver'] as $financeGroup) {
            if (! $user->inGroup($financeGroup)) {
                $user->addGroup($financeGroup);
            }
        }

        $linked = $profiles->findByUserId((int) $user->id);
        if ($linked !== null) {
            throw new RuntimeException('Admin user already has a member profile with a different Member ID.');
        }

        $profiles->insert([
            'user_id'         => $user->id,
            'member_id'       => $rootId,
            'member_name'     => $name !== '' ? $name : 'Root Sponsor',
            'mobile'          => '0000000000',
            'usdt_wallet'     => '0x0000000000000000000000000000000000000001',
            'account_status'  => 'active',
            'is_root_sponsor' => 1,
        ]);

        $prefix = strtoupper(trim((string) env('platform.memberIdPrefix', $config->memberIdPrefix))) ?: 'NGW';
        $start  = max(1, (int) ($config->memberIdStartNumber ?? 981234));
        $floor  = $start - 1;
        $exists = $this->db->table('member_id_sequences')->where('prefix', $prefix)->countAllResults();
        if ($exists === 0) {
            $this->db->table('member_id_sequences')->insert([
                'prefix'      => $prefix,
                'last_number' => $floor,
                'created_at'  => date('Y-m-d H:i:s'),
                'updated_at'  => date('Y-m-d H:i:s'),
            ]);
        }

        if ($this->shouldAnnounce()) {
            CLI::write("Root sponsor {$rootId} and admin {$email} seeded successfully.");
        }
    }

    /**
     * Spark/CLI installers may announce success; PHPUnit must stay silent
     * (beStrictAboutOutputDuringTests) without swallowing thrown errors.
     */
    private function shouldAnnounce(): bool
    {
        return is_cli() && ENVIRONMENT !== 'testing';
    }
}
