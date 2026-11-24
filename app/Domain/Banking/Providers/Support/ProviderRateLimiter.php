<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Per-provider rate limiting (Laravel's RateLimiter facade). The key is
 * namespaced per provider so one abusive caller cannot exhaust another
 * provider's budget, and a provider's own limit never blocks an
 * unrelated provider.
 */
final class ProviderRateLimiter
{
    public function attempt(string $provider, string $key, ?int $maxAttempts = null, ?int $windowSeconds = null): bool
    {
        $maxAttempts ??= (int) config('providers.rate_limit.max_attempts', 100);
        $windowSeconds ??= (int) config('providers.rate_limit.window_seconds', 60);

        return RateLimiter::attempt(
            "provider:{$provider}:{$key}",
            $maxAttempts,
            static fn (): bool => true,
            $windowSeconds,
        );
    }

    public function remaining(string $provider, string $key, ?int $maxAttempts = null): int
    {
        $maxAttempts ??= (int) config('providers.rate_limit.max_attempts', 100);

        return RateLimiter::remaining("provider:{$provider}:{$key}", $maxAttempts);
    }
}
