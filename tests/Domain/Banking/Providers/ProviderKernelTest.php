<?php

declare(strict_types=1);

use App\Domain\Banking\Providers\Contracts\ExternalProviderInterface;
use App\Domain\Banking\Providers\Contracts\ProviderMode;
use App\Domain\Banking\Providers\Contracts\ProviderOutcome;
use App\Domain\Banking\Providers\Contracts\ProviderResult;
use App\Domain\Banking\Providers\Exceptions\ProviderUnavailableException;
use App\Domain\Banking\Providers\Support\CircuitBreaker;
use App\Domain\Banking\Providers\Support\IdempotencyStore;
use App\Domain\Banking\Providers\Support\ProviderAuditLogger;
use App\Domain\Banking\Providers\Support\ProviderRateLimiter;
use App\Domain\Banking\Providers\Support\ProviderRegistry;
use App\Domain\Banking\Providers\Support\RequestSigner;
use App\Domain\Banking\Providers\Support\WebhookVerifier;
use Illuminate\Support\Facades\Log;

function kernelFakeProvider(string $name, ProviderMode $mode = ProviderMode::SANDBOX): ExternalProviderInterface
{
    return new class ($name, $mode) implements ExternalProviderInterface {
        public function __construct(private readonly string $name, private readonly ProviderMode $mode)
        {
        }

        public function getName(): string
        {
            return $this->name;
        }

        public function getMode(): ProviderMode
        {
            return $this->mode;
        }

        public function isAvailable(): bool
        {
            return true;
        }

        public function healthCheck(): ProviderResult
        {
            return ProviderResult::success($this->name, 'ok');
        }
    };
}

it('registry resolves a registered provider and fails closed on unknown names', function (): void {
    $registry = new ProviderRegistry();
    $registry->register('sandbox-bank', kernelFakeProvider('sandbox-bank'));

    expect($registry->resolve('sandbox-bank')->getName())->toBe('sandbox-bank')
        ->and($registry->has('sandbox-bank'))->toBeTrue()
        ->and($registry->has('nope'))->toBeFalse();

    $registry->require('nope');
})->throws(ProviderUnavailableException::class, 'Unknown provider');

it('registry refuses to register a sandbox provider when sandbox mode is disallowed', function (): void {
    $registry = new ProviderRegistry(sandboxAllowed: false);

    $registry->register('sandbox-bank', kernelFakeProvider('sandbox-bank'));
})->throws(ProviderUnavailableException::class, 'production');

it('registry lists only sandbox providers when asked', function (): void {
    $registry = new ProviderRegistry();
    $registry->register('sandbox-bank', kernelFakeProvider('sandbox-bank'));
    $registry->register('live-bank', kernelFakeProvider('live-bank', ProviderMode::PRODUCTION));

    expect($registry->names())->toContain('sandbox-bank', 'live-bank')
        ->and($registry->sandboxNames())->toBe(['sandbox-bank']);
});

it('provider result carries explicit failed and unknown outcomes, never a fake success', function (): void {
    $failed = ProviderResult::failed('stripe', 'card_declined', 'Card was declined.');
    $unknown = ProviderResult::unknown('stripe', 'timeout', 'No response within 30s.');

    expect($failed->outcome)->toBe(ProviderOutcome::FAILED)
        ->and($failed->isSuccess())->toBeFalse()
        ->and($unknown->outcome)->toBe(ProviderOutcome::UNKNOWN)
        ->and($unknown->isSuccess())->toBeFalse()
        ->and($unknown->isUnknown())->toBeTrue()
        ->and(ProviderResult::success('stripe', 'ok')->isSuccess())->toBeTrue();
});

it('circuit breaker opens after the failure threshold and refuses calls while open', function (): void {
    config(['providers.circuit_breaker.failure_threshold' => 3, 'providers.circuit_breaker.open_seconds' => 60]);

    $breaker = new CircuitBreaker('test-provider');

    expect($breaker->isOpen())->toBeFalse();

    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeFalse();

    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    $breaker->guard();
})->throws(ProviderUnavailableException::class, 'circuit breaker is open');

it('circuit breaker closes again after a success', function (): void {
    config(['providers.circuit_breaker.failure_threshold' => 2, 'providers.circuit_breaker.open_seconds' => 60]);

    $breaker = new CircuitBreaker('test-provider-2');
    $breaker->recordFailure();
    $breaker->recordFailure();
    expect($breaker->isOpen())->toBeTrue();

    $breaker->recordSuccess();
    expect($breaker->isOpen())->toBeFalse();
});

it('webhook verifier accepts a valid HMAC signature and rejects bad, empty and replayed ones', function (): void {
    config(['providers.webhook.tolerance_seconds' => 300]);

    $verifier = new WebhookVerifier();
    $secret = 'whsec-test-secret';
    $body = '{"type":"payment.succeeded","id":"evt_1"}';
    $timestamp = (string) time();
    $signature = hash_hmac('sha256', $timestamp . '.' . $body, $secret);

    expect($verifier->verify($body, $signature, $secret, $timestamp))->toBeTrue()
        ->and($verifier->verify($body, 'deadbeef', $secret, $timestamp))->toBeFalse()
        ->and($verifier->verify($body, $signature, '', $timestamp))->toBeFalse()
        // Replay: the same (body, signature, timestamp) twice is rejected.
        ->and($verifier->verify($body, $signature, $secret, $timestamp))->toBeFalse()
        // Stale timestamp outside the tolerance window is rejected.
        ->and($verifier->verify($body, hash_hmac('sha256', ((string) (time() - 3600)) . '.' . $body, $secret), $secret, (string) (time() - 3600)))->toBeFalse();
});

it('request signer signs and verifies a canonical payload', function (): void {
    $signer = new RequestSigner();
    $secret = 'signing-secret';
    $payload = ['amount' => '10.00', 'currency' => 'EUR'];

    $signature = $signer->sign($payload, $secret);

    expect($signer->verify($payload, $signature, $secret))->toBeTrue()
        ->and($signer->verify(['amount' => '20.00', 'currency' => 'EUR'], $signature, $secret))->toBeFalse();
});

it('idempotency store claims a key once and returns the stored result on replay', function (): void {
    $store = new IdempotencyStore('test-scope');

    expect($store->claim('key-1'))->toBeTrue()
        ->and($store->claim('key-1'))->toBeFalse();

    $store->complete('key-1', ['status' => 'successful']);

    expect($store->fetch('key-1'))->toBe(['status' => 'successful']);
});

it('provider rate limiter blocks after the configured attempts', function (): void {
    config(['providers.rate_limit.max_attempts' => 2, 'providers.rate_limit.window_seconds' => 60]);

    $limiter = new ProviderRateLimiter();

    expect($limiter->attempt('stripe', 'user-1'))->toBeTrue()
        ->and($limiter->attempt('stripe', 'user-1'))->toBeTrue()
        ->and($limiter->attempt('stripe', 'user-1'))->toBeFalse()
        ->and($limiter->attempt('stripe', 'user-2'))->toBeTrue();
});

it('audit logger redacts secrets and masks account numbers', function (): void {
    Log::spy();

    $logger = new ProviderAuditLogger();
    $logger->log('stripe', 'charge', [
        'api_key'        => 'sk_live_secret',
        'account_number' => 'DE89370400440532013000',
        'amount'         => '10.00',
    ]);

    Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context): bool {
        $encoded = json_encode($context);

        return str_contains($message, 'provider_audit')
            && ! str_contains($encoded, 'sk_live_secret')
            && ! str_contains($encoded, 'DE89370400440532013000')
            && str_contains($encoded, '10.00');
    });
});
