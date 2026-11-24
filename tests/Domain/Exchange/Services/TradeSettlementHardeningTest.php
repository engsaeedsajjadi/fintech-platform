<?php

declare(strict_types=1);

use App\Domain\Exchange\Activities\MatchOrderActivity;
use App\Domain\Exchange\Activities\TransferAssetsActivity;
use App\Domain\Exchange\Aggregates\Order as OrderAggregate;
use App\Domain\Exchange\Exceptions\TradingSettlementException;
use App\Domain\Exchange\Models\TradingHold;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\OrderBook as OrderBookProjection;
use App\Domain\Exchange\Projections\Trade;
use App\Domain\Exchange\Services\TradeSettlementService;
use App\Domain\Exchange\Services\TradingHoldService;
use App\Domain\Ledger\Contracts\LedgerDriverInterface;
use App\Domain\Ledger\Models\JournalEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function TradingRoomTest\enablePair;
use function TradingRoomTest\enableTrading;
use function TradingRoomTest\ensureAssets;
use function TradingRoomTest\makeAccount;
use function TradingRoomTest\orderBookService;

require_once __DIR__ . '/../trading-room-helpers.php';

beforeEach(function (): void {
    enableTrading();
    ensureAssets();
    enablePair(marketOrders: true);
});

/** Place an order straight on the aggregate, without matching. */
function placeRestingOrder(string $accountId, string $side, string $amount, string $price): OrderProjection
{
    $orderId = (string) Str::uuid();

    OrderAggregate::retrieve($orderId)->placeOrder(
        orderId: $orderId,
        accountId: $accountId,
        type: $side,
        orderType: 'limit',
        baseCurrency: 'BTC',
        quoteCurrency: 'USDT',
        amount: $amount,
        price: $price,
        metadata: ['execution_venue' => 'order_book', 'source' => 'order_book'],
    )->persist();

    return OrderProjection::where('order_id', $orderId)->firstOrFail();
}

it('records the resting buy as the maker with correct fee attribution', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);
    $seller = makeAccount(['BTC' => '1']);

    // The buy rests first, so the buy is the maker and the sell is the taker.
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade)->not->toBeNull()
        ->and($trade->maker_side)->toBe('buy')
        // Maker (buy) pays 0.1% = 20, taker (sell) pays 0.2% = 40.
        // (Fee columns come back as ints from the decimal columns.)
        ->and(bccomp((string) $trade->maker_fee, '20', 18))->toBe(0)
        ->and(bccomp((string) $trade->taker_fee, '40', 18))->toBe(0)
        ->and(bccomp((string) $trade->buyer_fee, '20', 18))->toBe(0)
        ->and(bccomp((string) $trade->seller_fee, '40', 18))->toBe(0);

    // Ledger fee split follows the roles, not the sides: buyer pays
    // value + 20, seller receives value - 40, fee income gets 60.
    $entries = JournalEntry::where('source_event_id', $trade->trade_id)->with('lines')->get();
    $quoteEntry = $entries->first(fn ($entry) => str_contains((string) $entry->description, 'settlement'));
    expect($quoteEntry)->not->toBeNull();

    $buyerQuote = $quoteEntry->lines->firstWhere('account_code', "trading:account:{$buyer->id}:USDT");
    $sellerQuote = $quoteEntry->lines->firstWhere('account_code', "trading:account:{$seller->id}:USDT");
    $feeLine = $quoteEntry->lines->firstWhere('account_code', 'trading:fees:USDT');

    expect((float) $buyerQuote->debit_amount)->toEqual(20020.0)
        ->and((float) $sellerQuote->credit_amount)->toEqual(19960.0)
        ->and((float) $feeLine->credit_amount)->toEqual(60.0);
});

it('refuses a dust trade that truncates to zero in the ledger', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $sell = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '30000');

    // 0.00000001 BTC @ 4000 = 0.00004 USDT truncates to 0.0000 at 4dp.
    expect(fn () => app(TradeSettlementService::class)->settle(
        buyOrderId: $buy->order_id,
        sellOrderId: $sell->order_id,
        tradeId: (string) Str::uuid(),
        executedAmount: '0.00000001',
        executedPrice: '4000',
        makerFee: '0',
        takerFee: '0',
    ))->toThrow(TradingSettlementException::class, 'truncates to zero');

    // Nothing was recorded: no trade, no journal entries, orders untouched.
    expect(Trade::count())->toBe(0);
    expect(JournalEntry::where('source_domain', 'exchange')->count())->toBe(0);
    expect($buy->refresh()->status)->toBe('pending');
    expect($sell->refresh()->status)->toBe('pending');
    expect(bccomp((string) $buy->refresh()->filled_amount, '0', 18))->toBe(0);
});

it('clamps an oversized settlement to the remaining amounts and never double-fills', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $sell = placeRestingOrder((string) $seller->id, 'sell', '0.5', '40000');
    $buy = placeRestingOrder((string) $buyer->id, 'buy', '0.5', '40000');

    $tradeId = (string) Str::uuid();
    $trade = app(TradeSettlementService::class)->settle(
        buyOrderId: $buy->order_id,
        sellOrderId: $sell->order_id,
        tradeId: $tradeId,
        executedAmount: '10',
        executedPrice: '40000',
        makerFee: '20',
        takerFee: '40',
        makerSide: 'sell',
    );

    expect($trade)->not->toBeNull();
    expect(bccomp((string) $trade->amount, '0.5', 18))->toBe(0);
    expect($buy->refresh()->status)->toBe('filled');
    expect($sell->refresh()->status)->toBe('filled');

    // Re-settling the same trade id is a no-op, even with a different amount.
    $again = app(TradeSettlementService::class)->settle(
        buyOrderId: $buy->order_id,
        sellOrderId: $sell->order_id,
        tradeId: $tradeId,
        executedAmount: '10',
        executedPrice: '40000',
        makerFee: '20',
        takerFee: '40',
        makerSide: 'sell',
    );

    expect($again->trade_id)->toBe($trade->trade_id);
    expect(Trade::count())->toBe(1);
    expect(JournalEntry::where('source_event_id', $tradeId)->count())->toBe(2);

    // A NEW trade id against fully-filled orders settles nothing.
    $nothing = app(TradeSettlementService::class)->settle(
        buyOrderId: $buy->order_id,
        sellOrderId: $sell->order_id,
        tradeId: (string) Str::uuid(),
        executedAmount: '0.5',
        executedPrice: '40000',
        makerFee: '20',
        takerFee: '40',
        makerSide: 'sell',
    );

    expect($nothing)->toBeNull();
    expect(Trade::count())->toBe(1);
});

it('never over-fills when the buy remainder is smaller than the sell remainder', function (): void {
    // Regression: Brick's min() is static, so the old chained ->min()
    // calls in the matcher and in settlement never clamped at all — a
    // 0.3 remainder against a 0.5 sell would settle 0.5 and over-fill
    // the buy to 0.7 of a 0.5 order.
    $sellerA = makeAccount(['BTC' => '1']);
    $sellerB = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $sellerA->id, 'sell', 'limit', 'BTC', 'USDT', '0.2', '40000');

    expect($buy->refresh()->status)->toBe('partially_filled');
    expect(bccomp((string) $buy->refresh()->filled_amount, '0.2', 18))->toBe(0);

    // The second sell is larger than the buy's 0.3 remainder.
    orderBookService()->placeOrder((string) $sellerB->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect($buy->refresh()->status)->toBe('filled');
    expect(bccomp((string) $buy->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect(Trade::count())->toBe(2);

    $secondTrade = Trade::orderBy('created_at')->get()->last();
    expect(bccomp((string) $secondTrade->amount, '0.3', 18))->toBe(0);
    expect(JournalEntry::where('source_domain', 'exchange')->count())->toBe(4);
});

it('resumes a trade interrupted between claim and finalise without double-posting', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade->metadata['settlement_status'] ?? null)->toBe('settled');

    // Simulate a crash after the legs posted but before finalise ran:
    // the journal entries exist, the trade looks pending again.
    $metadata = $trade->metadata ?? [];
    $metadata['settlement_status'] = 'pending';
    unset($metadata['ledger_entry_numbers']);
    $trade->update(['metadata' => $metadata]);

    $resumed = app(TradeSettlementService::class)->settle(
        buyOrderId: $trade->buy_order_id,
        sellOrderId: $trade->sell_order_id,
        tradeId: $trade->trade_id,
        executedAmount: (string) $trade->amount,
        executedPrice: (string) $trade->price,
        makerFee: (string) $trade->maker_fee,
        takerFee: (string) $trade->taker_fee,
    );

    expect($resumed->trade_id)->toBe($trade->trade_id);
    expect(Trade::count())->toBe(1);
    // The two posted legs were replayed, not posted again.
    expect(JournalEntry::where('source_event_id', $trade->trade_id)->count())->toBe(2);
    expect($resumed->refresh()->metadata['settlement_status'])->toBe('settled');
    expect($resumed->refresh()->metadata['ledger_entry_numbers'])->toHaveCount(2);
    expect(TradingHold::active()->count())->toBe(0);
});

it('settles even when the ledger driver sync fails, and records the failed sync in the outbox', function (): void {
    // A driver whose sync always fails (e.g. TigerBeetle down). Ledger
    // records the failure in its driver-sync outbox and does NOT fail
    // the posting: the DB journal is authoritative.
    app()->bind(LedgerDriverInterface::class, fn () => new class () implements LedgerDriverInterface {
        public function post(JournalEntry $entry): void
        {
            throw new RuntimeException('TigerBeetle down (test fake)');
        }

        /** @return array{amount: string, currency: string} */
        public function balance(string $accountCode, ?Carbon $asOf = null): array
        {
            return ['amount' => '0', 'currency' => 'USD'];
        }

        /** @return array<string, array{debit: string, credit: string, balance: string}> */
        public function trialBalance(?Carbon $asOf = null): array
        {
            return [];
        }

        /** @return Collection<int, array<string, mixed>> */
        public function accountHistory(string $accountCode, Carbon $from, Carbon $to): Collection
        {
            return collect();
        }
    });

    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect($buy->refresh()->status)->toBe('filled');

    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade)->not->toBeNull()
        ->and($trade->metadata['settlement_status'] ?? null)->toBe('settled');
    expect(JournalEntry::where('source_event_id', $trade->trade_id)->count())->toBe(2);

    // Both legs are durably recorded as pending sync, for retry by the
    // ledger's driver-sync reconciler — the trade is settled, the sync
    // is not lost.
    expect(DB::table('ledger_driver_syncs')->where('status', 'pending')->count())->toBe(2);
});

it('consumes a buy hold exactly once across partial fills', function (): void {
    $sellerA = makeAccount(['BTC' => '1']);
    $sellerB = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);
    $holds = app(TradingHoldService::class);

    orderBookService()->placeOrder((string) $sellerA->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '1', '40000');

    expect($buy->refresh()->status)->toBe('partially_filled');

    // Hold was 40080 (1 * 40000 * 1.002); first fill consumed 20040.
    $buyHold = TradingHold::where('source_type', 'order_book')
        ->where('source_id', $buy->order_id)
        ->where('asset_code', 'USDT')
        ->first();
    expect($buyHold)->not->toBeNull()
        ->and($buyHold->status)->toBe('active')
        ->and(bccomp((string) $buyHold->amount, '20040', 18))->toBe(0);
    expect($holds->availableBalance((string) $buyer->id, 'USDT'))->toBe('29960.000000000000000000');

    // Second fill consumes the rest; the hold is released, not consumed twice.
    orderBookService()->placeOrder((string) $sellerB->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect($buy->refresh()->status)->toBe('filled');
    expect(TradingHold::active()->count())->toBe(0);
    expect(Trade::count())->toBe(2);
    expect(JournalEntry::where('source_domain', 'exchange')->count())->toBe(4);
});

it('rejects order amounts and prices beyond the asset precision', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    // BTC has precision 8: 9dp is refused.
    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.000000001', '40000'))
        ->toThrow(InvalidArgumentException::class, 'decimal places');

    // USDT has precision 2: a 3dp price is refused.
    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000.123'))
        ->toThrow(InvalidArgumentException::class, 'decimal places');

    expect(OrderProjection::count())->toBe(0);

    // Exactly at precision is accepted.
    $ok = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.00010001', '40000.12');
    expect($ok->status)->toBe('pending');
});

it('keeps the best ask numerically ordered, not lexicographically', function (): void {
    $seller = makeAccount(['BTC' => '2']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '10000');
    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '9000');

    // String sorting would put '10000' before '9000' ('1' < '9').
    $book = OrderBookProjection::forPair('BTC', 'USDT')->first();
    expect($book)->not->toBeNull();
    expect(bccomp((string) $book->best_ask, '9000', 18))->toBe(0);

    $serviceBook = orderBookService()->getOrderBook('BTC', 'USDT');
    expect(bccomp((string) $serviceBook['asks'][0]['price'], '9000', 18))->toBe(0);
});

it('matches each maker once per run: a large taker against a small maker produces one match', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = placeRestingOrder((string) $buyer->id, 'buy', '1', '40000');

    $result = (new MatchOrderActivity())->execute($buy->order_id, 100);

    expect($result->matches)->toHaveCount(1);
    expect(bccomp((string) $result->matches[0]->executedAmount, '0.5', 18))->toBe(0);
});

it('settles a workflow match under the match trade id, and a replayed matchOrder is a no-op', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $sell = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = placeRestingOrder((string) $buyer->id, 'buy', '0.5', '40000');

    $tradeId = (string) Str::uuid();

    // What the workflow does: settle via the activity with the match's id.
    $result = (new TransferAssetsActivity())->execute(
        $buy->order_id,
        $sell->order_id,
        '0.5',
        '40000',
        $tradeId,
        '20',
        '40',
        'sell',
    );

    expect($result->success)->toBeTrue();
    expect($result->tradeId)->toBe($tradeId);
    expect(Trade::count())->toBe(1);
    expect(bccomp((string) $buy->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect(bccomp((string) $sell->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect(JournalEntry::where('source_event_id', $tradeId)->count())->toBe(2);

    // What the workflow does next: replay matchOrder() with the same
    // trade id. Before the fix this recorded a SECOND match (the
    // activity had minted its own id) — double filled amounts, two
    // Trade rows. Now it must be a no-op.
    OrderAggregate::retrieve($buy->order_id)->matchOrder(
        matchedOrderId: $sell->order_id,
        tradeId: $tradeId,
        executedPrice: '40000',
        executedAmount: '0.5',
        makerFee: '20',
        takerFee: '40',
        metadata: ['maker_side' => 'sell'],
    )->persist();

    OrderAggregate::retrieve($sell->order_id)->matchOrder(
        matchedOrderId: $buy->order_id,
        tradeId: $tradeId,
        executedPrice: '40000',
        executedAmount: '0.5',
        makerFee: '20',
        takerFee: '40',
        metadata: ['maker_side' => 'sell'],
    )->persist();

    expect(Trade::count())->toBe(1);
    expect(bccomp((string) $buy->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect(bccomp((string) $sell->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect(JournalEntry::where('source_event_id', $tradeId)->count())->toBe(2);

    // Settling the same match again through the activity is a no-op too.
    $again = (new TransferAssetsActivity())->execute(
        $buy->order_id,
        $sell->order_id,
        '0.5',
        '40000',
        $tradeId,
        '20',
        '40',
        'sell',
    );

    expect($again->success)->toBeTrue();
    expect(Trade::count())->toBe(1);
    expect(JournalEntry::where('source_event_id', $tradeId)->count())->toBe(2);
});
