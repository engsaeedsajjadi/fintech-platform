<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Bank Account Opening — Provider Configuration
    |--------------------------------------------------------------------------
    |
    | External bank accounts are opened ONLY through an AccountProvider
    | adapter (see App\Domain\Banking\Contracts\AccountProviderInterface).
    | No account number or IBAN is ever generated locally: both come from
    | the provider's response, and the opening workflow fails closed when no
    | provider is configured.
    |
    | Default provider is "sandbox": a clearly-labelled local simulation for
    | development and tests. It REFUSES to run in production. A production
    | provider requires a named partner bank, that bank's official
    | account-opening API documentation, credentials, and an explicit
    | BANK_ACCOUNT_PROVIDER value — none of which exist yet.
    |
    */

    'default_provider' => env('BANK_ACCOUNT_PROVIDER', 'sandbox'),

    'providers' => [
        'sandbox' => [
            'driver'     => 'sandbox',
            'bank_code'  => 'SANDBOX',
            'bank_name'  => 'Sandbox Bank (simulated — not a real bank)',
            // The sandbox adapter is only resolvable outside production.
            'enabled'    => (bool) env('BANK_SANDBOX_ENABLED', true),
            'webhook_secret' => env('BANK_SANDBOX_WEBHOOK_SECRET', ''),
        ],

        // Open Banking (PSD2) is read-only aggregation + payment initiation.
        // It has no account-opening endpoint: its createAccount() always
        // fails with an explicit unsupported error (capability
        // account_opening=false). Listed here so the failure mode is
        // explicit in config, not hidden in code.
        'openbanking' => [
            'driver'                  => 'openbanking',
            'supports_account_opening' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding workflow
    |--------------------------------------------------------------------------
    */

    // Account types the workflow accepts. The provider may reject any of
    // them; acceptance here is only a shape check.
    'account_types' => ['current', 'savings', 'business'],

    'currencies' => ['EUR', 'USD', 'GBP'],

    // KYC gate: the workflow calls Compliance (KycService::isKycApproved)
    // and never duplicates KYC logic. A user whose KYC is not approved
    // cannot start an application.
    'kyc_gate' => true,
];
