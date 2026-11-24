<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Sandbox exchange rates (Asset/ExchangeRateService)
    |--------------------------------------------------------------------------
    |
    | The hardcoded rate tables inside ExchangeRateService are a sandbox
    | fixture: fixed, stale, invented prices (BTC 42000, USD/EUR 0.85,
    | ...). They must never reach a production money path. They are
    | available only when this flag is on — on by default in local /
    | testing / demo (the repo's usual sandbox convention), off in
    | production and staging unless an operator opts in explicitly with
    | ASSET_SANDBOX_RATES_ENABLED=true. While on, every rate fetched
    | from them is stored with source `sandbox_mock` and labelled as a
    | sandbox rate, never as a live API rate. While off, the fetchers
    | return null and callers fail closed (no rate available).
    |
    */
    'sandbox_rates_enabled' => (bool) env(
        'ASSET_SANDBOX_RATES_ENABLED',
        in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true),
    ),
];
