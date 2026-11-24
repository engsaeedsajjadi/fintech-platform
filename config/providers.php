<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | External provider defaults
    |--------------------------------------------------------------------------
    |
    | Shared defaults for every external provider (bank connectors, payment
    | gateways, card issuers, rails). A provider that needs different
    | values overrides them in its own config section. Nothing here
    | enables a live provider: each provider still needs its own
    | `enabled` flag, endpoint and credentials.
    |
    */

    // Outbound HTTP calls: a hard ceiling so a hung provider can never
    // hold a request (or a DB transaction) open indefinitely.
    'timeout_seconds'         => (int) env('PROVIDER_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => (int) env('PROVIDER_CONNECT_TIMEOUT_SECONDS', 5),

    /*
    | Retries apply ONLY to idempotent read/status operations (balance,
    | status, health). A money-moving call is never retried blindly: an
    | unclear outcome goes to `unknown` / manual review instead.
    */
    'retries' => [
        'max_attempts' => (int) env('PROVIDER_RETRY_MAX_ATTEMPTS', 2),
        'backoff_ms'   => (int) env('PROVIDER_RETRY_BACKOFF_MS', 500),
    ],

    'circuit_breaker' => [
        // Consecutive failures before the breaker opens.
        'failure_threshold' => (int) env('PROVIDER_BREAKER_FAILURE_THRESHOLD', 5),
        // Seconds the breaker stays open before a probe is allowed.
        'open_seconds' => (int) env('PROVIDER_BREAKER_OPEN_SECONDS', 60),
    ],

    'webhook' => [
        // Incoming webhook timestamps older than this are rejected
        // (replay protection), even with a valid signature.
        'tolerance_seconds' => (int) env('PROVIDER_WEBHOOK_TOLERANCE_SECONDS', 300),
    ],

    'rate_limit' => [
        'max_attempts'   => (int) env('PROVIDER_RATE_LIMIT_MAX_ATTEMPTS', 100),
        'window_seconds' => (int) env('PROVIDER_RATE_LIMIT_WINDOW_SECONDS', 60),
    ],

    'idempotency' => [
        // How long a claimed idempotency key (and its stored result) lives.
        'ttl_seconds' => (int) env('PROVIDER_IDEMPOTENCY_TTL_SECONDS', 86400),
    ],
];
