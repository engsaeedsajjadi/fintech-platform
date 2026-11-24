<?php

use App\Domain\Asset\Services\TradingPairService;
use App\Domain\Exchange\Exceptions\MarketDataUnavailableException;
use App\Domain\Exchange\Providers\SandboxMarketDataProvider;
use App\Domain\Exchange\Services\DemoExchangeService;
use App\Domain\Exchange\Services\MarketDataService;
use App\Domain\Exchange\Services\TradingAdminService;
use App\Domain\Exchange\Services\TradingReportService;

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

it('serves deterministic sandbox prices and never invents a price', function (): void {
    $provider = new SandboxMarketDataProvider();

    $first = $provider->getTicker('BTC', 'USDT');
    $second = $provider->getTicker('BTC', 'USDT');

    // Deterministic: identical on every call, no random variation.
    expect($first->last->__toString())->toBe('45000')
        ->and($second->last->__toString())->toBe('45000')
        ->and($first->metadata['source'])->toBe('sandbox');

    // Unknown symbol: throws instead of fabricating a price.
    expect(fn () => $provider->getTicker('DOGE', 'USDT'))
        ->toThrow(MarketDataUnavailableException::class);

    // A non-sandbox provider name throws: there is no live provider yet
    // and the real path must never silently fall back to sandbox data.
    config(['trading.market_data.provider' => 'binance-live']);
    expect(fn () => app(MarketDataService::class)->getTicker('BTC', 'USDT'))
        ->toThrow(MarketDataUnavailableException::class);
});

it('keeps the demo service gated and labelled', function (): void {
    config(['trading.demo.enabled' => false]);

    expect(fn () => new DemoExchangeService())
        ->toThrow(RuntimeException::class, 'cannot be used in production');

    config(['trading.demo.enabled' => true]);
    $demo = new DemoExchangeService();
    $book = $demo->getOrderBook('BTC', 'USDT');

    expect($book['demo'])->toBeTrue()
        ->and($book['source'])->toBe('simulated');

    $market = $demo->getMarketData('BTC', 'USDT');
    expect($market['demo'])->toBeTrue()
        ->and($market['source'])->toBe('simulated');
});

it('manages trading pairs: halt blocks trading, resume restores it', function (): void {
    $admin = app(TradingAdminService::class);
    $buyer = makeAccount(['USDT' => '50000']);

    $admin->haltPair('BTC', 'USDT');
    expect(app(TradingPairService::class)->isHalted('BTC', 'USDT'))->toBeTrue();
    expect(fn () => orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.1', '40000'))
        ->toThrow(App\Domain\Exchange\Exceptions\TradingPairHaltedException::class);

    $admin->resumePair('BTC', 'USDT');
    expect(app(TradingPairService::class)->isHalted('BTC', 'USDT'))->toBeFalse();

    $order = orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.1', '40000');
    expect($order->status)->toBe('pending');
});

it('reports order-book and OTC activity separately', function (): void {
    $seller = makeAccount(['BTC' => '1']);
    $buyer = makeAccount(['USDT' => '100000']);

    orderBookService()->placeOrder((string) $seller->id, 'sell', 'limit', 'BTC', 'USDT', '0.5', '40000');
    orderBookService()->placeOrder((string) $buyer->id, 'buy', 'limit', 'BTC', 'USDT', '0.5', '40000');

    $quote = otcService()->requestQuote((string) $buyer->id, 'buy', 'BTC', 'USDT', '0.1');
    otcService()->acceptQuote($quote->quote_id);

    $report = app(TradingReportService::class);
    $pair = $report->pairSummary('BTC', 'USDT');
    expect($pair['trades'])->toBe(1)
        ->and(bccomp($pair['base_volume'], '0.5', 18))->toBe(0)
        ->and(bccomp($pair['quote_volume'], '20000', 18))->toBe(0)
        ->and(bccomp($pair['fees'], '60', 18))->toBe(0);

    $daily = $report->dailyReport(now());
    expect($daily['order_book_trades'])->toBe(1)
        ->and($daily['otc_deals'])->toBe(1)
        ->and(bccomp($daily['otc_quote_volume'], '4502.25', 18))->toBe(0);

    $user = $report->userSummary((string) $buyer->id);
    expect($user['filled_orders'])->toBe(1)
        ->and($user['trades'])->toBe(1);
});
