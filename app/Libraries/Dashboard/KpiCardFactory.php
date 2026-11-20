<?php

declare(strict_types=1);

namespace App\Libraries\Dashboard;

/**
 * Builds consistent KPI card payloads for admin/member dashboards.
 *
 * @phpstan-type KpiCard array{
 *   key: string,
 *   label: string,
 *   value: string|int|null,
 *   display: string,
 *   unit: string|null,
 *   status: 'ok'|'unavailable'|'empty'|'error',
 *   accent: string,
 *   icon: string,
 *   href: string|null,
 *   reason: string|null
 * }
 */
final class KpiCardFactory
{
    /**
     * @return KpiCard
     */
    public static function ok(
        string $key,
        string $label,
        string|int $value,
        string $accent,
        string $icon,
        ?string $unit = null,
        ?string $href = null
    ): array {
        $display = is_int($value) ? (string) $value : $value;

        return [
            'key'     => $key,
            'label'   => $label,
            'value'   => $value,
            'display' => $display,
            'unit'    => $unit,
            'status'  => 'ok',
            'accent'  => $accent,
            'icon'    => $icon,
            'href'    => $href,
            'reason'  => null,
        ];
    }

    /**
     * Ledger-backed financial amount shown with full precision (no trailing-zero trimming) plus a status note.
     *
     * @return KpiCard
     */
    public static function exact(
        string $key,
        string $label,
        string|int $value,
        string $accent,
        string $icon,
        ?string $unit = null,
        ?string $href = null,
        ?string $note = null
    ): array {
        return self::ok($key, $label, $value, $accent, $icon, $unit, $href) + [
            'exact' => true,
            'note'  => $note,
        ];
    }

    /**
     * Metric exists in the product roadmap but has no ledger/service yet.
     *
     * @return KpiCard
     */
    public static function unavailable(
        string $key,
        string $label,
        string $accent,
        string $icon,
        string $reason = 'Not available',
        ?string $href = null
    ): array {
        return [
            'key'     => $key,
            'label'   => $label,
            'value'   => null,
            'display' => 'Not available',
            'unit'    => null,
            'status'  => 'unavailable',
            'accent'  => $accent,
            'icon'    => $icon,
            'href'    => $href,
            'reason'  => $reason,
        ];
    }

    /**
     * @return KpiCard
     */
    public static function error(
        string $key,
        string $label,
        string $accent,
        string $icon,
        string $reason = 'Unable to load'
    ): array {
        return [
            'key'     => $key,
            'label'   => $label,
            'value'   => null,
            'display' => 'Error',
            'unit'    => null,
            'status'  => 'error',
            'accent'  => $accent,
            'icon'    => $icon,
            'href'    => null,
            'reason'  => $reason,
        ];
    }
}
