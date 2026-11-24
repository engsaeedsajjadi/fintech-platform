<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Exchange\Exceptions\TradingLimitExceededException;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\Trade;
use Brick\Math\BigDecimal;

/**
 * Per-user trading limits, enforced before an order is placed or an OTC
 * quote is accepted. Limits are quote-currency major-unit decimals from
 * config('trading.user_limits'). A zero limit disables that check.
 */
class TradingLimitService
{
    public function assertCanPlace(string $accountId, string $quoteCurrency, string $estimatedQuoteValue): void
    {
        $this->assertOpenOrderLimit($accountId);
        $this->assertDailyLimit($accountId, $quoteCurrency, $estimatedQuoteValue);
    }

    public function assertOpenOrderLimit(string $accountId): void
    {
        $max = (int) config('trading.user_limits.max_open_orders', 50);
        if ($max <= 0) {
            return;
        }

        $open = OrderProjection::forAccount($accountId)->open()->count();
        if ($open >= $max) {
            throw new TradingLimitExceededException(
                "Open order limit reached: {$open} open orders (max {$max})."
            );
        }
    }

    public function assertDailyLimit(string $accountId, string $quoteCurrency, string $additionalValue): void
    {
        $max = (string) config('trading.user_limits.daily_max_quote_value', '100000');
        if (BigDecimal::of($max)->isLessThanOrEqualTo(0)) {
            return;
        }

        $used = $this->dailyVolume($accountId, $quoteCurrency);
        $projected = BigDecimal::of($used)->plus(BigDecimal::of($additionalValue));

        if ($projected->isGreaterThan(BigDecimal::of($max))) {
            throw new TradingLimitExceededException(
                "Daily trading limit exceeded for {$quoteCurrency}: used {$used}, "
                . "new order adds {$additionalValue}, limit {$max}."
            );
        }
    }

    /** Today's filled trade value for an account in one quote currency. */
    public function dailyVolume(string $accountId, string $quoteCurrency): string
    {
        $total = Trade::forAccount($accountId)
            ->where('quote_currency', $quoteCurrency)
            ->where('created_at', '>=', now()->startOfDay())
            ->sum('value');

        return BigDecimal::of((string) ($total ?? '0'))->toScale(18)->__toString();
    }
}
