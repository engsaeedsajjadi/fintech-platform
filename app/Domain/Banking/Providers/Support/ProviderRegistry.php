<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use App\Domain\Banking\Providers\Contracts\ExternalProviderInterface;
use App\Domain\Banking\Providers\Contracts\ProviderMode;
use App\Domain\Banking\Providers\Exceptions\ProviderUnavailableException;

/**
 * The single place providers are registered and resolved.
 *
 * Rules:
 * - An unknown name fails closed ({@see ProviderUnavailableException});
 *   there is no silent fallback to the sandbox or to any other provider.
 * - A sandbox provider is refused when sandbox mode is disallowed
 *   (production). The default follows the app environment.
 * - Registration is explicit: a provider that was never registered does
 *   not exist, however plausible its name looks in config.
 */
final class ProviderRegistry
{
    /** @var array<string, ExternalProviderInterface> */
    private array $providers = [];

    public function __construct(
        private readonly bool $sandboxAllowed = true,
    ) {
    }

    public function register(string $name, ExternalProviderInterface $provider): void
    {
        if ($provider->getMode()->isSandbox() && ! $this->sandboxAllowed) {
            throw ProviderUnavailableException::sandboxInProduction($name);
        }

        $this->providers[$name] = $provider;
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    public function resolve(string $name): ?ExternalProviderInterface
    {
        return $this->providers[$name] ?? null;
    }

    public function require(string $name): ExternalProviderInterface
    {
        $provider = $this->resolve($name);
        if ($provider === null) {
            throw ProviderUnavailableException::unknown($name);
        }

        return $provider;
    }

    /** @return string[] */
    public function names(): array
    {
        return array_keys($this->providers);
    }

    /** @return string[] */
    public function sandboxNames(): array
    {
        return array_keys(array_filter(
            $this->providers,
            static fn (ExternalProviderInterface $p): bool => $p->getMode() === ProviderMode::SANDBOX,
        ));
    }
}
