<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Account\Models\Account;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Services\TradingPairService;
use App\Domain\Exchange\Activities\MatchOrderActivity;
use App\Domain\Exchange\Activities\UpdateOrderBookActivity;
use App\Domain\Exchange\Aggregates\Order as OrderAggregate;
use App\Domain\Exchange\Aggregates\OrderBook as OrderBookAggregate;
use App\Domain\Exchange\Exceptions\MarketDataUnavailableException;
use App\Domain\Exchange\Exceptions\MarketOrdersNotSupportedException;
use App\Domain\Exchange\Exceptions\TradingDisabledException;
use App\Domain\Exchange\Exceptions\TradingPairHaltedException;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\OrderBook as OrderBookProjection;
use App\Domain\Exchange\Projections\Trade;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Order-book trading service (model A).
 *
 * Synchronous, deterministic order placement + matching for the real
 * order book. It REUSES the existing matching engine — MatchOrderActivity
 * (price-time priority) and Order::matchOrder() — and the existing
 * OrderMatchingWorkflow's activities; it does not implement a second
 * matcher. The workflow remains the async entry point; this service is
 * the synchronous entry point used by tests and (later) the API.
 *
 * Safety properties:
 * - Trading is gated on config('trading.enabled'); disabled by default
 *   in production until venue/regulatory approval exists.
 * - Funds are reserved (TradingHoldService) BEFORE matching, inside the
 *   same transaction as the order, so a match can never overdraw.
 * - Placement, matching and settlement run in SEPARATE transactions:
 *   settlement posts its ledger legs only after its own claim
 *   transaction commits, so it must never run inside an open outer
 *   transaction (a later failure would roll the trade back after the
 *   ledger driver already synced it). See TradeSettlementService.
 * - Duplicate execution is prevented three ways: idempotency keys on
 *   placement, Trade-trade_id checks in settlement, and the aggregate
 *   guard in Order::matchOrder().
 * - Market orders only match resting limit orders. With no resting
 *   counterparty they are rejected — never filled against a simulated
 *   or invented price.
 * - DemoExchangeService is never called from here.
 */
class OrderBookTradingService
{
    public function __construct(
        private readonly TradingPairService $pairs,
        private readonly TradingHoldService $holds,
        private readonly TradeSettlementService $settlement,
        private readonly MarketDataService $marketData,
        private readonly TradingLimitService $limits,
    ) {
    }

    /**
     * Place an order and run matching synchronously. Returns the fresh
     * Order projection (status pending/open, partially_filled, filled,
     * or cancelled when a market order found no liquidity).
     *
     * @throws TradingDisabledException|TradingPairHaltedException|MarketOrdersNotSupportedException
     * @throws \App\Domain\Exchange\Exceptions\InsufficientTradingBalanceException
     * @throws \App\Domain\Exchange\Exceptions\TradingLimitExceededException
     * @param  array<string, mixed>  $metadata
     */
    public function placeOrder(
        string $accountId,
        string $side,
        string $orderType,
        string $baseCurrency,
        string $quoteCurrency,
        string $amount,
        ?string $price = null,
        ?string $idempotencyKey = null,
        array $metadata = [],
    ): OrderProjection {
        $this->assertTradingEnabled();

        $side = strtolower($side);
        $orderType = strtolower($orderType);
        if (! in_array($side, ['buy', 'sell'], true)) {
            throw new InvalidArgumentException("Invalid order side: {$side}. Expected buy or sell.");
        }
        if (! in_array($orderType, ['limit', 'market'], true)) {
            throw new InvalidArgumentException("Unsupported order type: {$orderType}. Only limit and market orders are supported.");
        }
        if (! is_numeric($amount) || BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Order amount must be a positive decimal string.');
        }

        $pair = $this->pairs->find($baseCurrency, $quoteCurrency);
        if ($pair === null) {
            throw new InvalidArgumentException("Unknown trading pair: {$baseCurrency}/{$quoteCurrency}.");
        }
        if (! $pair->is_active) {
            throw new InvalidArgumentException("Trading pair is not active: {$baseCurrency}/{$quoteCurrency}.");
        }
        if ($pair->halted) {
            throw new TradingPairHaltedException("Trading pair is halted: {$baseCurrency}/{$quoteCurrency}.");
        }

        $baseAsset = Asset::where('code', $baseCurrency)->first();
        $quoteAsset = Asset::where('code', $quoteCurrency)->first();
        if ($baseAsset === null || $quoteAsset === null || ! $baseAsset->is_tradeable || ! $quoteAsset->is_tradeable) {
            throw new InvalidArgumentException('Currency pair not available for trading');
        }

        // Per-asset precision, checked before the size limits so a
        // malformed amount reports its precision, not its size: amounts
        // are in the base asset, prices in the quote asset. A
        // sub-precision value is refused, never rounded.
        $this->assertDecimalScale($amount, (int) ($baseAsset->precision ?? 18), 'Order amount');
        if ($price !== null) {
            $this->assertDecimalScale($price, (int) ($quoteAsset->precision ?? 18), 'Order price');
        }

        if (BigDecimal::of($amount)->isLessThan((string) $pair->min_order_size)) {
            throw new InvalidArgumentException("Order amount {$amount} is below the minimum {$pair->min_order_size} {$baseCurrency}.");
        }
        if (BigDecimal::of($amount)->isGreaterThan((string) $pair->max_order_size)) {
            throw new InvalidArgumentException("Order amount {$amount} exceeds the maximum {$pair->max_order_size} {$baseCurrency}.");
        }

        if ($orderType === 'limit') {
            if ($price === null || ! is_numeric($price) || BigDecimal::of($price)->isLessThanOrEqualTo(0)) {
                throw new InvalidArgumentException('Price is required and must be positive for limit orders.');
            }
        } elseif (! $pair->market_orders_enabled) {
            throw new MarketOrdersNotSupportedException(
                "Market orders are not enabled for {$baseCurrency}/{$quoteCurrency}."
            );
        }

        if ($idempotencyKey !== null) {
            if (! Str::isUuid($idempotencyKey)) {
                throw new InvalidArgumentException('Idempotency key must be a UUID.');
            }
            $existing = OrderProjection::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $account = $this->resolveAccount($accountId);
        if ($account === null) {
            throw new InvalidArgumentException('Account not found');
        }

        $referencePrice = $orderType === 'limit' ? $price : $this->referencePrice($baseCurrency, $quoteCurrency, $side);
        if ($referencePrice === null) {
            throw new MarketDataUnavailableException(
                "No reference price for {$baseCurrency}/{$quoteCurrency}; market order cannot be sized or placed."
            );
        }
        $estimatedQuoteValue = BigDecimal::of($amount)->multipliedBy($referencePrice)->toScale(18, RoundingMode::DOWN)->__toString();
        $this->limits->assertCanPlace($accountId, $quoteCurrency, $estimatedQuoteValue);

        $orderId = Str::uuid()->toString();

        try {
            $order = DB::transaction(function () use (
                $orderId,
                $accountId,
                $side,
                $orderType,
                $baseCurrency,
                $quoteCurrency,
                $amount,
                $price,
                $referencePrice,
                $idempotencyKey,
                $metadata
            ): OrderProjection {
                // 1. Reserve funds BEFORE the order exists in the book.
                $this->reserveForOrder($orderId, $accountId, $side, $orderType, $baseCurrency, $quoteCurrency, $amount, $price, $referencePrice);

                // 2. Record the order on the aggregate. The projector creates
                // the read row; the routing saga skips order-book venue orders.
                OrderAggregate::retrieve($orderId)->placeOrder(
                    orderId: $orderId,
                    accountId: $accountId,
                    type: $side,
                    orderType: $orderType,
                    baseCurrency: $baseCurrency,
                    quoteCurrency: $quoteCurrency,
                    amount: BigDecimal::of($amount)->toScale(18)->__toString(),
                    price: $price !== null ? BigDecimal::of($price)->toScale(18)->__toString() : null,
                    metadata: array_merge($metadata, [
                        'execution_venue' => 'order_book',
                        'idempotency_key' => $idempotencyKey,
                        'source'          => 'order_book',
                    ]),
                )->persist();

                $order = OrderProjection::where('order_id', $orderId)->firstOrFail();

                // 3. Limit orders rest in the book; market orders never do.
                $this->ensureOrderBookExists($baseCurrency, $quoteCurrency);
                if ($orderType === 'limit') {
                    (new UpdateOrderBookActivity())->execute($orderId, 'add');
                }

                return $order;
            });
        } catch (QueryException $e) {
            // Idempotency race: a concurrent placement with the same key
            // committed first. Return the winner's order instead of a
            // raw unique-violation error (the loser's transaction rolled
            // back, so no hold or order row of ours survives).
            if ($idempotencyKey !== null && $this->isUniqueViolation($e)) {
                $existing = OrderProjection::where('idempotency_key', $idempotencyKey)->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw $e;
        }

        // 4. Matching + settlement run OUTSIDE the placement transaction:
        // TradeSettlementService posts ledger legs only after its own
        // claim transaction commits, so it must never run inside an open
        // outer transaction. A settlement failure here leaves the placed
        // order in the book (the match can be retried by trade id) and
        // propagates as TradingSettlementException — it never reports a
        // trade settled that was not durably recorded.
        $matching = (new MatchOrderActivity())->execute($orderId, 100);

        foreach ($matching->matches as $match) {
            $makerSide = $match->buyOrderId === $orderId ? 'sell' : 'buy';
            $this->settlement->settle(
                buyOrderId: $match->buyOrderId,
                sellOrderId: $match->sellOrderId,
                tradeId: $match->tradeId,
                executedAmount: $match->executedAmount,
                executedPrice: $match->executedPrice,
                makerFee: $match->makerFee,
                takerFee: $match->takerFee,
                makerSide: $makerSide,
            );
        }

        if (! $matching->matches->isEmpty()) {
            (new UpdateOrderBookActivity())->execute($orderId, 'update_after_match', $matching);
        }

        // 5. A market order that could not fill is rejected, not rested.
        $order = OrderProjection::where('order_id', $orderId)->firstOrFail();
        if ($orderType === 'market' && BigDecimal::of((string) $order->remaining_amount)->isPositive()) {
            $this->rejectUnfilledMarketOrder($orderId);
            $order = OrderProjection::where('order_id', $orderId)->firstOrFail();
        }

        return $order;
    }

    /** Cancel an unfilled market order and release its hold. */
    private function rejectUnfilledMarketOrder(string $orderId): void
    {
        DB::transaction(function () use ($orderId): void {
            $order = OrderProjection::where('order_id', $orderId)->lockForUpdate()->firstOrFail();

            if (! $order->canBeCancelled()) {
                return;
            }

            OrderAggregate::retrieve($orderId)->cancelOrder('No liquidity for market order')->persist();
            $this->holds->releaseForSource('order_book', $orderId);
        });
    }

    /**
     * Reject amounts/prices with more decimal places than the asset's
     * precision allows (e.g. 9dp of BTC, whose precision is 8). Values
     * are never silently rounded: money is exact or refused.
     */
    private function assertDecimalScale(string $value, int $precision, string $what): void
    {
        $plain = BigDecimal::of($value)->toScale(18)->__toString();
        $fraction = str_contains($plain, '.')
            ? substr($plain, (int) strpos($plain, '.') + 1)
            : '';
        $effectiveScale = strlen(rtrim($fraction, '0'));

        if ($effectiveScale > $precision) {
            throw new InvalidArgumentException(
                "{$what} has {$effectiveScale} decimal places, more than the asset precision of {$precision} allows.",
            );
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        // SQLSTATE 23000 covers SQLite, MySQL and Postgres unique violations.
        return $e->getCode() === '23000'
            || str_contains($e->getMessage(), 'UNIQUE constraint failed')
            || str_contains($e->getMessage(), 'Duplicate entry');
    }

    /** Cancel an open order and release its remaining hold. */
    public function cancelOrder(string $orderId, ?string $accountId = null, string $reason = 'User requested'): OrderProjection
    {
        $this->assertTradingEnabled();

        return DB::transaction(function () use ($orderId, $accountId, $reason): OrderProjection {
            $order = OrderProjection::where('order_id', $orderId)->lockForUpdate()->first();
            if ($order === null) {
                throw new InvalidArgumentException('Order not found');
            }
            if ($accountId !== null && (string) $order->account_id !== $accountId) {
                throw new InvalidArgumentException('Order does not belong to this account');
            }
            if (! $order->canBeCancelled()) {
                throw new InvalidArgumentException("Order cannot be cancelled. Status: {$order->status}");
            }

            OrderAggregate::retrieve($orderId)->cancelOrder($reason)->persist();

            $this->holds->releaseForSource('order_book', $orderId);

            if (OrderBookProjection::forPair($order->base_currency, $order->quote_currency)->exists()) {
                (new UpdateOrderBookActivity())->execute($orderId, 'remove');
            }

            return $order->refresh();
        });
    }

    public function getOrder(string $orderId): ?OrderProjection
    {
        return OrderProjection::where('order_id', $orderId)->first();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, OrderProjection> */
    public function listOrders(string $accountId, ?string $status = null, int $limit = 50)
    {
        $query = OrderProjection::forAccount($accountId)->orderByDesc('created_at')->limit($limit);
        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, OrderProjection> */
    public function listOpenOrders(string $accountId)
    {
        return OrderProjection::forAccount($accountId)->open()->orderBy('created_at')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Trade> */
    public function tradeHistory(string $baseCurrency, string $quoteCurrency, ?string $accountId = null, int $limit = 50)
    {
        $query = Trade::forPair($baseCurrency, $quoteCurrency)->orderByDesc('created_at')->limit($limit);
        if ($accountId !== null) {
            $query->forAccount($accountId);
        }

        return $query->get();
    }

    /** @return array<string, mixed> */
    public function getOrderBook(string $baseCurrency, string $quoteCurrency, int $depth = 20): array
    {
        return (new ExchangeService())->getOrderBook($baseCurrency, $quoteCurrency, $depth);
    }

    public function assertTradingEnabled(): void
    {
        if (! (bool) config('trading.enabled', false)) {
            throw new TradingDisabledException(
                'Trading is disabled (TRADING_ENABLED=false). Trading stays disabled until venue/regulatory approval exists.'
            );
        }
    }

    private function reserveForOrder(
        string $orderId,
        string $accountId,
        string $side,
        string $orderType,
        string $baseCurrency,
        string $quoteCurrency,
        string $amount,
        ?string $price,
        string $referencePrice,
    ): void {
        // Pair is re-read here so the fee buffer uses the live fee config.
        $pair = $this->pairs->find($baseCurrency, $quoteCurrency);
        if ($side === 'buy') {
            if ($orderType === 'limit' && $price === null) {
                throw new InvalidArgumentException('Price is required for limit orders.');
            }
            // Limit: exact quote cost PLUS the estimated taker fee, so the
            // hold always covers value + fee. Market: reference price + 10%
            // buffer (any unspent buffer is released on fill/cancel).
            $rates = (new FeeCalculator())->ratesForPair($pair);
            $toLock = $orderType === 'limit'
                ? BigDecimal::of($amount)->multipliedBy($price)->multipliedBy(BigDecimal::one()->plus($rates['taker']))->toScale(18, RoundingMode::DOWN)->__toString()
                : BigDecimal::of($amount)->multipliedBy(BigDecimal::of($referencePrice)->multipliedBy('1.1'))->toScale(18, RoundingMode::DOWN)->__toString();

            $this->holds->reserve($accountId, $quoteCurrency, $toLock, 'order_book', $orderId);
        } else {
            $this->holds->reserve($accountId, $baseCurrency, $amount, 'order_book', $orderId);
        }
    }

    /**
     * Reference price for sizing a market order: best opposite book price
     * first, else the market-data provider. Null when neither exists.
     */
    private function referencePrice(string $baseCurrency, string $quoteCurrency, string $side): ?string
    {
        $book = OrderBookProjection::forPair($baseCurrency, $quoteCurrency)->first();
        if ($book !== null) {
            $bookPrice = $side === 'buy' ? $book->best_ask : $book->best_bid;
            if ($bookPrice !== null && BigDecimal::of((string) $bookPrice)->isPositive()) {
                return (string) $bookPrice;
            }
        }

        try {
            $ticker = $this->marketData->getTicker($baseCurrency, $quoteCurrency);

            return $side === 'buy' ? $ticker->ask->__toString() : $ticker->bid->__toString();
        } catch (MarketDataUnavailableException) {
            return null;
        }
    }

    private function ensureOrderBookExists(string $baseCurrency, string $quoteCurrency): void
    {
        $orderBookId = OrderBookAggregate::generateId($baseCurrency, $quoteCurrency);

        if (OrderBookProjection::where('order_book_id', $orderBookId)->exists()) {
            return;
        }

        OrderBookAggregate::retrieve($orderBookId)->initialize(
            orderBookId: $orderBookId,
            baseCurrency: $baseCurrency,
            quoteCurrency: $quoteCurrency,
            metadata: ['created_at' => now()->toIso8601String()],
        )->persist();
    }

    private function resolveAccount(string $accountId): ?Account
    {
        if (is_numeric($accountId)) {
            $account = Account::find($accountId);
            if ($account !== null) {
                return $account;
            }
        }

        return Account::where('uuid', $accountId)->first();
    }
}
