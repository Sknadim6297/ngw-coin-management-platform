<?php

declare(strict_types=1);

namespace App\Services;

use CodeIgniter\Email\Email;
use CodeIgniter\Shield\Models\UserModel;
use Config\Email as EmailConfig;
use Config\Platform;
use RuntimeException;

class RegistrationEmailVerificationService
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_SENT     = 'sent';
    public const STATUS_FAILED   = 'failed';
    public const STATUS_VERIFIED = 'verified';
    public const STATUS_EXPIRED  = 'expired';

    public function __construct(
        private readonly ?Email $mailer = null,
        private readonly ?EmailConfig $emailConfig = null,
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{sent: bool, throttled: bool}
     */
    public function sendForUser(int $userId): array
    {
        $users = model(UserModel::class);
        $user  = $users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Registration verification user was not found.');
        }

        $email = strtolower(trim((string) $user->getEmail()));
        if ($email === '') {
            throw new RuntimeException('Registration verification email is missing.');
        }

        if ((int) $user->active === 1) {
            return ['sent' => true, 'throttled' => false];
        }

        $db       = db_connect();
        $now      = date('Y-m-d H:i:s');
        $config   = $this->config ?? config('Platform');
        $maxPerHour = max(1, (int) $config->registrationEmailMaxPerHour);
        $ttl        = max(60, (int) $config->registrationEmailTtl);
        $token      = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $emailHash = hash('sha256', $email);
        $tokenId  = null;

        $db->transBegin();
        try {
            $lockedUser = $db->query(
                'SELECT id, active FROM users WHERE id = ? FOR UPDATE',
                [$userId]
            )->getRowArray();
            if ($lockedUser === null) {
                throw new RuntimeException('Registration verification user was not found.');
            }
            if ((int) $lockedUser['active'] === 1) {
                $db->transCommit();

                return ['sent' => true, 'throttled' => false];
            }

            $since = date('Y-m-d H:i:s', time() - 3600);
            $recent = (int) $db->table('registration_email_verification_tokens')
                ->where('user_id', $userId)
                ->where('created_at >=', $since)
                ->countAllResults();
            if ($recent >= $maxPerHour) {
                $db->transCommit();

                return ['sent' => false, 'throttled' => true];
            }

            $db->table('registration_email_verification_tokens')
                ->where('user_id', $userId)
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED])
                ->update([
                    'status'     => self::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);

            $inserted = $db->table('registration_email_verification_tokens')->insert([
                'user_id'    => $userId,
                'token_hash' => $tokenHash,
                'email_hash' => $emailHash,
                'status'     => self::STATUS_PENDING,
                'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            if (! $inserted) {
                throw new RuntimeException('Unable to store registration verification token.');
            }
            $tokenId = (int) $db->insertID();

            if ($db->transStatus() === false) {
                throw new RuntimeException('Unable to store registration verification token.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }

        $mailConfig = $this->emailConfig ?? config('Email');
        $reason     = $this->deliveryConfigurationError($mailConfig);
        $sent       = false;
        $transportException = null;

        if ($reason === null) {
            $mailer = $this->mailer ?? service('email');
            try {
                $mailer->clear();
                $mailer->setTo($email);
                $mailer->setSubject('Verify your NGW email address');
                $mailer->setMailType('html');
                $mailer->setMessage(view('emails/registration_verify', [
                    'verifyUrl' => site_url('verify-email/' . $token),
                    'appName'   => $config->appName,
                    'ttl'       => $ttl,
                ]));
                $sent = $mailer->send(false);
            } catch (\Throwable $e) {
                $transportException = get_class($e);
                $sent = false;
            } finally {
                $mailer->clear();
            }

            if (! $sent) {
                $reason = $transportException === null ? 'transport_failed' : 'transport_exception';
            }
        }

        $status = $sent ? self::STATUS_SENT : self::STATUS_FAILED;
        $updated = $db->table('registration_email_verification_tokens')
            ->where('id', $tokenId)
            ->update([
                'status'     => $status,
                'sent_at'    => $sent ? date('Y-m-d H:i:s') : null,
                'last_error' => $sent ? null : $reason,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        if (! $updated) {
            log_message('error', 'Registration verification delivery status could not be stored for user {user_id}.', [
                'user_id' => $userId,
            ]);

            return ['sent' => false, 'throttled' => false];
        }

        if (! $sent) {
            log_message('error', 'Registration verification email delivery failed for user {user_id}; reason={reason}; exception={exception}.', [
                'user_id'   => $userId,
                'reason'    => $reason,
                'exception' => $transportException ?? 'none',
            ]);
        }

        return ['sent' => $sent, 'throttled' => false];
    }

    public function verify(string $plainToken): bool
    {
        if (! preg_match('/^[a-f0-9]{64}$/i', $plainToken)) {
            return false;
        }

        $db = db_connect();
        $db->transBegin();

        try {
            $tokenHash = hash('sha256', strtolower($plainToken));
            $tokenRef  = $db->table('registration_email_verification_tokens')
                ->select('user_id')
                ->where('token_hash', $tokenHash)
                ->get()
                ->getRowArray();
            if ($tokenRef === null) {
                $db->transRollback();

                return false;
            }

            $db->query(
                'SELECT id FROM users WHERE id = ? FOR UPDATE',
                [(int) $tokenRef['user_id']]
            );
            $row = $db->query(
                'SELECT * FROM registration_email_verification_tokens WHERE token_hash = ? FOR UPDATE',
                [$tokenHash]
            )->getRowArray();
            if ($row === null
                || (string) $row['status'] !== self::STATUS_SENT
                || $row['used_at'] !== null
            ) {
                $db->transRollback();

                return false;
            }

            if (strtotime((string) $row['expires_at']) < time()) {
                $db->table('registration_email_verification_tokens')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'status'     => self::STATUS_EXPIRED,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $db->transCommit();

                return false;
            }

            $users = model(UserModel::class);
            $user  = $users->findById((int) $row['user_id']);
            if ($user === null) {
                $db->transRollback();

                return false;
            }

            $email = strtolower(trim((string) $user->getEmail()));
            if (! hash_equals((string) $row['email_hash'], hash('sha256', $email))) {
                $db->table('registration_email_verification_tokens')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'status'     => self::STATUS_EXPIRED,
                        'updated_at' => date('Y-m-d H:i:s'),
                    ]);
                $db->transCommit();

                return false;
            }

            $now = date('Y-m-d H:i:s');
            $db->table('registration_email_verification_tokens')
                ->where('id', (int) $row['id'])
                ->update([
                    'status'     => self::STATUS_VERIFIED,
                    'used_at'    => $now,
                    'updated_at' => $now,
                ]);
            $db->table('registration_email_verification_tokens')
                ->where('user_id', (int) $row['user_id'])
                ->where('id !=', (int) $row['id'])
                ->whereIn('status', [self::STATUS_PENDING, self::STATUS_SENT, self::STATUS_FAILED])
                ->update([
                    'status'     => self::STATUS_EXPIRED,
                    'updated_at' => $now,
                ]);

            $user->activate();
            $users->save($user);

            if ($db->transStatus() === false) {
                throw new RuntimeException('Email verification could not be completed.');
            }
            $db->transCommit();

            return true;
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    private function deliveryConfigurationError(EmailConfig $email): ?string
    {
        if (strtolower(trim($email->protocol)) !== 'smtp'
            || trim($email->SMTPHost) === ''
            || trim($email->fromEmail) === ''
        ) {
            return 'smtp_not_configured';
        }

        if (in_array(strtolower($email->SMTPAuthMethod), ['login', 'plain'], true)
            && (trim($email->SMTPUser) === '' || trim($email->SMTPPass) === '')
        ) {
            return 'smtp_credentials_not_configured';
        }

        return null;
    }
}
