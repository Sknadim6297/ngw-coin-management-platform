<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

use InvalidArgumentException;

/**
 * Integer-safe ERC-20 amount helpers using BCMath only.
 */
final class TokenAmountMath
{
    /**
     * Convert hex quantity (base units) to decimal string at display scale.
     */
    public static function baseUnitsHexToDecimal(string $hexQuantity, int $tokenDecimals, int $displayScale = 8): string
    {
        $hex = strtolower(trim($hexQuantity));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            $hex = '0';
        }
        if (! preg_match('/^[0-9a-f]+$/', $hex)) {
            throw new InvalidArgumentException('Invalid hex token quantity.');
        }

        $base = self::hexToDec($hex);
        if ($tokenDecimals < 0 || $tokenDecimals > 36) {
            throw new InvalidArgumentException('Invalid token decimals.');
        }

        if ($tokenDecimals === 0) {
            return bcadd($base, '0', $displayScale);
        }

        $divisor = bcpow('10', (string) $tokenDecimals, 0);

        return bcdiv($base, $divisor, $displayScale);
    }

    public static function hexToDec(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (str_starts_with($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }

        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '16', 0);
            $digit = $hex[$i];
            $value = ctype_digit($digit) ? $digit : (string) (10 + (ord($digit) - ord('a')));
            $dec = bcadd($dec, $value, 0);
        }

        return $dec;
    }

    public static function normalizeAddress(string $address): string
    {
        $address = strtolower(trim($address));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            throw new InvalidArgumentException('Invalid EVM address.');
        }

        return $address;
    }

    /**
     * Topic address is 32-byte left-padded.
     */
    public static function addressFromTopic(string $topic): string
    {
        $topic = strtolower(trim($topic));
        if (str_starts_with($topic, '0x')) {
            $topic = substr($topic, 2);
        }
        if (strlen($topic) !== 64 || ! preg_match('/^[0-9a-f]{64}$/', $topic)) {
            throw new InvalidArgumentException('Invalid topic address.');
        }

        return '0x' . substr($topic, 24);
    }
}
