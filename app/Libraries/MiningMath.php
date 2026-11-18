<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Exact decimal mining arithmetic (bcmath only, never floats).
 *
 * daily amount = eligible x percent / (100 x divisor)
 *
 * The whole quotient is evaluated at INTERMEDIATE_SCALE and rounded exactly once, at the final
 * amount. No intermediate "daily factor" is truncated (Phase 13B fix for the Phase 13A finding where
 * the factor was cut to 12 decimals and then truncated again at 8).
 */
final class MiningMath
{
    public const INTERMEDIATE_SCALE = 40;

    public const ROUND_DOWN    = 'down';
    public const ROUND_HALF_UP = 'half_up';

    public const DIVISOR_CALENDAR = 'calendar_days';
    public const DIVISOR_FIXED_30 = 'fixed_30';

    /** @return list<string> */
    public static function roundingModes(): array
    {
        return [self::ROUND_DOWN, self::ROUND_HALF_UP];
    }

    /** @return list<string> */
    public static function divisorModes(): array
    {
        return [self::DIVISOR_CALENDAR, self::DIVISOR_FIXED_30];
    }

    public static function isDecimal(string $value): bool
    {
        return (bool) preg_match('/^\d+(\.\d+)?$/', $value);
    }

    /**
     * @param string $eligible  non-negative decimal (coins)
     * @param string $percent   rate percent, 0..100 inclusive
     * @param int    $divisor   days divisor (>=1)
     */
    public static function dailyAmount(string $eligible, string $percent, int $divisor, int $scale, string $mode): string
    {
        if (! self::isDecimal($eligible)) {
            throw new InvalidArgumentException('Eligible coins must be a non-negative decimal.');
        }
        if (! self::isDecimal($percent) || bccomp($percent, '100', 12) > 0) {
            throw new InvalidArgumentException('Rate percent must be a decimal between 0 and 100.');
        }
        if ($divisor < 1) {
            throw new InvalidArgumentException('Divisor must be at least 1.');
        }
        if ($scale < 0 || $scale > 18) {
            throw new InvalidArgumentException('Unsupported precision scale.');
        }

        $numerator   = bcmul($eligible, $percent, self::INTERMEDIATE_SCALE);
        $denominator = bcmul('100', (string) $divisor, 0);
        $quotient    = bcdiv($numerator, $denominator, self::INTERMEDIATE_SCALE);

        return self::round($quotient, $scale, $mode);
    }

    /** Round a non-negative decimal to $scale places. */
    public static function round(string $value, int $scale, string $mode): string
    {
        if (! self::isDecimal($value)) {
            throw new InvalidArgumentException('Only non-negative decimals can be rounded.');
        }
        if ($mode === self::ROUND_DOWN) {
            return bcadd($value, '0', $scale);
        }
        if ($mode === self::ROUND_HALF_UP) {
            $half = '0.' . str_repeat('0', $scale) . '5';

            return bcadd(bcadd($value, $half, self::INTERMEDIATE_SCALE), '0', $scale);
        }

        throw new InvalidArgumentException('Unsupported rounding mode: ' . $mode);
    }

    /** Days divisor for one business date under an explicit policy mode. */
    public static function divisor(string $rateBasis, string $businessDate, ?string $effectiveMonth, string $divisorMode): int
    {
        if ($rateBasis === 'daily') {
            return 1;
        }
        if ($rateBasis === 'annual') {
            return 365;
        }
        if ($rateBasis !== 'monthly') {
            throw new InvalidArgumentException('Unsupported rate basis.');
        }
        if ($divisorMode === self::DIVISOR_FIXED_30) {
            return 30;
        }
        if ($divisorMode !== self::DIVISOR_CALENDAR) {
            throw new InvalidArgumentException('Unsupported divisor mode: ' . $divisorMode);
        }

        $ym = $effectiveMonth !== null && preg_match('/^\d{4}-\d{2}$/', $effectiveMonth)
            ? $effectiveMonth
            : substr($businessDate, 0, 7);
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $ym . '-01');
        if ($dt === false || $dt->format('Y-m') !== $ym) {
            throw new InvalidArgumentException('Invalid month.');
        }

        return (int) $dt->format('t');
    }
}
