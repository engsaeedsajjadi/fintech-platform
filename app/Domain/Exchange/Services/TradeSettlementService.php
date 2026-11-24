<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Exchange\Aggregates\Order as OrderAggregate;
use App\Domain\Exchange\Exceptions\TradingSettlementException;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\Trade;
use App\Domain\Ledger\Services\LedgerService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Settlement for one order-book match.
 *
 * This is the ONLY place where a match moves value, and it is shared by
 * the synchronous OrderBookTradingService and the async
 * OrderMatchingWorkflow (via TransferAssetsActivity) — there is one
 * settlement implementation, not two.
 *
 * Settlement = balanced journal entries via LedgerService::post() ONLY.
 * No account balance is ever written directly; the journal is the
 * settlement record and `source_event_id` is the trade id, so every entry
 * traces back to its trade.
 *
 * Idempotency: settling the same trade id twice is a no-op (the existing
 * Trade row is returned). Amounts are clamped to the remaining amounts
 * of both orders inside a row-locked transaction, so a concurrent second
 * execution can only settle what is still unfilled — never double-fill.
 *
 * Known ledger limitation (pre-existing): LedgerService balances at
 * scale 4 and journal_lines are decimal(20,4), while trade amounts are
 * 18dp. Leg amounts are truncated to 4dp for the journal; the Trade row
 * keeps the exact 18dp amounts. A leg that truncates to zero (dust)
 * refuses to settle instead of silently losing value.
 */
class TradeSettlementService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly TradingHoldService $holds,
    ) {
    }

    /**
     * Settle one match. Returns the Trade row, or null when nothing was
     * left to settle (both orders already filled by a concurrent run).
     *
     * @throws TradingSettlementException
     */
    public function settle(
        string $buyOrderId,
        string $sellOrderId,
        string $tradeId,
        string $executedAmount,
        string $executedPrice,
        string $makerFee,
        string $takerFee,
        ?string $makerSide = null,
    ): ?Trade {
        return DB::transaction(function () use (
            $buyOrderId,
            $sellOrderId,
            $tradeId,
            $executedAmount,
            $executedPrice,
            $makerFee,
            $takerFee,
            $makerSide
        ): ?Trade {
            $existing = Trade::where('trade_id', $tradeId)->first();
            if ($existing !== null) {
                return $existing;
            }

            // Lock both order rows in a deterministic order (deadlock-safe)
            // and clamp to what is genuinely still unfilled.
            $ids = [$buyOrderId, $sellOrderId];
            sort($ids);
            $locked = OrderProjection::whereIn('order_id', $ids)->lockForUpdate()->get()->keyBy('order_id');
            $buyOrder = $locked->get($buyOrderId);
            $sellOrder = $locked->get($sellOrderId);

            if ($buyOrder === null || $sellOrder === null) {
                throw new TradingSettlementException("Cannot settle trade {$tradeId}: order not found.");
            }

            $amount = BigDecimal::of($executedAmount)
                ->min(BigDecimal::of($buyOrder->remaining_amount))
                ->min(BigDecimal::of($sellOrder->remaining_amount));

            if ($amount->isLessThanOrEqualTo(0)) {
                return null;
            }

            $makerSide ??= $buyOrder->created_at <= $sellOrder->created_at ? 'buy' : 'sell';
            $buyerFee = $makerSide === 'buy' ? $makerFee : $takerFee;
            $sellerFee = $makerSide === 'sell' ? $makerFee : $takerFee;

            $amountStr = $amount->toScale(18)->__toString();
            $price = BigDecimal::of($executedPrice);
            $value = $amount->multipliedBy($price)->toScale(18);

            $this->postLedgerLegs(
                tradeId: $tradeId,
                buyOrder: $buyOrder,
                sellOrder: $sellOrder,
                amount: $amountStr,
                value: $value->__toString(),
                buyerFee: $buyerFee,
                sellerFee: $sellerFee,
            );

            // Record the match on both aggregates (aggregate-level guard
            // makes a duplicate trade id a no-op) — the projector creates
            // the Trade row from the first OrderMatched event.
            OrderAggregate::retrieve($buyOrderId)->matchOrder(
                matchedOrderId: $sellOrderId,
                tradeId: $tradeId,
                executedPrice: $price->toScale(18)->__toString(),
                executedAmount: $amountStr,
                makerFee: $makerFee,
                takerFee: $takerFee,
            )->persist();

            OrderAggregate::retrieve($sellOrderId)->matchOrder(
                matchedOrderId: $buyOrderId,
                tradeId: $tradeId,
                executedPrice: $price->toScale(18)->__toString(),
                executedAmount: $amountStr,
                makerFee: $makerFee,
                takerFee: $takerFee,
            )->persist();

            $this->consumeHolds($buyOrder, $sellOrder, $amountStr, $value->__toString(), $buyerFee, $sellerFee);

            return Trade::where('trade_id', $tradeId)->first();
        });
    }

    private function postLedgerLegs(
        string $tradeId,
        OrderProjection $buyOrder,
        OrderProjection $sellOrder,
        string $amount,
        string $value,
        string $buyerFee,
        string $sellerFee,
    ): void {
        $base = $buyOrder->base_currency;
        $quote = $buyOrder->quote_currency;

        $baseLeg = $this->toLedgerScale($amount);
        $quoteValue = $this->toLedgerScale($value);
        $buyerFeeLeg = $this->toLedgerScale($buyerFee);
        $sellerFeeLeg = $this->toLedgerScale($sellerFee);

        if (BigDecimal::of($baseLeg)->isLessThanOrEqualTo(0) || BigDecimal::of($quoteValue)->isLessThanOrEqualTo(0)) {
            throw new TradingSettlementException(
                "Cannot settle trade {$tradeId} via the ledger: leg amount truncates to zero at 4dp "
                . "(base {$amount} {$base} / value {$value} {$quote}). The ledger posts at scale 4; "
                . 'dust-size trades cannot be represented there.'
            );
        }

        $buyerBase = "trading:account:{$buyOrder->account_id}:{$base}";
        $sellerBase = "trading:account:{$sellOrder->account_id}:{$base}";
        $buyerQuote = "trading:account:{$buyOrder->account_id}:{$quote}";
        $sellerQuote = "trading:account:{$sellOrder->account_id}:{$quote}";
        $feeIncome = "trading:fees:{$quote}";

        // Base leg: base asset moves seller -> buyer.
        $baseEntry = $this->ledger->post(
            "Trade {$tradeId}: {$amount} {$base} @ {$buyOrder->price} {$quote}",
            [
                ['account_code' => $buyerBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "Buy {$amount} {$base}"],
                ['account_code' => $sellerBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "Sell {$amount} {$base}"],
            ],
            'exchange',
            $tradeId,
        );

        // Quote leg: buyer pays value + buyer fee; seller receives
        // value - seller fee; fees go to fee income. Balanced by construction.
        $buyerDebit = BigDecimal::of($quoteValue)->plus($buyerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
        $sellerCredit = BigDecimal::of($quoteValue)->minus($sellerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
        $totalFees = BigDecimal::of($buyerFeeLeg)->plus($sellerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();

        if (BigDecimal::of($sellerCredit)->isNegative()) {
            throw new TradingSettlementException("Cannot settle trade {$tradeId}: seller fee exceeds trade value.");
        }

        $quoteEntry = $this->ledger->post(
            "Trade {$tradeId}: {$value} {$quote} settlement (fees {$totalFees} {$quote})",
            [
                ['account_code' => $buyerQuote, 'debit' => $buyerDebit, 'credit' => '0', 'narrative' => "Pay {$value} {$quote} + fee {$buyerFeeLeg}"],
                ['account_code' => $sellerQuote, 'debit' => '0', 'credit' => $sellerCredit, 'narrative' => "Receive {$value} {$quote} - fee {$sellerFeeLeg}"],
                ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $totalFees, 'narrative' => "Trade fees {$tradeId}"],
            ],
            'exchange',
            $tradeId,
        );

        // Keep the entry numbers discoverable from the trade narrative path;
        // the entries themselves carry source_event_id = trade id.
        unset($baseEntry, $quoteEntry);
    }

    /** Truncate an 18dp amount to the ledger's 4dp scale. */
    private function toLedgerScale(string $amount): string
    {
        return BigDecimal::of($amount)->toScale(4, RoundingMode::DOWN)->__toString();
    }

    private function consumeHolds(
        OrderProjection $buyOrder,
        OrderProjection $sellOrder,
        string $amount,
        string $value,
        string $buyerFee,
        string $sellerFee,
    ): void {
        // Refresh: the projector has updated filled amounts by now.
        $buyOrder->refresh();
        $sellOrder->refresh();

        $buyHold = $this->holds->activeHoldForSource('order_book', $buyOrder->order_id, $buyOrder->quote_currency);
        if ($buyHold !== null) {
            if (BigDecimal::of((string) $buyOrder->remaining_amount)->isLessThanOrEqualTo(0)) {
                $this->holds->release($buyHold);
            } else {
                $this->holds->consume($buyHold, BigDecimal::of($value)->plus($buyerFee)->toScale(18, RoundingMode::DOWN)->__toString());
            }
        }

        $sellHold = $this->holds->activeHoldForSource('order_book', $sellOrder->order_id, $sellOrder->base_currency);
        if ($sellHold !== null) {
            if (BigDecimal::of((string) $sellOrder->remaining_amount)->isLessThanOrEqualTo(0)) {
                $this->holds->release($sellHold);
            } else {
                $this->holds->consume($sellHold, $amount);
            }
        }
    }
}
