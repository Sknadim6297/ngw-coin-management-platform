<?php

declare(strict_types=1);

namespace App\Libraries;

use InvalidArgumentException;

/**
 * Decimal-safe arithmetic helpers using BCMath.
 */
final class MoneyMath
{
    public static function normalize(string $value, int $scale = 8): string
    {
        $value = trim($value);
        if ($value === '' || ! is_numeric($value)) {
            throw new InvalidArgumentException('Value must be numeric.');
        }

        return bcadd($value, '0', $scale);
    }

    public static function isPositive(string $value, int $scale = 8): bool
    {
        return bccomp(self::normalize($value, $scale), '0', $scale) === 1;
    }

    public static function isNegative(string $value, int $scale = 8): bool
    {
        return bccomp(self::normalize($value, $scale), '0', $scale) === -1;
    }

    public static function add(string $a, string $b, int $scale = 8): string
    {
        return bcadd(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function sub(string $a, string $b, int $scale = 8): string
    {
        return bcsub(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function mul(string $a, string $b, int $scale = 8): string
    {
        return bcmul(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function div(string $a, string $b, int $scale = 8): string
    {
        $divisor = self::normalize($b, $scale);
        if (bccomp($divisor, '0', $scale) === 0) {
            throw new InvalidArgumentException('Division by zero.');
        }

        return bcdiv(self::normalize($a, $scale), $divisor, $scale);
    }

    public static function compare(string $a, string $b, int $scale = 8): int
    {
        return bccomp(self::normalize($a, $scale), self::normalize($b, $scale), $scale);
    }

    public static function format(string $value, int $scale = 8): string
    {
        return self::normalize($value, $scale);
    }
}
