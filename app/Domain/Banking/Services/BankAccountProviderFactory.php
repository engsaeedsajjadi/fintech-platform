<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Exceptions\AccountOpeningException;
use App\Domain\Banking\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Banking\Providers\Support\ProviderRegistry;

/**
 * Resolves the configured bank-account provider through the shared
 * {@see ProviderRegistry}. Production stays empty until a partner bank
 * is contracted: the default is the sandbox adapter, which refuses to
 * run in production, and any other name fails closed with an explicit
 * error instead of silently falling back to the sandbox.
 */
class BankAccountProviderFactory
{
    public function __construct(
        private readonly ?ProviderRegistry $registry = null,
    ) {
    }

    public function make(?string $providerName = null): AccountProviderInterface
    {
        $providerName ??= (string) config('banking.default_provider', 'sandbox');

        if ($providerName === 'openbanking') {
            throw new AccountOpeningException(
                'Open Banking (PSD2) does not support account opening (capability account_opening=false). ' .
                'Choose a partner-bank AccountProvider adapter instead — see config/banking.php.',
            );
        }

        $registry = $this->registry ?? new ProviderRegistry(
            sandboxAllowed: ! app()->environment('production'),
        );

        if ($providerName === 'sandbox') {
            $this->ensureSandboxRegistered($registry);
        }

        try {
            $provider = $registry->require($providerName);
        } catch (ProviderUnavailableException $e) {
            throw new AccountOpeningException(
                "Unknown bank account provider: {$providerName}. No production bank provider is configured.",
                previous: $e,
            );
        }

        if (! $provider instanceof AccountProviderInterface) {
            throw new AccountOpeningException(
                "Provider '{$providerName}' does not support account opening.",
            );
        }

        return $provider;
    }

    private function ensureSandboxRegistered(ProviderRegistry $registry): void
    {
        if ($registry->has('sandbox')) {
            return;
        }

        if (! (bool) config('banking.providers.sandbox.enabled', true)) {
            throw new AccountOpeningException(
                'The sandbox bank provider is disabled (banking.providers.sandbox.enabled=false) and no production provider is configured.',
            );
        }

        $registry->register('sandbox', new SandboxBankAdapter((array) config('banking.providers.sandbox', [])));
    }
}
