<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use App\Domain\Banking\Providers\Exceptions\ProviderUnavailableException;
use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed circuit breaker, one per provider.
 *
 * After `failure_threshold` consecutive failures the breaker opens and
 * {@see guard()} refuses calls for `open_seconds`, so a dead provider
 * stops burning timeouts (and stops holding request threads). A success
 * closes it again. The breaker never invents an outcome: while open,
 * callers get an explicit {@see ProviderUnavailableException}.
 */
final class CircuitBreaker
{
    public function __construct(
        private readonly string $provider,
    ) {
    }

    public function guard(): void
    {
        if ($this->isOpen()) {
            throw ProviderUnavailableException::circuitOpen($this->provider);
        }
    }

    public function isOpen(): bool
    {
        $openedAt = Cache::get($this->openedKey());
        if (! is_int($openedAt)) {
            return false;
        }

        if (time() - $openedAt >= $this->openSeconds()) {
            // Open window elapsed: allow a probe; the next failure re-opens.
            Cache::forget($this->openedKey());
            Cache::put($this->failuresKey(), 0, $this->openSeconds());

            return false;
        }

        return true;
    }

    public function recordSuccess(): void
    {
        Cache::forget($this->failuresKey());
        Cache::forget($this->openedKey());
    }

    public function recordFailure(): void
    {
        $failures = (int) Cache::get($this->failuresKey(), 0) + 1;
        Cache::put($this->failuresKey(), $failures, $this->openSeconds() * 2);

        if ($failures >= $this->failureThreshold()) {
            Cache::put($this->openedKey(), time(), $this->openSeconds());
        }
    }

    private function failuresKey(): string
    {
        return "provider_breaker:{$this->provider}:failures";
    }

    private function openedKey(): string
    {
        return "provider_breaker:{$this->provider}:opened_at";
    }

    private function failureThreshold(): int
    {
        return (int) config('providers.circuit_breaker.failure_threshold', 5);
    }

    private function openSeconds(): int
    {
        return (int) config('providers.circuit_breaker.open_seconds', 60);
    }
}
