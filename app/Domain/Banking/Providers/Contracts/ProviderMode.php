<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Contracts;

/**
 * Where a provider actually runs. Sandbox providers simulate a partner
 * locally: they hold no real money, issue no real account numbers or
 * IBANs, and must never run in production.
 */
enum ProviderMode: string
{
    case SANDBOX = 'sandbox';
    case PRODUCTION = 'production';

    public function isSandbox(): bool
    {
        return $this === self::SANDBOX;
    }
}
