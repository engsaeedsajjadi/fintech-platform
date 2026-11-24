<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use Illuminate\Support\Facades\Cache;

/**
 * HMAC webhook verification with replay protection.
 *
 * The signature is `hash_hmac('sha256', $timestamp.'.'.$body, $secret)`,
 * compared with hash_equals(). A timestamp outside
 * `providers.webhook.tolerance_seconds` is rejected even with a valid
 * signature, and a (body, signature, timestamp) triple that verified
 * once is never accepted again. An empty secret never verifies — there
 * is no "skip verification in non-production" path.
 *
 * Also accepts the `t=<ts>,v1=<hex>` header form (Stripe-style).
 */
final class WebhookVerifier
{
    public function verify(string $rawBody, string $signature, string $secret, ?string $timestamp = null): bool
    {
        if ($rawBody === '' || $signature === '' || $secret === '') {
            return false;
        }

        $timestamp ??= $this->extractTimestamp($signature);
        $provided = $this->extractSignature($signature);

        if ($timestamp !== null) {
            if (! ctype_digit($timestamp)) {
                return false;
            }
            $age = abs(time() - (int) $timestamp);
            if ($age > $this->toleranceSeconds()) {
                return false;
            }
        }

        $signedPayload = $timestamp !== null ? $timestamp . '.' . $rawBody : $rawBody;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        if (! hash_equals($expected, $provided)) {
            return false;
        }

        // Replay guard: a verified (body, signature[, timestamp]) triple is
        // accepted exactly once. Phase 8: this used to apply only when the
        // signature carried a timestamp — a bare signature (no `t=`) could
        // be replayed indefinitely. The guard now covers both forms; the
        // cache key is a hash of the body + signature + timestamp, so no
        // secret material is ever stored in the cache key.
        $replayKey = 'webhook_replay:' . hash('sha256', ($timestamp ?? '') . '.' . $provided . '.' . hash('sha256', $rawBody));
        if (! Cache::add($replayKey, 1, $this->toleranceSeconds())) {
            return false;
        }

        return true;
    }

    private function extractTimestamp(string $signature): ?string
    {
        if (preg_match('/\bt=(\d+)\b/', $signature, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function extractSignature(string $signature): string
    {
        if (preg_match('/\bv1=([0-9a-fA-F]+)\b/', $signature, $m) === 1) {
            return $m[1];
        }

        return $signature;
    }

    private function toleranceSeconds(): int
    {
        return (int) config('providers.webhook.tolerance_seconds', 300);
    }
}
