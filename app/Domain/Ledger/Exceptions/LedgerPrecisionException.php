<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Exceptions;

use RuntimeException;

/**
 * A ledger amount cannot be represented exactly at the posting scale
 * (e.g. a crypto amount needing 8 decimals on a 4-decimal ledger).
 * Fail-closed: the caller must use minor units or wait for the precision
 * migration — the amount is refused, never silently truncated.
 */
final class LedgerPrecisionException extends RuntimeException
{
}
