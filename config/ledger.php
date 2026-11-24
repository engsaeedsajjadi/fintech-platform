<?php

declare(strict_types=1);

return [
    'driver'                  => env('LEDGER_DRIVER', 'eloquent'),
    'auto_posting'            => (bool) env('LEDGER_AUTO_POSTING', true),
    'reconciliation_schedule' => env('LEDGER_RECONCILIATION_SCHEDULE', 'daily'),
    'tigerbeetle'             => [
        'addresses'  => env('TIGERBEETLE_ADDRESSES', '127.0.0.1:3001'),
        'cluster_id' => (int) env('TIGERBEETLE_CLUSTER_ID', 0),
        'timeout_ms' => (int) env('TIGERBEETLE_TIMEOUT', 5000),
    ],
    'default_currency' => env('LEDGER_DEFAULT_CURRENCY', 'USD'),

    /*
    |--------------------------------------------------------------------------
    | Posting scale
    |--------------------------------------------------------------------------
    |
    | Decimal places used by LedgerService for every balance check and
    | invariant (bcadd/bccomp). Journal lines are stored decimal(20,4), so
    | the scale is 4. Crypto amounts needing more precision must be
    | represented in minor units, not by raising this scale.
    |
    */
    'posting_scale' => (int) env('LEDGER_POSTING_SCALE', 4),

    /*
    |--------------------------------------------------------------------------
    | Registered-account enforcement (Phase 4.2-B)
    |--------------------------------------------------------------------------
    |
    | When true, LedgerService refuses lines whose account code has no row
    | in the chart of accounts. Default false: trading virtual accounts
    | (`trading:account:{id}:{ccy}`, `trading:fees:{ccy}`) are not chart
    | rows — Exchange posts to them directly — so turning this on requires
    | registering those accounts first. An account that DOES exist in the
    | chart must always be active, regardless of this flag.
    |
    */
    'require_registered_accounts' => (bool) env('LEDGER_REQUIRE_REGISTERED_ACCOUNTS', false),

    /*
    |--------------------------------------------------------------------------
    | Idempotency claim TTL (Phase 4.2-C)
    |--------------------------------------------------------------------------
    |
    | Seconds after which a claim with no journal entry attached is treated
    | as stale (its process crashed before the entry commit) and may be
    | reclaimed by the next caller instead of blocking the key forever.
    |
    */
    'idempotency_claim_ttl_seconds' => (int) env('LEDGER_IDEMPOTENCY_CLAIM_TTL', 120),

    /*
    |--------------------------------------------------------------------------
    | Currency / asset precision model (Phase 4.2-F)
    |--------------------------------------------------------------------------
    |
    | The ledger posts at `posting_scale` (4). Fiat needs at most 3 decimals
    | (ISO-4217 minor units), so fiat amounts only lose sub-0.0001 dust.
    | Digital assets need more (BTC/ETH 8, SOL 9, USDT/USDC 6): amounts
    | that need more decimals than the posting scale are REFUSED, never
    | truncated. `crypto` maps asset code => native scale; unknown codes are
    | refused above the posting scale as well. See
    | App\Domain\Ledger\ValueObjects\LedgerPrecision.
    |
    */
    'precision' => [
        'crypto' => [
            'BTC'  => 8,
            'ETH'  => 8,
            'SOL'  => 9,
            'USDT' => 6,
            'USDC' => 6,
            'DAI'  => 6,
            'GCU'  => 4,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Direct ledger funding (demo feature)
    |--------------------------------------------------------------------------
    |
    | Enables the demo-only endpoints that credit or debit internal account
    | balances directly, without an external payment rail: v1/v2 account
    | deposit/withdraw, account opening balances, the BIAN API, the mock
    | custodian transfer, transaction reversal, the simulated web wallet
    | deposits (simulate, Open Banking, bank/manual/crypto pages), the web
    | wallet withdrawals and the ledger-moving GraphQL mutations.
    | Payment-backed web deposits do not depend on it: card deposits use
    | web_card_deposits_enabled below and Paysera uses PAYSERA_ENABLED. Their
    | simulated demo gateways still need this flag.
    | Secure by default: on only for APP_ENV=local, testing or demo; every
    | other value (production, staging, unset) turns it off. An explicit
    | LEDGER_DIRECT_FUNDING_ENABLED value always wins, so a production-mode
    | demo can opt back in with LEDGER_DIRECT_FUNDING_ENABLED=true.
    |
    */
    'direct_funding_enabled' => (bool) env('LEDGER_DIRECT_FUNDING_ENABLED', in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true)),

    // ops:verify-env FAILs a production deploy while direct funding is on,
    // unless the operator confirms it with LEDGER_DIRECT_FUNDING_ACKNOWLEDGED=true
    // (a production-mode demo). The deposit activities also re-check
    // direct_funding_enabled, so queued deposits stop once it is off.
    'direct_funding_acknowledged' => (bool) env('LEDGER_DIRECT_FUNDING_ACKNOWLEDGED', false),

    /*
    |--------------------------------------------------------------------------
    | Web wallet card deposits (Stripe / HyperSwitch)
    |--------------------------------------------------------------------------
    |
    | GET/POST /wallet/deposit/card, GET /wallet/deposit/confirm and the saved
    | payment-method routes. A card payment backs these deposits, so they
    | have their own switch instead of LEDGER_DIRECT_FUNDING_ENABLED.
    | Unset, it is on for APP_ENV=local, testing or demo, and elsewhere only
    | with HYPERSWITCH_ENABLED=true (the card rail opt-in). Plain Stripe card
    | deposits need WEB_CARD_DEPOSITS_ENABLED=true, because the Stripe keys
    | also serve subscriptions and KYC payments. The simulated demo gateway
    | (APP_ENV=demo) also needs direct_funding_enabled.
    |
    */
    'web_card_deposits_enabled' => (bool) env(
        'WEB_CARD_DEPOSITS_ENABLED',
        in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true) || (bool) env('HYPERSWITCH_ENABLED', false),
    ),
];
