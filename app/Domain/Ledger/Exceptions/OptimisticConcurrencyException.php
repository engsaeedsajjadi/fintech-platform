<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * Thrown when a balance-affecting operation was attempted against a stale
 * aggregate version: another writer changed the ledger account first.
 * The caller must re-read the current state and retry the command — it must
 * never silently overwrite the other writer's change.
 */
final class OptimisticConcurrencyException extends RuntimeException
{
}
