<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Platform;
use InvalidArgumentException;

/**
 * Immutable, versioned set of mining calculation policies used by one posting run.
 *
 * No policy is selected automatically for production: values come from explicit Platform draft
 * settings (or named test fixtures) and every run stores the policy JSON + version hash it used, so a
 * resumed run that sees a different policy fails closed instead of mixing two calculations.
 */
final class MiningCalculationPolicy
{
    public const APPROVAL_FULL_DAY    = 'full_day';
    public const APPROVAL_EXCLUDE_DAY = 'exclude_approval_day';

    public const RESERVED_INCLUDE = 'include';
    public const RESERVED_EXCLUDE = 'exclude';

    public const MATURITY_INCLUDE_ALL      = 'include_all';
    public const MATURITY_EXCLUDE_MATURED  = 'exclude_matured_locked';

    /** Named test-only fixtures (never production approvals). */
    public const FIXTURES = [
        'T-DEFAULT'        => ['approval_day' => 'full_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'down', 'reserved' => 'include', 'maturity' => 'include_all'],
        'T-CALENDAR-HALFUP' => ['approval_day' => 'full_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'half_up', 'reserved' => 'include', 'maturity' => 'include_all'],
        'T-FIXED30-HALFUP' => ['approval_day' => 'full_day', 'divisor_mode' => 'fixed_30', 'rounding' => 'half_up', 'reserved' => 'include', 'maturity' => 'include_all'],
        'T-NEXTDAY-DOWN'   => ['approval_day' => 'exclude_approval_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'down', 'reserved' => 'include', 'maturity' => 'include_all'],
        'T-RESERVED-EXCL'  => ['approval_day' => 'full_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'half_up', 'reserved' => 'exclude', 'maturity' => 'include_all'],
        'T-MATURED-EXCL'   => ['approval_day' => 'full_day', 'divisor_mode' => 'calendar_days', 'rounding' => 'half_up', 'reserved' => 'include', 'maturity' => 'exclude_matured_locked'],
    ];

    public function __construct(
        public readonly string $approvalDay,
        public readonly string $divisorMode,
        public readonly string $rounding,
        public readonly string $reserved,
        public readonly string $maturity
    ) {
        $this->assertOneOf($approvalDay, [self::APPROVAL_FULL_DAY, self::APPROVAL_EXCLUDE_DAY], 'approval-day policy');
        $this->assertOneOf($divisorMode, MiningMath::divisorModes(), 'month divisor mode');
        $this->assertOneOf($rounding, MiningMath::roundingModes(), 'rounding mode');
        $this->assertOneOf($reserved, [self::RESERVED_INCLUDE, self::RESERVED_EXCLUDE], 'reserved-coin policy');
        $this->assertOneOf($maturity, [self::MATURITY_INCLUDE_ALL, self::MATURITY_EXCLUDE_MATURED], 'maturity policy');
    }

    public static function fromPlatform(?Platform $cfg = null): self
    {
        $cfg ??= config('Platform');

        return new self(
            strtolower((string) $cfg->miningApprovalDayPolicy),
            strtolower((string) $cfg->miningMonthDayDivisorMode),
            strtolower((string) $cfg->miningRoundingMode),
            strtolower((string) $cfg->miningReservedCoinPolicy),
            strtolower((string) $cfg->miningMaturityPolicy)
        );
    }

    public static function fixture(string $name): self
    {
        if (! isset(self::FIXTURES[$name])) {
            throw new InvalidArgumentException('Unknown policy fixture: ' . $name);
        }
        $f = self::FIXTURES[$name];

        return new self($f['approval_day'], $f['divisor_mode'], $f['rounding'], $f['reserved'], $f['maturity']);
    }

    /** @param array<string, string> $a */
    public static function fromArray(array $a): self
    {
        return new self(
            (string) ($a['approval_day'] ?? ''),
            (string) ($a['divisor_mode'] ?? ''),
            (string) ($a['rounding'] ?? ''),
            (string) ($a['reserved'] ?? ''),
            (string) ($a['maturity'] ?? '')
        );
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'approval_day' => $this->approvalDay,
            'divisor_mode' => $this->divisorMode,
            'rounding'     => $this->rounding,
            'reserved'     => $this->reserved,
            'maturity'     => $this->maturity,
        ];
    }

    public function json(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function version(): string
    {
        return 'MPOL-' . substr(hash('sha256', $this->json()), 0, 12);
    }

    /** Snapshot instant: end of the business day (inclusive of approval day) or its start. */
    public function usesStartOfDaySnapshot(): bool
    {
        return $this->approvalDay === self::APPROVAL_EXCLUDE_DAY;
    }

    /** @param list<string> $allowed */
    private function assertOneOf(string $value, array $allowed, string $label): void
    {
        if (! in_array($value, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('Invalid %s "%s" (allowed: %s).', $label, $value, implode(', ', $allowed)));
        }
    }
}
