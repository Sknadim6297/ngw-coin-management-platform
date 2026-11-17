<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\MemberProfileModel;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 10D secure admin→member support view.
 * Keeps Shield admin session intact; uses path-scoped /support-view cookie context.
 */
class ImpersonationService
{
    public const COOKIE_NAME = 'ngw_sv';

    public const GRANT_TTL_SECONDS = 60;

    public const SESSION_MAX_SECONDS = 1800;

    public const IDLE_SECONDS = 900;

    public const RATE_LIMIT_PER_HOUR = 20;

    /** @var array<string, mixed>|null */
    private ?array $activeContext = null;

    public function __construct(
        private readonly MemberProfileModel $profiles = new MemberProfileModel(),
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @param array<string, mixed> $context
     */
    public function setActiveContext(array $context): void
    {
        $this->activeContext = $context;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function activeContext(): ?array
    {
        return $this->activeContext;
    }

    public function cookiePath(): string
    {
        $base = (string) (parse_url((string) config('App')->baseURL, PHP_URL_PATH) ?: '');
        $base = rtrim($base, '/');

        return ($base === '' ? '' : $base) . '/support-view';
    }

    public function canImpersonate(?object $user): bool
    {
        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }
        if (method_exists($user, 'isActivated') && ! $user->isActivated()) {
            return false;
        }

        return (bool) $user->can('admin.members.impersonate');
    }

    /**
     * @return array{grant_ref: string, token: string, expires_at: string, target_member_id: string, target_name: string}
     */
    public function createGrant(int $adminUserId, string $targetMemberId, string $adminPassword): array
    {
        $admin = auth()->getProvider()->findById($adminUserId);
        if ($admin === null || ! $this->canImpersonate($admin)) {
            $this->audit->log('admin.impersonation_denied', $adminUserId, 'member_profile', $targetMemberId, [
                'reason' => 'permission',
            ]);
            throw new InvalidArgumentException('You are not authorized to login as a member.');
        }

        if (! $this->verifyAdminPassword($admin, $adminPassword)) {
            $this->audit->log('admin.impersonation_denied', $adminUserId, 'member_profile', $targetMemberId, [
                'reason' => 'reauth_failed',
            ]);
            throw new InvalidArgumentException('Admin password confirmation failed.');
        }

        $this->assertRateLimit($adminUserId);

        $profile = $this->profiles->findByMemberId(trim($targetMemberId));
        if ($profile === null) {
            $this->audit->log('admin.impersonation_denied', $adminUserId, 'member_profile', $targetMemberId, [
                'reason' => 'not_found',
            ]);
            throw new InvalidArgumentException('Member not found.');
        }

        $this->assertTargetEligible($adminUserId, $profile);

        $raw   = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $raw);
        $ref   = 'AIG-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
        $now   = date('Y-m-d H:i:s');
        $exp   = date('Y-m-d H:i:s', time() + self::GRANT_TTL_SECONDS);
        $req   = service('request');

        db_connect()->table('admin_impersonation_grants')->insert([
            'grant_ref'         => $ref,
            'admin_user_id'     => $adminUserId,
            'target_user_id'    => (int) $profile->user_id,
            'target_member_id'  => (string) $profile->member_id,
            'token_hash'        => $hash,
            'status'            => 'pending',
            'expires_at'        => $exp,
            'ip_address'        => $req->getIPAddress(),
            'user_agent'        => substr((string) $req->getUserAgent(), 0, 255),
            'created_at'        => $now,
        ]);

        $this->audit->log('admin.impersonation_requested', $adminUserId, 'impersonation_grant', $ref, [
            'target_member_id' => $profile->member_id,
            'target_user_id'   => (int) $profile->user_id,
            'session_ref'      => null,
        ]);

        return [
            'grant_ref'         => $ref,
            'token'             => $raw,
            'expires_at'        => $exp,
            'target_member_id'  => (string) $profile->member_id,
            'target_name'       => (string) $profile->member_name,
        ];
    }

    /**
     * Consume single-use grant and create path-scoped support-view session.
     *
     * @return array{session: array<string, mixed>, raw_token: string}
     */
    public function claimGrant(string $rawToken, int $adminUserId): array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '' || strlen($rawToken) < 32) {
            throw new InvalidArgumentException('Invalid impersonation grant.');
        }

        $hash = hash('sha256', $rawToken);
        $db   = db_connect();
        $db->transBegin();

        try {
            $grant = $db->query(
                'SELECT * FROM admin_impersonation_grants WHERE token_hash = ? FOR UPDATE',
                [$hash]
            )->getRowArray();

            if ($grant === null) {
                throw new InvalidArgumentException('Impersonation grant not found.');
            }
            if ((int) $grant['admin_user_id'] !== $adminUserId) {
                $this->audit->log('admin.impersonation_denied', $adminUserId, 'impersonation_grant', (string) $grant['grant_ref'], [
                    'reason' => 'admin_mismatch',
                ]);
                throw new InvalidArgumentException('Impersonation grant does not belong to this admin.');
            }
            if (($grant['status'] ?? '') !== 'pending') {
                throw new InvalidArgumentException('Impersonation grant was already used.');
            }
            if (strtotime((string) $grant['expires_at']) < time()) {
                $db->table('admin_impersonation_grants')->where('id', (int) $grant['id'])->update([
                    'status' => 'expired',
                ]);
                $this->audit->log('admin.impersonation_expired', $adminUserId, 'impersonation_grant', (string) $grant['grant_ref'], []);
                throw new InvalidArgumentException('Impersonation grant expired.');
            }

            $profile = $this->profiles->findByUserId((int) $grant['target_user_id']);
            if ($profile === null) {
                throw new InvalidArgumentException('Target member no longer exists.');
            }
            $this->assertTargetEligible($adminUserId, $profile);

            $now = date('Y-m-d H:i:s');
            $db->table('admin_impersonation_grants')->where('id', (int) $grant['id'])->update([
                'status'      => 'consumed',
                'consumed_at' => $now,
            ]);

            $sessionRaw  = bin2hex(random_bytes(32));
            $sessionHash = hash('sha256', $sessionRaw);
            $sessionRef  = 'AIS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $expiresAt   = date('Y-m-d H:i:s', time() + self::SESSION_MAX_SECONDS);
            $req         = service('request');

            $db->table('admin_impersonation_sessions')->insert([
                'session_ref'       => $sessionRef,
                'grant_ref'         => (string) $grant['grant_ref'],
                'admin_user_id'     => $adminUserId,
                'target_user_id'    => (int) $grant['target_user_id'],
                'target_member_id'  => (string) $grant['target_member_id'],
                'token_hash'        => $sessionHash,
                'status'            => 'active',
                'created_at'        => $now,
                'last_activity_at'  => $now,
                'expires_at'        => $expiresAt,
                'ip_address'        => $req->getIPAddress(),
                'user_agent'        => substr((string) $req->getUserAgent(), 0, 255),
            ]);

            $session = $db->table('admin_impersonation_sessions')
                ->where('session_ref', $sessionRef)
                ->get()
                ->getRowArray();

            $this->audit->log('admin.impersonation_started', $adminUserId, 'impersonation_session', $sessionRef, [
                'target_member_id' => $grant['target_member_id'],
                'target_user_id'   => (int) $grant['target_user_id'],
                'grant_ref'        => $grant['grant_ref'],
            ]);

            $db->transCommit();

            return [
                'session'   => $session ?? [],
                'raw_token' => $sessionRaw,
            ];
        } catch (\Throwable $e) {
            if ($db->transDepth > 0) {
                $db->transRollback();
            }
            throw $e;
        }
    }

    /**
     * @return array{
     *   session_ref: string,
     *   admin_user_id: int,
     *   target_user_id: int,
     *   target_member_id: string,
     *   target_name: string,
     *   read_only: bool
     * }
     */
    public function resolveActiveContext(int $adminUserId, ?string $rawToken): array
    {
        if ($rawToken === null || $rawToken === '') {
            throw new RuntimeException('Support view session missing.');
        }

        $hash = hash('sha256', $rawToken);
        $row  = db_connect()->table('admin_impersonation_sessions')
            ->where('token_hash', $hash)
            ->get()
            ->getRowArray();

        if ($row === null || ($row['status'] ?? '') !== 'active') {
            throw new RuntimeException('Support view session is not active.');
        }
        if ((int) $row['admin_user_id'] !== $adminUserId) {
            throw new RuntimeException('Support view session admin mismatch.');
        }

        $now = time();
        if (strtotime((string) $row['expires_at']) < $now) {
            $this->endSessionByRef((string) $row['session_ref'], 'expired');
            $this->audit->log('admin.impersonation_expired', $adminUserId, 'impersonation_session', (string) $row['session_ref'], []);
            throw new RuntimeException('Support view session expired.');
        }
        if (strtotime((string) $row['last_activity_at']) + self::IDLE_SECONDS < $now) {
            $this->endSessionByRef((string) $row['session_ref'], 'idle');
            $this->audit->log('admin.impersonation_expired', $adminUserId, 'impersonation_session', (string) $row['session_ref'], [
                'reason' => 'idle',
            ]);
            throw new RuntimeException('Support view session idle timeout.');
        }

        if (! $this->canImpersonate(auth()->user())) {
            $this->endSessionByRef((string) $row['session_ref'], 'permission_revoked');
            throw new RuntimeException('Impersonation permission revoked.');
        }

        $profile = $this->profiles->findByUserId((int) $row['target_user_id']);
        if ($profile === null) {
            $this->endSessionByRef((string) $row['session_ref'], 'target_missing');
            throw new RuntimeException('Target member no longer available.');
        }

        try {
            $this->assertTargetEligible($adminUserId, $profile);
        } catch (InvalidArgumentException $e) {
            $this->endSessionByRef((string) $row['session_ref'], 'target_ineligible');
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        db_connect()->table('admin_impersonation_sessions')
            ->where('id', (int) $row['id'])
            ->update(['last_activity_at' => date('Y-m-d H:i:s')]);

        return [
            'session_ref'      => (string) $row['session_ref'],
            'admin_user_id'    => (int) $row['admin_user_id'],
            'target_user_id'   => (int) $row['target_user_id'],
            'target_member_id' => (string) $row['target_member_id'],
            'target_name'      => (string) $profile->member_name,
            'read_only'        => true,
            'profile'          => $profile,
        ];
    }

    public function endSessionByToken(?string $rawToken, string $reason = 'ended'): void
    {
        if ($rawToken === null || $rawToken === '') {
            return;
        }
        $row = db_connect()->table('admin_impersonation_sessions')
            ->where('token_hash', hash('sha256', $rawToken))
            ->get()
            ->getRowArray();
        if ($row === null) {
            return;
        }
        $this->endSessionByRef((string) $row['session_ref'], $reason);
        $this->audit->log('admin.impersonation_ended', (int) $row['admin_user_id'], 'impersonation_session', (string) $row['session_ref'], [
            'reason'           => $reason,
            'target_member_id' => $row['target_member_id'],
        ]);
    }

    public function endSessionByRef(string $sessionRef, string $reason = 'ended'): void
    {
        db_connect()->table('admin_impersonation_sessions')
            ->where('session_ref', $sessionRef)
            ->where('status', 'active')
            ->update([
                'status'     => 'ended',
                'ended_at'   => date('Y-m-d H:i:s'),
                'end_reason' => substr($reason, 0, 40),
            ]);
    }

    public function revokeAllForAdmin(int $adminUserId, string $reason = 'admin_logout'): int
    {
        $db = db_connect();
        $rows = $db->table('admin_impersonation_sessions')
            ->where('admin_user_id', $adminUserId)
            ->where('status', 'active')
            ->get()
            ->getResultArray();

        $db->table('admin_impersonation_sessions')
            ->where('admin_user_id', $adminUserId)
            ->where('status', 'active')
            ->update([
                'status'     => 'ended',
                'ended_at'   => date('Y-m-d H:i:s'),
                'end_reason' => substr($reason, 0, 40),
            ]);

        foreach ($rows as $row) {
            $this->audit->log('admin.impersonation_ended', $adminUserId, 'impersonation_session', (string) $row['session_ref'], [
                'reason'           => $reason,
                'target_member_id' => $row['target_member_id'],
            ]);
        }

        return count($rows);
    }

    public function attachSessionCookie(ResponseInterface $response, string $rawToken, int $ttlSeconds): ResponseInterface
    {
        $secure = (bool) config('App')->forceGlobalSecureRequests
            || (! empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

        return $response->setCookie([
            'name'     => self::COOKIE_NAME,
            'value'    => $rawToken,
            'expire'   => $ttlSeconds,
            'domain'   => '',
            'path'     => $this->cookiePath(),
            'prefix'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Strict',
            'raw'      => false,
        ]);
    }

    public function clearSessionCookie(ResponseInterface $response): ResponseInterface
    {
        return $response->deleteCookie(self::COOKIE_NAME, $this->cookiePath());
    }

    private function verifyAdminPassword(object $admin, string $password): bool
    {
        $password = trim($password);
        if ($password === '') {
            return false;
        }

        try {
            $hash = (string) ($admin->password_hash ?? '');
            if ($hash === '') {
                return false;
            }

            return service('passwords')->verify($password, $hash);
        } catch (\Throwable) {
            return false;
        }
    }

    private function assertRateLimit(int $adminUserId): void
    {
        $since = date('Y-m-d H:i:s', time() - 3600);
        $count = db_connect()->table('admin_impersonation_grants')
            ->where('admin_user_id', $adminUserId)
            ->where('created_at >=', $since)
            ->countAllResults();

        if ($count >= self::RATE_LIMIT_PER_HOUR) {
            throw new InvalidArgumentException('Impersonation rate limit exceeded. Try again later.');
        }
    }

    private function assertTargetEligible(int $adminUserId, object $profile): void
    {
        $rootId = trim((string) ($this->config ?? config('Platform'))->rootSponsorId);
        if (! empty($profile->is_root_sponsor) || ($rootId !== '' && $profile->member_id === $rootId)) {
            throw new InvalidArgumentException('Cannot open support view for the root/admin sponsor account.');
        }

        $target = auth()->getProvider()->findById((int) $profile->user_id);
        if ($target === null) {
            throw new InvalidArgumentException('Target user account not found.');
        }
        if (method_exists($target, 'inGroup') && $target->inGroup('admin', 'finance_viewer', 'finance_operator', 'finance_approver', 'finance_auditor')) {
            throw new InvalidArgumentException('Cannot impersonate another administrator or finance operator.');
        }
        if (method_exists($target, 'inGroup') && ! $target->inGroup('member')) {
            throw new InvalidArgumentException('Target is not a member account.');
        }
        if ((int) $profile->user_id === $adminUserId) {
            throw new InvalidArgumentException('Cannot open support view for your own account.');
        }
    }
}
