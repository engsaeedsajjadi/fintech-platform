<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Ledger\Enums\TransactionState;
use App\Domain\Payment\Enums\PaymentIntentDirection;
use App\Domain\Payment\Enums\PaymentIntentKind;
use App\Domain\Payment\Models\PaymentIntent;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Unified payment engine: provider-independent intents.
 *
 * Every payment — internal, external, incoming, outgoing, refund,
 * reversal — starts as a PaymentIntent in state INITIATED with an
 * idempotency key. Providers only ever move an intent through the
 * canonical state machine below; they never create money, never edit a
 * terminal intent, and never report success without provider evidence.
 *
 * Legal transitions:
 *   initiated            → pending, processing, successful, failed, unknown, manual-review
 *   pending              → processing, successful, failed, unknown, manual-review
 *   processing           → successful, failed, unknown, manual-review
 *   unknown              → successful, failed, reversed, manual-review
 *   requires-manual-review → pending, processing, successful, failed, reversed
 *   successful, failed    → reversed (a correction is a new state, the
 *                            original row is kept as history)
 *   reversed             → (terminal)
 *
 * `unknown` is resolved only with evidence (a status query, a webhook,
 * or a human decision) — never by retrying the money movement.
 */
final class PaymentIntentService
{
    /** @var array<string, string[]> */
    private const TRANSITIONS = [
        'initiated'              => ['pending', 'processing', 'successful', 'failed', 'unknown', 'requires-manual-review'],
        'pending'                => ['processing', 'successful', 'failed', 'unknown', 'requires-manual-review'],
        'processing'             => ['successful', 'failed', 'unknown', 'requires-manual-review'],
        'unknown'                => ['successful', 'failed', 'reversed', 'requires-manual-review'],
        'requires-manual-review' => ['pending', 'processing', 'successful', 'failed', 'reversed'],
        'successful'             => ['reversed'],
        'failed'                 => ['reversed'],
        'reversed'               => [],
    ];

    /**
     * Normalize a caller-supplied amount to integer minor units.
     *
     * The payment data objects carry integer minor units (cents); a
     * decimal string is major units and is converted exactly. A float is
     * refused outright — it cannot represent money exactly.
     */
    public static function amountToMinor(mixed $amount, string $currency): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        if (is_string($amount) && is_numeric($amount)) {
            return PaymentIntent::majorToMinor($amount, $currency);
        }

        throw new InvalidArgumentException('Payment amount must be an integer in minor units or an exact decimal string — never a float.');
    }

    /**
     * Create an intent. The same idempotency key returns the same intent,
     * never a second money movement.
     *
     * @param  array{
     *     user_id?: int|null, account_uuid?: string|null,
     *     direction: PaymentIntentDirection|string,
     *     kind: PaymentIntentKind|string,
     *     amount_minor: int, currency: string, provider: string,
     *     provider_reference?: string|null, metadata?: array<string, mixed>,
     *     reverses_intent_id?: string|null,
     * }  $data
     */
    public function create(array $data, ?string $idempotencyKey = null): PaymentIntent
    {
        $idempotencyKey ??= (string) Str::uuid();

        $existing = PaymentIntent::query()->where('idempotency_key', $idempotencyKey)->first();
        if ($existing instanceof PaymentIntent) {
            return $existing;
        }

        $amountMinor = $data['amount_minor'] ?? null;
        if (! is_int($amountMinor) || $amountMinor <= 0) {
            throw new InvalidArgumentException('amount_minor must be a positive integer (minor units, never a float).');
        }

        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new InvalidArgumentException("currency must be a 3-letter ISO code, got '{$currency}'.");
        }

        $direction = $data['direction'] instanceof PaymentIntentDirection
            ? $data['direction']
            : PaymentIntentDirection::tryFrom((string) ($data['direction'] ?? ''));
        if ($direction === null) {
            throw new InvalidArgumentException('Unknown payment intent direction.');
        }

        $kind = $data['kind'] instanceof PaymentIntentKind
            ? $data['kind']
            : PaymentIntentKind::tryFrom((string) ($data['kind'] ?? ''));
        if ($kind === null) {
            throw new InvalidArgumentException('Unknown payment intent kind.');
        }

        $provider = trim((string) ($data['provider'] ?? ''));
        if ($provider === '') {
            throw new InvalidArgumentException('provider must name the provider that will carry this intent.');
        }

        return PaymentIntent::create([
            'user_id'            => $data['user_id'] ?? null,
            'account_uuid'       => $data['account_uuid'] ?? null,
            'direction'          => $direction,
            'kind'               => $kind,
            'provider'           => $provider,
            'provider_reference' => $data['provider_reference'] ?? null,
            'amount_minor'       => $amountMinor,
            'currency'           => $currency,
            'state'              => TransactionState::INITIATED,
            'idempotency_key'    => $idempotencyKey,
            'reverses_intent_id' => $data['reverses_intent_id'] ?? null,
            'metadata'           => $data['metadata'] ?? [],
        ]);
    }

    public function transition(PaymentIntent $intent, TransactionState $to, ?string $reason = null): PaymentIntent
    {
        $from = $intent->state;
        $allowed = self::TRANSITIONS[$from->value] ?? [];

        if (! in_array($to->value, $allowed, true)) {
            if ($from->isTerminal()) {
                throw new InvalidArgumentException(
                    "Terminal intent {$intent->id} (state {$from->value}) cannot transition to {$to->value}. A correction is a new intent.",
                );
            }

            throw new InvalidArgumentException(
                "Illegal payment intent transition: {$from->value} → {$to->value}.",
            );
        }

        $updates = ['state' => $to];
        if ($reason !== null) {
            if ($to === TransactionState::FAILED || $to === TransactionState::UNKNOWN) {
                $updates['failure_reason'] = $reason;
            }
            $metadata = $intent->metadata ?? [];
            $metadata['last_transition_reason'] = $reason;
            $updates['metadata'] = $metadata;
        }
        if ($to === TransactionState::UNKNOWN) {
            $updates['unknown_since_at'] = now();
        }
        if ($to->isTerminal()) {
            $updates['resolved_at'] = now();
        }

        $intent->update($updates);

        return $intent->fresh() ?? $intent;
    }

    /**
     * Resolve an `unknown` intent with evidence only: a successful outcome
     * settles it, a failed outcome fails it, a reversed outcome reverses
     * it. Anything else is not evidence and is refused.
     */
    public function resolveUnknown(PaymentIntent $intent, TransactionState $outcome, string $reason): PaymentIntent
    {
        if ($intent->state !== TransactionState::UNKNOWN && $intent->state !== TransactionState::REQUIRES_MANUAL_REVIEW) {
            throw new InvalidArgumentException(
                "Only unknown / requires-manual-review intents can be resolved; current state is {$intent->state->value}.",
            );
        }

        if (! in_array($outcome, [TransactionState::SUCCESSFUL, TransactionState::FAILED, TransactionState::REVERSED], true)) {
            throw new InvalidArgumentException(
                "Cannot resolve an unknown intent to {$outcome->value}; evidence must say successful, failed, or reversed.",
            );
        }

        $metadata = $intent->metadata ?? [];
        $metadata['resolution_reason'] = $reason;

        $resolved = $this->transition($intent, $outcome, $reason);
        $resolved->update(['metadata' => array_merge($resolved->metadata ?? [], $metadata)]);

        return $resolved->fresh() ?? $resolved;
    }

    /**
     * Map a provider status onto the intent. Unclassifiable provider
     * states go to manual review, never to a terminal state. Terminal
     * intents are never moved.
     */
    public function reconcile(PaymentIntent $intent, string $providerState, ?string $providerReference = null): PaymentIntent
    {
        if ($intent->state->isTerminal()) {
            throw new InvalidArgumentException(
                "Terminal intent {$intent->id} (state {$intent->state->value}) cannot be reconciled.",
            );
        }

        $mapped = TransactionState::fromLegacy($providerState);

        $updates = [];
        if ($providerReference !== null) {
            $updates['provider_reference'] = $providerReference;
        }
        if ($updates !== []) {
            $intent->update($updates);
            $intent = $intent->fresh() ?? $intent;
        }

        if ($mapped === $intent->state) {
            return $intent;
        }

        // Only terminal / unknown / manual-review outcomes move the intent
        // from here; a non-terminal provider state that differs from the
        // local state is recorded on the reference only — the local
        // state machine is the authority until the provider confirms.
        if ($mapped->isTerminal() || $mapped === TransactionState::UNKNOWN || $mapped === TransactionState::REQUIRES_MANUAL_REVIEW) {
            return $this->transition($intent, $mapped, "Provider reported '{$providerState}'.");
        }

        return $intent;
    }

    /**
     * Create a refund intent linked to a successful original. The refund
     * never exceeds the original amount and never edits the original.
     */
    public function refund(PaymentIntent $original, int $amountMinor, ?string $idempotencyKey = null): PaymentIntent
    {
        if ($original->state !== TransactionState::SUCCESSFUL) {
            throw new InvalidArgumentException(
                "Only a successful intent can be refunded; current state is {$original->state->value}.",
            );
        }
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException('Refund amount_minor must be a positive integer.');
        }
        if ($amountMinor > $original->amount_minor) {
            throw new InvalidArgumentException(
                "Refund amount {$amountMinor} exceeds the original intent amount {$original->amount_minor}.",
            );
        }

        $direction = match ($original->direction) {
            PaymentIntentDirection::INCOMING => PaymentIntentDirection::OUTGOING,
            PaymentIntentDirection::OUTGOING => PaymentIntentDirection::INCOMING,
            default                          => $original->direction,
        };

        return $this->create([
            'user_id'            => $original->user_id,
            'account_uuid'       => $original->account_uuid,
            'direction'          => $direction,
            'kind'               => PaymentIntentKind::REFUND,
            'amount_minor'       => $amountMinor,
            'currency'           => $original->currency,
            'provider'           => $original->provider,
            'reverses_intent_id' => $original->id,
            'metadata'           => ['refunds_intent' => $original->id],
        ], $idempotencyKey);
    }
}
