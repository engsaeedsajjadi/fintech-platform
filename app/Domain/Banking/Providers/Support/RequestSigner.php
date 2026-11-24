<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

/**
 * HMAC-SHA256 signing for outbound provider requests. The payload is
 * canonicalised (keys sorted recursively, JSON-encoded) before signing,
 * so the same logical request always produces the same signature.
 */
final class RequestSigner
{
    /** @param array<string, mixed> $payload */
    public function sign(array $payload, string $secret): string
    {
        return hash_hmac('sha256', $this->canonical($payload), $secret);
    }

    /** @param array<string, mixed> $payload */
    public function verify(array $payload, string $signature, string $secret): bool
    {
        if ($signature === '' || $secret === '') {
            return false;
        }

        return hash_equals($this->sign($payload, $secret), $signature);
    }

    /** @param  array<string, mixed>  $payload */
    private function canonical(array $payload): string
    {
        ksort($payload);
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonicalArray($value);
            }
        }

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @param  array<mixed>  $value */
    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function canonicalArray(array $value): array
    {
        $isList = array_is_list($value);
        if (! $isList) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->canonicalArray($item);
            }
        }

        return $value;
    }
}
