<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'v1/*', 'v2/*', 'auth/*', 'broadcasting/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_merge(
        // Production origins (env-driven for multi-domain deployment)
        array_filter(explode(',', (string) env('CORS_PRODUCTION_ORIGINS', 'https://finaegis.org,https://www.finaegis.org,https://api.finaegis.org,https://app.finaegis.org,https://dashboard.finaegis.org'))),
        // Frontend URL (configurable per environment)
        array_filter([env('FRONTEND_URL')]),
        // Local development origins. Phase 8: these used to default to
        // the localhost list in EVERY environment (including production),
        // which, combined with supports_credentials=true, would let a page
        // on a local dev-server origin make credentialed API calls
        // against production. They now default to the localhost list only
        // in local/testing/demo; production/staging default to empty
        // unless an operator opts in explicitly with CORS_LOCAL_ORIGINS.
        explode(',', (string) env(
            'CORS_LOCAL_ORIGINS',
            in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true)
                ? 'http://localhost:3000,http://localhost:8080,http://localhost:5173,http://localhost:8081'
                : ''
        ))
    )),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With', 'X-CSRF-TOKEN', 'Accept', 'Origin', 'X-Client-Platform', 'X-Client-Version', 'X-Payment-Version', 'X-Tenant-ID'],

    'exposed_headers' => [],

    'max_age' => 86400,

    'supports_credentials' => true,

];
