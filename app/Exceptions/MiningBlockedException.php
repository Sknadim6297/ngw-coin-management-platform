<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * A mining posting run was refused by a fail-closed pre-condition (policy, rate, incomplete business day,
 * frozen-input mismatch, authorization). Never an infrastructure failure; no ledger write was attempted.
 */
class MiningBlockedException extends RuntimeException
{
}
