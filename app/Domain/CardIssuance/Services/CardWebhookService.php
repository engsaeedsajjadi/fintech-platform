<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Services;

use App\Domain\CardIssuance\Enums\CardStatus;
use App\Domain\CardIssuance\Models\Card;
use App\Domain\CardIssuance\Models\CardWebhookEvent;
use App\Infrastructure\FinCard\FinCardWebhookVerifier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provider-neutral inbound card webhook processing.
 *
 * Order of operations (never reversed):
 *   1. Store the raw payload durably (CardWebhookEvent, unique on
 *      issuer + provider event id) — a duplicate delivery is acknowledged
 *      without re-processing.
 *   2. Verify the signature on the RAW body with hash_equals() BEFORE any
 *      state change. An unverifiable payload is marked `rejected` and
 *      never processed.
 *   3. Dispatch to the issuer-specific applier (FinCard reuses
 *      {@see FinCardCardService::applyCardWebhook()}; other issuers use the
 *      generic status applier below).
 *
 * Signature schemes (per issuer):
 * - fincard: RSA SHA256withRSA over the raw body, header X-FC-SIGNATURE
 *   (or X-WSB-SIGNATURE), verified by {@see FinCardWebhookVerifier}.
 * - rain: HMAC-SHA256 hex of the raw body keyed by the configured webhook
 *   secret, header configured at `cardissuance.issuers.rain.webhook_signature_header`
 *   (default X-Rain-Signature). The exact header name must be confirmed
 *   against the Rain programme agreement before production enablement.
 * - marqeta: HTTP Basic Auth with the configured webhook username/password
 *   (Marqeta webhooks authenticate this way; there is no HMAC scheme).
 *   Without credentials configured, verification fails closed.
 * - demo / unknown issuer: no real webhooks exist — always rejected.
 *
 * Never log the raw payload: it may carry cardholder PII.
 */
class CardWebhookService
{
    public function __construct(
        private readonly FinCardWebhookVerifier $finCardVerifier,
        private readonly FinCardCardService $finCardCardService,
    ) {
    }

    /**
     * Process one inbound webhook.
     *
     * @param  array<string, string>  $headers  Raw request headers.
     * @return array{status: string, http: int}
     */
    public function handle(string $issuer, string $rawBody, array $headers = []): array
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        $payload = json_decode($rawBody, true);
        if (! is_array($payload)) {
            return ['status' => 'invalid_payload', 'http' => 400];
        }

        $eventId = $this->extractEventId($issuer, $payload);
        $eventType = (string) ($payload['eventType'] ?? $payload['type'] ?? $payload['event_type'] ?? 'unknown');

        // 1. Store raw payload FIRST (dedupe on issuer + provider event id).
        try {
            $record = CardWebhookEvent::query()->create([
                'issuer'            => $issuer,
                'provider_event_id' => $eventId,
                'event_type'        => $eventType,
                'raw_payload'       => $payload,
                'status'            => CardWebhookEvent::STATUS_RECEIVED,
            ]);
        } catch (QueryException) {
            return ['status' => 'duplicate', 'http' => 200];
        }

        // 2. Verify signature on the raw body BEFORE any state change.
        if (! $this->verifySignature($issuer, $rawBody, $headers)) {
            $record->status = CardWebhookEvent::STATUS_REJECTED;
            $record->save();

            Log::warning('Card webhook: invalid signature', ['issuer' => $issuer, 'event' => $eventType]);

            return ['status' => 'rejected', 'http' => 401];
        }
        $record->status = CardWebhookEvent::STATUS_VERIFIED;
        $record->save();

        // 3. Apply.
        try {
            $this->apply($issuer, $eventType, $payload);
        } catch (Throwable $e) {
            $record->status = CardWebhookEvent::STATUS_FAILED;
            $record->save();

            Log::error('Card webhook: apply failed', ['issuer' => $issuer, 'event' => $eventType, 'error' => $e->getMessage()]);

            return ['status' => 'failed', 'http' => 500];
        }

        $record->status = CardWebhookEvent::STATUS_PROCESSED;
        $record->processed_at = now();
        $record->save();

        return ['status' => 'processed', 'http' => 200];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verifySignature(string $issuer, string $rawBody, array $headers): bool
    {
        return match ($issuer) {
            'fincard' => $this->verifyFinCard($rawBody, $headers),
            'rain' => $this->verifyRainHmac($rawBody, $headers),
            'marqeta' => $this->verifyMarqetaBasicAuth($headers),
            default => false,
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verifyFinCard(string $rawBody, array $headers): bool
    {
        $signature = $headers['x-fc-signature'] ?? $headers['x-wsb-signature'] ?? '';
        if ($signature === '') {
            return false;
        }

        return $this->finCardVerifier->verify($rawBody, $signature);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verifyRainHmac(string $rawBody, array $headers): bool
    {
        $secret = (string) config('cardissuance.issuers.rain.webhook_secret', '');
        if ($secret === '') {
            return false;
        }
        $headerName = strtolower((string) config('cardissuance.issuers.rain.webhook_signature_header', 'X-Rain-Signature'));
        $signature = $headers[$headerName] ?? '';
        if ($signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), strtolower($signature));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function verifyMarqetaBasicAuth(array $headers): bool
    {
        $username = (string) config('cardissuance.issuers.marqeta.webhook_username', '');
        $password = (string) config('cardissuance.issuers.marqeta.webhook_password', '');
        if ($username === '' || $password === '') {
            return false;
        }
        $authorization = $headers['authorization'] ?? '';
        if (! str_starts_with($authorization, 'Basic ')) {
            return false;
        }

        return hash_equals('Basic '.base64_encode("{$username}:{$password}"), $authorization);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function apply(string $issuer, string $eventType, array $payload): void
    {
        if ($issuer === 'fincard') {
            $this->finCardCardService->applyCardWebhook($eventType, $payload);

            return;
        }

        $this->applyGenericStatus($issuer, $payload);
    }

    /**
     * Generic status applier for issuers without a dedicated webhook
     * service: map the payload's card status onto the local mirror.
     *
     * @param  array<string, mixed>  $payload
     */
    private function applyGenericStatus(string $issuer, array $payload): void
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $cardToken = (string) ($data['card_id'] ?? $data['cardId'] ?? $data['card_token'] ?? '');
        $status = strtolower((string) ($data['status'] ?? $data['state'] ?? ''));
        if ($cardToken === '' || $status === '') {
            Log::info('Card webhook: no card status to apply', ['issuer' => $issuer]);

            return;
        }

        $mapped = match ($status) {
            'active' => CardStatus::ACTIVE,
            'frozen', 'locked', 'suspended' => CardStatus::FROZEN,
            'cancelled', 'terminated', 'closed' => CardStatus::CANCELLED,
            'expired' => CardStatus::EXPIRED,
            default => null,
        };
        if ($mapped === null) {
            return;
        }

        $card = Card::query()
            ->where('issuer_card_token', $cardToken)
            ->where('issuer', $issuer)
            ->first();
        if (! $card instanceof Card) {
            Log::warning('Card webhook for unknown card', ['issuer' => $issuer, 'card_token' => $cardToken]);

            return;
        }

        $card->status = $mapped->value;
        if ($mapped === CardStatus::FROZEN) {
            $card->frozen_at = now();
        } elseif ($mapped === CardStatus::CANCELLED) {
            $card->cancelled_at = now();
        }
        $card->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractEventId(string $issuer, array $payload): string
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $candidate = $payload['eventId'] ?? $payload['event_id'] ?? $payload['id']
            ?? $data['orderNo'] ?? $data['tradeNo'] ?? $data['id'] ?? null;

        if (is_string($candidate) && $candidate !== '') {
            return $issuer.':'.$candidate;
        }

        // No provider event id: fall back to a hash of the raw payload so the
        // row is still deduped on identical redelivery.
        return $issuer.':'.hash('sha256', (string) json_encode($payload));
    }
}
