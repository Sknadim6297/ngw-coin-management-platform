<?php

declare(strict_types=1);

namespace App\Services;

use Config\Platform;
use RuntimeException;

/**
 * Phase 12A — time-boxed, DB-isolated authorization for financial engine tests.
 *
 * Production Platform kill-switches stay OFF. Sandbox temporarily authorizes
 * perform/credit paths only while a window is open on an allowlisted database
 * and only for synthetic members (SYN* / P12A-SYN*).
 */
class FinancialSandboxService
{
    public const ALLOWED_DATABASES = [
        'coin_platform_phase12a',
        'coin_platform_phase12b',
        'coin_platform_phase13a',
        'coin_platform_phase13b',
        'coin_platform_phase13c',
        'ng_database_phase14',
        // NOTE: *_snapshot databases are deliberately NOT allowlisted (read-only baseline, SELECT-only account).
    ];

    public const FORBIDDEN_DATABASES = [
        'coin_platform',
        'ng_database',
        'wwwzeont_ngw_test_db',
        'coin_platform_phase13c_snapshot',
        'ng_database_phase14_snapshot',
    ];

    public const SYNTHETIC_MEMBER_PREFIX = 'SYN';

    public const SYNTHETIC_NAME_PREFIX = 'P12A-SYN';

    /** Phase 12B staging synthetic name marker (IDs may use NGW1234+ for sequence validation). */
    public const STAGING_NAME_PREFIX = 'P12B-STG';

    /** @var array{started_at:int,expires_at:int,db:string,token:string}|null */
    private static ?array $window = null;

    public function __construct(
        private readonly ?Platform $config = null
    ) {
    }

    /**
     * @return array{database: string, hostname: string, group: string, is_allowed: bool, is_forbidden: bool}
     */
    public function inspectConnection(?string $group = null): array
    {
        $dbConfig = config('Database');
        $group ??= $dbConfig->defaultGroup;
        /** @var array<string, mixed> $cfg */
        $cfg = $dbConfig->{$group} ?? $dbConfig->default;

        $database = (string) ($cfg['database'] ?? '');
        $hostname = (string) ($cfg['hostname'] ?? '');

        return [
            'database'     => $database,
            'hostname'     => $hostname,
            'group'        => $group,
            // A production-classified database can never be a sandbox, even if also allowlisted.
            'is_allowed'   => in_array($database, self::ALLOWED_DATABASES, true)
                && ! ProductionSafetyGate::isLiveDatabase($database),
            'is_forbidden' => in_array($database, self::FORBIDDEN_DATABASES, true)
                || ProductionSafetyGate::isLiveDatabase($database),
        ];
    }

    public function assertIsolatedDatabase(?string $group = null): void
    {
        $info = $this->inspectConnection($group);
        if ($info['is_forbidden'] || ! $info['is_allowed']) {
            throw new RuntimeException(
                'Financial sandbox refused: database "' . $info['database']
                . '" is not an allowlisted isolated test database. Allowed: '
                . implode(', ', self::ALLOWED_DATABASES)
            );
        }
    }

    /**
     * @return array{token: string, started_at: string, expires_at: string, seconds: int, database: string}
     */
    public function openWindow(int $seconds = 120, ?string $group = null): array
    {
        $seconds = max(1, min(300, $seconds));
        $this->assertIsolatedDatabase($group);
        $info = $this->inspectConnection($group);

        $started = time();
        $expires = $started + $seconds;
        $token   = 'FSW-' . strtoupper(bin2hex(random_bytes(8)));

        self::$window = [
            'started_at' => $started,
            'expires_at' => $expires,
            'db'         => $info['database'],
            'token'      => $token,
        ];

        return [
            'token'      => $token,
            'started_at' => date('c', $started),
            'expires_at' => date('c', $expires),
            'seconds'    => $seconds,
            'database'   => $info['database'],
        ];
    }

    public function closeWindow(): void
    {
        self::$window = null;
    }

    public function isWindowOpen(): bool
    {
        if (self::$window === null) {
            return false;
        }

        if (time() >= (int) self::$window['expires_at']) {
            self::$window = null;

            return false;
        }

        try {
            $this->assertIsolatedDatabase();
        } catch (RuntimeException) {
            self::$window = null;

            return false;
        }

        $info = $this->inspectConnection();
        if ($info['database'] !== (string) self::$window['db']) {
            self::$window = null;

            return false;
        }

        return true;
    }

    /**
     * @return array{open: bool, token: ?string, started_at: ?string, expires_at: ?string, remaining_seconds: int, database: ?string}
     */
    public function status(): array
    {
        $open = $this->isWindowOpen();

        return [
            'open'               => $open,
            'token'              => $open ? (string) self::$window['token'] : null,
            'started_at'         => $open ? date('c', (int) self::$window['started_at']) : null,
            'expires_at'         => $open ? date('c', (int) self::$window['expires_at']) : null,
            'remaining_seconds'  => $open ? max(0, (int) self::$window['expires_at'] - time()) : 0,
            'database'           => $open ? (string) self::$window['db'] : null,
        ];
    }

    public function assertActive(string $capability = 'any'): void
    {
        if (! $this->isWindowOpen()) {
            throw new RuntimeException(
                'Financial sandbox window is closed or expired. Capability refused: ' . $capability
            );
        }
    }

    public function allows(string $capability): bool
    {
        return $this->isWindowOpen();
    }

    public function isSyntheticMemberId(string $memberId): bool
    {
        $id = strtoupper(trim($memberId));
        if ($id === 'ROOT0001' || str_starts_with($id, self::SYNTHETIC_MEMBER_PREFIX)) {
            return true;
        }

        // Phase 12B: NGW* IDs are allowed only when the profile name is staging-marked.
        if (str_starts_with($id, 'NGW')) {
            $profile = model(\App\Models\MemberProfileModel::class)->findByMemberId($id);
            if ($profile !== null && str_starts_with((string) $profile->member_name, self::STAGING_NAME_PREFIX)) {
                return true;
            }
        }

        return false;
    }

    public function assertSyntheticMember(string $memberId, string $context = 'operation'): void
    {
        $id = strtoupper(trim($memberId));
        if ($id === 'ROOT0001') {
            return;
        }
        if ($this->isWindowOpen()
            && (string) (self::$window['db'] ?? '') === 'ng_database_phase14'
            && (string) db_connect()->getDatabase() === 'ng_database_phase14'
        ) {
            return;
        }
        if (! $this->isSyntheticMemberId($id)) {
            throw new RuntimeException(
                'Sandbox refused non-synthetic member "' . $memberId . '" for ' . $context
            );
        }
    }

    /**
     * Phase 12B staging overrides — NGW1234 sequence validation on isolated DB only.
     */
    public function applyPhase12bStagingOverrides(): void
    {
        $this->assertActive('phase12b_overrides');
        $platform = $this->config ?? config('Platform');

        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->coinSellingEnabled                = false;

        // Staging-only sequence to validate requested NGW1234+ format (padding 4).
        $platform->memberIdPrefix                    = 'NGW';
        $platform->memberIdStartNumber               = 1234;
        $platform->memberIdPadding                   = 4;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->coinPlanLockDays                  = 1000;
        $platform->miningBusinessTimezone            = 'Asia/Kolkata';
        $platform->miningMonthDayDivisorMode         = 'calendar_days';
        $platform->miningApprovalDayPolicy           = 'full_day';
        $platform->miningEarningAsset                = 'COIN';
    }

    /**
     * Apply sandbox-only Platform overrides (never persist to .env).
     */
    public function applySandboxPlatformOverrides(): void
    {
        $this->assertActive('platform_overrides');
        $platform = $this->config ?? config('Platform');

        // Keep production kill-switches OFF — sandbox ORs into enabled() helpers.
        $platform->miningPostingEnabled              = false;
        $platform->miningP2pEnabled                  = false;
        $platform->commissionPostingEnabled          = false;
        $platform->commissionWithdrawalEnabled       = false;
        $platform->commissionBlockchainPayoutEnabled = false;
        $platform->coinSellingEnabled                = false;

        $platform->memberIdPrefix                    = self::SYNTHETIC_MEMBER_PREFIX;
        $platform->memberIdStartNumber               = 1;
        $platform->memberIdPadding                   = 6;
        $platform->requireVerifiedDepositForApproval = false;
        $platform->mandatoryPurchaseLockEnabled      = true;
        $platform->coinPlanLockDays                  = 1000;
        $platform->miningBusinessTimezone            = 'Asia/Kolkata';
        $platform->miningMonthDayDivisorMode         = 'calendar_days';
        $platform->miningApprovalDayPolicy           = 'full_day';
        $platform->miningEarningAsset                = 'COIN';
    }

    /**
     * Snapshot of production-facing kill-switches (should remain false).
     *
     * @return array<string, bool>
     */
    public function productionSwitchSnapshot(): array
    {
        $cfg = $this->config ?? config('Platform');

        return [
            'miningPostingEnabled'              => (bool) $cfg->miningPostingEnabled,
            'miningP2pEnabled'                  => (bool) $cfg->miningP2pEnabled,
            'commissionPostingEnabled'          => (bool) $cfg->commissionPostingEnabled,
            'commissionWithdrawalEnabled'       => (bool) $cfg->commissionWithdrawalEnabled,
            'commissionBlockchainPayoutEnabled' => (bool) $cfg->commissionBlockchainPayoutEnabled,
            'coinSellingEnabled'                => (bool) $cfg->coinSellingEnabled,
            'sandbox_window_open'               => $this->isWindowOpen(),
        ];
    }
}
