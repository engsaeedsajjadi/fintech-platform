<?php

declare(strict_types=1);

namespace App\Domain\Exchange\Services;

use App\Domain\Exchange\Models\OtcDeal;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\Trade;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;

/**
 * Trading reports, aggregated from the orders / trades / OTC deals
 * read models. Reports are read-only summaries; they never move funds.
 */
class TradingReportService
{
    /**
     * @return array{pair: string, trades: int, base_volume: string, quote_volume: string, fees: string, open_orders: int}
     */
    public function pairSummary(string $baseCurrency, string $quoteCurrency, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $trades = Trade::forPair($baseCurrency, $quoteCurrency);
        if ($from !== null) {
            $trades->where('created_at', '>=', $from);
        }
        if ($to !== null) {
            $trades->where('created_at', '<=', $to);
        }
        $rows = $trades->get();

        $baseVolume = $rows->reduce(fn (BigDecimal $c, Trade $t) => $c->plus($t->amount), BigDecimal::zero());
        $quoteVolume = $rows->reduce(fn (BigDecimal $c, Trade $t) => $c->plus($t->value), BigDecimal::zero());
        $fees = $rows->reduce(
            fn (BigDecimal $c, Trade $t) => $c->plus($t->maker_fee)->plus($t->taker_fee),
            BigDecimal::zero()
        );

        return [
            'pair'         => "{$baseCurrency}/{$quoteCurrency}",
            'trades'       => $rows->count(),
            'base_volume'  => $baseVolume->toScale(18)->__toString(),
            'quote_volume' => $quoteVolume->toScale(18)->__toString(),
            'fees'         => $fees->toScale(18)->__toString(),
            'open_orders'  => OrderProjection::forPair($baseCurrency, $quoteCurrency)->open()->count(),
        ];
    }

    /**
     * @return array{account_id: string, open_orders: int, filled_orders: int, cancelled_orders: int, trades: int, quote_volume: string, fees_paid: string}
     */
    public function userSummary(string $accountId): array
    {
        $orders = OrderProjection::forAccount($accountId)->get();
        $trades = Trade::forAccount($accountId)->get();

        $quoteVolume = $trades->reduce(fn (BigDecimal $c, Trade $t) => $c->plus($t->value), BigDecimal::zero());
        $feesPaid = $trades->reduce(
            fn (BigDecimal $c, Trade $t) => $c->plus($t->buyer_fee)->plus($t->seller_fee),
            BigDecimal::zero()
        );

        return [
            'account_id'       => $accountId,
            'open_orders'      => $orders->whereIn('status', ['pending', 'open', 'partially_filled'])->count(),
            'filled_orders'    => $orders->where('status', 'filled')->count(),
            'cancelled_orders' => $orders->where('status', 'cancelled')->count(),
            'trades'           => $trades->count(),
            'quote_volume'     => $quoteVolume->toScale(18)->__toString(),
            'fees_paid'        => $feesPaid->toScale(18)->__toString(),
        ];
    }

    /**
     * @return array{date: string, order_book_trades: int, order_book_quote_volume: string, otc_deals: int, otc_quote_volume: string}
     */
    public function dailyReport(Carbon $date): array
    {
        $from = $date->copy()->startOfDay();
        $to = $date->copy()->endOfDay();

        $trades = Trade::whereBetween('created_at', [$from, $to])->get();
        $deals = OtcDeal::whereBetween('created_at', [$from, $to])->get();

        return [
            'date'                    => $from->toDateString(),
            'order_book_trades'       => $trades->count(),
            'order_book_quote_volume' => $trades->reduce(fn (BigDecimal $c, Trade $t) => $c->plus($t->value), BigDecimal::zero())->toScale(18)->__toString(),
            'otc_deals'               => $deals->count(),
            'otc_quote_volume'        => $deals->reduce(fn (BigDecimal $c, OtcDeal $d) => $c->plus($d->value), BigDecimal::zero())->toScale(18)->__toString(),
        ];
    }
}
