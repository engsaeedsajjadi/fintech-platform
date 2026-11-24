<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Exchange\Contracts\MarketDataProviderInterface;
use App\Domain\Exchange\Exceptions\MarketDataUnavailableException;
use App\Domain\Exchange\Providers\SandboxMarketDataProvider;
use App\Domain\Exchange\ValueObjects\ExternalOrderBook;
use App\Domain\Exchange\ValueObjects\ExternalTicker;

/**
 * Resolves the configured market-data provider.
 *
 * Only 'sandbox' is implemented. Any other configured provider name throws
 * MarketDataUnavailableException: there is no live provider yet, and the
 * real trading path must NEVER silently fall back to sandbox/s Simulated
 * data when a live feed was requested.
 */
class MarketDataService
{
    public function provider(): MarketDataProviderInterface
    {
        $name = (string) config('trading.market_data.provider', 'sandbox');

        return match ($name) {
            'sandbox' => new SandboxMarketDataProvider(),
            default   => throw new MarketDataUnavailableException(
                "Market-data provider '{$name}' is not configured. No live market-data provider exists yet; "
                . 'contract one before enabling live trading. Prices are never invented.'
            ),
        };
    }

    public function getTicker(string $baseCurrency, string $quoteCurrency): ExternalTicker
    {
        return $this->provider()->getTicker($baseCurrency, $quoteCurrency);
    }

    public function getOrderBook(string $baseCurrency, string $quoteCurrency, int $depth = 20): ExternalOrderBook
    {
        return $this->provider()->getOrderBook($baseCurrency, $quoteCurrency, $depth);
    }
}
