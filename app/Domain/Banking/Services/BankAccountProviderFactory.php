<?php

declare(strict_types=1);

namespace App\Domain\Banking\Services;

use App\Domain\Banking\Connectors\SandboxBankAdapter;
use App\Domain\Banking\Contracts\AccountProviderInterface;
use App\Domain\Banking\Exceptions\AccountOpeningException;

/**
 * Resolves the configured bank-account provider. Production stays empty
 * until a partner bank is contracted: the default is the sandbox adapter,
 * which refuses to run in production, and any other name fails closed with
 * an explicit error instead of silently falling back to the sandbox.
 */
class BankAccountProviderFactory
{
    public function make(?string $providerName = null): AccountProviderInterface
    {
        $providerName ??= (string) config('banking.default_provider', 'sandbox');

        return match ($providerName) {
            'sandbox' => $this->sandbox(),
            'openbanking' => throw new AccountOpeningException(
                'Open Banking (PSD2) does not support account opening (capability account_opening=false). '.
                'Choose a partner-bank AccountProvider adapter instead — see config/banking.php.',
            ),
            default => throw new AccountOpeningException(
                "Unknown bank account provider: {$providerName}. No production bank provider is configured.",
            ),
        };
    }

    private function sandbox(): SandboxBankAdapter
    {
        if (! (bool) config('banking.providers.sandbox.enabled', true)) {
            throw new AccountOpeningException(
                'The sandbox bank provider is disabled (banking.providers.sandbox.enabled=false) and no production provider is configured.',
            );
        }

        return new SandboxBankAdapter((array) config('banking.providers.sandbox', []));
    }
}
