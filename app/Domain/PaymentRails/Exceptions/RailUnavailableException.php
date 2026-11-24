<?php

declare(strict_types=1);

namespace App\Domain\PaymentRails\Exceptions;

use RuntimeException;

/**
 * A payment rail cannot carry this payment: it is disabled, has no live
 * transmission endpoint, or no dedicated service exists for it. The
 * caller must surface this as an explicit failure — never by recording
 * a stub "pending" transaction that looks like money in flight.
 */
final class RailUnavailableException extends RuntimeException
{
}
