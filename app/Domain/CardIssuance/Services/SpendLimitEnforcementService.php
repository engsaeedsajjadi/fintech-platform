<?php

declare(strict_types=1);

namespace App\Domain\CardIssuance\Services;

use App\Domain\CardIssuance\Models\Card;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Enforces per-card spend limits using cache-backed running totals.
 *
 * Cards may have a spend_limit_cents with a spend_limit_interval (daily/monthly).
 * This service tracks cumulative spend per interval and rejects transactions
 * that would exceed the configured limit.
 */
class SpendLimitEnforcementService
{
    /** @var array<string, Card|null> Request-scoped card cache */
    private array $cardCache = [];

    /**
     * Check if a spend amount is within the card's configured limit.
     *
     * The amount is accepted as an exact decimal string (preferred),
     * an int in minor units via {@see checkLimitCents()}, or — for
     * legacy callers — a float that is converted with bcmath immediately.
     * The comparison itself always runs in integer minor units.
     *
     * @param  string $cardToken The issuer card token
     * @param  string|float|int $amount The transaction amount in decimal (e.g. '25.50')
     * @return bool   True if the spend is allowed, false if it exceeds the limit
     */
    public function checkLimit(string $cardToken, string|float|int $amount): bool
    {
        return $this->checkLimitCents($cardToken, $this->toCents($amount));
    }

    /**
     * Check a spend in integer minor units — the canonical form.
     */
    public function checkLimitCents(string $cardToken, int $amountCents): bool
    {
        $card = $this->resolveCard($cardToken);

        if ($card === null || $card->spend_limit_cents === null) {
            return true;
        }

        $interval = $card->spend_limit_interval ?? 'daily';
        $limitCents = $card->spend_limit_cents;

        $cacheKey = $this->buildCacheKey($cardToken, $interval);

        /** @var int $currentSpendCents */
        $currentSpendCents = Cache::get($cacheKey, 0);

        if (($currentSpendCents + $amountCents) > $limitCents) {
            Log::warning('SpendLimitEnforcement: Limit exceeded', [
                'card_token'      => $cardToken,
                'interval'        => $interval,
                'limit_cents'     => $limitCents,
                'current_spend'   => $currentSpendCents,
                'requested_cents' => $amountCents,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Record a successful spend against the card's running total.
     *
     * Uses atomic cache operations to prevent race conditions under concurrent requests.
     *
     * @param string $cardToken The issuer card token
     * @param string|float|int $amount The transaction amount in decimal (e.g. '25.50')
     */
    public function recordSpend(string $cardToken, string|float|int $amount): void
    {
        $this->recordSpendCents($cardToken, $this->toCents($amount));
    }

    /**
     * Record a successful spend in integer minor units — the canonical form.
     */
    public function recordSpendCents(string $cardToken, int $amountCents): void
    {
        $card = $this->resolveCard($cardToken);

        if ($card === null || $card->spend_limit_cents === null) {
            return;
        }

        $interval = $card->spend_limit_interval ?? 'daily';

        $cacheKey = $this->buildCacheKey($cardToken, $interval);
        $ttl = $this->getTtl($interval);

        // Atomic: ensure key exists with TTL, then increment
        Cache::add($cacheKey, 0, $ttl);
        Cache::increment($cacheKey, $amountCents);

        Log::info('SpendLimitEnforcement: Spend recorded', [
            'card_token'   => $cardToken,
            'interval'     => $interval,
            'amount_cents' => $amountCents,
        ]);
    }

    /**
     * Convert a decimal amount to integer minor units, exactly.
     * (int) round((float) $amount * 100) loses cents: 19.99 * 100 is
     * 1998.999... in binary, which rounds to 1998 or 1999 depending on
     * the platform. bcmath keeps it exact.
     */
    private function toCents(string|float|int $amount): int
    {
        if (is_int($amount)) {
            return $amount;
        }

        $decimal = \App\Support\DecimalString::fromNumeric($amount, 4) ?? '0.0000';

        return (int) bcmul($decimal, '100', 0);
    }

    /**
     * Resolve a card by its issuer token, with request-scoped memoization.
     */
    private function resolveCard(string $cardToken): ?Card
    {
        if (! array_key_exists($cardToken, $this->cardCache)) {
            $this->cardCache[$cardToken] = Card::where('issuer_card_token', $cardToken)->first();
        }

        return $this->cardCache[$cardToken];
    }

    /**
     * Build the cache key for a card's spend tracking.
     */
    private function buildCacheKey(string $cardToken, string $interval): string
    {
        $dateSuffix = match ($interval) {
            'monthly' => date('Y-m'),
            default   => date('Y-m-d'),
        };

        return "card_spend:{$interval}:{$cardToken}:{$dateSuffix}";
    }

    /**
     * Get the TTL in seconds for a given interval.
     */
    private function getTtl(string $interval): int
    {
        return match ($interval) {
            'monthly' => 2678400, // 31 days
            default   => 86400,   // 24 hours
        };
    }
}
