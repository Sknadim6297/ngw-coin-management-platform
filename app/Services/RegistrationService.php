<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;
use App\Models\SponsorRelationshipModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

class RegistrationService
{
    public function __construct(
        private readonly MemberIdGenerator $memberIds = new MemberIdGenerator(),
        private readonly SponsorValidator $sponsors = new SponsorValidator(),
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly SponsorRelationshipModel $relationships = new SponsorRelationshipModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @param array{
     *   member_name: string,
     *   sponsor_id: string,
     *   mobile: string,
     *   email: string,
     *   usdt_wallet: string,
     *   password: string
     * } $data
     * @return array{user: User, profile: object, member_id: string, verification_email_sent: bool}
     */
    public function register(array $data, ?int $actorUserId = null, string $source = 'public'): array
    {
        $config = $this->config ?? config('Platform');

        if ($source === 'public') {
            $settings = service('platformSettingsService');
            if (! $settings->bool('registration_enabled')) {
                throw new InvalidArgumentException('Public registration is currently disabled.');
            }

            $sponsorId = trim((string) ($data['sponsor_id'] ?? ''));
            if ($sponsorId === '' && ! $settings->bool('sponsor_required')) {
                $data['sponsor_id'] = (string) $config->rootSponsorId;
            } elseif ($sponsorId === '' && $settings->bool('sponsor_required')) {
                throw new InvalidArgumentException('Sponsor ID is required.');
            }
        }

        $sponsorResult = $this->sponsors->validate($data['sponsor_id']);
        if (! $sponsorResult['valid']) {
            throw new InvalidArgumentException($sponsorResult['message']);
        }

        /** @var \App\Entities\MemberProfile $sponsor */
        $sponsor = $sponsorResult['profile'];

        $users = model(UserModel::class);
        $existingEmail = $users->findByCredentials(['email' => $data['email']]);
        if ($existingEmail !== null) {
            throw new InvalidArgumentException('An account with this email already exists.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $memberId = $this->memberIds->generate();

            // Ensure generated ID never collides with configured root sponsor ID.
            if ($memberId === $config->rootSponsorId) {
                $memberId = $this->memberIds->generate();
            }

            // Explicit self-sponsorship guard (IDs should never collide, but keep invariant).
            if (strcasecmp($memberId, (string) $sponsor->member_id) === 0) {
                throw new InvalidArgumentException('A member cannot sponsor themselves.');
            }

            if ((int) $sponsor->user_id <= 0) {
                throw new InvalidArgumentException('Sponsor ID was not found.');
            }

            $user = new User([
                'username' => $memberId,
                'active'   => 0,
            ]);
            $users->save($user);
            $user = $users->findById($users->getInsertID());

            // Circular / self edge cannot exist for a brand-new user; still assert before insert.
            if ((int) $user->id === (int) $sponsor->user_id
                || service('referralTeamService')->wouldCreateCycle((int) $user->id, (int) $sponsor->user_id)
            ) {
                throw new InvalidArgumentException('Invalid sponsor relationship: self or circular sponsorship is not allowed.');
            }

            $user->createEmailIdentity([
                'email'    => $data['email'],
                'password' => $data['password'],
            ]);

            $user->addGroup('member');

            $profileId = $this->profiles->insert([
                'user_id'         => $user->id,
                'member_id'       => $memberId,
                'member_name'     => $data['member_name'],
                'mobile'          => $data['mobile'],
                'usdt_wallet'     => $data['usdt_wallet'],
                'account_status'  => 'inactive',
                'is_root_sponsor' => 0,
            ], true);

            if ($profileId === false) {
                throw new RuntimeException('Unable to create member profile.');
            }

            $this->relationships->insert([
                'member_user_id'    => $user->id,
                'sponsor_user_id'   => $sponsor->user_id,
                'sponsor_member_id' => $sponsor->member_id,
            ]);

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Registration failed.');
            }

            $db->transCommit();

            $profile = $this->profiles->find($profileId);
            try {
                $verification = service('registrationEmailVerificationService')->sendForUser((int) $user->id);
            } catch (RuntimeException) {
                log_message('error', 'Registration verification could not be initiated for user {user_id}.', [
                    'user_id' => (int) $user->id,
                ]);
                $verification = ['sent' => false, 'throttled' => false];
            }

            $this->audit->log(
                $source === 'admin' ? 'admin.member_created' : 'member.registered',
                $actorUserId ?? $user->id,
                'member_profile',
                (string) $memberId,
                [
                    'sponsor_id' => $sponsor->member_id,
                    'source'     => $source,
                ]
            );

            return [
                'user'      => $user,
                'profile'   => $profile,
                'member_id' => $memberId,
                'verification_email_sent' => $verification['sent'],
            ];
        } catch (\Throwable $e) {
            if ($db->transStatus() !== false) {
                $db->transRollback();
            }

            throw $e;
        }
    }
}
