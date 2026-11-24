<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Exceptions;

use RuntimeException;

/**
 * A provider is unknown, unconfigured, unavailable, or its circuit
 * breaker is open. Callers surface this as an explicit failure — never
 * as a fake success and never by silently falling back to a sandbox.
 */
final class ProviderUnavailableException extends RuntimeException
{
    public static function unknown(string $name): self
    {
        return new self("Unknown provider: {$name}. No provider with that name is registered.");
    }

    public static function sandboxInProduction(string $name): self
    {
        return new self(
            "Sandbox provider '{$name}' must never run in production. Configure a real provider with official API credentials.",
        );
    }

    public static function circuitOpen(string $name): self
    {
        return new self("Provider '{$name}' is unavailable: circuit breaker is open after repeated failures.");
    }
}
