<?php

namespace App\Domain\Exchange\Activities;

use App\Domain\Exchange\Exceptions\InsufficientTradingBalanceException;
use App\Domain\Exchange\Services\MarketDataService;
use App\Domain\Exchange\Services\TradingHoldService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Throwable;
use Workflow\Activity;

/**
 * Reserve (hold) the funds an order needs before matching.
 *
 * Rewritten to use TradingHoldService: holds are separate rows in
 * `trading_holds`, never direct writes to account balances. The previous
 * implementation referenced App\Models\AssetBalance, a class that does
 * not exist, so the lock step could never run.
 *
 * The returned `lockId` is the hold UUID; ReleaseAccountBalanceActivity
 * releases it by that id.
 */
class LockAccountBalanceActivity extends Activity
{
    public function execute(string $orderId, object $order): object
    {
        return DB::transaction(
            function () use ($orderId, $order) {
                // For buy orders, lock quote currency; for sell orders, lock base currency.
                $currencyToLock = $order->type === 'buy' ? $order->quote_currency : $order->base_currency;

                if ($order->type === 'buy') {
                    if ($order->order_type === 'market') {
                        // Market buy: hold amount = remaining * reference price
                        // with a 10% buffer. The reference price comes from
                        // the book's best ask, else the market-data provider.
                        // No reference price -> the order cannot be placed.
                        $estimatedPrice = $this->referencePrice($order->base_currency, $order->quote_currency);

                        if ($estimatedPrice === null || BigDecimal::of($estimatedPrice)->isZero()) {
                            return (object) [
                                'success' => false,
                                'message' => 'No market price available',
                            ];
                        }

                        $amountToLock = BigDecimal::of((string) $order->remaining_amount)
                            ->multipliedBy(BigDecimal::of($estimatedPrice)->multipliedBy('1.1'))
                            ->toScale(18, RoundingMode::DOWN)->__toString();
                    } else {
                        // Limit buy: quote cost plus the estimated taker fee,
                        // so the hold covers value + fee.
                        $pair = app(\App\Domain\Asset\Services\TradingPairService::class)
                            ->find($order->base_currency, $order->quote_currency);
                        $takerRate = (new \App\Domain\Exchange\Services\FeeCalculator())
                            ->ratesForPair($pair)['taker'];
                        $amountToLock = BigDecimal::of((string) $order->remaining_amount)
                            ->multipliedBy((string) $order->price)
                            ->multipliedBy(BigDecimal::one()->plus($takerRate))
                            ->toScale(18, RoundingMode::DOWN)->__toString();
                    }
                } else {
                    $amountToLock = $order->remaining_amount;
                }

                try {
                    $hold = app(TradingHoldService::class)->reserve(
                        accountId: (string) $order->account_id,
                        assetCode: $currencyToLock,
                        amount: $amountToLock,
                        sourceType: 'order_book',
                        sourceId: $orderId,
                    );
                } catch (InsufficientTradingBalanceException $e) {
                    return (object) [
                        'success' => false,
                        'message' => $e->getMessage(),
                    ];
                }

                return (object) [
                    'success'      => true,
                    'lockId'       => $hold->hold_id,
                    'amountLocked' => $amountToLock,
                    'currency'     => $currencyToLock,
                ];
            }
        );
    }

    private function referencePrice(string $baseCurrency, string $quoteCurrency): ?string
    {
        $orderBook = \App\Domain\Exchange\Projections\OrderBook::forPair($baseCurrency, $quoteCurrency)->first();

        if ($orderBook && $orderBook->best_ask !== null) {
            return (string) $orderBook->best_ask;
        }

        try {
            return app(MarketDataService::class)
                ->getTicker($baseCurrency, $quoteCurrency)
                ->ask->__toString();
        } catch (Throwable) {
            return null;
        }
    }
}
