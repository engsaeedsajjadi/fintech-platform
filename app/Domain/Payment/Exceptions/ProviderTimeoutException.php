<?php

declare(strict_types=1);

namespace App\Domain\Payment\Exceptions;

use RuntimeException;

/**
 * The provider call timed out or returned an ambiguous outcome. The
 * transfer's outcome is UNKNOWN — it is never treated as failed, and no
 * hold is released. Resolution happens only via a status query, a webhook,
 * or manual review (PaymentTransferService::resolveUnknown()).
 */
final class ProviderTimeoutException extends RuntimeException
{
}
