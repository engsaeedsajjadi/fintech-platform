<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Providers;

use App\Domain\Exchange\Contracts\MarketDataProviderInterface;
use App\Domain\Exchange\Exceptions\MarketDataUnavailableException;
use App\Domain\Exchange\ValueObjects\ExternalOrderBook;
use App\Domain\Exchange\ValueObjects\ExternalTicker;
use Brick\Math\BigDecimal;
use DateTimeImmutable;

/**
 * Sandbox market-data provider.
 *
 * Serves ONLY the fixed prices in config('trading.market_data.sandbox_prices'),
 * deterministically (no randomness, no invented variation). Every response
 * is labelled `'source' => 'sandbox'` in metadata so sandbox data can never
 * be mistaken for a live feed. Unknown symbols throw instead of returning
 * a fabricated price.
 */
class SandboxMarketDataProvider implements MarketDataProviderInterface
{
    public function getName(): string
    {
        return 'sandbox';
    }

    public function isAvailable(): bool
    {
        return config('trading.market_data.provider') === 'sandbox';
    }

    public function getTicker(string $baseCurrency, string $quoteCurrency): ExternalTicker
    {
        $mid = $this->priceFor($baseCurrency, $quoteCurrency);
        $spread = BigDecimal::of((string) config('trading.market_data.sandbox_spread', '0.001'));
        $halfSpread = $mid->multipliedBy($spread)->dividedBy('2', 18);

        return new ExternalTicker(
            baseCurrency: $baseCurrency,
            quoteCurrency: $quoteCurrency,
            bid: $mid->minus($halfSpread),
            ask: $mid->plus($halfSpread),
            last: $mid,
            volume24h: BigDecimal::zero(),
            high24h: $mid,
            low24h: $mid,
            change24h: BigDecimal::zero(),
            timestamp: new DateTimeImmutable(),
            exchange: 'sandbox',
            metadata: ['source' => 'sandbox', 'disclaimer' => 'Sandbox price — not a live market feed.'],
        );
    }

    public function getOrderBook(string $baseCurrency, string $quoteCurrency, int $depth = 20): ExternalOrderBook
    {
        $ticker = $this->getTicker($baseCurrency, $quoteCurrency);

        // A sandbox book is indicative only: it is NEVER used to fill orders.
        $bids = collect(range(1, $depth))->map(fn (int $i) => [
            'price'  => $ticker->bid->minus($ticker->bid->multipliedBy('0.001')->multipliedBy((string) $i)),
            'amount' => BigDecimal::of('1'),
        ]);
        $asks = collect(range(1, $depth))->map(fn (int $i) => [
            'price'  => $ticker->ask->plus($ticker->ask->multipliedBy('0.001')->multipliedBy((string) $i)),
            'amount' => BigDecimal::of('1'),
        ]);

        return new ExternalOrderBook(
            baseCurrency: $baseCurrency,
            quoteCurrency: $quoteCurrency,
            bids: $bids,
            asks: $asks,
            timestamp: new DateTimeImmutable(),
            exchange: 'sandbox',
            metadata: ['source' => 'sandbox', 'disclaimer' => 'Indicative sandbox book — never used for execution.'],
        );
    }

    public function getSupportedSymbols(): array
    {
        return array_keys((array) config('trading.market_data.sandbox_prices', []));
    }

    private function priceFor(string $baseCurrency, string $quoteCurrency): BigDecimal
    {
        $prices = (array) config('trading.market_data.sandbox_prices', []);
        $symbol = "{$baseCurrency}/{$quoteCurrency}";

        if (isset($prices[$symbol])) {
            return BigDecimal::of((string) $prices[$symbol]);
        }

        $inverse = "{$quoteCurrency}/{$baseCurrency}";
        if (isset($prices[$inverse]) && ! BigDecimal::of((string) $prices[$inverse])->isZero()) {
            return BigDecimal::one()->dividedBy((string) $prices[$inverse], 18);
        }

        throw new MarketDataUnavailableException(
            "No sandbox market price for {$symbol}. Configure a live market-data provider; prices are never invented."
        );
    }
}
