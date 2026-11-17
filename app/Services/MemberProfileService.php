<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberEmailChangeTokenModel;
use App\Models\MemberProfileModel;
use App\Models\MemberWalletChangeEventModel;
use CodeIgniter\Shield\Models\UserModel;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Secure member self-service profile updates (non-financial fields only).
 *
 * Email identity changes require pending verification of the new address.
 * USDT wallet changes require reauthentication, BEP20 validation, and immutable audit.
 */
class MemberProfileService
{
    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly UserModel $users = new UserModel(),
        private readonly MemberEmailChangeTokenModel $emailTokens = new MemberEmailChangeTokenModel(),
        private readonly MemberWalletChangeEventModel $walletEvents = new MemberWalletChangeEventModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @param array{
     *   member_name?: string,
     *   mobile?: string,
     *   email?: string,
     *   usdt_wallet?: string,
     *   current_password?: string,
     *   confirm_wallet_change?: string|bool
     * } $input
     * @return array{
     *   profile: object,
     *   email: string,
     *   changed: list<string>,
     *   pending_email: ?string,
     *   email_verify_token?: string
     * }
     */
    public function updateOwnProfile(int $memberUserId, array $input, ?string $ip = null, ?string $userAgent = null): array
    {
        $config  = $this->config ?? config('Platform');
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $user = $this->users->findById($memberUserId);
        if ($user === null) {
            throw new InvalidArgumentException('User account not found.');
        }

        $password = (string) ($input['current_password'] ?? '');
        if ($password === '') {
            throw new InvalidArgumentException('Current password is required to save profile changes.');
        }
        if (! service('passwords')->verify($password, (string) $user->password_hash)) {
            throw new InvalidArgumentException('Current password is incorrect.');
        }

        $name   = trim((string) ($input['member_name'] ?? $profile->member_name));
        $mobile = trim((string) ($input['mobile'] ?? $profile->mobile));
        $email  = strtolower(trim((string) ($input['email'] ?? (string) $user->getEmail())));
        $wallet = trim((string) ($input['usdt_wallet'] ?? $profile->usdt_wallet));

        if ($name === '' || mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Full name must be between 2 and 120 characters.');
        }
        if ($mobile === '' || ! preg_match('/^[0-9+\-\s]{8,20}$/', $mobile)) {
            throw new InvalidArgumentException('Enter a valid mobile number.');
        }
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }
        if ($wallet === '' || strlen($wallet) > 255) {
            throw new InvalidArgumentException('USDT wallet address is required.');
        }

        $dupMobile = $this->profiles
            ->where('mobile', $mobile)
            ->where('user_id !=', $memberUserId)
            ->first();
        if ($dupMobile !== null) {
            throw new InvalidArgumentException('This mobile number is already used by another member.');
        }

        $currentEmail = strtolower(trim((string) $user->getEmail()));
        $emailChanging = $email !== $currentEmail;
        if ($emailChanging) {
            $this->assertEmailAvailable($email, $memberUserId);
            $recent = $this->emailTokens->countRecentForUser($memberUserId, 3600);
            if ($recent >= (int) $config->emailChangeMaxPerHour) {
                throw new InvalidArgumentException('Too many email change requests. Try again later.');
            }
        }

        $currentWallet = trim((string) $profile->usdt_wallet);
        $walletChanging = strcasecmp($wallet, $currentWallet) !== 0;
        if ($walletChanging) {
            $confirmed = filter_var($input['confirm_wallet_change'] ?? false, FILTER_VALIDATE_BOOLEAN)
                || in_array(strtolower((string) ($input['confirm_wallet_change'] ?? '')), ['1', 'on', 'yes', 'true'], true);
            if (! $confirmed) {
                throw new InvalidArgumentException('Confirm the USDT wallet change to continue.');
            }
            if (! preg_match($config->bep20Pattern, $wallet)) {
                throw new InvalidArgumentException('USDT wallet must be a valid BEP20 address (0x + 40 hex characters).');
            }
        }

        $changed       = [];
        $profileUpdate = [];
        $pendingEmail  = null;
        $verifyToken   = null;

        if ($name !== (string) $profile->member_name) {
            $profileUpdate['member_name'] = $name;
            $changed[] = 'member_name';
        }
        if ($mobile !== (string) $profile->mobile) {
            $profileUpdate['mobile'] = $mobile;
            $changed[] = 'mobile';
        }

        $db = db_connect();
        $db->transBegin();

        try {
            if ($walletChanging) {
                $cooldownUntil = date('Y-m-d H:i:s', time() + ((int) $config->walletChangeCooldownDays * 86400));
                $profileUpdate['usdt_wallet']               = $wallet;
                $profileUpdate['usdt_wallet_changed_at']    = date('Y-m-d H:i:s');
                $profileUpdate['usdt_wallet_cooldown_until'] = $cooldownUntil;
                $changed[] = 'usdt_wallet';

                $eventId = $this->walletEvents->insert([
                    'user_id'         => $memberUserId,
                    'member_id'       => (string) $profile->member_id,
                    'previous_wallet' => $currentWallet,
                    'new_wallet'      => $wallet,
                    'cooldown_until'  => $cooldownUntil,
                    'ip_address'      => $ip !== null && $ip !== '' ? substr($ip, 0, 45) : null,
                    'user_agent'      => $userAgent !== null && $userAgent !== '' ? substr($userAgent, 0, 255) : null,
                    'created_at'      => date('Y-m-d H:i:s'),
                ], true);
                if ($eventId === false) {
                    throw new RuntimeException('Unable to record wallet change audit.');
                }
            }

            if ($emailChanging) {
                $this->emailTokens->cancelOpenForUser($memberUserId);
                $plain = bin2hex(random_bytes(32));
                $hash  = hash('sha256', $plain);
                $tokenId = $this->emailTokens->insert([
                    'user_id'       => $memberUserId,
                    'member_id'     => (string) $profile->member_id,
                    'current_email' => $currentEmail,
                    'new_email'     => $email,
                    'token_hash'    => $hash,
                    'expires_at'    => date('Y-m-d H:i:s', time() + (int) $config->emailChangeTtl),
                    'created_at'    => date('Y-m-d H:i:s'),
                ], true);
                if ($tokenId === false) {
                    throw new RuntimeException('Unable to create email verification request.');
                }
                $profileUpdate['pending_email'] = $email;
                $changed[] = 'pending_email';
                $pendingEmail = $email;
                $verifyToken  = $plain;
                $this->queueEmailChangeVerification($email, $plain, (string) $profile->member_name, $config);
            }

            if ($profileUpdate !== []) {
                $ok = $this->profiles->update((int) $profile->id, $profileUpdate);
                if ($ok === false) {
                    throw new RuntimeException('Unable to update member profile.');
                }
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Profile update failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        if ($changed !== []) {
            $this->audit->log('member.profile_updated', $memberUserId, 'member_profile', (string) $profile->member_id, [
                'changed'       => $changed,
                'pending_email' => $pendingEmail,
            ]);
            if ($walletChanging) {
                $this->audit->log('member.wallet_changed', $memberUserId, 'member_profile', (string) $profile->member_id, [
                    'cooldown_days' => (int) $config->walletChangeCooldownDays,
                ]);
            }
            if ($emailChanging) {
                $this->audit->log('member.email_change_requested', $memberUserId, 'member_profile', (string) $profile->member_id, [
                    'new_email' => $email,
                ]);
            }
        }

        $fresh     = $this->profiles->findByUserId($memberUserId);
        $freshUser = $this->users->findById($memberUserId);
        $payload   = [
            'profile'       => $fresh ?? $profile,
            'email'         => (string) ($freshUser?->getEmail() ?? $currentEmail),
            'changed'       => $changed,
            'pending_email' => $pendingEmail ?? ($fresh->pending_email ?? null),
        ];
        if (ENVIRONMENT === 'testing' && $verifyToken !== null) {
            $payload['email_verify_token'] = $verifyToken;
        }

        return $payload;
    }

    /**
     * Verify pending email with single-use token and switch Shield identity.
     *
     * @return array{email: string, member_id: string}
     */
    public function confirmEmailChange(string $plainToken): array
    {
        $hash = hash('sha256', $plainToken);
        $row  = $this->emailTokens->findValidByHash($hash);
        if ($row === null) {
            throw new InvalidArgumentException('Email verification link is invalid or expired.');
        }

        $userId  = (int) $row['user_id'];
        $newEmail = strtolower(trim((string) $row['new_email']));
        $this->assertEmailAvailable($newEmail, $userId);

        $profile = $this->profiles->findByUserId($userId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $updated = $db->table('auth_identities')
                ->where('user_id', $userId)
                ->where('type', 'email_password')
                ->update([
                    'secret'     => $newEmail,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($updated === false) {
                throw new RuntimeException('Unable to update account email.');
            }

            $this->emailTokens->update((int) $row['id'], [
                'used_at' => date('Y-m-d H:i:s'),
            ]);
            $this->emailTokens->cancelOpenForUser($userId);

            $ok = $this->profiles->update((int) $profile->id, [
                'pending_email' => null,
            ]);
            if ($ok === false) {
                throw new RuntimeException('Unable to clear pending email.');
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Email confirmation failed.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        $this->audit->log('member.email_change_confirmed', $userId, 'member_profile', (string) $profile->member_id, [
            'email' => $newEmail,
        ]);

        return [
            'email'     => $newEmail,
            'member_id' => (string) $profile->member_id,
        ];
    }

    public function cancelPendingEmailChange(int $memberUserId): void
    {
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            throw new InvalidArgumentException('Member profile not found.');
        }

        $this->emailTokens->cancelOpenForUser($memberUserId);
        $this->profiles->update((int) $profile->id, ['pending_email' => null]);
        $this->audit->log('member.email_change_cancelled', $memberUserId, 'member_profile', (string) $profile->member_id);
    }

    /**
     * Destination cooldown for future withdrawal activation (not enforced for live payouts yet).
     */
    public function walletCooldownUntil(int $memberUserId): ?string
    {
        $profile = $this->profiles->findByUserId($memberUserId);
        if ($profile === null) {
            return null;
        }
        $until = $profile->usdt_wallet_cooldown_until ?? null;

        return $until !== null && $until !== '' ? (string) $until : null;
    }

    private function assertEmailAvailable(string $email, int $exceptUserId): void
    {
        $dupEmail = db_connect()->table('auth_identities')
            ->where('type', 'email_password')
            ->where('secret', $email)
            ->where('user_id !=', $exceptUserId)
            ->countAllResults();
        if ($dupEmail > 0) {
            throw new InvalidArgumentException('This email address is already used by another account.');
        }

        $pendingDup = $this->emailTokens
            ->where('new_email', $email)
            ->where('used_at', null)
            ->where('cancelled_at', null)
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->where('user_id !=', $exceptUserId)
            ->countAllResults();
        if ($pendingDup > 0) {
            throw new InvalidArgumentException('This email address is already pending verification on another account.');
        }
    }

    private function queueEmailChangeVerification(string $newEmail, string $plainToken, string $memberName, Platform $config): void
    {
        $verifyUrl = site_url('confirm-email-change/' . $plainToken);
        $emailService = service('email');
        $emailService->setTo($newEmail);
        $emailService->setSubject('Confirm your new email address');
        $emailService->setMessage(view('emails/email_change_verify', [
            'verifyUrl'  => $verifyUrl,
            'appName'    => $config->appName,
            'memberName' => $memberName,
        ]));
        @$emailService->send();
    }
}
