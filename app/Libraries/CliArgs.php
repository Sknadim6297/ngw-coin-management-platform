<?php

declare(strict_types=1);

namespace App\Libraries;

use CodeIgniter\CLI\CLI;

/**
 * Strict argument validation for financial Spark commands.
 *
 * CodeIgniter's CLI parser only understands `--option value`. It silently turns `--group=phase13b` into an
 * option literally named "group=phase13b" (value null), so `CLI::getOption('group')` returns null and a command
 * quietly falls back to its DEFAULT database group (the live DB). This guard re-reads the raw argv and
 * fails closed on: `--opt=value`, unknown options, missing values, flags given a value, duplicates, short
 * options, unexpected positionals, conflicting flags, invalid values and unsupported database groups.
 *
 * Spec keys: values (name => validator|null), flags (list), positionals (int max), conflicts (list of pairs),
 * required (list). Validators: 'int' | 'date' | 'int-positive' | list<string> (allowed) | regex string (/.../).
 */
final class CliArgs
{
    /** Every database group any financial command may legitimately be pointed at. */
    public const DB_GROUPS = ['default', 'phase12a', 'phase12b', 'phase12d', 'phase12f', 'phase13a', 'phase13b', 'phase13c', 'phase14'];

    /**
     * @param list<string>        $tokens tokens that follow the command name
     * @param array<string, mixed> $spec
     * @return array{options: array<string, string|true>, positionals: list<string>, errors: list<string>}
     */
    public static function parse(array $tokens, array $spec): array
    {
        $values   = (array) ($spec['values'] ?? []);
        $flags    = array_values((array) ($spec['flags'] ?? []));
        $maxPos   = (int) ($spec['positionals'] ?? 0);
        $errors   = [];
        $options  = [];
        $positionals = [];

        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = (string) $tokens[$i];

            if (str_starts_with($t, '--')) {
                $name = substr($t, 2);
                if ($name === '') {
                    $errors[] = 'Bare "--" is not supported.';

                    continue;
                }
                if (str_contains($name, '=')) {
                    $bare     = explode('=', $name, 2)[0];
                    $errors[] = sprintf('Unsupported syntax "%s". Use "--%s <value>" (space-separated); "=" is not parsed by the CI4 CLI and would be ignored.', $t, $bare);

                    continue;
                }
                $isValue = array_key_exists($name, $values);
                $isFlag  = in_array($name, $flags, true);
                if (! $isValue && ! $isFlag) {
                    $errors[] = 'Unknown option "--' . $name . '".';

                    continue;
                }
                if (array_key_exists($name, $options)) {
                    $errors[] = 'Option "--' . $name . '" was given more than once.';
                    if ($isValue && $i + 1 < $n && ! str_starts_with((string) $tokens[$i + 1], '-')) {
                        $i++;
                    }

                    continue;
                }
                if ($isFlag) {
                    $options[$name] = true;

                    continue;
                }

                $next = $i + 1 < $n ? (string) $tokens[$i + 1] : null;
                if ($next === null || str_starts_with($next, '--') || $next === '') {
                    $errors[] = 'Option "--' . $name . '" requires a value.';

                    continue;
                }
                $i++;
                $options[$name] = $next;
                $err = self::validateValue($name, $next, $values[$name]);
                if ($err !== null) {
                    $errors[] = $err;
                }

                continue;
            }

            if (str_starts_with($t, '-') && strlen($t) > 1 && ! is_numeric($t)) {
                $errors[] = 'Short option "' . $t . '" is not supported.';

                continue;
            }

            $positionals[] = $t;
        }

        if (count($positionals) > $maxPos) {
            $errors[] = 'Unexpected argument(s): ' . implode(' ', array_slice($positionals, $maxPos))
                . ($maxPos === 0 ? ' (this command takes only --options).' : '');
        }

        foreach ((array) ($spec['conflicts'] ?? []) as $pair) {
            if (isset($options[$pair[0]], $options[$pair[1]])) {
                $errors[] = 'Conflicting options: --' . $pair[0] . ' and --' . $pair[1] . ' cannot be combined.';
            }
        }
        foreach ((array) ($spec['required'] ?? []) as $req) {
            if (! array_key_exists($req, $options)) {
                $errors[] = 'Missing required option --' . $req . '.';
            }
        }

        return ['options' => $options, 'positionals' => $positionals, 'errors' => $errors];
    }

    /**
     * @param mixed $validator
     */
    private static function validateValue(string $name, string $value, mixed $validator): ?string
    {
        if ($validator === null) {
            return null;
        }
        if (is_array($validator)) {
            return in_array($value, $validator, true) ? null : sprintf('Invalid value "%s" for --%s (allowed: %s).', $value, $name, implode(', ', $validator));
        }
        if ($validator === 'int') {
            return preg_match('/^\d+$/', $value) ? null : sprintf('--%s must be a whole number (got "%s").', $name, $value);
        }
        if ($validator === 'int-positive') {
            return preg_match('/^[1-9]\d*$/', $value) ? null : sprintf('--%s must be a positive whole number (got "%s").', $name, $value);
        }
        if ($validator === 'date') {
            return preg_match('/^(today|yesterday|\d{4}-\d{2}-\d{2})$/', $value) ? null : sprintf('--%s must be today, yesterday or YYYY-MM-DD (got "%s").', $name, $value);
        }
        if (is_string($validator) && str_starts_with($validator, '/')) {
            return preg_match($validator, $value) ? null : sprintf('Invalid value "%s" for --%s.', $value, $name);
        }

        return null;
    }

    /**
     * Tokens that follow the command name in the real process argv.
     *
     * @param list<string>|null $argv
     * @return list<string>
     */
    public static function tokensAfter(string $command, ?array $argv = null): array
    {
        $argv ??= (array) ($_SERVER['argv'] ?? []);
        $argv = array_values(array_map('strval', $argv));
        $idx  = array_search($command, $argv, true);
        if ($idx === false) {
            // Not launched through `spark <command>` (e.g. a PHPUnit process): there is no CLI syntax to validate.
            return (defined('ENVIRONMENT') && ENVIRONMENT === 'testing') ? [] : array_slice($argv, 2);
        }

        return array_slice($argv, $idx + 1);
    }

    /**
     * Validate the live argv; prints every error and returns null when the command must not run.
     *
     * @param array<string, mixed> $spec
     * @param list<string>|null    $argv
     * @return array<string, string|true>|null parsed options
     */
    public static function guard(string $command, array $spec, ?array $argv = null): ?array
    {
        $parsed = self::parse(self::tokensAfter($command, $argv), $spec);
        if ($parsed['errors'] === []) {
            return $parsed['options'];
        }

        foreach ($parsed['errors'] as $e) {
            CLI::error('[' . $command . '] ' . $e);
        }
        CLI::error('Nothing was executed. See: php spark help ' . $command);

        return null;
    }
}
