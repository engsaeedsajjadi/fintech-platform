<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Atomic idempotency claims for provider calls.
 *
 * `claim()` succeeds exactly once per key (Cache::add is atomic);
 * a replayed key returns false and the caller must return the stored
 * result from `fetch()` instead of calling the provider again. The
 * result is stored by `complete()` and lives for
 * `providers.idempotency.ttl_seconds`.
 */
final class IdempotencyStore
{
    public function __construct(
        private readonly string $scope,
    ) {
    }

    public function claim(string $key): bool
    {
        return Cache::add($this->claimKey($key), 1, $this->ttlSeconds());
    }

    /** @param  array<string, mixed>  $result */
    public function complete(string $key, array $result): void
    {
        Cache::put($this->resultKey($key), $result, $this->ttlSeconds());
    }

    /** @return array<string, mixed>|null */
    public function fetch(string $key): ?array
    {
        $result = Cache::get($this->resultKey($key));

        return is_array($result) ? $result : null;
    }

    private function claimKey(string $key): string
    {
        return "idempotency:{$this->scope}:{$key}:claim";
    }

    private function resultKey(string $key): string
    {
        return "idempotency:{$this->scope}:{$key}:result";
    }

    private function ttlSeconds(): int
    {
        return (int) config('providers.idempotency.ttl_seconds', 86400);
    }
}
