<?php

declare(strict_types=1);

namespace App\Services;

use Config\Platform;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 9C editable platform settings (whitelist only).
 * Never mutates payment destinations, coin price, or financial kill-switches.
 */
class PlatformSettingsService
{
    public const ALLOWED_KEYS = [
        'platform_name',
        'platform_logo',
        'contact_email',
        'support_contact',
        'timezone',
        'date_format',
        'registration_enabled',
        'sponsor_required',
        'registration_instructions',
        'notify_admin_alerts',
        'notify_member_events',
        'notify_payment_submission',
        'notify_purchase_approval',
    ];

    public const DATE_FORMATS = [
        'Y-m-d H:i',
        'Y-m-d',
        'd/m/Y H:i',
        'd M Y H:i',
        'm/d/Y h:i A',
    ];

    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger(),
        private readonly ?Platform $platform = null
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $rows = db_connect()->table('platform_settings')->get()->getResultArray();
        $out  = $this->defaults();
        foreach ($rows as $row) {
            $key = (string) ($row['setting_key'] ?? '');
            if (in_array($key, self::ALLOWED_KEYS, true)) {
                $out[$key] = (string) ($row['setting_value'] ?? '');
            }
        }

        return $out;
    }

    public function get(string $key, ?string $default = null): string
    {
        if (! in_array($key, self::ALLOWED_KEYS, true)) {
            throw new InvalidArgumentException('Unknown setting key.');
        }

        $row = db_connect()->table('platform_settings')->where('setting_key', $key)->get()->getRowArray();
        if ($row === null) {
            return $default ?? ($this->defaults()[$key] ?? '');
        }

        return (string) ($row['setting_value'] ?? '');
    }

    public function bool(string $key): bool
    {
        return in_array(strtolower($this->get($key, '0')), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{changed: list<string>, settings: array<string, string>}
     */
    public function updateGroup(string $group, array $input, int $adminUserId): array
    {
        $keys = match ($group) {
            'general'      => ['platform_name', 'contact_email', 'support_contact', 'timezone', 'date_format'],
            'registration' => ['registration_enabled', 'sponsor_required', 'registration_instructions'],
            'notifications'=> [
                'notify_admin_alerts',
                'notify_member_events',
                'notify_payment_submission',
                'notify_purchase_approval',
            ],
            default        => throw new InvalidArgumentException('Unknown settings group.'),
        };

        $payload = [];
        foreach ($keys as $key) {
            if ($group === 'registration' && in_array($key, ['registration_enabled', 'sponsor_required'], true)) {
                $payload[$key] = ! empty($input[$key]) ? '1' : '0';
                continue;
            }
            if ($group === 'notifications') {
                $payload[$key] = ! empty($input[$key]) ? '1' : '0';
                continue;
            }
            $payload[$key] = trim((string) ($input[$key] ?? ''));
        }

        return $this->updateMany($payload, $adminUserId);
    }

    /**
     * @param array<string, string> $values
     * @return array{changed: list<string>, settings: array<string, string>}
     */
    public function updateMany(array $values, int $adminUserId): array
    {
        if ($adminUserId <= 0) {
            throw new InvalidArgumentException('Administrator is required.');
        }

        $normalized = [];
        foreach ($values as $key => $value) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new InvalidArgumentException('Setting key is not editable: ' . $key);
            }
            $normalized[$key] = $this->normalizeValue($key, (string) $value);
        }

        $db = db_connect();
        $db->transBegin();
        $changed = [];
        $now     = date('Y-m-d H:i:s');
        $ip      = service('request')->getIPAddress();

        try {
            foreach ($normalized as $key => $newValue) {
                $existing = $db->table('platform_settings')->where('setting_key', $key)->get()->getRowArray();
                $oldValue = $existing !== null ? (string) ($existing['setting_value'] ?? '') : ($this->defaults()[$key] ?? '');
                if ($oldValue === $newValue) {
                    continue;
                }

                if ($existing === null) {
                    $db->table('platform_settings')->insert([
                        'setting_key'   => $key,
                        'setting_value' => $newValue,
                        'updated_by'    => $adminUserId,
                        'created_at'    => $now,
                        'updated_at'    => $now,
                    ]);
                } else {
                    $db->table('platform_settings')->where('setting_key', $key)->update([
                        'setting_value' => $newValue,
                        'updated_by'    => $adminUserId,
                        'updated_at'    => $now,
                    ]);
                }

                $db->table('settings_audit')->insert([
                    'setting_key'    => $key,
                    'previous_value' => $this->redactForAudit($key, $oldValue),
                    'new_value'      => $this->redactForAudit($key, $newValue),
                    'changed_by'     => $adminUserId,
                    'changed_at'     => $now,
                    'ip_address'     => $ip,
                ]);
                $changed[] = $key;
            }

            if ($db->transStatus() === false) {
                $db->transRollback();
                throw new RuntimeException('Unable to save settings.');
            }
            $db->transCommit();
        } catch (\Throwable $e) {
            $db->transRollback();
            throw $e;
        }

        if ($changed !== []) {
            $this->audit->log('platform.settings_updated', $adminUserId, 'platform_settings', implode(',', $changed), [
                'changed' => $changed,
            ]);
        }

        return [
            'changed'  => $changed,
            'settings' => $this->all(),
        ];
    }

    /**
     * @return array{path: string, settings: array<string, string>}
     */
    public function storeLogo(?\CodeIgniter\HTTP\Files\UploadedFile $file, int $adminUserId): array
    {
        if ($file === null || ! $file->isValid() || $file->hasMoved()) {
            throw new InvalidArgumentException('Choose a valid logo image to upload.');
        }

        $mime = (string) $file->getMimeType();
        $allowed = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
        if (! in_array($mime, $allowed, true)) {
            throw new InvalidArgumentException('Logo must be PNG, JPEG, WEBP, or GIF.');
        }
        if ($file->getSize() > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Logo must be 2 MB or smaller.');
        }

        $dir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'platform';
        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create logo storage directory.');
        }

        $ext = match ($mime) {
            'image/png'  => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif'  => 'gif',
            default      => 'bin',
        };
        $name = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
        $file->move($dir, $name, true);

        $relative = 'uploads/platform/' . $name;
        $this->updateMany(['platform_logo' => $relative], $adminUserId);

        return [
            'path'     => $relative,
            'settings' => $this->all(),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function auditHistory(int $page = 1, int $perPage = 25): array
    {
        $page    = max(1, $page);
        $perPage = max(5, min(100, $perPage));
        $db      = db_connect();
        $total   = (int) $db->table('settings_audit')->countAllResults();
        $rows    = $db->table('settings_audit a')
            ->select('a.*, u.username, mp.member_id, mp.member_name')
            ->join('users u', 'u.id = a.changed_by', 'left')
            ->join('member_profiles mp', 'mp.user_id = a.changed_by', 'left')
            ->orderBy('a.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Read-only security policy snapshot (no secrets).
     *
     * @return list<array{label: string, value: string}>
     */
    public function securitySnapshot(): array
    {
        $session  = config('Session');
        $platform = $this->platform ?? config('Platform');
        $auth     = config('Auth');

        return [
            ['label' => 'Session Driver', 'value' => class_basename((string) $session->driver)],
            ['label' => 'Session Expiration', 'value' => ((int) $session->expiration) . ' seconds'],
            ['label' => 'Session Match IP', 'value' => ! empty($session->matchIP) ? 'Enabled' : 'Disabled'],
            ['label' => 'Password Reset TTL', 'value' => ((int) $platform->passwordResetTtl) . ' seconds'],
            ['label' => 'Verified Deposit Gate', 'value' => $platform->requireVerifiedDepositForApproval ? 'Required' : 'Optional (non-production)'],
            ['label' => 'Commission Posting', 'value' => 'Not Activated'],
            ['label' => 'Commission Withdrawals', 'value' => 'Not Activated'],
            ['label' => 'Mining Payouts', 'value' => 'Not Activated'],
            ['label' => 'Auth Authenticator', 'value' => class_basename((string) ($auth->authenticators['session'] ?? 'session'))],
        ];
    }

    /**
     * @return array{configured: bool, email_enabled: bool, min_severity: string}
     */
    public function notificationInfrastructureStatus(): array
    {
        $cfg = config('FinanceNotifications');
        $svc = service('financialNotificationService');

        return [
            'configured'    => $svc->isDeliveryConfigured(),
            'email_enabled' => (bool) $cfg->emailEnabled,
            'min_severity'  => (string) $cfg->minSeverity,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $platform = $this->platform ?? config('Platform');

        return [
            'platform_name'             => (string) $platform->appName,
            'platform_logo'             => '',
            'contact_email'             => '',
            'support_contact'           => '',
            'timezone'                  => (string) config('App')->appTimezone,
            'date_format'               => 'Y-m-d H:i',
            'registration_enabled'      => '1',
            'sponsor_required'          => '1',
            'registration_instructions' => '',
            'notify_admin_alerts'       => '1',
            'notify_member_events'      => '0',
            'notify_payment_submission' => '1',
            'notify_purchase_approval'  => '1',
        ];
    }

    private function normalizeValue(string $key, string $value): string
    {
        $value = trim($value);

        return match ($key) {
            'platform_name' => $this->requireLength($value, 2, 120, 'Platform name'),
            'contact_email', 'support_contact' => $this->normalizeOptionalEmail($value, $key === 'contact_email' ? 'Contact email' : 'Support contact'),
            'timezone' => $this->normalizeTimezone($value),
            'date_format' => $this->normalizeDateFormat($value),
            'registration_instructions' => mb_substr($value, 0, 2000),
            'platform_logo' => mb_substr($value, 0, 255),
            'registration_enabled', 'sponsor_required',
            'notify_admin_alerts', 'notify_member_events',
            'notify_payment_submission', 'notify_purchase_approval' => in_array($value, ['1', '0'], true) ? $value : (in_array(strtolower($value), ['true', 'yes', 'on'], true) ? '1' : '0'),
            default => throw new InvalidArgumentException('Unsupported setting.'),
        };
    }

    private function requireLength(string $value, int $min, int $max, string $label): string
    {
        $len = mb_strlen($value);
        if ($len < $min || $len > $max) {
            throw new InvalidArgumentException($label . ' must be between ' . $min . ' and ' . $max . ' characters.');
        }

        return $value;
    }

    private function normalizeOptionalEmail(string $value, string $label): string
    {
        if ($value === '') {
            return '';
        }
        if (! filter_var($value, FILTER_VALIDATE_EMAIL) || strlen($value) > 255) {
            throw new InvalidArgumentException('Enter a valid ' . strtolower($label) . '.');
        }

        return strtolower($value);
    }

    private function normalizeTimezone(string $value): string
    {
        if ($value === '' || ! in_array($value, \DateTimeZone::listIdentifiers(), true)) {
            throw new InvalidArgumentException('Select a valid timezone.');
        }

        return $value;
    }

    private function normalizeDateFormat(string $value): string
    {
        if (! in_array($value, self::DATE_FORMATS, true)) {
            throw new InvalidArgumentException('Select a supported date format.');
        }

        return $value;
    }

    private function redactForAudit(string $key, string $value): string
    {
        // No secrets are stored in these settings; still truncate long text.
        if (mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500) . '…';
        }

        return $value;
    }
}
