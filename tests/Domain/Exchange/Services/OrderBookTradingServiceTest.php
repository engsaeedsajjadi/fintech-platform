<?php

use App\Domain\Exchange\Exceptions\InsufficientTradingBalanceException;
use App\Domain\Exchange\Exceptions\MarketDataUnavailableException;
use App\Domain\Exchange\Exceptions\MarketOrdersNotSupportedException;
use App\Domain\Exchange\Exceptions\TradingDisabledException;
use App\Domain\Exchange\Exceptions\TradingLimitExceededException;
use App\Domain\Exchange\Exceptions\TradingPairHaltedException;
use App\Domain\Exchange\Models\TradingHold;
use App\Domain\Exchange\Projections\Trade;
use App\Domain\Exchange\Services\FeeCalculator;
use App\Domain\Exchange\Services\TradeSettlementService;
use App\Domain\Exchange\Services\TradingAdminService;
use App\Domain\Exchange\Services\TradingHoldService;
use App\Domain\Ledger\Models\JournalEntry;
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

it('matches a limit buy against a resting limit sell and settles both legs in the ledger', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $sell = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    expect($sell->status)->toBe('pending');

    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect($buy->refresh()->status)->toBe('filled');
    expect($sell->refresh()->status)->toBe('filled');

    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade)->not->toBeNull()
        ->and(bccomp($trade->price, '40000', 18))->toBe(0)
        ->and(bccomp($trade->amount, '0.5', 18))->toBe(0)
        ->and(bccomp($trade->value, '20000', 18))->toBe(0)
        // Maker (resting sell) pays 0.1% = 20, taker (buy) pays 0.2% = 40.
        ->and(bccomp($trade->maker_fee, '20', 18))->toBe(0)
        ->and(bccomp($trade->taker_fee, '40', 18))->toBe(0);

    // Settlement: exactly two balanced journal entries per trade.
    $entries = JournalEntry::where('source_domain', 'exchange')
        ->where('source_event_id', $trade->trade_id)->with('lines')->get();
    expect($entries)->toHaveCount(2);
    foreach ($entries as $entry) {
        $debits = $entry->lines->sum(fn ($line) => (float) $line->debit_amount);
        $credits = $entry->lines->sum(fn ($line) => (float) $line->credit_amount);
        expect($debits)->toEqual($credits);
    }

    // Holds are fully consumed/released after a full fill.
    expect(TradingHold::active()->count())->toBe(0);
});

it('fills a market buy against the resting limit sell at the maker price', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'market', 'BTC', 'USDT', '0.5');

    expect($buy->refresh()->status)->toBe('filled');
    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade)->not->toBeNull()
        ->and(bccomp($trade->price, '40000', 18))->toBe(0)
        ->and(bccomp($trade->amount, '0.5', 18))->toBe(0);
});

it('partially fills a large buy and completes it with a second sell', function (): void {
    $sellerA = makeAccount(['BTC' => '1']);
    $sellerB = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    $sellA = orderBookService()->placeOrder((string) $sellerA->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '1', '40000');

    expect($buy->refresh()->status)->toBe('partially_filled');
    expect(bccomp((string) $buy->refresh()->filled_amount, '0.5', 18))->toBe(0);
    expect($sellA->refresh()->status)->toBe('filled');
    expect(Trade::count())->toBe(1);

    $sellB = orderBookService()->placeOrder((string) $sellerB->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect($buy->refresh()->status)->toBe('filled');
    expect($sellB->refresh()->status)->toBe('filled');
    expect(Trade::count())->toBe(2);
});

it('releases the held funds when an open order is cancelled', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);
    $holds = app(TradingHoldService::class);

    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');
    expect($buy->status)->toBe('pending');

    // 20000 USDT + 0.2% taker-fee buffer is held; only 30000-ish is free.
    $available = $holds->availableBalance((string) $buyer->id, 'USDT');
    expect(bccomp($available, '30000', 18))->toBe(-1)
        ->and(bccomp($available, '29900', 18))->toBe(1);

    $cancelled = orderBookService()->cancelOrder($buy->order_id, (string) $buyer->id);
    expect($cancelled->status)->toBe('cancelled');

    expect($holds->availableBalance((string) $buyer->id, 'USDT'))->toBe('50000.000000000000000000');
    expect(TradingHold::active()->count())->toBe(0);
});

it('prevents duplicate order execution via idempotency key', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $key = (string) Str::uuid();

    $first = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000', $key);
    $second = orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000', $key);

    expect($second->order_id)->toBe($first->order_id);
    expect(App\Domain\Exchange\Projections\Order::where('idempotency_key', $key)->count())->toBe(1);
    expect(TradingHold::active()->count())->toBe(1);
});

it('prevents duplicate settlement of the same trade', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '50000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $trade = Trade::forPair('BTC', 'USDT')->first();
    expect($trade)->not->toBeNull();

    // Re-settling the exact same trade is a no-op: no second trade row,
    // no extra journal entries, no change to filled amounts.
    $again = app(TradeSettlementService::class)->settle(
        buyOrderId: $trade->buy_order_id,
        sellOrderId: $trade->sell_order_id,
        tradeId: $trade->trade_id,
        executedAmount: (string) $trade->amount,
        executedPrice: (string) $trade->price,
        makerFee: (string) $trade->maker_fee,
        takerFee: (string) $trade->taker_fee,
    );

    expect($again->trade_id)->toBe($trade->trade_id);
    expect(Trade::count())->toBe(1);
    expect(JournalEntry::where('source_event_id', $trade->trade_id)->count())->toBe(2);
});

it('prevents double-spend: a second order exceeding the available balance is rejected', function (): void {
    $buyer = makeAccount(['USDT' => '30000']);

    $first = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');
    expect($first->status)->toBe('pending');

    // Only ~9960 USDT is free after the first hold (20040), so this must fail.
    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000'))
        ->toThrow(InsufficientTradingBalanceException::class);

    expect(App\Domain\Exchange\Projections\Order::count())->toBe(1);

    // Once the first order is cancelled, the same second order succeeds.
    orderBookService()->cancelOrder($first->order_id, (string) $buyer->id);
    $second = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');
    expect($second->status)->toBe('pending');
});

it('rejects a market order with no resting liquidity instead of filling it against a simulated price', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    // The sandbox provider HAS a price (45000) — the order must still not fill.
    $buy = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'market', 'BTC', 'USDT', '0.1');

    expect($buy->refresh()->status)->toBe('cancelled');
    expect(Trade::count())->toBe(0);
    expect(TradingHold::active()->count())->toBe(0);
});

it('refuses to place orders while trading is disabled', function (): void {
    config(['trading.enabled' => false]);
    $buyer = makeAccount(['USDT' => '50000']);

    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.1', '40000'))
        ->toThrow(TradingDisabledException::class);
});

it('refuses to place orders on a halted pair', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);
    app(TradingAdminService::class)->haltPair('BTC', 'USDT');

    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.1', '40000'))
        ->toThrow(TradingPairHaltedException::class);
});

it('refuses market orders where the pair does not enable them', function (): void {
    enablePair(marketOrders: false);
    $buyer = makeAccount(['USDT' => '50000']);

    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'market', 'BTC', 'USDT', '0.1'))
        ->toThrow(MarketOrdersNotSupportedException::class);
});

it('rejects a market order when no reference price exists at all', function (): void {
    config(['trading.market_data.provider' => 'live-not-configured']);
    $buyer = makeAccount(['USDT' => '50000']);

    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'market', 'BTC', 'USDT', '0.1'))
        ->toThrow(MarketDataUnavailableException::class);
});

it('enforces the per-user daily trading limit', function (): void {
    config(['trading.user_limits.daily_max_quote_value' => '25000']);
    $seller = makeAccount(['BTC' => '2']);
    $buyer = makeAccount(['USDT' => '100000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    // 20000 of the 25000 daily budget is used; another 20000 order exceeds it.
    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000'))
        ->toThrow(TradingLimitExceededException::class);
});

it('calculates fees with exact decimal arithmetic', function (): void {
    $calculator = new FeeCalculator();

    expect($calculator->calculateFeeExact('20000', '0.002')->__toString())->toBe('40.000000000000000000');
    expect($calculator->calculateFeeExact('20000', '0.001')->__toString())->toBe('20.000000000000000000');
    // 18dp truncation, never rounds up: 0.123456789 * 0.001 = 0.000123456789 exactly.
    expect($calculator->calculateFeeExact('0.123456789', '0.001')->__toString())->toBe('0.000123456789000000');
    // A repeating decimal truncates down, it never exceeds the trade.
    expect($calculator->calculateFeeExact('100', '0.003333333333333333333')->__toString())->toBe('0.333333333333333333');
});

it('shows the resting sell in the order book', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $book = orderBookService()->getOrderBook('BTC', 'USDT');
    expect($book['pair'])->toBe('BTC/USDT')
        ->and($book['asks'])->toHaveCount(1)
        ->and(bccomp($book['asks'][0]['price'], '40000', 18))->toBe(0)
        ->and($book['bids'])->toBeEmpty();
});
