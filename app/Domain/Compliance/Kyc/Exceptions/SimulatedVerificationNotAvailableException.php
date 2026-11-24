<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Kyc\Exceptions;

use RuntimeException;

/**
 * Thrown when a simulated (sandbox/demo) verification provider is invoked
 * outside an allowed sandbox environment — i.e. in production, or when
 * simulated providers are disabled by config.
 *
 * This is a fail-closed signal: the caller must route the verification to
 * manual review (or a real provider), never treat the absence of a result
 * as a pass.
 */
final class SimulatedVerificationNotAvailableException extends RuntimeException
{
    public static function for(string $check): self
    {
        return new self(sprintf(
            'Simulated %s verification is not available outside a sandbox environment. '
            . 'No real provider is configured; the verification requires manual review.',
            $check,
        ));
    }
}
