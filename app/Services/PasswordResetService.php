<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PasswordResetTokenModel;
use CodeIgniter\Shield\Models\UserModel;
use Config\Platform;

class PasswordResetService
{
    public function __construct(
        private readonly PasswordResetTokenModel $tokens = new PasswordResetTokenModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * Always returns a generic result so callers cannot detect email existence.
     *
     * @return array{queued: bool, token?: string}
     */
    public function requestReset(string $email): array
    {
        $config = $this->config ?? config('Platform');
        $users  = model(UserModel::class);
        $user   = $users->findByCredentials(['email' => $email]);

        if ($user === null) {
            return ['queued' => true];
        }

        // Invalidate previous unused tokens for this user.
        $this->tokens->where('user_id', $user->id)
            ->where('used_at', null)
            ->set(['used_at' => date('Y-m-d H:i:s')])
            ->update();

        $plain = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $plain);

        $this->tokens->insert([
            'user_id'    => $user->id,
            'email'      => $email,
            'token_hash' => $hash,
            'expires_at' => date('Y-m-d H:i:s', time() + $config->passwordResetTtl),
        ]);

        $resetUrl = site_url('reset-password/' . $plain);

        $emailService = service('email');
        $emailService->setTo($email);
        $emailService->setSubject('Password reset request');
        $emailService->setMessage(view('emails/password_reset', [
            'resetUrl' => $resetUrl,
            'appName'  => $config->appName,
        ]));
        @$emailService->send();

        $this->audit->log('auth.password_reset_requested', (int) $user->id, 'user', (string) $user->id);

        // Token returned only for testing environments.
        $payload = ['queued' => true];
        if (ENVIRONMENT === 'testing') {
            $payload['token'] = $plain;
        }

        return $payload;
    }

    public function resetPassword(string $plainToken, string $password): bool
    {
        $hash = hash('sha256', $plainToken);
        $row  = $this->tokens->findValidByHash($hash);

        if ($row === null) {
            return false;
        }

        $users = model(UserModel::class);
        $user  = $users->findById((int) $row['user_id']);

        if ($user === null) {
            return false;
        }

        $user->fill([
            'password' => $password,
        ]);
        $users->save($user);

        $this->tokens->update($row['id'], [
            'used_at' => date('Y-m-d H:i:s'),
        ]);

        $this->audit->log('auth.password_reset_completed', (int) $user->id, 'user', (string) $user->id);

        return true;
    }
}
