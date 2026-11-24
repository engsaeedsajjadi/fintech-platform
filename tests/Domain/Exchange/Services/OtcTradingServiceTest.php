<?php

use App\Domain\Exchange\Exceptions\InsufficientTradingBalanceException;
use App\Domain\Exchange\Exceptions\OtcQuoteException;
use App\Domain\Exchange\Exceptions\TradingDisabledException;
use App\Domain\Exchange\Models\OtcDeal;
use App\Domain\Exchange\Models\OtcQuote;
use App\Domain\Exchange\Models\TradingHold;
use App\Domain\Exchange\Projections\Order as OrderProjection;
use App\Domain\Exchange\Projections\Trade;
use App\Domain\Ledger\Models\JournalEntry;

use function TradingRoomTest\enablePair;
use function TradingRoomTest\enableTrading;
use function TradingRoomTest\ensureAssets;
use function TradingRoomTest\makeAccount;
use function TradingRoomTest\orderBookService;
use function TradingRoomTest\otcService;

require_once __DIR__ . '/../trading-room-helpers.php';

beforeEach(function (): void {
    enableTrading();
    ensureAssets();
    enablePair(marketOrders: true);
});

it('settles a buy quote: quote -> accept -> ledger settlement, with no order-book rows', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');

    // Sandbox ask for BTC/USDT is 45000 * 1.0005 = 45022.5.
    expect($quote->status)->toBe('quoted')
        ->and(bccomp($quote->price, '45022.5', 18))->toBe(0)
        ->and(bccomp($quote->amount, '0.5', 18))->toBe(0)
        // Fee is 0.2% of 22511.25 = 45.0225; total is value + fee.
        ->and(bccomp($quote->fee, '45.0225', 18))->toBe(0)
        ->and(bccomp($quote->total, '22556.2725', 18))->toBe(0);

    $deal = otcService()->acceptQuote($quote->quote_id);

    expect($deal->status)->toBe('settled')
        ->and($deal->quote_id)->toBe($quote->quote_id)
        ->and($deal->ledger_entry_numbers)->toHaveCount(2);
    expect($quote->refresh()->status)->toBe('settled');

    // Exactly two balanced journal entries, traceable to the quote.
    $entries = JournalEntry::where('source_domain', 'exchange')
        ->where('source_event_id', $quote->quote_id)->with('lines')->get();
    expect($entries)->toHaveCount(2);
    foreach ($entries as $entry) {
        $debits = $entry->lines->sum(fn ($line) => (float) $line->debit_amount);
        $credits = $entry->lines->sum(fn ($line) => (float) $line->credit_amount);
        expect($debits)->toEqual($credits);
    }

    // OTC never touches the order book: no orders, no trades, no active holds.
    expect(OrderProjection::count())->toBe(0);
    expect(Trade::count())->toBe(0);
    expect(TradingHold::active()->count())->toBe(0);
});

it('settles a sell quote at the bid price with the fee deducted from proceeds', function (): void {
    $seller = makeAccount(['BTC' => '1']);

    $quote = otcService()->requestQuote((string) $seller->id, 'sell', 'BTC', 'USDT', '0.5');

    // Sandbox bid for BTC/USDT is 45000 * 0.9995 = 44977.5.
    expect(bccomp($quote->price, '44977.5', 18))->toBe(0);

    $deal = otcService()->acceptQuote($quote->quote_id);
    expect($deal->status)->toBe('settled');
    expect(OtcDeal::count())->toBe(1);
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(2);
});

it('refuses to settle an expired quote', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');
    $quote->update(['expires_at' => now()->subSecond()]);

    expect(fn () => otcService()->acceptQuote($quote->quote_id))
        ->toThrow(OtcQuoteException::class, 'expired');

    expect($quote->refresh()->status)->toBe('expired');
    expect(OtcDeal::count())->toBe(0);
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(0);
});

it('expires stale quotes with the sweeper', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');
    $quote->update(['expires_at' => now()->subSecond()]);

    expect(otcService()->expireQuotes())->toBe(1);
    expect($quote->refresh()->status)->toBe('expired');
});

it('accepting the same quote twice returns the same deal without double settlement', function (): void {
    $buyer = makeAccount(['USDT' => '50000']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');

    $first = otcService()->acceptQuote($quote->quote_id);
    $second = otcService()->acceptQuote($quote->quote_id);

    expect($second->deal_id)->toBe($first->deal_id);
    expect(OtcDeal::count())->toBe(1);
    expect(JournalEntry::where('source_event_id', $quote->quote_id)->count())->toBe(2);
});

it('refuses to accept a quote the requester cannot fund', function (): void {
    $buyer = makeAccount(['USDT' => '100']);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5');

    expect(fn () => otcService()->acceptQuote($quote->quote_id))
        ->toThrow(InsufficientTradingBalanceException::class);

    expect($quote->refresh()->status)->toBe('quoted');
    expect(OtcDeal::count())->toBe(0);
});

it('refuses OTC while trading is disabled', function (): void {
    config(['trading.enabled' => false]);
    $buyer = makeAccount(['USDT' => '50000']);

    expect(fn () => otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5'))
        ->toThrow(TradingDisabledException::class);
});

it('refuses OTC while the OTC switch is off', function (): void {
    config(['trading.otc.enabled' => false]);
    $buyer = makeAccount(['USDT' => '50000']);

    expect(fn () => otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.5'))
        ->toThrow(TradingDisabledException::class);
});

it('keeps order-book and OTC code paths distinct', function (): void {
    // Behavioural separation: an order-book fill creates no OTC rows,
    // and an OTC deal creates no order-book rows.
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '100000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    expect(OtcQuote::count())->toBe(0);
    expect(OtcDeal::count())->toBe(0);

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.1');
    otcService()->acceptQuote($quote->quote_id);

    expect(Trade::count())->toBe(1); // only the order-book trade
    expect(OrderProjection::count())->toBe(2); // only the two book orders

    // Static separation: the OTC service never references the matcher,
    // the matching workflow, or the order aggregate.
    $otcSource = file_get_contents(app_path('Domain/Exchange/Services/OtcTradingService.php'));
    expect($otcSource)->not->toContain('MatchOrderActivity')
        ->and($otcSource)->not->toContain('OrderMatchingWorkflow')
        ->and($otcSource)->not->toContain('matchOrder')
        ->and($otcSource)->not->toContain('OrderAggregate');
});
