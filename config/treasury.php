<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Sandbox market prices (Treasury/AssetValuationService)
    |--------------------------------------------------------------------------
    |
    | The price tables inside AssetValuationService are a sandbox fixture:
    | hardcoded prices with random ±2% jitter, presented as market data.
    | They must never value a real portfolio. They are available only
    | when this flag is on — on by default in local / testing / demo
    | (the repo's usual sandbox convention), off in production and
    | staging unless an operator opts in explicitly with
    | TREASURY_SANDBOX_PRICES_ENABLED=true. While on, every price is
    | labelled source `sandbox_mock`. While off, valuation throws
    | instead of returning invented prices: a treasury valuation needs a
    | configured market-data provider (not yet wired — see the Phase 3
    | report).
    |
    */
    'sandbox_prices_enabled' => (bool) env(
        'TREASURY_SANDBOX_PRICES_ENABLED',
        in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true),
    ),
];
