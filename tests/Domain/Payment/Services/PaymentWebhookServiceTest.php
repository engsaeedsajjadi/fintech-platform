<?php

declare(strict_types=1);

use App\Domain\Payment\Models\PaymentTransaction;
use App\Domain\Payment\Services\DepositReplayGuard;
use App\Domain\Payment\Services\PaymentWebhookService;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->webhooks = new PaymentWebhookService(new DepositReplayGuard());
    $this->secret = 'test-webhook-secret';

    PaymentTransaction::create([
        'aggregate_uuid'     => Str::uuid()->toString(),
        'account_uuid'       => Str::uuid()->toString(),
        'type'               => 'deposit',
        'status'             => 'pending',
        'amount'             => 5000,
        'currency'           => 'USD',
        'reference'          => 'TEST-WH-1',
        'external_reference' => 'evt_123',
        'initiated_at'       => now(),
    ]);
});

it('applies a valid webhook exactly once across duplicate deliveries', function (): void {
    $rawBody = json_encode(['event_id' => 'evt_123', 'status' => 'completed'], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $rawBody, $this->secret);
    $applied = 0;

    $apply = function () use (&$applied): void {
        $applied++;
    };

    expect($this->webhooks->handle('stripe', 'evt_123', $rawBody, $signature, $this->secret, $apply))
        ->toBe('applied')
        ->and($this->webhooks->handle('stripe', 'evt_123', $rawBody, $signature, $this->secret, $apply))
        ->toBe('duplicate')
        ->and($applied)->toBe(1);
});

it('rejects a webhook with a bad signature before any state change', function (): void {
    $rawBody = json_encode(['event_id' => 'evt_456', 'status' => 'completed'], JSON_THROW_ON_ERROR);
    $applied = 0;

    $apply = function () use (&$applied): void {
        $applied++;
    };

    expect($this->webhooks->handle('stripe', 'evt_456', $rawBody, 'wrong-signature', $this->secret, $apply))
        ->toBe('invalid-signature')
        ->and($applied)->toBe(0);

    // And again with an empty secret: fail closed, never verify.
    expect($this->webhooks->handle('stripe', 'evt_456', $rawBody, hash_hmac('sha256', $rawBody, $this->secret), '', $apply))
        ->toBe('invalid-signature')
        ->and($applied)->toBe(0);
});

it('releases the claim when the handler throws so a redelivery can retry', function (): void {
    $rawBody = json_encode(['event_id' => 'evt_789', 'status' => 'completed'], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $rawBody, $this->secret);
    $attempts = 0;

    $failing = function () use (&$attempts): void {
        $attempts++;
        throw new RuntimeException('transient apply failure');
    };

    expect(fn () => $this->webhooks->handle('stripe', 'evt_789', $rawBody, $signature, $this->secret, $failing))
        ->toThrow(RuntimeException::class, 'transient apply failure');

    $applied = 0;
    $succeeding = function () use (&$applied): void {
        $applied++;
    };

    expect($this->webhooks->handle('stripe', 'evt_789', $rawBody, $signature, $this->secret, $succeeding))
        ->toBe('applied')
        ->and($applied)->toBe(1)
        ->and($attempts)->toBe(1);
});
