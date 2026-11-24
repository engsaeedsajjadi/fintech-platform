<?php

namespace App\Domain\Exchange\Activities;

use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Services\FeeCalculator;
use App\Domain\Exchange\Services\TradeSettlementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Exception;
use Illuminate\Support\Str;
use Workflow\Activity;
use Workflow\Models\StoredWorkflow;

/**
 * Settle one match from the OrderMatchingWorkflow.
 *
 * Delegates to TradeSettlementService — the single settlement
 * implementation shared with the synchronous OrderBookTradingService.
 * The previous implementation referenced classes that do not exist
 * (App\Domain\Account\Aggregates\Account, App\Domain\Transaction\*)
 * and read non-existent `base_asset`/`quote_asset` fields off the Order
 * projection, so workflow settlement could never run.
 *
 * The workflow MUST pass the match's own trade id (and its maker/taker
 * fees): minting a fresh trade id here used to make the workflow's
 * follow-up matchOrder() calls record a SECOND OrderMatched event for
 * the same economic match — double filled amounts and two Trade rows.
 * With the match's trade id, the follow-up calls are no-ops via the
 * aggregate's per-trade-id guard.
 */
class TransferAssetsActivity extends Activity
{
    /**
     * Dual-mode constructor, same pattern as MatchOrderActivity: with no
     * arguments the activity runs synchronously (direct calls, tests);
     * with the runtime's (index, now, storedWorkflow, ...args) the
     * parent constructor runs so the activity can be dispatched as a
     * queued job by the workflow.
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
        string $buyOrderId,
        string $sellOrderId,
        string $executedAmount,
        string $executedPrice,
        ?string $tradeId = null,
        ?string $makerFee = null,
        ?string $takerFee = null,
        ?string $makerSide = null,
    ): object {
        try {
            $buyOrder = OrderProjection::query()->where('order_id', $buyOrderId)->firstOrFail();
            $sellOrder = OrderProjection::query()->where('order_id', $sellOrderId)->firstOrFail();

            // The maker is the older (resting) order; the taker is the
            // incoming one. The workflow passes the matcher's assignment
            // explicitly — only fall back to the timestamp rule for
            // callers that predate it.
            $makerSide ??= $buyOrder->created_at <= $sellOrder->created_at ? 'buy' : 'sell';

            if ($makerFee === null || $takerFee === null) {
                $takerAccountId = $makerSide === 'buy' ? $sellOrder->account_id : $buyOrder->account_id;
                $makerAccountId = $makerSide === 'buy' ? $buyOrder->account_id : $sellOrder->account_id;

                $fees = (new FeeCalculator())->calculateFees(
                    BigDecimal::of($executedAmount),
                    BigDecimal::of($executedPrice),
                    $takerAccountId,
                    $makerAccountId
                );
                $makerFee ??= $fees->makerFee->__toString();
                $takerFee ??= $fees->takerFee->__toString();
            }

            $trade = app(TradeSettlementService::class)->settle(
                buyOrderId: $buyOrderId,
                sellOrderId: $sellOrderId,
                // Never mint a new id: the match already has one, and the
                // workflow replays it in its follow-up matchOrder() calls.
                tradeId: $tradeId ?? Str::uuid()->toString(),
                executedAmount: $executedAmount,
                executedPrice: $executedPrice,
                makerFee: $makerFee,
                takerFee: $takerFee,
                makerSide: $makerSide,
            );

            if ($trade === null) {
                return (object) [
                    'success' => false,
                    'error'   => 'Nothing left to settle for this match.',
                ];
            }

            return (object) [
                'success'            => true,
                'tradeId'            => $trade->trade_id,
                'baseTransactionId'  => $trade->trade_id,
                'quoteTransactionId' => $trade->trade_id,
                'baseAmount'         => $executedAmount,
                'quoteAmount'        => BigDecimal::of($executedAmount)->multipliedBy($executedPrice)->toScale(18, RoundingMode::DOWN)->__toString(),
            ];
        } catch (Exception $e) {
            return (object) [
                'success' => false,
                'error'   => $e->getMessage(),
            ];
        }
    }
}
