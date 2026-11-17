<?php

declare(strict_types=1);

namespace App\Services;

use Config\Database;
use RuntimeException;

/**
 * Fail-closed gate for production financial / identity mutations.
 */
class ProductionSafetyGate
{
    public const LIVE_DATABASE = 'coin_platform';

    public const CONFIRM_PHRASE = 'I_AUTHORIZE_PRODUCTION_CHANGE';

    /**
     * @return array{group: string, database: string, is_live: bool}
     */
    public function inspect(?string $group = null): array
    {
        $group = $group ?: (string) config('Database')->defaultGroup;
        $db    = Database::connect($group, false);
        $name  = (string) $db->getDatabase();

        return [
            'group'     => $group,
            'database'  => $name,
            'is_live'   => $name === self::LIVE_DATABASE,
        ];
    }

    /**
     * Refuse live DB mutations unless both allow-production and exact confirm phrase.
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
                'Refusing ' . $operation . ' on live database "' . self::LIVE_DATABASE
                . '". Use staging (--db-group=phase12b) or pass --allow-production with explicit confirm.'
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
            'miningP2pEnabled',
            'commissionPostingEnabled',
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
