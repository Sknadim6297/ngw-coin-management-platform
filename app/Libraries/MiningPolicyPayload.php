<?php

declare(strict_types=1);

namespace App\Libraries;

use Config\Platform;
use InvalidArgumentException;
use Throwable;

/**
 * Single source of truth for the canonical Mining policy payload (financial_policy_versions, domain "mining").
 *
 *  - canonicalize(): used when a draft is saved/edited. Maps legacy field names (day_divisor_mode) to the canonical
 *    ones (month_day_divisor_mode), validates enumerations, writes compounding_enabled = false and
 *    business_timezone explicitly and ALWAYS derives policy_version from the MiningCalculationPolicy engine.
 *    It never invents a business decision (rate, divisor, approval day, asset, rounding stay exactly as entered).
 *  - requirements(): used by the review screen, the single-admin eligibility check and readiness. Every mandatory
 *    term is returned with its required value, the value found and a precise problem when it is missing, invalid or
 *    inconsistent, so what is saved and what is validated can never drift apart again.
 */
final class MiningPolicyPayload
{
    public const REQUIRED_PERCENT  = '3';
    public const REQUIRED_TIMEZONE = 'Asia/Kolkata';

    /** Legacy key => canonical key. Legacy keys are never accepted by the approval validator. */
    public const LEGACY_ALIASES = ['day_divisor_mode' => 'month_day_divisor_mode'];

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function canonicalize(array $payload, ?Platform $cfg = null): array
    {
        $cfg ??= config('Platform');

        foreach (self::LEGACY_ALIASES as $legacy => $canonical) {
            if (! array_key_exists($legacy, $payload)) {
                continue;
            }
            $old = $payload[$legacy];
            $new = $payload[$canonical] ?? null;
            if ($old !== null && $new !== null && (string) $old !== (string) $new) {
                throw new InvalidArgumentException(sprintf('Conflicting values for %s ("%s") and legacy field %s ("%s").', $canonical, (string) $new, $legacy, (string) $old));
            }
            if ($new === null) {
                $payload[$canonical] = $old;
            }
            unset($payload[$legacy]);
        }

        $divisor = $payload['month_day_divisor_mode'] ?? null;
        if ($divisor !== null && ! in_array((string) $divisor, MiningMath::divisorModes(), true)) {
            throw new InvalidArgumentException('month_day_divisor_mode must be one of: ' . implode(', ', MiningMath::divisorModes()) . '.');
        }
        $payload['month_day_divisor_mode'] = $divisor === null ? null : (string) $divisor;

        $approval = $payload['approval_day_policy'] ?? null;
        if ($approval !== null && ! in_array((string) $approval, [MiningCalculationPolicy::APPROVAL_FULL_DAY, MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY], true)) {
            throw new InvalidArgumentException('approval_day_policy must be full_day or exclude_approval_day.');
        }

        $rounding = $payload['rounding_mode'] ?? null;
        if ($rounding !== null) {
            $rounding = strtolower(trim((string) $rounding));
            if (! in_array($rounding, MiningMath::roundingModes(), true)) {
                throw new InvalidArgumentException('rounding_mode must be one of: ' . implode(', ', MiningMath::roundingModes()) . '.');
            }
        }
        $payload['rounding_mode'] = $rounding;

        // Explicit, engine-derived terms. Entered values are kept (and later rejected by requirements()) when they differ.
        if (($payload['compounding_enabled'] ?? null) === null) {
            $payload['compounding_enabled'] = false;
        }
        if (($payload['business_timezone'] ?? null) === null) {
            $payload['business_timezone'] = (string) $cfg->miningBusinessTimezone;
        }
        if (($payload['rate_basis'] ?? null) === null) {
            $payload['rate_basis'] = 'monthly';
        }
        // The version is NEVER taken from input: always the version of the engine's actual runtime policy.
        $payload['policy_version'] = self::runtimeVersion($cfg);

        return $payload;
    }

    /** Version of the policy the posting engine will really run with (null if the engine configuration is invalid). */
    public static function runtimeVersion(?Platform $cfg = null): ?string
    {
        try {
            return MiningCalculationPolicy::fromPlatform($cfg ?? config('Platform'))->version();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $p decoded payload exactly as stored
     * @return list<array{key: string, label: string, required: string, actual: string, ok: bool, problem: ?string}>
     */
    public static function requirements(array $p, ?Platform $cfg = null): array
    {
        $cfg ??= config('Platform');
        $rows = [];
        $add  = static function (string $key, string $label, string $required, mixed $found, ?string $problem) use (&$rows): void {
            $rows[] = [
                'key'      => $key,
                'label'    => $label,
                'required' => $required,
                'actual'   => self::show($found),
                'ok'       => $problem === null,
                'problem'  => $problem,
            ];
        };
        $missing = static fn (mixed $v): bool => $v === null || (is_string($v) && trim($v) === '');

        $v = $p['monthly_percent'] ?? null;
        $problem = null;
        if ($missing($v)) {
            $problem = 'monthly_percent is missing (must be 3).';
        } elseif (! is_numeric((string) $v) || MoneyMath::compare(MoneyMath::normalize((string) $v, 8), self::REQUIRED_PERCENT, 8) !== 0) {
            $problem = 'monthly_percent must be 3 (found ' . self::show($v) . ').';
        }
        $add('monthly_percent', 'Monthly rate (%)', '3', $v, $problem);

        $v = $p['rate_basis'] ?? null;
        $add('rate_basis', 'Rate basis', 'monthly', $v, ($v === null || $v === 'monthly') ? null : 'rate_basis must be monthly (found ' . self::show($v) . ').');

        $v = $p['month_day_divisor_mode'] ?? null;
        $problem = null;
        if ($missing($v)) {
            $problem = 'month_day_divisor_mode is missing; day divisor must be the actual calendar days of the month.'
                . (array_key_exists('day_divisor_mode', $p) ? ' The legacy field day_divisor_mode is not recognised: revise the policy.' : '');
        } elseif ($v !== MiningMath::DIVISOR_CALENDAR) {
            $problem = 'Day divisor must be the actual calendar days of the month (month_day_divisor_mode found ' . self::show($v) . ').';
        }
        $add('month_day_divisor_mode', 'Day divisor', MiningMath::DIVISOR_CALENDAR, $v, $problem);

        $v = $p['approval_day_policy'] ?? null;
        $add('approval_day_policy', 'Approval day', MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY, $v,
            $v === MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY ? null : ($missing($v) ? 'approval_day_policy is missing; approval day must be excluded.' : 'Approval day must be excluded (found ' . self::show($v) . ').'));

        $v = $p['earning_asset'] ?? null;
        $add('earning_asset', 'Reward asset', 'COIN', $v,
            $v === 'COIN' ? null : ($missing($v) ? 'earning_asset is missing; reward asset must be COIN.' : 'Reward asset must be COIN (found ' . self::show($v) . ').'));

        $v = $p['compounding_enabled'] ?? null;
        $add('compounding_enabled', 'Compounding', 'false', $v,
            $v === false ? null : ($v === null ? 'compounding_enabled is missing; compounding must be disabled.' : 'Compounding must be disabled (found ' . self::show($v) . ').'));

        $v = $p['business_timezone'] ?? null;
        $add('business_timezone', 'Business timezone', self::REQUIRED_TIMEZONE, $v,
            $v === self::REQUIRED_TIMEZONE ? null : ($missing($v) ? 'business_timezone is missing; Business timezone must be ' . self::REQUIRED_TIMEZONE . '.' : 'Business timezone must be ' . self::REQUIRED_TIMEZONE . ' (found ' . self::show($v) . ').'));

        $runtimeRounding = strtolower((string) $cfg->miningRoundingMode);
        $v               = $p['rounding_mode'] ?? null;
        $problem         = null;
        if ($missing($v)) {
            $problem = 'rounding_mode is missing; it must match the engine rounding (' . $runtimeRounding . ').';
        } elseif (! in_array((string) $v, MiningMath::roundingModes(), true)) {
            $problem = 'rounding_mode must be one of ' . implode(', ', MiningMath::roundingModes()) . ' (found ' . self::show($v) . ').';
        } elseif ((string) $v !== $runtimeRounding) {
            $problem = 'rounding_mode ' . (string) $v . ' does not match the engine configuration (' . $runtimeRounding . ').';
        }
        $add('rounding_mode', 'Rounding', $runtimeRounding, $v, $problem);

        $runtime = self::runtimeVersion($cfg);
        $v       = $p['policy_version'] ?? null;
        $problem = null;
        if ($runtime === null) {
            $problem = 'Engine configuration invalid: the runtime policy version cannot be derived.';
        } elseif ($missing($v)) {
            $problem = 'policy_version is missing; it must match the engine configuration (' . $runtime . ').';
        } elseif ((string) $v !== $runtime) {
            $problem = 'policy_version must match the engine configuration (' . $runtime . '); found ' . self::show($v) . '.';
        }
        $add('policy_version', 'Policy version (engine)', $runtime ?? 'unavailable', $v, $problem);

        $v = $p['decimal_precision'] ?? null;
        if (! $missing($v)) {
            $add('decimal_precision', 'Decimal precision', '8', $v, (string) $v === '8' ? null : 'decimal_precision must be 8 (found ' . self::show($v) . ').');
        }

        // Optional terms: blank is allowed (the engine configuration applies), but a stated value must agree with the engine.
        try {
            $engine = MiningCalculationPolicy::fromPlatform($cfg);
        } catch (Throwable) {
            $engine = null;
        }
        if ($engine !== null) {
            foreach ([
                'post_maturity_eligibility' => ['Post-maturity mining', $engine->maturity],
                'post_sale_eligibility'     => ['Post-sale mining', $engine->reserved],
            ] as $key => [$label, $want]) {
                $v = $p[$key] ?? null;
                if ($missing($v)) {
                    continue;
                }
                $add($key, $label, $want, $v, (string) $v === $want ? null : $key . ' must match the engine configuration (' . $want . '); found ' . self::show($v) . '.');
            }
        }

        return $rows;
    }

    /**
     * Inconsistencies that are not tied to one term (legacy field names, platform configuration drift).
     *
     * @param array<string, mixed> $p
     * @return list<string>
     */
    public static function consistencyProblems(array $p, ?Platform $cfg = null): array
    {
        $cfg ??= config('Platform');
        $bad = [];
        foreach (self::LEGACY_ALIASES as $legacy => $canonical) {
            if (array_key_exists($legacy, $p)) {
                $bad[] = 'Legacy field ' . $legacy . ' is present; the canonical field is ' . $canonical . '. Revise the policy.';
            }
        }
        if ($cfg->miningMonthDayDivisorMode !== MiningMath::DIVISOR_CALENDAR
            || $cfg->miningApprovalDayPolicy !== MiningCalculationPolicy::APPROVAL_EXCLUDE_DAY
            || $cfg->miningEarningAsset !== 'COIN'
            || $cfg->miningBusinessTimezone !== self::REQUIRED_TIMEZONE) {
            $bad[] = 'Platform mining configuration does not match the owner-approved rules.';
        }

        return $bad;
    }

    /**
     * Every problem (per-term + consistency) as plain strings.
     *
     * @param array<string, mixed> $p
     * @return list<string>
     */
    public static function problems(array $p, ?Platform $cfg = null): array
    {
        $out = [];
        foreach (self::requirements($p, $cfg) as $r) {
            if ($r['problem'] !== null) {
                $out[] = $r['problem'];
            }
        }

        return array_merge($out, self::consistencyProblems($p, $cfg));
    }

    /** Keys the review screen must always show, even when the payload does not contain them. */
    public static function mandatoryKeys(): array
    {
        return ['monthly_percent', 'rate_basis', 'month_day_divisor_mode', 'approval_day_policy', 'earning_asset', 'compounding_enabled', 'business_timezone', 'rounding_mode', 'policy_version'];
    }

    private static function show(mixed $v): string
    {
        return match (true) {
            $v === null || (is_string($v) && trim($v) === '') => 'missing',
            is_bool($v)                                      => $v ? 'true' : 'false',
            is_array($v)                                     => (string) json_encode($v, JSON_UNESCAPED_SLASHES),
            default                                          => (string) $v,
        };
    }
}
