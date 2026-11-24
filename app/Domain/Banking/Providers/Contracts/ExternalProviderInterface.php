<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Contracts;

/**
 * Minimal contract every external provider honours, whatever it does
 * (bank, payment gateway, card issuer, rail). Domain-specific contracts
 * (AccountProviderInterface, CardIssuerInterface, …) extend this shape
 * with their own operations; the registry, health checks, circuit
 * breaker and audit log only depend on this interface.
 */
interface ExternalProviderInterface
{
    public function getName(): string;

    public function getMode(): ProviderMode;

    public function isAvailable(): bool;

    public function healthCheck(): ProviderResult;
}
