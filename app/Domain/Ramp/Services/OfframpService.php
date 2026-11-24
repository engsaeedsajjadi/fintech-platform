<?php

declare(strict_types=1);

namespace App\Domain\Ramp\Services;

use App\Domain\Ramp\Exceptions\OfframpNotAvailableException;

/**
 * Off-ramp (crypto -> fiat) is explicitly NOT available in v1: the
 * platform only ships on-ramp (Bridge: bank-rail on-ramp only). This
 * service is the single named place that states that — it always throws
 * OfframpNotAvailableException and never fakes a settlement, a quote, or
 * a payout. Off-ramp lands only with a licensed provider (v1.1).
 */
final class OfframpService
{
    public function requestOfframp(string $provider, array $request = []): never
    {
        throw new OfframpNotAvailableException($provider);
    }

    public function isAvailable(string $provider): bool
    {
        return false;
    }
}
