<?php

namespace App\Domain\Exchange\Activities;

use App\Domain\Exchange\Projections\Order;
use App\Domain\Exchange\Services\FeeCalculator;
use Brick\Math\BigDecimal;
use Cache;
use Illuminate\Support\Str;
use Workflow\Activity;

class MatchOrderActivity extends Activity
{
    private FeeCalculator $feeCalculator;

    public function __construct()
    {
        $this->feeCalculator = new FeeCalculator();
    }

    public function execute(string $orderId, int $maxIterations = 100): object
    {
        /** @var Order|null $order */
        $order = Order::where('order_id', $orderId)->first();

        if (! $order || ! $order->isOpen()) {
            return (object) [
                'matches' => collect(),
                'message' => 'Order not available for matching',
            ];
        }

        $matches = collect();
        $remainingAmount = BigDecimal::of($order->remaining_amount);
        $iterations = 0;

        while ($remainingAmount->isPositive() && $iterations < $maxIterations) {
            $iterations++;

            // Find best matching order
            $matchingOrder = $this->findBestMatch($order);

            if (! $matchingOrder) {
                break; // No more matches available
            }

            // Calculate execution details
            $executionDetails = $this->calculateExecution($order, $matchingOrder, $remainingAmount);

            if ($executionDetails->executedAmount->isZero()) {
                break; // Cannot execute
            }

            // Create match record
            $match = (object) [
                'tradeId'        => Str::uuid()->toString(),
                'buyOrderId'     => $order->type === 'buy' ? $order->order_id : $matchingOrder->order_id,
                'sellOrderId'    => $order->type === 'sell' ? $order->order_id : $matchingOrder->order_id,
                'executedPrice'  => $executionDetails->executedPrice->__toString(),
                'executedAmount' => $executionDetails->executedAmount->__toString(),
                'makerFee'       => $executionDetails->makerFee->__toString(),
                'takerFee'       => $executionDetails->takerFee->__toString(),
            ];

            $matches->push($match);

            // Update remaining amount
            $remainingAmount = $remainingAmount->minus($executionDetails->executedAmount);

            // Mark matching order for update
            $this->markOrderForUpdate($matchingOrder->order_id, $executionDetails->executedAmount);
        }

        return (object) [
            'matches'         => $matches,
            'message'         => $matches->isEmpty() ? 'No matches found' : "Found {$matches->count()} matches",
            'remainingAmount' => $remainingAmount->__toString(),
        ];
    }

    private function findBestMatch(Order $order): ?Order
    {
        // Only resting LIMIT orders can be counterparties. Market orders are
        // never rested (an unfilled market order is rejected, not booked),
        // and a market-vs-market match would have no determinable price —
        // inventing one (the old code fell back to '1') is forbidden.
        $query = Order::open()
            ->forPair($order->base_currency, $order->quote_currency)
            ->where('order_id', '!=', $order->order_id)
            ->where('type', $order->type === 'buy' ? 'sell' : 'buy')
            ->where('order_type', 'limit');

        if ($order->order_type === 'limit') {
            // For limit orders, find orders that match the price criteria
            if ($order->type === 'buy') {
                // Buy order: can match with sell orders at or below the limit price
                $query->where('price', '<=', $order->price);
            } else {
                // Sell order: can match with buy orders at or above the limit price
                $query->where('price', '>=', $order->price);
            }
        }

        // Order by best price first, then by time (FIFO)
        if ($order->type === 'buy') {
            // For buy orders, match with lowest sell prices first
            $query->orderByRaw('CAST(price AS DECIMAL(36,18)) ASC');
        } else {
            // For sell orders, match with highest buy prices first
            $query->orderByRaw('CAST(price AS DECIMAL(36,18)) DESC');
        }

        $query->orderBy('created_at', 'asc'); // FIFO

        return $query->first();
    }

    private function calculateExecution(Order $takerOrder, Order $makerOrder, BigDecimal $remainingAmount): object
    {
        // The execution price is ALWAYS the resting (maker) limit price.
        // A market taker executes at the maker's price; a limit taker
        // executes at the maker's price when it is inside the limit.
        // There is no fallback price: if the maker has no price, the
        // execution amount is zero and no match is produced.
        if ($makerOrder->price === null || BigDecimal::of((string) $makerOrder->price)->isLessThanOrEqualTo(0)) {
            return (object) [
                'executedPrice'  => BigDecimal::zero(),
                'executedAmount' => BigDecimal::zero(),
                'makerFee'       => BigDecimal::zero(),
                'takerFee'       => BigDecimal::zero(),
            ];
        }

        $executedPrice = BigDecimal::of($makerOrder->price);

        // Calculate executed amount (minimum of both orders' remaining amounts)
        $makerRemaining = BigDecimal::of($makerOrder->remaining_amount);
        $executedAmount = $remainingAmount->min($makerRemaining);

        // Calculate fees
        $fees = $this->feeCalculator->calculateFees(
            $executedAmount,
            $executedPrice,
            $takerOrder->account_id,
            $makerOrder->account_id
        );

        return (object) [
            'executedPrice'  => $executedPrice,
            'executedAmount' => $executedAmount,
            'makerFee'       => $fees->makerFee,
            'takerFee'       => $fees->takerFee,
        ];
    }

    private function markOrderForUpdate(string $orderId, BigDecimal $executedAmount): void
    {
        // Store in cache for the workflow to process
        $key = "order_updates:{$orderId}";
        $updates = Cache::get($key, []);
        $updates[] = [
            'executed_amount' => $executedAmount->__toString(),
            'timestamp'       => now()->toIso8601String(),
        ];
        Cache::put($key, $updates, now()->addMinutes(10));
    }
}
