<?php

declare(strict_types=1);

namespace App\Domain\International\Exceptions;

use App\Domain\International\Enums\InternationalRail;

/**
 * Thrown whenever code tries to execute an international rail that has
 * no registered provider. Fail-closed by design: the message names the
 * rail and states that no provider is connected, so the failure is
 * never mistaken for a transient error worth retrying.
 */
class InternationalRailNotAvailableException extends InternationalTransferException
{
    public static function forRail(InternationalRail $rail): self
    {
        return new self(
            "International rail '{$rail->value}' ({$rail->label()}) is not available: " .
            'no provider is registered, no endpoint or credential exists. ' .
            'Execution is disabled until a licensed provider contract, sandbox credentials ' .
            'and a compliance review exist. The transfer was not dispatched and no money moved.'
        );
    }
}
