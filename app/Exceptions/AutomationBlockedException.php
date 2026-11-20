<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a Financial Automation switch cannot be turned ON because prerequisites are missing.
 * Carries the human-readable blockers so the admin UI can explain exactly what is missing.
 */
class AutomationBlockedException extends RuntimeException
{
    /**
     * @param list<string> $blockers
     */
    public function __construct(private readonly array $blockers, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'Activation blocked: ' . implode(' | ', $blockers));
    }

    /** @return list<string> */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
