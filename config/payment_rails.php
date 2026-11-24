<?php

declare(strict_types=1);

return [
    'ach' => [
        'enabled'          => env('ACH_ENABLED', false),
        'originator_id'    => env('ACH_ORIGINATOR_ID'),
        'originating_dfi'  => env('ACH_ORIGINATING_DFI'),
        'company_name'     => env('ACH_COMPANY_NAME', 'FinAegis'),
        'company_id'       => env('ACH_COMPANY_ID'),
        'same_day_enabled' => (bool) env('ACH_SAME_DAY', true),
        'cutoff_time'      => env('ACH_CUTOFF_TIME', '16:30'),
        'cutoff_timezone'  => env('ACH_CUTOFF_TIMEZONE', 'America/New_York'),
    ],
    'fedwire' => [
        'enabled'    => env('FEDWIRE_ENABLED', false),
        'sender_aba' => env('FEDWIRE_SENDER_ABA'),
        'endpoint'   => env('FEDWIRE_ENDPOINT'),
        'timeout'    => (int) env('FEDWIRE_TIMEOUT', 30),
    ],
    'rtp' => [
        'enabled'        => env('RTP_ENABLED', false),
        'participant_id' => env('RTP_PARTICIPANT_ID'),
        'endpoint'       => env('RTP_ENDPOINT'),
        'max_amount'     => (int) env('RTP_MAX_AMOUNT', 100000000),
    ],
    'fednow' => [
        'enabled'        => env('FEDNOW_ENABLED', false),
        'participant_id' => env('FEDNOW_PARTICIPANT_ID'),
        'endpoint'       => env('FEDNOW_ENDPOINT'),
        'max_amount'     => (int) env('FEDNOW_MAX_AMOUNT', 50000000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Iranian payment rails — RESERVED, DISABLED, SANDBOX ONLY
    |--------------------------------------------------------------------------
    |
    | SATNA / PAYA / PEL (پل) / card-to-card do not exist as live rails in
    | this codebase: there is no CBI or bank API documentation, no endpoint,
    | no credential, and no message format anywhere in the repository, and
    | none is invented here. The entries below are reserved configuration
    | so the rails can be addressed by name, and the only implementation is
    | SandboxIranianRailAdapter, which simulates the local state machine
    | and nothing else.
    |
    | Production execution stays DISABLED until ALL of these exist:
    |   1. official CBI / partner-bank API documentation,
    |   2. a licensed Iranian bank / PSP partnership and sandbox credentials,
    |   3. a compliance / sanctions review for the intended users.
    | Until then, enabling any of these rails (or running the sandbox
    | adapter outside local/testing/demo) fails closed.
    |
    */
    'satna' => [
        'enabled' => env('SATNA_ENABLED', false),
    ],
    'paya' => [
        'enabled' => env('PAYA_ENABLED', false),
    ],
    'pel' => [
        'enabled' => env('PEL_ENABLED', false),
    ],
    'card_to_card' => [
        'enabled' => env('CARD_TO_CARD_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Simulation gate
    |--------------------------------------------------------------------------
    |
    | Simulated money paths (sandbox rail adapter, demo gateways) may run
    | only when this flag is on AND the app environment is local, testing
    | or demo. DemoModeGuard enforces both conditions and fails closed in
    | production — there is no flag that re-enables simulation there.
    |
    */
    'simulation' => [
        'enabled' => env('PAYMENT_SIMULATION_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Internal transfer limits + fees (PaymentTransferService)
    |--------------------------------------------------------------------------
    |
    | Amounts are integer minor units of the transfer currency.
    | fee_basis_points: per-10,000 fee rate, computed with exact decimal
    | arithmetic (TransferFeeCalculator). fee_fixed_minor: flat fee in
    | minor units, added on top. Set either to 0 to disable that part.
    |
    */
    'transfers' => [
        'max_amount_minor'  => (int) env('TRANSFER_MAX_AMOUNT_MINOR', 100000000),
        'daily_limit_minor' => (int) env('TRANSFER_DAILY_LIMIT_MINOR', 500000000),
        'fee_basis_points'  => (int) env('TRANSFER_FEE_BASIS_POINTS', 0),
        'fee_fixed_minor'   => (int) env('TRANSFER_FEE_FIXED_MINOR', 0),
        // Ledger account that holds reserved funds between the reservation
        // and the provider outcome. Operators must create this account in
        // the chart of accounts as an ASSET account; the service fails
        // closed when it is missing.
        'hold_account_code' => env('TRANSFER_HOLD_ACCOUNT_CODE', 'HOLD'),
    ],
];
