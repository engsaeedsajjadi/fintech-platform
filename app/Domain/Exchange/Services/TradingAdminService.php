<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Asset\Models\TradingPair;
use App\Domain\Asset\Services\TradingPairService;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/**
 * Admin trading controls: halt/resume pairs, enable/disable pairs and
 * market orders, per-pair limits and fee overrides. All changes are
 * plain DB updates on `trading_pairs` (audited by the caller); halting
 * takes effect immediately because placement and validation re-read the
 * pair row on every order.
 */
class TradingAdminService
{
    public function __construct(
        private readonly TradingPairService $pairs,
    ) {
    }

    public function haltPair(string $baseCurrency, string $quoteCurrency): TradingPair
    {
        return $this->updatePair($baseCurrency, $quoteCurrency, ['halted' => true]);
    }

    public function resumePair(string $baseCurrency, string $quoteCurrency): TradingPair
    {
        return $this->updatePair($baseCurrency, $quoteCurrency, ['halted' => false]);
    }

    public function setPairActive(string $baseCurrency, string $quoteCurrency, bool $active): TradingPair
    {
        return $this->updatePair($baseCurrency, $quoteCurrency, ['is_active' => $active]);
    }

    public function setMarketOrdersEnabled(string $baseCurrency, string $quoteCurrency, bool $enabled): TradingPair
    {
        return $this->updatePair($baseCurrency, $quoteCurrency, ['market_orders_enabled' => $enabled]);
    }

    public function setPairLimits(string $baseCurrency, string $quoteCurrency, string $minOrderSize, string $maxOrderSize): TradingPair
    {
        if (BigDecimal::of($minOrderSize)->isLessThanOrEqualTo(0) || BigDecimal::of($maxOrderSize)->isLessThan($minOrderSize)) {
            throw new InvalidArgumentException('Invalid pair limits: require 0 < min <= max.');
        }

        return $this->updatePair($baseCurrency, $quoteCurrency, [
            'min_order_size' => $minOrderSize,
            'max_order_size' => $maxOrderSize,
        ]);
    }

    public function setFeeRates(string $baseCurrency, string $quoteCurrency, ?string $makerFeeRate, ?string $takerFeeRate): TradingPair
    {
        foreach (['maker' => $makerFeeRate, 'taker' => $takerFeeRate] as $name => $rate) {
            if ($rate !== null && (BigDecimal::of($rate)->isNegative() || BigDecimal::of($rate)->isGreaterThan(1))) {
                throw new InvalidArgumentException("Invalid {$name} fee rate: must be between 0 and 1.");
            }
        }

        return $this->updatePair($baseCurrency, $quoteCurrency, [
            'maker_fee_rate' => $makerFeeRate,
            'taker_fee_rate' => $takerFeeRate,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function updatePair(string $baseCurrency, string $quoteCurrency, array $attributes): TradingPair
    {
        $pair = $this->pairs->find($baseCurrency, $quoteCurrency);

        if ($pair === null) {
            throw new InvalidArgumentException("Unknown trading pair: {$baseCurrency}/{$quoteCurrency}.");
        }

        $pair->update($attributes);

        return $pair->refresh();
    }
}
