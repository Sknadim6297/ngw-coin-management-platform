<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;
use CodeIgniter\Shield\Authentication\Authenticators\Session;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

class MemberAuthService
{
    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel()
    ) {
    }

    /**
     * Authenticate a member using Member ID + password.
     *
     * @return array{success: bool, message: string, user?: User}
     */
    public function attempt(string $memberId, string $password, bool $remember = false): array
    {
        $profile = $this->profiles->findByMemberId(trim($memberId));

        if ($profile === null) {
            return [
                'success' => false,
                'message' => 'Invalid Member ID or password.',
            ];
        }

        /** @var UserModel $users */
        $users = auth()->getProvider();
        $user  = $users->findById($profile->user_id);

        if ($user === null || ! $user->inGroup('member', 'admin')) {
            return [
                'success' => false,
                'message' => 'Invalid Member ID or password.',
            ];
        }
        if ((int) $user->active !== 1) {
            return [
                'success' => false,
                'message' => 'Invalid Member ID or password.',
            ];
        }

        /** @var Session $authenticator */
        $authenticator = auth('session')->getAuthenticator();

        if ($remember) {
            $authenticator->remember(true);
        }

        $result = $authenticator->attempt([
            'email'    => $user->getEmail(),
            'password' => $password,
        ]);

        if (! $result->isOK()) {
            return [
                'success' => false,
                'message' => 'Invalid Member ID or password.',
            ];
        }

        return [
            'success' => true,
            'message' => 'Login successful.',
            'user'    => $result->extraInfo(),
        ];
    }
}
