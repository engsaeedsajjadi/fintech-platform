<?php

declare(strict_types=1);

namespace TradingRoomTest;

use App\Domain\Account\Models\Account;
use App\Domain\Account\Models\AccountBalance;
use App\Domain\Asset\Models\Asset;
use App\Domain\Asset\Models\TradingPair;
use App\Domain\Asset\Services\TradingPairService;
use App\Domain\Exchange\Services\OrderBookTradingService;
use App\Domain\Exchange\Services\OtcTradingService;

/**
 * Shared setup helpers for the trading-room tests. Balances are stored
 * in integer minor units (AccountBalance::balance); amounts here are
 * major-unit decimals converted with the asset precision.
 */
function ensureAssets(): void
{
    Asset::updateOrCreate(['code' => 'BTC'], [
        'name'         => 'Bitcoin', 'type' => 'crypto', 'is_active' => true,
        'is_tradeable' => true, 'precision' => 8,
    ]);
    Asset::updateOrCreate(['code' => 'USDT'], [
        'name'         => 'Tether', 'type' => 'crypto', 'is_active' => true,
        'is_tradeable' => true, 'precision' => 2,
    ]);
}

/** @param array<string, string> $balances e.g. ['BTC' => '1.5', 'USDT' => '50000'] */
function makeAccount(array $balances = []): Account
{
    $account = Account::factory()->create();

    foreach ($balances as $code => $major) {
        $precision = (int) (Asset::where('code', $code)->value('precision') ?? 2);
        $minor = (int) bcmul($major, bcpow('10', (string) $precision), 0);
        AccountBalance::create([
            'account_uuid' => $account->uuid,
            'asset_code'   => $code,
            'balance'      => $minor,
        ]);
    }

    return $account;
}

function enablePair(bool $marketOrders = true): TradingPair
{
    $pair = app(TradingPairService::class)->find('BTC', 'USDT');
    assert($pair !== null);
    $pair->update([
        'is_active'             => true,
        'halted'                => false,
        'market_orders_enabled' => $marketOrders,
        'min_order_size'        => '0.0001',
        'max_order_size'        => '1000000',
    ]);

    return $pair->refresh();
}

function orderBookService(): OrderBookTradingService
{
    return app(OrderBookTradingService::class);
}

function otcService(): OtcTradingService
{
    return app(OtcTradingService::class);
}

function enableTrading(): void
{
    config([
        'trading.enabled'                           => true,
        'trading.demo.enabled'                      => true,
        'trading.otc.enabled'                       => true,
        'trading.market_data.provider'              => 'sandbox',
        'trading.user_limits.daily_max_quote_value' => '1000000',
        'trading.user_limits.max_open_orders'       => 50,
    ]);
}
