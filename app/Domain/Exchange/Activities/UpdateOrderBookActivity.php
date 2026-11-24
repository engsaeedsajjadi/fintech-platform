<?php

namespace App\Domain\Exchange\Activities;

use App\Domain\Exchange\Aggregates\OrderBook;
use App\Domain\Exchange\Projections\Order;
use Brick\Math\BigDecimal;
use Exception;
use InvalidArgumentException;
use Workflow\Activity;
use Workflow\Models\StoredWorkflow;

class UpdateOrderBookActivity extends Activity
{
    /**
     * Dual-mode constructor: with no arguments the activity runs
     * synchronously outside the durable-workflow runtime (as the sync
     * order-book service does); with the runtime's (index, now,
     * storedWorkflow, ...args) the parent constructor runs so the
     * activity can be dispatched as a queued job. A plain no-arg
     * constructor broke runtime dispatch — Dispatchable::dispatch()
     * constructs with the runtime arguments.
     */
    public function __construct(
        int $index = 0,
        string $now = '',
        ?StoredWorkflow $storedWorkflow = null,
        mixed ...$arguments,
    ) {
        if ($storedWorkflow !== null) {
            parent::__construct($index, $now, $storedWorkflow, ...$arguments);
        }
    }

    public function execute(
        string $orderId,
        string $action,
        ?object $matchingResult = null
    ): object {
        try {
            // Get order details
            $order = Order::query()
                ->where('order_id', $orderId)
                ->firstOrFail();

            $orderBookId = OrderBook::generateId($order->base_currency, $order->quote_currency);
            $orderBook = OrderBook::retrieve($orderBookId);

            switch ($action) {
                case 'add':
                    // Market orders are never added to the book: they either
                    // fill immediately against resting limit orders or are
                    // rejected. Only limit orders rest in the book.
                    if ($order->order_type !== 'limit' || $order->price === null) {
                        return (object) [
                            'success' => false,
                            'action'  => $action,
                            'orderId' => $orderId,
                            'error'   => 'Only limit orders are added to the order book',
                        ];
                    }

                    // Add order to order book
                    $orderBook->addOrder(
                        orderId: $orderId,
                        type: $order->type,
                        price: (string) $order->price,
                        amount: $order->remaining_amount,
                        metadata: [
                        'account_id' => $order->account_id,
                        'order_type' => $order->order_type,
                        'timestamp'  => now()->toIso8601String(),
                        ]
                    )->persist();
                    break;

                case 'remove':
                    // Remove order from order book
                    $orderBook->removeOrder($orderId, 'order_cancelled', [])->persist();
                    break;

                case 'update_after_match':
                    // Update order book after matching
                    if ($matchingResult && isset($matchingResult->matches)) {
                        foreach ($matchingResult->matches as $match) {
                            // Update or remove orders based on their remaining amounts
                            $buyOrder = Order::query()
                            ->where('order_id', $match->buyOrderId)
                            ->first();

                            $sellOrder = Order::query()
                            ->where('order_id', $match->sellOrderId)
                            ->first();

                            // Remove filled orders
                            if ($buyOrder && BigDecimal::of((string) $buyOrder->remaining_amount)->isLessThanOrEqualTo(0)) {
                                $orderBook->removeOrder(
                                    $match->buyOrderId,
                                    'order_filled',
                                    [
                                    'trade_id' => $match->tradeId,
                                    ]
                                );
                            }

                            if ($sellOrder && BigDecimal::of((string) $sellOrder->remaining_amount)->isLessThanOrEqualTo(0)) {
                                $orderBook->removeOrder(
                                    $match->sellOrderId,
                                    'order_filled',
                                    [
                                    'trade_id' => $match->tradeId,
                                    ]
                                );
                            }

                            // For partially filled orders, we need to remove and re-add with new amount
                            if ($buyOrder && BigDecimal::of((string) $buyOrder->remaining_amount)->isPositive() && $buyOrder->price !== null) {
                                $orderBook->removeOrder($match->buyOrderId, 'partial_fill', [])
                                ->addOrder(
                                    orderId: $match->buyOrderId,
                                    type: $buyOrder->type,
                                    price: (string) $buyOrder->price,
                                    amount: $buyOrder->remaining_amount,
                                    metadata: [
                                        'account_id'       => $buyOrder->account_id,
                                        'order_type'       => $buyOrder->order_type,
                                        'partially_filled' => true,
                                    ]
                                );
                            }

                            if ($sellOrder && BigDecimal::of((string) $sellOrder->remaining_amount)->isPositive() && $sellOrder->price !== null) {
                                $orderBook->removeOrder($match->sellOrderId, 'partial_fill', [])
                                ->addOrder(
                                    orderId: $match->sellOrderId,
                                    type: $sellOrder->type,
                                    price: (string) $sellOrder->price,
                                    amount: $sellOrder->remaining_amount,
                                    metadata: [
                                        'account_id'       => $sellOrder->account_id,
                                        'order_type'       => $sellOrder->order_type,
                                        'partially_filled' => true,
                                    ]
                                );
                            }
                        }

                        $orderBook->persist();
                    }
                    break;

                default:
                    throw new InvalidArgumentException("Unknown action: {$action}");
            }

            return (object) [
                'success' => true,
                'action'  => $action,
                'orderId' => $orderId,
            ];
        } catch (Exception $e) {
            return (object) [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
