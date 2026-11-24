<?php

declare(strict_types=1);

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Payment\Enums\PaymentIntentDirection;
use App\Domain\Payment\Enums\PaymentIntentKind;
use App\Domain\Payment\Models\PaymentIntent;
use App\Domain\Payment\Services\PaymentIntentService;
use App\Models\User;

function intentService(): PaymentIntentService
{
    return app(PaymentIntentService::class);
}

function intentData(array $overrides = []): array
{
    return array_merge([
        'user_id'      => User::factory()->create()->id,
        'direction'    => PaymentIntentDirection::OUTGOING,
        'kind'         => PaymentIntentKind::TRANSFER,
        'amount_minor' => 10050,
        'currency'     => 'EUR',
        'provider'     => 'stripe',
    ], $overrides);
}

it('creates a pending intent with exact integer minor units and an idempotency key', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-1');

    expect($intent->state)->toBe(TransactionState::INITIATED)
        ->and($intent->amount_minor)->toBe(10050)
        ->and($intent->currency)->toBe('EUR')
        ->and($intent->direction)->toBe(PaymentIntentDirection::OUTGOING)
        ->and($intent->kind)->toBe(PaymentIntentKind::TRANSFER)
        ->and($intent->idempotency_key)->toBe('intent-key-1')
        ->and($intent->provider_reference)->toBeNull();
});

it('returns the same intent for a duplicate create with the same idempotency key', function (): void {
    $first = intentService()->create(intentData(), 'intent-key-dup');
    $second = intentService()->create(intentData(['amount_minor' => 99999]), 'intent-key-dup');

    expect($second->id)->toBe($first->id)
        ->and($second->amount_minor)->toBe(10050)
        ->and(PaymentIntent::query()->where('idempotency_key', 'intent-key-dup')->count())->toBe(1);
});

it('rejects non-positive and non-integer amounts — money is never a float here', function (): void {
    intentService()->create(intentData(['amount_minor' => 0]), 'intent-key-zero');
})->throws(InvalidArgumentException::class, 'amount_minor');

it('rejects an unknown provider direction/kind combination explicitly', function (): void {
    intentService()->create(intentData(['currency' => 'EURO']), 'intent-key-ccy');
})->throws(InvalidArgumentException::class, 'currency');

it('walks the legal lifecycle initiated → processing → successful and then refuses further transitions', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-life');
    $intent = intentService()->transition($intent, TransactionState::PROCESSING, 'submitted to provider');
    expect($intent->state)->toBe(TransactionState::PROCESSING);

    $intent = intentService()->transition($intent, TransactionState::SUCCESSFUL, 'provider confirmed');
    expect($intent->state)->toBe(TransactionState::SUCCESSFUL)
        ->and($intent->resolved_at)->not->toBeNull();

    intentService()->transition($intent, TransactionState::FAILED, 'too late');
})->throws(InvalidArgumentException::class, 'Terminal');

it('marks an ambiguous outcome unknown and resolves it only with evidence', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-unknown');
    $intent = intentService()->transition($intent, TransactionState::PROCESSING);
    $intent = intentService()->transition($intent, TransactionState::UNKNOWN, 'provider timeout');

    expect($intent->state)->toBe(TransactionState::UNKNOWN)
        ->and($intent->unknown_since_at)->not->toBeNull();

    // Resolving to "processing" is not evidence — it must be successful or failed.
    intentService()->resolveUnknown($intent, TransactionState::PROCESSING, 'still waiting');
})->throws(InvalidArgumentException::class, 'evidence');

it('resolves an unknown intent to successful on provider confirmation', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-resolve');
    $intent = intentService()->transition($intent, TransactionState::PROCESSING);
    $intent = intentService()->transition($intent, TransactionState::UNKNOWN, 'timeout');

    $resolved = intentService()->resolveUnknown($intent, TransactionState::SUCCESSFUL, 'status query confirmed settlement');

    expect($resolved->state)->toBe(TransactionState::SUCCESSFUL)
        ->and($resolved->metadata['resolution_reason'] ?? '')->toContain('status query confirmed');
});

it('reconciles a provider status onto the intent and sends unclassifiable states to manual review', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-reconcile');

    $reconciled = intentService()->reconcile($intent, 'completed', 'prov_123');

    expect($reconciled->state)->toBe(TransactionState::SUCCESSFUL)
        ->and($reconciled->provider_reference)->toBe('prov_123');

    $second = intentService()->create(intentData(), 'intent-key-reconcile-2');
    $review = intentService()->reconcile($second, 'weird_provider_state', 'prov_456');

    expect($review->state)->toBe(TransactionState::REQUIRES_MANUAL_REVIEW);
});

it('reconciliation never moves a terminal intent', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-reconcile-term');
    $intent = intentService()->transition($intent, TransactionState::FAILED, 'declined');

    intentService()->reconcile($intent, 'completed', 'prov_999');
})->throws(InvalidArgumentException::class, 'Terminal');

it('creates a linked refund intent only from a successful original', function (): void {
    $original = intentService()->create(intentData(), 'intent-key-refund-src');
    $original = intentService()->transition($original, TransactionState::PROCESSING);
    $original = intentService()->transition($original, TransactionState::SUCCESSFUL);

    $refund = intentService()->refund($original, 5000, 'intent-key-refund-1');

    expect($refund->kind)->toBe(PaymentIntentKind::REFUND)
        ->and($refund->direction)->toBe(PaymentIntentDirection::INCOMING)
        ->and($refund->amount_minor)->toBe(5000)
        ->and($refund->reverses_intent_id)->toBe($original->id)
        ->and($refund->state)->toBe(TransactionState::INITIATED);

    // A refund may not exceed the original amount.
    intentService()->refund($original, 10051, 'intent-key-refund-2');
})->throws(InvalidArgumentException::class, 'exceed');

it('refuses a refund from a non-successful intent', function (): void {
    $original = intentService()->create(intentData(), 'intent-key-refund-bad');

    intentService()->refund($original, 100, 'intent-key-refund-3');
})->throws(InvalidArgumentException::class, 'successful');

it('exposes a normalized provider-independent transaction shape', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-norm');
    $normalized = $intent->normalized();

    expect($normalized['intent_id'])->toBe($intent->id)
        ->and($normalized['direction'])->toBe('outgoing')
        ->and($normalized['kind'])->toBe('transfer')
        ->and($normalized['state'])->toBe('initiated')
        ->and($normalized['amount_minor'])->toBe(10050)
        ->and($normalized['amount'])->toBe('100.50')
        ->and($normalized['currency'])->toBe('EUR')
        ->and($normalized['provider'])->toBe('stripe')
        ->and(array_key_exists('provider_reference', $normalized))->toBeTrue();
});

it('marks a successful intent reversed as a correction, never by editing the original', function (): void {
    $intent = intentService()->create(intentData(), 'intent-key-rev');
    $intent = intentService()->transition($intent, TransactionState::PROCESSING);
    $intent = intentService()->transition($intent, TransactionState::SUCCESSFUL);

    $reversed = intentService()->transition($intent, TransactionState::REVERSED, 'chargeback received');

    expect($reversed->state)->toBe(TransactionState::REVERSED);
    expect(PaymentIntent::query()->where('id', $intent->id)->count())->toBe(1);
});
