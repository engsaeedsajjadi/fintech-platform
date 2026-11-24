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
 * Fees are recomputed here from the resting (maker) and incoming (taker)
 * orders: the maker is the older of the two orders, which is how the
 * matcher (MatchOrderActivity) assigns maker/taker as well.
 */
class TransferAssetsActivity extends Activity
{
    public function execute(
        string $buyOrderId,
        string $sellOrderId,
        string $executedAmount,
        string $executedPrice
    ): object {
        try {
            $buyOrder = OrderProjection::query()->where('order_id', $buyOrderId)->firstOrFail();
            $sellOrder = OrderProjection::query()->where('order_id', $sellOrderId)->firstOrFail();

            $makerSide = $buyOrder->created_at <= $sellOrder->created_at ? 'buy' : 'sell';
            $takerAccountId = $makerSide === 'buy' ? $sellOrder->account_id : $buyOrder->account_id;
            $makerAccountId = $makerSide === 'buy' ? $buyOrder->account_id : $sellOrder->account_id;

            $fees = (new FeeCalculator())->calculateFees(
                BigDecimal::of($executedAmount),
                BigDecimal::of($executedPrice),
                $takerAccountId,
                $makerAccountId
            );

            $trade = app(TradeSettlementService::class)->settle(
                buyOrderId: $buyOrderId,
                sellOrderId: $sellOrderId,
                tradeId: Str::uuid()->toString(),
                executedAmount: $executedAmount,
                executedPrice: $executedPrice,
                makerFee: $fees->makerFee->__toString(),
                takerFee: $fees->takerFee->__toString(),
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
