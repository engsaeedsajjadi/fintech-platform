<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | KYC Purpose Routing
    |--------------------------------------------------------------------------
    |
    | Maps a KycPurpose value to the provider name that should handle it.
    | KycProviderRouter::resolve(KycPurpose) reads from this config.
    |
    | Per docs/BACKEND_HANDOVER_BRIDGE_RAMP.md §7.5, purposes are partitioned
    | — Ondato data and Bridge data must not be conflated. A user who
    | completes TRUSTCERT KYC under Ondato does NOT automatically pass RAMP
    | KYC under Bridge, and vice versa.
    */

    'routing' => [
        'trustcert' => env('KYC_TRUSTCERT_PROVIDER', 'ondato'),
        'ramp'      => env('KYC_RAMP_PROVIDER', 'bridge'),
        'cards'     => env('KYC_CARDS_PROVIDER', 'bridge'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Ondato config remains in config/services.php (existing layout). Bridge
    | gets its own block here. New providers register their config under
    | this `providers` key.
    */

    'providers' => [
        'ondato' => [
            // Existing Ondato credentials are read from config('services.ondato.*')
            // by OndatoService. OndatoKycProvider is a thin adapter; it does not
            // duplicate the config surface.
        ],

        'bridge' => [
            'api_key' => env('BRIDGE_API_KEY'),
            // Legacy shared-secret HMAC scheme (kept for tests / older tenants).
            'webhook_secret' => env('BRIDGE_WEBHOOK_SECRET'),
            // Current "Bridge by Stripe" platform: per-endpoint RSA public key
            // (PEM, or base64-encoded PEM) used to verify X-Webhook-Signature.
            // This is NOT a secret — it is the public half Bridge exposes in
            // the dashboard webhook detail view.
            'webhook_public_key' => env('BRIDGE_WEBHOOK_PUBLIC_KEY', ''),
            'base_url'           => env('BRIDGE_API_BASE_URL', 'https://api.bridge.xyz'),
            // Default per-customer developer fee (Free tier). Pro upgrade
            // PATCHes the customer to 0. See ADR-0006.
            'default_developer_fee_bps' => (int) env('BRIDGE_DEFAULT_DEV_FEE_BPS', 75),
        ],

        'iranian' => [
            // Iranian KYC (national identity, mobile ownership, bank
            // ownership, documents). There is NO real provider
            // contracted: no civil-registry, Shahkar, or bank API
            // access exists in-repo. 'sandbox' is the only value that
            // resolves today (SandboxIranianKycProvider: format-only
            // national-code checksum + requires_manual_review for every
            // real check). A future real adapter registers here under
            // its own name with its own credentials — never by
            // pretending the sandbox is a registry lookup.
            'provider' => env('KYC_IRANIAN_PROVIDER', 'sandbox'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Identity Verification (liveness / document / identity)
    |--------------------------------------------------------------------------
    |
    | The in-house BiometricVerificationService, DocumentAnalysisService and
    | IdentityVerificationService (Jumio / Onfido / SmileID branches) are
    | SIMULATED. They must never return a passing verification verdict in a
    | production path. KycVerificationGate enforces this:
    |
    |  - `mode = production` (or APP_ENV=production) disables every simulated
    |    provider outright, regardless of the flag below. Fail closed.
    |  - `simulated_providers_enabled` only takes effect in the sandbox
    |    environments listed below. Even there, simulated results are labelled
    |    `sandbox` / `simulated` and carry status `requires_manual_review` —
    |    a human reviewer is the only path from a sandbox result to approval.
    |  - A real liveness / document verdict requires a licensed provider
    |    (Ondato / Bridge today) that implements
    |    IdentityVerificationProviderInterface. Until one is contracted and
    |    its credentials are configured, verification stays pending / in
    |    manual review. No real vendor contract exists in-repo yet.
    |
    | `liveness_provider` / `bank_account_provider`: 'sandbox' (default),
    | 'ondato', 'bridge', or 'none'. 'none' means no provider is configured:
    | the step stays pending and is routed to manual review, never auto-pass.
    */

    'verification' => [
        'mode'                        => env('KYC_VERIFICATION_MODE', 'sandbox'),
        'simulated_providers_enabled' => env(
            'KYC_SIMULATED_PROVIDERS_ENABLED',
            in_array(env('APP_ENV', 'production'), ['local', 'testing', 'demo'], true)
        ),
        'sandbox_environments'  => ['local', 'testing', 'demo'],
        'liveness_provider'     => env('KYC_LIVENESS_PROVIDER', 'sandbox'),
        'bank_account_provider' => env('KYC_BANK_ACCOUNT_PROVIDER', 'sandbox'),

        // KYC documents are stored on this disk with private visibility and
        // are only retrievable through SecureDocumentStorage (signed, time-
        // limited access tokens). NOTE: no 'private'/'secure' disk is defined
        // in config/filesystems.php today — SecureDocumentStorage falls back
        // to the 'local' disk (storage/app, not web-accessible) when the
        // configured disk is absent. Define a real private disk before
        // storing production documents.
        'document_disk'        => env('KYC_DOCUMENT_DISK', 'private'),
        'document_path_prefix' => 'kyc',
    ],

    /*
    |--------------------------------------------------------------------------
    | User Restrictions by Verification Status
    |--------------------------------------------------------------------------
    |
    | KycRestrictionService enforces this matrix. An unverified user cannot
    | move money. A pending user may deposit (funds are held, not usable)
    | but cannot withdraw, transfer, open a card, or trade. Only an approved
    | user may use money-moving actions, within the tier limits from
    | KycService::getRequirements(). A sanctions match or high-risk rating
    | restricts the user regardless of KYC status.
    */

    'restrictions' => [
        'not_submitted' => [],
        'rejected'      => [],
        'expired'       => [],
        'pending'       => ['deposit'],
        'approved'      => ['deposit', 'transfer', 'withdraw', 'card_issue', 'trade'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Suspicious Activity Reports
    |--------------------------------------------------------------------------
    |
    | There is NO live regulator filing integration in-repo. SAR filing is a
    | manual, human-driven step: SarWorkflowService::fileExternally() records
    | a filing only when a human supplies the real external reference
    | obtained from the regulator's own filing portal. The legacy simulated
    | submission in SuspiciousActivityReportService is disabled unless
    | `real_filing_enabled` is explicitly true AND a filing integration is
    | configured — it never invents a BSA reference.
    */

    'sar' => [
        'real_filing_enabled' => env('KYC_SAR_REAL_FILING_ENABLED', false),
    ],
];
