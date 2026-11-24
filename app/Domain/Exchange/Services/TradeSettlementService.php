<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Exchange\Aggregates\Order as OrderAggregate;
use App\Domain\Exchange\Exceptions\TradingSettlementException;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\Trade;
use App\Domain\Ledger\Models\JournalEntry;
use App\Domain\Ledger\Services\LedgerService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

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
 * Durable phases (never a single transaction around an external sync):
 *   A. Claim + record — one transaction: lock both order rows in a
 *      deterministic order (deadlock-safe), clamp to what is genuinely
 *      still unfilled, and persist the match events. The projector
 *      creates the Trade row with metadata settlement_status=pending.
 *      No ledger call happens here.
 *   B. Ledger legs — OUTSIDE any open transaction, via
 *      LedgerService::post(). Each leg is posted at most once: before
 *      posting, an entry with the same (source_event_id, description)
 *      is looked up and reused, so a retry after a partial failure
 *      replays the posted leg instead of double-posting it. Posting
 *      here means the ledger driver's sync runs only after phase A
 *      committed — never inside an open outer transaction, so a later
 *      failure cannot leave external transfers with no database record
 *      behind them. (Ledger itself also owns the driver sync: a sync
 *      failure is recorded in its driver-sync outbox for retry and does
 *      NOT fail the posting — the DB journal is authoritative, so the
 *      trade is settled once the entries are durable.)
 *   C. Finalise — one transaction: re-check the Trade row, consume the
 *      holds, and flip metadata settlement_status to settled with the
 *      ledger entry numbers. A trade is only RETURNED as settled once
 *      this phase commits.
 *
 * Failure handling: if phase B fails (ledger validation or persist
 * error), the Trade row stays pending/ledger_failed with the failure
 * recorded in its metadata, and settle() throws
 * TradingSettlementException — the trade is NEVER reported settled
 * before settlement is durably recorded. Re-calling settle() with the
 * same trade id resumes from the stored Trade row (same amounts, same
 * fees) and completes the missing legs; already-posted legs are
 * replayed, never duplicated. A pure driver-sync failure does not reach
 * here: Ledger outboxes it for retry and the posting succeeds.
 *
 * Idempotency: settling the same trade id twice is a no-op (the existing
 * settled Trade row is returned). Amounts are clamped to the remaining
 * amounts of both orders inside a row-locked transaction, so a concurrent
 * second execution can only settle what is still unfilled — never
 * double-fill. A per-trade cache lock serialises concurrent settle()
 * calls for the same trade id.
 *
 * CALLERS MUST call settle() outside any business database transaction:
 * phase B must run with no open transaction so the driver sync lands
 * after a real commit. (The test-suite transaction is exempt: it is the
 * harness, never commits, and its driver is the no-op Eloquent driver.)
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
        if (DB::transactionLevel() > (app()->runningUnitTests() ? 1 : 0)) {
            throw new TradingSettlementException(
                "Trade {$tradeId} cannot settle inside an open database transaction: "
                . 'the ledger legs must post after the trade record commits, '
                . 'so call settle() outside any business transaction.',
            );
        }

        $lock = Cache::lock("trade-settlement:{$tradeId}", 60);

        try {
            $lock->block(10);
        } catch (LockTimeoutException $e) {
            throw new TradingSettlementException(
                "Trade {$tradeId} is already being settled by another process.",
                0,
                $e,
            );
        }

        try {
            $plan = $this->claimAndRecord(
                $buyOrderId,
                $sellOrderId,
                $tradeId,
                $executedAmount,
                $executedPrice,
                $makerFee,
                $takerFee,
                $makerSide,
            );

            if ($plan === null) {
                return null;
            }

            $entryNumbers = $this->postLegs($plan);

            return $this->finalise($plan, $entryNumbers);
        } finally {
            $lock->release();
        }
    }

    /**
     * Phase A: lock, clamp, validate the legs, persist the match.
     *
     * Returns null when nothing is left to settle. On a retry of a
     * pending/failed trade the stored Trade row is the plan — the amounts
     * are never re-clamped, so a retry settles exactly what was claimed.
     *
     * @return array<string, string>|null
     */
    private function claimAndRecord(
        string $buyOrderId,
        string $sellOrderId,
        string $tradeId,
        string $executedAmount,
        string $executedPrice,
        string $makerFee,
        string $takerFee,
        ?string $makerSide,
    ): ?array {
        return DB::transaction(function () use (
            $buyOrderId,
            $sellOrderId,
            $tradeId,
            $executedAmount,
            $executedPrice,
            $makerFee,
            $takerFee,
            $makerSide
        ): ?array {
            $existing = Trade::where('trade_id', $tradeId)->lockForUpdate()->first();

            if ($existing !== null) {
                $status = (string) ($existing->metadata['settlement_status'] ?? 'settled');

                if ($status === 'settled') {
                    // Already settled: return the plan so settle() can
                    // return the existing row without touching anything.
                    return $this->planFromTrade($existing, settled: true);
                }

                return $this->planFromTrade($existing, settled: false);
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

            // NOTE: Brick's min()/max() are STATIC (BigDecimal::min(...)).
            // Calling ->min() on an instance ignores the instance and
            // returns the min of the arguments alone — the old chained
            // ->min() calls never clamped at all: the amount always came
            // out as the sell order's remaining, over-filling the buy
            // whenever the buy was the constraining side.
            $amount = BigDecimal::min(
                BigDecimal::of($executedAmount),
                BigDecimal::of($buyOrder->remaining_amount),
                BigDecimal::of($sellOrder->remaining_amount),
            );

            if ($amount->isLessThanOrEqualTo(0)) {
                return null;
            }

            $makerSide ??= $buyOrder->created_at <= $sellOrder->created_at ? 'buy' : 'sell';
            $buyerFee = $makerSide === 'buy' ? $makerFee : $takerFee;
            $sellerFee = $makerSide === 'sell' ? $makerFee : $takerFee;

            $amountStr = $amount->toScale(18)->__toString();
            $price = BigDecimal::of($executedPrice);
            $value = $amount->multipliedBy($price)->toScale(18);

            $plan = [
                'trade_id'        => $tradeId,
                'buy_order_id'    => $buyOrderId,
                'sell_order_id'   => $sellOrderId,
                'amount'          => $amountStr,
                'price'           => $price->toScale(18)->__toString(),
                'maker_fee'       => $makerFee,
                'taker_fee'       => $takerFee,
                'maker_side'      => $makerSide,
                'base_currency'   => $buyOrder->base_currency,
                'quote_currency'  => $buyOrder->quote_currency,
                'buy_account_id'  => (string) $buyOrder->account_id,
                'sell_account_id' => (string) $sellOrder->account_id,
                'value'           => $value->__toString(),
                'buyer_fee'       => $buyerFee,
                'seller_fee'      => $sellerFee,
            ];

            // Validate the legs BEFORE any mutation: a dust trade or a
            // fee that exceeds the value must fail here, while the
            // orders are still untouched — not after the Trade row exists.
            $this->buildLegs($plan);

            // Record the match on both aggregates (aggregate-level guard
            // makes a duplicate trade id a no-op) — the projector creates
            // the Trade row from the first OrderMatched event, with the
            // maker side and the pending settlement status in metadata.
            $metadata = [
                'maker_side'        => $makerSide,
                'settlement_status' => 'pending',
            ];

            OrderAggregate::retrieve($buyOrderId)->matchOrder(
                matchedOrderId: $sellOrderId,
                tradeId: $tradeId,
                executedPrice: $price->toScale(18)->__toString(),
                executedAmount: $amountStr,
                makerFee: $makerFee,
                takerFee: $takerFee,
                metadata: $metadata,
            )->persist();

            OrderAggregate::retrieve($sellOrderId)->matchOrder(
                matchedOrderId: $buyOrderId,
                tradeId: $tradeId,
                executedPrice: $price->toScale(18)->__toString(),
                executedAmount: $amountStr,
                makerFee: $makerFee,
                takerFee: $takerFee,
                metadata: $metadata,
            )->persist();

            return $plan;
        });
    }

    /**
     * Phase B: post both ledger legs outside any transaction. A leg that
     * was already posted (same source_event_id + description) is reused,
     * so a retry after a partial failure never double-posts.
     *
     * @param  array<string, string>  $plan
     * @return array{base: string, quote: string}
     *
     * @throws TradingSettlementException
     */
    private function postLegs(array $plan): array
    {
        if (($plan['settled'] ?? 'no') === 'yes') {
            return [
                'base'  => (string) ($plan['base_entry_number'] ?? ''),
                'quote' => (string) ($plan['quote_entry_number'] ?? ''),
            ];
        }

        $legs = $this->buildLegs($plan);
        $numbers = [];

        foreach (['base', 'quote'] as $leg) {
            try {
                $numbers[$leg] = $this->postLegOnce($plan['trade_id'], $legs[$leg]);
            } catch (TradingSettlementException $e) {
                // Record the failure durably on the Trade row: the trade
                // is pending reconciliation, and a retry resumes from the
                // legs that did post. Never report it settled.
                $this->markLedgerFailed($plan['trade_id'], $leg, $e->getMessage(), $numbers);

                throw $e;
            }
        }

        return $numbers;
    }

    /**
     * Post one leg exactly once: reuse the entry when an entry with the
     * same (source_event_id, description) already exists.
     *
     * @param  array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}  $leg
     *
     * @throws TradingSettlementException
     */
    private function postLegOnce(string $tradeId, array $leg): string
    {
        $existing = JournalEntry::where('source_domain', 'exchange')
            ->where('source_event_id', $tradeId)
            ->where('description', $leg['description'])
            ->first();

        if ($existing !== null) {
            return (string) $existing->entry_number;
        }

        try {
            $entry = $this->ledger->post($leg['description'], $leg['lines'], 'exchange', $tradeId);
        } catch (Throwable $e) {
            throw new TradingSettlementException(
                "Ledger posting failed for trade {$tradeId}: {$e->getMessage()}",
                0,
                $e,
            );
        }

        return (string) $entry->entry_number;
    }

    /**
     * Phase C: consume the holds and mark the trade settled — only after
     * both ledger legs are durably posted.
     *
     * @param  array<string, string>  $plan
     * @param  array{base: string, quote: string}  $entryNumbers
     */
    private function finalise(array $plan, array $entryNumbers): Trade
    {
        // A retry of an already-settled trade returns the stored row.
        if (($plan['settled'] ?? 'no') === 'yes') {
            return Trade::where('trade_id', $plan['trade_id'])->firstOrFail();
        }

        return DB::transaction(function () use ($plan, $entryNumbers): Trade {
            $trade = Trade::where('trade_id', $plan['trade_id'])->lockForUpdate()->firstOrFail();

            if ((string) ($trade->metadata['settlement_status'] ?? '') === 'settled') {
                return $trade;
            }

            $buyOrder = OrderProjection::where('order_id', $plan['buy_order_id'])->firstOrFail();
            $sellOrder = OrderProjection::where('order_id', $plan['sell_order_id'])->firstOrFail();

            $this->consumeHolds($buyOrder, $sellOrder, $plan['amount'], $plan['value'], $plan['buyer_fee'], $plan['seller_fee']);

            $metadata = is_array($trade->metadata) ? $trade->metadata : [];
            $metadata['settlement_status'] = 'settled';
            $metadata['ledger_entry_numbers'] = [$entryNumbers['base'], $entryNumbers['quote']];
            unset($metadata['ledger_sync_failed'], $metadata['ledger_failed_leg'], $metadata['ledger_error']);

            $trade->update(['metadata' => $metadata]);

            return $trade->refresh();
        });
    }

    /**
     * Build both ledger legs for a plan. Pure function of the plan, so
     * phase A (validation) and phase B (posting) always agree.
     *
     * @param  array<string, string>  $plan
     * @return array{base: array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}, quote: array{description: string, lines: array<int, array{account_code: string, debit: string, credit: string, narrative: string}>}}
     *
     * @throws TradingSettlementException
     */
    private function buildLegs(array $plan): array
    {
        $tradeId = $plan['trade_id'];
        $base = $plan['base_currency'];
        $quote = $plan['quote_currency'];
        $amount = $plan['amount'];
        $value = $plan['value'];

        $baseLeg = $this->toLedgerScale($amount);
        $quoteValue = $this->toLedgerScale($value);
        $buyerFeeLeg = $this->toLedgerScale($plan['buyer_fee']);
        $sellerFeeLeg = $this->toLedgerScale($plan['seller_fee']);

        if (BigDecimal::of($baseLeg)->isLessThanOrEqualTo(0) || BigDecimal::of($quoteValue)->isLessThanOrEqualTo(0)) {
            throw new TradingSettlementException(
                "Cannot settle trade {$tradeId} via the ledger: leg amount truncates to zero at 4dp "
                . "(base {$amount} {$base} / value {$value} {$quote}). The ledger posts at scale 4; "
                . 'dust-size trades cannot be represented there.'
            );
        }

        $buyerBase = "trading:account:{$plan['buy_account_id']}:{$base}";
        $sellerBase = "trading:account:{$plan['sell_account_id']}:{$base}";
        $buyerQuote = "trading:account:{$plan['buy_account_id']}:{$quote}";
        $sellerQuote = "trading:account:{$plan['sell_account_id']}:{$quote}";
        $feeIncome = "trading:fees:{$quote}";

        // Quote leg: buyer pays value + buyer fee; seller receives
        // value - seller fee; fees go to fee income. Balanced by construction.
        $buyerDebit = BigDecimal::of($quoteValue)->plus($buyerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
        $sellerCredit = BigDecimal::of($quoteValue)->minus($sellerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();
        $totalFees = BigDecimal::of($buyerFeeLeg)->plus($sellerFeeLeg)->toScale(4, RoundingMode::DOWN)->__toString();

        if (BigDecimal::of($sellerCredit)->isNegative()) {
            throw new TradingSettlementException("Cannot settle trade {$tradeId}: seller fee exceeds trade value.");
        }

        return [
            'base' => [
                'description' => "Trade {$tradeId}: {$amount} {$base} @ {$plan['price']} {$quote}",
                'lines'       => [
                    ['account_code' => $buyerBase, 'debit' => $baseLeg, 'credit' => '0', 'narrative' => "Buy {$amount} {$base}"],
                    ['account_code' => $sellerBase, 'debit' => '0', 'credit' => $baseLeg, 'narrative' => "Sell {$amount} {$base}"],
                ],
            ],
            'quote' => [
                'description' => "Trade {$tradeId}: {$value} {$quote} settlement (fees {$totalFees} {$quote})",
                'lines'       => [
                    ['account_code' => $buyerQuote, 'debit' => $buyerDebit, 'credit' => '0', 'narrative' => "Pay {$value} {$quote} + fee {$buyerFeeLeg}"],
                    ['account_code' => $sellerQuote, 'debit' => '0', 'credit' => $sellerCredit, 'narrative' => "Receive {$value} {$quote} - fee {$sellerFeeLeg}"],
                    ['account_code' => $feeIncome, 'debit' => '0', 'credit' => $totalFees, 'narrative' => "Trade fees {$tradeId}"],
                ],
            ],
        ];
    }

    /** Truncate an 18dp amount to the ledger's 4dp scale. */
    private function toLedgerScale(string $amount): string
    {
        return BigDecimal::of($amount)->toScale(4, RoundingMode::DOWN)->__toString();
    }

    /**
     * Rebuild a settlement plan from a stored Trade row (retry path).
     * The amounts are the ones that were claimed — never re-clamped.
     * Values are canonicalised back to 18dp: the trades table stores
     * decimal(36,18), which drops trailing zeros on read ('0.5' instead
     * of '0.500000000000000000'), and the leg descriptions must match
     * the originally posted entries exactly or the replay check misses
     * and double-posts.
     *
     * @return array<string, string>
     */
    private function planFromTrade(Trade $trade, bool $settled): array
    {
        $makerSide = (string) ($trade->metadata['maker_side'] ?? ($trade->maker_side ?? 'sell'));
        $amount = BigDecimal::of((string) $trade->amount)->toScale(18)->__toString();
        $price = BigDecimal::of((string) $trade->price)->toScale(18)->__toString();
        $makerFee = BigDecimal::of((string) $trade->maker_fee)->toScale(18)->__toString();
        $takerFee = BigDecimal::of((string) $trade->taker_fee)->toScale(18)->__toString();
        $value = BigDecimal::of($amount)
            ->multipliedBy($price)
            ->toScale(18)
            ->__toString();

        $numbers = $trade->metadata['ledger_entry_numbers'] ?? [];

        return [
            'trade_id'           => (string) $trade->trade_id,
            'buy_order_id'       => (string) $trade->buy_order_id,
            'sell_order_id'      => (string) $trade->sell_order_id,
            'amount'             => $amount,
            'price'              => $price,
            'maker_fee'          => $makerFee,
            'taker_fee'          => $takerFee,
            'maker_side'         => $makerSide,
            'base_currency'      => (string) $trade->base_currency,
            'quote_currency'     => (string) $trade->quote_currency,
            'buy_account_id'     => (string) $trade->buyer_account_id,
            'sell_account_id'    => (string) $trade->seller_account_id,
            'value'              => $value,
            'buyer_fee'          => $makerSide === 'buy' ? $makerFee : $takerFee,
            'seller_fee'         => $makerSide === 'sell' ? $makerFee : $takerFee,
            'settled'            => $settled ? 'yes' : 'no',
            'base_entry_number'  => (string) ($numbers[0] ?? ''),
            'quote_entry_number' => (string) ($numbers[1] ?? ''),
        ];
    }

    /**
     * @param  array<string, string>  $postedNumbers  Entry numbers already posted, keyed by leg ('base'/'quote').
     */
    private function markLedgerFailed(string $tradeId, string $leg, string $error, array $postedNumbers): void
    {
        DB::transaction(function () use ($tradeId, $leg, $error, $postedNumbers): void {
            $trade = Trade::where('trade_id', $tradeId)->lockForUpdate()->first();
            if ($trade === null) {
                return;
            }

            $metadata = is_array($trade->metadata) ? $trade->metadata : [];
            $metadata['settlement_status'] = 'ledger_failed';
            $metadata['ledger_sync_failed'] = true;
            $metadata['ledger_failed_leg'] = $leg;
            $metadata['ledger_error'] = $error;
            if ($postedNumbers !== []) {
                $metadata['ledger_entry_numbers'] = array_values($postedNumbers);
            }

            $trade->update(['metadata' => $metadata]);
        });
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
