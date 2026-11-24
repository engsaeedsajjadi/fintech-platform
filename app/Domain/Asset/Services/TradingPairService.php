<?php

declare(strict_types=1);

namespace App\Domain\Asset\Services;

use App\Domain\Asset\Models\TradingPair;
use Illuminate\Support\Collection;

/**
 * Trading-pair registry (Asset domain — pairs only).
 *
 * Pairs are admin-managed rows in `trading_pairs`, seeded from
 * config('trading.pairs') on first use. The order book and OTC services
 * resolve pairs through here so a halted / disabled / unknown pair can
 * never be traded.
 */
class TradingPairService
{
    /**
     * All pairs from config, ensuring a DB row exists for each.
     *
     * @return Collection<int, TradingPair>
     */
    public function all(): Collection
    {
        $this->seedFromConfig();

        return TradingPair::orderBy('base_currency')->orderBy('quote_currency')->get();
    }

    public function find(string $baseCurrency, string $quoteCurrency): ?TradingPair
    {
        return TradingPair::forPair($baseCurrency, $quoteCurrency)->first()
            ?? $this->seedPair($baseCurrency, $quoteCurrency);
    }

    public function isTradeable(string $baseCurrency, string $quoteCurrency): bool
    {
        $pair = $this->find($baseCurrency, $quoteCurrency);

        return $pair !== null && $pair->isTradeable();
    }

    public function isHalted(string $baseCurrency, string $quoteCurrency): bool
    {
        $pair = TradingPair::forPair($baseCurrency, $quoteCurrency)->first();

        return $pair !== null && $pair->halted;
    }

    /**
     * Create the DB row for a config-listed pair (or null when the pair is
     * not configured at all — unknown pairs are never auto-created).
     */
    public function seedPair(string $baseCurrency, string $quoteCurrency): ?TradingPair
    {
        $symbol = "{$baseCurrency}/{$quoteCurrency}";

        if (! in_array($symbol, (array) config('trading.pairs', []), true)) {
            return null;
        }

        return TradingPair::firstOrCreate(
            ['base_currency' => $baseCurrency, 'quote_currency' => $quoteCurrency],
            [
                'is_active'             => true,
                'halted'                => false,
                'market_orders_enabled' => false,
                'min_order_size'        => (string) (config("trading.limits.min_order.{$baseCurrency}", '0.0001')),
                'max_order_size'        => (string) (config("trading.limits.max_order.{$baseCurrency}", '1000000')),
                'tick_size'             => '0.00000001',
            ]
        );
    }

    public function seedFromConfig(): void
    {
        foreach ((array) config('trading.pairs', []) as $symbol) {
            $parts = explode('/', (string) $symbol, 2);
            if (count($parts) === 2) {
                $this->seedPair($parts[0], $parts[1]);
            }
        }
    }
}
