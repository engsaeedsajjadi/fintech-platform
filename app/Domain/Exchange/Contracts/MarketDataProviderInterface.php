<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Contracts;

use App\Domain\Exchange\ValueObjects\ExternalOrderBook;
use App\Domain\Exchange\ValueObjects\ExternalTicker;

/**
 * Market-data provider seam (TARGET_ARCHITECTURE §4.9).
 *
 * Market data is ADVISORY ONLY: it prices quotes and sizes market-order
 * holds, but it never executes or fills an order. Execution prices come
 * from resting limit orders in the book. An implementation MUST NOT
 * invent prices: when it has no price for a symbol it throws
 * MarketDataUnavailableException instead of returning a made-up value.
 */
interface MarketDataProviderInterface
{
    public function getName(): string;

    public function isAvailable(): bool;

    /**
     * @throws \App\Domain\Exchange\Exceptions\MarketDataUnavailableException
     */
    public function getTicker(string $baseCurrency, string $quoteCurrency): ExternalTicker;

    /**
     * @throws \App\Domain\Exchange\Exceptions\MarketDataUnavailableException
     */
    public function getOrderBook(string $baseCurrency, string $quoteCurrency, int $depth = 20): ExternalOrderBook;

    /** @return array<int, string> e.g. ['BTC/USD', 'ETH/USD'] */
    public function getSupportedSymbols(): array;
}
