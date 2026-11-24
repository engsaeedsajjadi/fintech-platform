<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Contracts;

/**
 * Provider-independent result of one provider call. Callers branch on
 * {@see ProviderOutcome} — never on a truthy response or a fabricated id.
 */
final readonly class ProviderResult
{
    /**
     * @param  array<string, mixed>  $data  Provider payload, already
     *                                       stripped of secrets by the caller.
     */
    public function __construct(
        public ProviderOutcome $outcome,
        public string $provider,
        public string $message,
        public ?string $reference = null,
        public array $data = [],
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(string $provider, string $message, ?string $reference = null, array $data = []): self
    {
        return new self(ProviderOutcome::SUCCESS, $provider, $message, $reference, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function failed(string $provider, string $code, string $message, array $data = []): self
    {
        return new self(ProviderOutcome::FAILED, $provider, $message, null, array_merge($data, ['code' => $code]));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function unknown(string $provider, string $code, string $message, array $data = []): self
    {
        return new self(ProviderOutcome::UNKNOWN, $provider, $message, null, array_merge($data, ['code' => $code]));
    }

    public function isSuccess(): bool
    {
        return $this->outcome === ProviderOutcome::SUCCESS;
    }

    public function isFailed(): bool
    {
        return $this->outcome === ProviderOutcome::FAILED;
    }

    public function isUnknown(): bool
    {
        return $this->outcome === ProviderOutcome::UNKNOWN;
    }
}
