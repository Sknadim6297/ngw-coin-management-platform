<?php

declare(strict_types=1);

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * Fail-closed gate for production financial / identity mutations.
 *
 * Classification rules (database NAME text such as "test" or "phase" is never evidence of safety):
 *   live    - explicitly listed production database (incl. env platform.liveDatabase and the default group DB)
 *   sandbox - explicitly listed isolated non-production database, or the PHPUnit tests DB under ENVIRONMENT=testing
 *   unknown - anything else; treated as PRODUCTION for protection purposes (fail-closed)
 * A live match always wins over a sandbox match.
 */
class ProductionSafetyGate
{
    /** Legacy constant kept for backward compatibility (local development copy). */
    public const LIVE_DATABASE = 'coin_platform';

    /**
     * Known production databases. NOTE: "wwwzeont_ngw_test_db" is the REAL production
     * database on the live server despite containing the word "test".
     *
     * @var list<string>
     */
    public const LIVE_DATABASES = [
        'coin_platform',
        'ng_database',
        'wwwzeont_ngw_test_db',
    ];

    /**
     * Explicit isolated non-production databases (rehearsal / staging / snapshot baselines).
     *
     * @var list<string>
     */
    public const NON_PRODUCTION_DATABASES = [
        'coin_platform_phase12a',
        'coin_platform_phase12b',
        'coin_platform_phase12d',
        'coin_platform_phase12f',
        'coin_platform_phase13a',
        'coin_platform_phase13b',
        'coin_platform_phase13c',
        'coin_platform_phase13c_snapshot',
        'ng_database_phase14',
        'ng_database_phase14_snapshot',
        'coin_platform_test',
    ];

    public const CONFIRM_PHRASE = 'I_AUTHORIZE_PRODUCTION_CHANGE';

    /**
     * @return list<string>
     */
    public static function liveDatabases(): array
    {
        $list = self::LIVE_DATABASES;

        $raw = [];
        $raw[] = (string) (getenv('platform.liveDatabase') ?: '');
        $raw[] = (string) ($_SERVER['platform.liveDatabase'] ?? '');
        $raw[] = (string) ($_ENV['platform.liveDatabase'] ?? '');
        try {
            $raw[] = (string) (config('Platform')->liveDatabase ?? '');
        } catch (\Throwable) {
            // config unavailable: hardcoded list still applies
        }
        foreach ($raw as $value) {
            foreach (explode(',', $value) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $list[] = $name;
                }
            }
        }

        // The database the application's default group connects to is production by definition.
        try {
            $default = trim((string) (config('Database')->default['database'] ?? ''));
            if ($default !== '') {
                $list[] = $default;
            }
        } catch (\Throwable) {
            // ignore
        }

        return array_values(array_unique($list));
    }

    /**
     * @return 'live'|'sandbox'|'unknown'
     */
    public static function classify(string $database): string
    {
        $database = trim($database);
        if ($database === '') {
            return 'unknown';
        }

        $lower = strtolower($database);
        foreach (self::liveDatabases() as $live) {
            if (strtolower($live) === $lower) {
                return 'live';
            }
        }
        if (str_contains($lower, 'prod')) {
            return 'live';
        }

        if (in_array($database, self::NON_PRODUCTION_DATABASES, true)) {
            return 'sandbox';
        }

        if (ENVIRONMENT === 'testing') {
            try {
                $testsDb = (string) (config('Database')->tests['database'] ?? '');
            } catch (\Throwable) {
                $testsDb = '';
            }
            if ($testsDb !== '' && $database === $testsDb) {
                return 'sandbox';
            }
        }

        return 'unknown';
    }

    /** True only for an explicitly recognised production database. */
    public static function isLiveDatabase(string $database): bool
    {
        return self::classify($database) === 'live';
    }

    /** Fail-closed: anything not positively identified as an isolated sandbox is protected like production. */
    public static function requiresProductionProtection(string $database): bool
    {
        return self::classify($database) !== 'sandbox';
    }

    /**
     * @return array{group: string, database: string, is_live: bool, classification: string}
     */
    public function inspect(?string $group = null): array
    {
        $group = $group ?: (string) config('Database')->defaultGroup;
        $db    = Database::connect($group, false);
        $name  = (string) $db->getDatabase();

        return [
            'group'          => $group,
            'database'       => $name,
            'is_live'        => self::requiresProductionProtection($name),
            'classification' => self::classify($name),
        ];
    }

    /**
     * Refuse live DB mutations unless both allow-production and exact confirm phrase.
     * Unknown/ambiguous databases are treated as live (fail-closed).
     *
     * @throws RuntimeException
     */
    public function assertMutationAllowed(
        bool $allowProduction,
        ?string $confirmPhrase,
        ?string $group = null,
        string $operation = 'mutation'
    ): array {
        $info = $this->inspect($group);
        if (! $info['is_live']) {
            return $info;
        }

        if (! $allowProduction) {
            throw new RuntimeException(
                'Refusing ' . $operation . ' on ' . ($info['classification'] === 'live' ? 'live' : 'unclassified (treated as live)')
                . ' database "' . $info['database']
                . '". Use an isolated sandbox group or pass --allow-production with explicit confirm.'
            );
        }

        if (($confirmPhrase ?? '') !== self::CONFIRM_PHRASE) {
            throw new RuntimeException(
                'Live ' . $operation . ' requires --confirm="' . self::CONFIRM_PHRASE . '".'
            );
        }

        return $info;
    }

    /**
     * Snapshot of production-facing kill-switches (must remain false in Phase 12D).
     *
     * @return array<string, bool>
     */
    public function killSwitchSnapshot(): array
    {
        return service('financialSandboxService')->productionSwitchSnapshot();
    }

    public function assertKillSwitchesOff(): void
    {
        $s = $this->killSwitchSnapshot();
        foreach ([
            'miningPostingEnabled',
            'miningAutoPostEnabled',
            'miningP2pEnabled',
            'commissionPostingEnabled',
            'commissionAutoPostOnApprovalEnabled',
            'commissionWithdrawalEnabled',
            'commissionBlockchainPayoutEnabled',
            'coinSellingEnabled',
        ] as $key) {
            if (! empty($s[$key])) {
                throw new RuntimeException('Abort: production kill-switch ' . $key . ' is ON.');
            }
        }
    }
}
