<?php

declare(strict_types=1);

namespace App\Domain\Banking\Providers\Support;

use Illuminate\Support\Facades\Log;

/**
 * Structured provider audit logging. Secrets are redacted and account
 * numbers / IBANs are masked (last 4 visible) before anything reaches
 * the log — audit rows must never become a credential leak.
 */
final class ProviderAuditLogger
{
    /** @var string[] */
    private const SECRET_KEYS = [
        'api_key', 'apikey', 'secret', 'client_secret', 'token', 'access_token',
        'password', 'authorization', 'private_key', 'cvv', 'cvc', 'pin',
        'webhook_secret', 'signing_secret', 'encryption_key',
    ];

    /** @var string[] */
    private const MASKED_KEYS = [
        'account_number', 'iban', 'account', 'card_number', 'cardholder_account',
    ];

    /** @param  array<string, mixed>  $context */
    public function log(string $provider, string $action, array $context = []): void
    {
        Log::info("provider_audit: {$provider} {$action}", $this->sanitize($context));
    }

    /** @param  array<string, mixed>  $context */
    public function logFailure(string $provider, string $action, string $error, array $context = []): void
    {
        Log::warning("provider_audit: {$provider} {$action} failed", $this->sanitize(
            array_merge($context, ['error' => $error]),
        ));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function sanitize(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, self::SECRET_KEYS, true)) {
                $clean[$key] = '[REDACTED]';

                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);

                continue;
            }
            if (in_array($lower, self::MASKED_KEYS, true) && is_string($value)) {
                $clean[$key] = $this->mask($value);

                continue;
            }
            if (is_string($value) && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', str_replace(' ', '', $value)) === 1) {
                $clean[$key] = $this->mask($value);

                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    private function mask(string $value): string
    {
        $compact = str_replace(' ', '', $value);
        if (strlen($compact) <= 4) {
            return '****';
        }

        return substr($compact, 0, 2) . '****' . substr($compact, -4);
    }
}
