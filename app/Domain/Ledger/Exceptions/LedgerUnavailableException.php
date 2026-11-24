<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * The ledger (or its external driver) is unavailable: the caller must fail
 * closed. This exception exists so a balance query can never fall back to a
 * fabricated '0.0000' — an unavailable ledger is an error, not a zero.
 */
final class LedgerUnavailableException extends RuntimeException
{
}
