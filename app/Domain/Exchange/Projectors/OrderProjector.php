<?php

namespace App\Domain\Exchange\Projectors;

use App\Domain\Exchange\Events\OrderCancelled;
use App\Domain\Exchange\Events\OrderFilled;
use App\Domain\Exchange\Events\OrderMatched;
use App\Domain\Exchange\Events\OrderPartiallyFilled;
use App\Domain\Exchange\Events\OrderPlaced;
use App\Domain\Exchange\Projections\Order;
use App\Domain\Exchange\Projections\Trade;
use Brick\Math\BigDecimal;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class OrderProjector extends Projector
{
    public function onOrderPlaced(OrderPlaced $event): void
    {
        // Check if order already exists (created by DemoExchangeService)
        if (Order::where('order_id', $event->orderId)->exists()) {
            return;
        }

        Order::create(
            [
            'order_id'       => $event->orderId,
            'account_id'     => $event->accountId,
            'type'           => $event->type,
            'order_type'     => $event->orderType,
            'base_currency'  => $event->baseCurrency,
            'quote_currency' => $event->quoteCurrency,
            'amount'         => $event->amount,
            'filled_amount'  => '0',
            'price'          => $event->price,
            'stop_price'     => $event->stopPrice,
            'status'         => 'pending',
            // NOTE: pass arrays, not json_encode() — the 'array' casts on
            // the model encode on save. Double-encoding stored the string
            // '"[]"' and broke `$trades[] = ...` in onOrderMatched.
            'trades'          => [],
            'metadata'        => $event->metadata ?: null,
            'idempotency_key' => $event->metadata['idempotency_key'] ?? null,
            'version'         => 0,
            'source'          => $event->metadata['execution_venue'] ?? 'order_book',
            ]
        );
    }

    public function onOrderMatched(OrderMatched $event): void
    {
        $order = Order::where('order_id', $event->orderId)->firstOrFail();

        // Update order with trade information
        $trades = $order->trades ?? [];
        if (! is_array($trades)) {
            // Tolerate rows written before the double-encoding fix.
            $decoded = json_decode((string) $trades, true);
            $trades = is_array($decoded) ? $decoded : [];
        }

        // Idempotent: projectors are registered twice in this codebase
        // (auto-discovery + ExchangeServiceProvider), so this handler runs
        // twice per event. A trade already recorded must not be applied
        // a second time, or filled amounts would double-count.
        if (collect($trades)->contains('trade_id', $event->tradeId)) {
            return;
        }

        $trades[] = [
            'trade_id'         => $event->tradeId,
            'matched_order_id' => $event->matchedOrderId,
            'executed_price'   => $event->executedPrice,
            'executed_amount'  => $event->executedAmount,
            // The fee this order pays depends on its ROLE (maker/taker),
            // not its side: a resting buy is the maker and pays the maker
            // fee. The role rides in the event metadata (set by
            // TradeSettlementService); fall back to the old side-based
            // derivation only for events recorded before that existed.
            'fee' => ($event->metadata['maker_side'] ?? ($order->type === 'buy' ? 'sell' : 'buy')) === $order->type
                ? $event->makerFee
                : $event->takerFee,
            'timestamp' => now()->toIso8601String(),
        ];

        $filledAmount = BigDecimal::of($order->filled_amount)->plus($event->executedAmount);

        // Calculate average price
        $totalValue = BigDecimal::zero();
        $totalAmount = BigDecimal::zero();
        foreach ($trades as $trade) {
            $tradeValue = BigDecimal::of($trade['executed_price'])->multipliedBy($trade['executed_amount']);
            $totalValue = $totalValue->plus($tradeValue);
            $totalAmount = $totalAmount->plus($trade['executed_amount']);
        }
        $averagePrice = $totalValue->dividedBy($totalAmount, 18);

        $order->update(
            [
            'filled_amount' => $filledAmount->__toString(),
            'average_price' => $averagePrice->__toString(),
            'trades'        => $trades,
            'status'        => $filledAmount->isEqualTo($order->amount) ? 'filled' : 'partially_filled',
            'version'       => ((int) $order->version) + 1,
            ]
        );

        // Create trade record
        $matchedOrder = Order::where('order_id', $event->matchedOrderId)->firstOrFail();

        // Check if trade already exists (created by DemoExchangeService)
        if (Trade::where('trade_id', $event->tradeId)->exists()) {
            return;
        }

        // The maker side is the side that rested in the book, recorded in
        // the event metadata by the settlement service — never derived
        // from which order's event happens to be handled first.
        $makerSide = $event->metadata['maker_side'] ?? ($order->type === 'buy' ? 'sell' : 'buy');

        Trade::create(
            [
            'trade_id'          => $event->tradeId,
            'buy_order_id'      => $order->type === 'buy' ? $order->order_id : $matchedOrder->order_id,
            'sell_order_id'     => $order->type === 'sell' ? $order->order_id : $matchedOrder->order_id,
            'buyer_account_id'  => $order->type === 'buy' ? $order->account_id : $matchedOrder->account_id,
            'seller_account_id' => $order->type === 'sell' ? $order->account_id : $matchedOrder->account_id,
            'base_currency'     => $order->base_currency,
            'quote_currency'    => $order->quote_currency,
            'price'             => $event->executedPrice,
            'amount'            => $event->executedAmount,
            'value'             => BigDecimal::of($event->executedPrice)->multipliedBy($event->executedAmount)->__toString(),
            'maker_fee'         => $event->makerFee,
            'taker_fee'         => $event->takerFee,
            'maker_side'        => $makerSide,
            'metadata'          => $event->metadata,
            ]
        );
    }

    public function onOrderPartiallyFilled(OrderPartiallyFilled $event): void
    {
        Order::where('order_id', $event->orderId)->update(
            [
            'status' => 'partially_filled',
            ]
        );
    }

    public function onOrderFilled(OrderFilled $event): void
    {
        Order::where('order_id', $event->orderId)->update(
            [
            'status'    => 'filled',
            'filled_at' => now(),
            ]
        );
    }

    public function onOrderCancelled(OrderCancelled $event): void
    {
        $order = Order::where('order_id', $event->orderId)->first();
        // Idempotent: skip when already cancelled (double registration).
        if ($order === null || $order->status === 'cancelled') {
            return;
        }

        // value() returns the raw column, so decode defensively.
        $existing = Order::where('order_id', $event->orderId)->value('metadata');
        $existingMeta = is_array($existing)
            ? $existing
            : (json_decode((string) $existing, true) ?? []);

        Order::where('order_id', $event->orderId)->update(
            [
            'status'       => 'cancelled',
            'cancelled_at' => now(),
            // Pass an array: the 'array' cast encodes on save.
            'metadata' => array_merge($existingMeta, ['cancellation_reason' => $event->reason]),
            ]
        );
        Order::where('order_id', $event->orderId)->increment('version');
    }
}
