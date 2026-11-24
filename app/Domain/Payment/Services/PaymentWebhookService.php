<?php

declare(strict_types=1);

namespace App\Domain\Payment\Services;

use App\Domain\Payment\Models\PaymentTransaction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider callback / webhook handling for payments.
 *
 * Order of operations (fail closed):
 *   1. Verify the signature over the RAW body with hash_equals() before
 *      parsing or any state change. Missing/empty secret → reject.
 *   2. Claim the provider event id (DepositReplayGuard, backed by the
 *      `processed_webhook_events` unique index). An already-claimed event
 *      is acknowledged as a duplicate and applied ZERO times.
 *   3. Apply the handler. If it throws, the claim is released so a later
 *      redelivery can retry — a failed apply is not a processed event.
 */
final class PaymentWebhookService
{
    public function __construct(
        private readonly DepositReplayGuard $replayGuard,
    ) {
    }

    /**
     * Verify an HMAC signature over the raw request body.
     * Constant-time comparison; any empty input fails closed.
     */
    public function verifySignature(string $rawBody, string $signature, string $secret, string $algo = 'sha256'): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }

        $expected = hash_hmac($algo, $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Handle one provider webhook delivery.
     *
     * @param callable(PaymentTransaction|null): void $apply Called once, with the
     *        PaymentTransaction matched by provider reference (or null when the
     *        event references no known transaction).
     * @return 'applied'|'duplicate'|'invalid-signature'
     */
    public function handle(
        string $provider,
        string $eventId,
        string $rawBody,
        string $signature,
        string $secret,
        callable $apply,
        string $algo = 'sha256',
    ): string {
        if (! $this->verifySignature($rawBody, $signature, $secret, $algo)) {
            Log::warning('Payment webhook rejected: bad signature', [
                'provider' => $provider,
                'event_id' => $eventId,
            ]);

            return 'invalid-signature';
        }

        if (! $this->replayGuard->claim($provider, $eventId)) {
            return 'duplicate';
        }

        try {
            $transaction = PaymentTransaction::query()
                ->where('external_reference', $eventId)
                ->first();

            $apply($transaction);
        } catch (Throwable $e) {
            // The event was not applied: give the claim back so a later
            // redelivery can retry instead of being swallowed as duplicate.
            $this->replayGuard->release($provider, $eventId);

            throw $e;
        }

        return 'applied';
    }
}
