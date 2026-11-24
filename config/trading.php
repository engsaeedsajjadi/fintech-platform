<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Trading Exchange Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the FinAegis Trading Exchange platform
    |
    | `enabled` gates the demo trading engine (exchange order book, liquidity
    | pools, GraphQL placeOrder and the trading scheduler jobs). Secure by
    | default: on only for APP_ENV=local, testing or demo, off for every other
    | value (production, staging, unset). An explicit TRADING_ENABLED value
    | always wins.
    |
    | TRADING IS DISABLED BY DEFAULT IN PRODUCTION. Operating a trading venue
    | / matching client orders is a regulated activity (MiFID in the EU, and
    | equivalent regimes elsewhere). Do NOT set TRADING_ENABLED=true outside
    | local/testing/demo until venue/regulatory approval exists and a live
    | market-data provider is contracted. There is no live provider today:
    | see `market_data` below.
    |
    */
    'enabled' => (bool) env('TRADING_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true)),

    /*
    |--------------------------------------------------------------------------
    | Demo separation
    |--------------------------------------------------------------------------
    |
    | The simulated DemoExchangeService (invented prices, instant self-fills)
    | is allowed ONLY when this flag is true. It is never used by the real
    | order-book path, and the real path never falls back to simulated fills:
    | a market order with no resting counterparty is rejected, never filled
    | against a simulated price.
    */
    'demo' => [
        'enabled' => (bool) env('TRADING_DEMO_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Market data
    |--------------------------------------------------------------------------
    |
    | `provider` selects the MarketDataProviderInterface implementation.
    | Only 'sandbox' exists today: a deterministic provider serving the
    | fixed prices below (labelled sandbox, for tests and demos only).
    | Any other value throws MarketDataUnavailableException — a live feed
    | requires a real, contracted provider; prices are never invented.
    */
    'market_data' => [
        'provider'       => env('TRADING_MARKET_DATA_PROVIDER', 'sandbox'),
        'sandbox_prices' => [
            'BTC/USD'  => '45000',
            'BTC/USDT' => '45000',
            'BTC/EUR'  => '41000',
            'ETH/USD'  => '2500',
            'ETH/USDT' => '2500',
            'EUR/USD'  => '1.10',
            'GBP/USD'  => '1.27',
            'GCU/USD'  => '1.00',
        ],
        // Bid/ask spread applied by the sandbox provider (0.1%).
        'sandbox_spread' => env('TRADING_MARKET_DATA_SANDBOX_SPREAD', '0.001'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-user trading limits
    |--------------------------------------------------------------------------
    |
    | Enforced by TradingLimitService on every order-book placement and OTC
    | quote acceptance. Amounts are quote-currency major units (decimal
    | strings). Zero disables that limit.
    */
    'user_limits' => [
        'daily_max_quote_value' => env('TRADING_USER_DAILY_MAX', '100000'),
        'max_open_orders'       => (int) env('TRADING_USER_MAX_OPEN_ORDERS', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTC / direct trade
    |--------------------------------------------------------------------------
    |
    | Quote -> accept -> settle. Quotes are firm for `quote_ttl_seconds`
    | and then expire; an expired quote can never settle.
    */
    'otc' => [
        'enabled'           => (bool) env('TRADING_OTC_ENABLED', true),
        'quote_ttl_seconds' => (int) env('TRADING_OTC_QUOTE_TTL', 60),
        'min_quote_value'   => env('TRADING_OTC_MIN_VALUE', '10'),
        'max_quote_value'   => env('TRADING_OTC_MAX_VALUE', '100000'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Order Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'min_order' => [
            'BTC' => env('TRADING_MIN_ORDER_BTC', '0.0001'),
            'ETH' => env('TRADING_MIN_ORDER_ETH', '0.001'),
            'EUR' => env('TRADING_MIN_ORDER_FIAT', '10'),
            'USD' => env('TRADING_MIN_ORDER_FIAT', '10'),
            'GBP' => env('TRADING_MIN_ORDER_FIAT', '10'),
            'GCU' => env('TRADING_MIN_ORDER_GCU', '10'),
        ],
        'max_order' => [
            'BTC' => env('TRADING_MAX_ORDER_BTC', '100'),
            'ETH' => env('TRADING_MAX_ORDER_ETH', '1000'),
            'EUR' => env('TRADING_MAX_ORDER_FIAT', '1000000'),
            'USD' => env('TRADING_MAX_ORDER_FIAT', '1000000'),
            'GBP' => env('TRADING_MAX_ORDER_FIAT', '1000000'),
            'GCU' => env('TRADING_MAX_ORDER_GCU', '1000000'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fee Structure
    |--------------------------------------------------------------------------
    */
    'fees' => [
        'maker'            => env('TRADING_MAKER_FEE', '0.001'), // 0.1%
        'taker'            => env('TRADING_TAKER_FEE', '0.002'), // 0.2%
        'volume_discounts' => [
            '1000000'   => '0.0009', // $1M volume: 0.09% maker
            '10000000'  => '0.0008', // $10M volume: 0.08% maker
            '100000000' => '0.0007', // $100M volume: 0.07% maker
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | External Exchange Connectors
    |--------------------------------------------------------------------------
    */
    'external_connectors' => explode(',', env('TRADING_EXTERNAL_CONNECTORS', 'binance,kraken')),

    /*
    |--------------------------------------------------------------------------
    | Market Making Configuration
    |--------------------------------------------------------------------------
    */
    'market_making' => [
        'enabled'           => env('TRADING_MARKET_MAKING_ENABLED', false),
        'system_account_id' => env('TRADING_SYSTEM_ACCOUNT_ID'),
        'spread_percentage' => env('TRADING_MM_SPREAD', '0.002'), // 0.2%
        'order_size'        => env('TRADING_MM_ORDER_SIZE', '0.1'),
        'max_exposure'      => env('TRADING_MM_MAX_EXPOSURE', '10'), // Max 10 BTC equivalent
    ],

    /*
    |--------------------------------------------------------------------------
    | Arbitrage Configuration
    |--------------------------------------------------------------------------
    */
    'arbitrage' => [
        'enabled'               => env('TRADING_ARBITRAGE_ENABLED', false),
        'min_spread_percentage' => env('TRADING_ARB_MIN_SPREAD', '0.005'), // 0.5%
        'check_interval'        => env('TRADING_ARB_CHECK_INTERVAL', 60), // seconds
        'max_order_size'        => env('TRADING_ARB_MAX_ORDER', '1'), // BTC equivalent
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */
    'rate_limits' => [
        'orders_per_minute'    => env('TRADING_RATE_LIMIT_ORDERS', 60),
        'api_calls_per_minute' => env('TRADING_RATE_LIMIT_API', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Supported Trading Pairs
    |--------------------------------------------------------------------------
    */
    'pairs' => [
        // Crypto pairs
        'BTC/EUR', 'BTC/USD', 'BTC/GBP', 'BTC/GCU',
        'BTC/USDT',
        'ETH/EUR', 'ETH/USD', 'ETH/GBP', 'ETH/GCU',
        'ETH/USDT',
        'ETH/BTC',

        // Fiat pairs with GCU
        'EUR/GCU', 'USD/GCU', 'GBP/GCU',

        // Traditional forex
        'EUR/USD', 'EUR/GBP', 'GBP/USD',
    ],
];
