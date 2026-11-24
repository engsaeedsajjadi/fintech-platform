<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Solana Send Configuration
    |--------------------------------------------------------------------------
    */

    'solana' => [
        // Public RPC endpoint used to broadcast signed transactions.
        // Helius mainnet RPC supports api-key as query param.
        'rpc_url' => (string) env('HELIUS_RPC_URL', 'https://mainnet.helius-rpc.com'),
        // Confirmation level required before treating a tx as confirmed.
        // Options: processed | confirmed | finalized
        'commitment' => (string) env('SOLANA_COMMITMENT', 'confirmed'),
        // Microlamports per compute unit for priority fee. Bump under congestion.
        'priority_fee_microlamports' => (int) env('SOLANA_PRIORITY_FEE_MICROLAMPORTS', 1000),
        // Compute unit limit for a single SPL transfer (with optional ATA create).
        'compute_unit_limit' => (int) env('SOLANA_COMPUTE_UNIT_LIMIT', 200000),

        /*
        |----------------------------------------------------------------------
        | Fee-Payer (Sponsor) Account
        |----------------------------------------------------------------------
        |
        | A non-custodial Solana wallet holds SPL stablecoins but typically no
        | SOL, so it cannot pay its own transaction fee — the send fails with
        | "Attempt to debit an account but found no record of a prior credit".
        | When `secret_key` is set, the platform's sponsor account becomes the
        | transaction fee payer (account index 0) and co-signs every send; the
        | user's device still signs as the transfer authority. Leave it empty
        | to keep the legacy behaviour (sender pays its own fee).
        |
        | `secret_key` is the base58-encoded 64-byte ed25519 secret key
        | (32-byte seed || 32-byte public key — the standard Solana layout).
        | Generate it OFF-machine and store it only in the secret manager.
        |
        */
        'sponsor' => [
            'secret_key' => (string) env('WALLET_SOLANA_SPONSOR_SECRET_KEY', ''),
            // Alert threshold for the scheduled balance check, in lamports.
            // 1 SOL = 1_000_000_000 lamports; default 0.1 SOL.
            'low_balance_lamports' => (int) env('WALLET_SOLANA_SPONSOR_LOW_BALANCE_LAMPORTS', 100_000_000),
        ],

        /*
        |----------------------------------------------------------------------
        | Inbound Dust Filter
        |----------------------------------------------------------------------
        |
        | Solana addresses are public, so anyone can send a wallet tiny
        | unsolicited SOL transfers ("dusting" / address-poisoning spam).
        | Inbound native-SOL transfers below this threshold are still
        | recorded as a BlockchainTransaction for audit, but are kept out of
        | the activity feed and suppress the push notification — so spam does
        | not buzz the user. Token transfers (USDC/USDT) are never filtered.
        |
        */
        'dust' => [
            'min_inbound_sol' => (string) env('WALLET_SOLANA_DUST_MIN_INBOUND_SOL', '0.001'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | EVM Send Configuration
    |--------------------------------------------------------------------------
    */

    'evm' => [
        // Networks enabled for outbound dispatch. Mobile validates against
        // PaymentNetwork enum, so values must match (lowercase casing).
        //
        // Ethereum L1 is intentionally excluded by default: a single L1 send
        // can cost $1-$20+ in gas (vs fractions of a cent on the L2s), and we
        // sponsor that gas. The L2s — polygon/base/arbitrum — cover real usage
        // cheaply. Re-add 'ethereum' only with a deliberate cost decision.
        'enabled_networks' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WALLET_SEND_EVM_NETWORKS', 'polygon,base,arbitrum')),
        )),
        // Token used to pay sponsorship fee. Must be supported by the paymaster.
        'fee_token' => (string) env('WALLET_SEND_EVM_FEE_TOKEN', 'USDC'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sponsored-Send Abuse Guardrail
    |--------------------------------------------------------------------------
    |
    | Gas-sponsored sends (EVM via the Pimlico paymaster, Solana via the fee
    | payer) cost the platform real money per transaction. These caps bound
    | that exposure: a per-user daily limit stops a single scripted account,
    | and a global daily ceiling is a kill-switch against a mass-abuse spike.
    | Counts reset at 00:00 UTC. Defaults are generous enough to be invisible
    | to real users.
    |
    */

    'sponsorship' => [
        'per_user_daily_limit' => (int) env('WALLET_SEND_PER_USER_DAILY_LIMIT', 30),
        'global_daily_limit'   => (int) env('WALLET_SEND_GLOBAL_DAILY_LIMIT', 5000),

        // Optional value-denominated cap on top of the count caps: total
        // estimated USD the platform will spend on sponsored gas per UTC day.
        // Null/empty disables the check. The spend counter accumulates at
        // confirmation time (when the real fee is known), so enforcement
        // trails in-flight sends slightly — a burst can overshoot the budget,
        // bounded by the count caps above. See SponsorshipCostTracker.
        'daily_budget_usd' => env('WALLET_SPONSORSHIP_DAILY_BUDGET_USD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wallet Address Registration (POST /api/v1/wallet/addresses)
    |--------------------------------------------------------------------------
    |
    | Every registered address is watched (Helius/Alchemy) and attributes
    | inbound transfers to its owner, so registration is bounded. Re-posting
    | addresses a user already owns is never limited.
    |
    | ownership_verification: enforce | monitor | off. `enforce` requires each
    | new address to be a wallet of the caller's Privy account (Privy server
    | API, needs PRIVY_APP_SECRET) and fails closed when Privy cannot be
    | asked. `monitor` runs the same check but only logs mismatches — use it
    | to confirm real users pass, then switch to `enforce`. The same check
    | covers a caller-supplied relayer smart-account owner address the user
    | does not hold yet (not the server-derived onboarding owner).
    |
    | max_addresses_per_chain: distinct Privy addresses one user may hold per
    | chain (the EVM address counts once for all EVM chains). A normal user
    | has exactly one; the headroom covers wallet migrations.
    |
    | max_smart_account_owners: distinct owner addresses one user may create
    | relayer smart accounts for (POST /api/v1/relayer/account and
    | /api/v1/wallet/create-account). A normal user has one owner mirrored
    | across the EVM networks.
    |
    | max_new_claims_per_hour: registrations that add an address the user
    | does not hold yet (refused ones count too, so probing is bounded).
    |
    | inbound_side_effects_per_address_per_hour: above this many matched
    | Solana webhook transactions for one address in an hour, the per-tx
    | balance RPC pre-warm and push are skipped (the transfer is still
    | recorded). Bounds the cost of a watched address with heavy traffic.
    |
    */

    'address_registration' => [
        'ownership_verification'                    => (string) env('WALLET_ADDRESS_OWNERSHIP_VERIFICATION', 'monitor'),
        'max_addresses_per_chain'                   => (int) env('WALLET_ADDRESS_MAX_PER_CHAIN', 3),
        'max_smart_account_owners'                  => (int) env('WALLET_SMART_ACCOUNT_MAX_OWNERS', 3),
        'max_new_claims_per_hour'                   => (int) env('WALLET_ADDRESS_NEW_CLAIMS_PER_HOUR', 10),
        'inbound_side_effects_per_address_per_hour' => (int) env('WALLET_INBOUND_SIDE_EFFECTS_PER_ADDRESS_PER_HOUR', 120),
    ],

    /*--------------------------------------------------------------------------
    | Fiat Wallet (FiatWalletService)
    |--------------------------------------------------------------------------
    |
    | Fiat balances are NOT stored on a free-floating field: the balance of a
    | fiat wallet is the balance of its ledger account
    | `WALLET:FIAT:{account_uuid}:{CURRENCY}` (a liability account), read via
    | LedgerService::getBalance(). Every deposit / withdrawal / transfer /
    | fee is a balanced LedgerService::post() entry. Amounts here are decimal
    | strings in MAJOR units (e.g. '100.50' USD); the ledger stores them at
    | scale 4. `currencies` is the allow-list for fiat wallets.
    |
    | `auto_approve_withdrawal_up_to`: withdrawals at or below this amount
    | (major units, per currency) are auto-approved; anything above needs a
    | second person (four-eyes: the approver must differ from the requester).
    | Set to '0' to require approval for every withdrawal.
    |
    */

    'fiat' => [
        'currencies' => array_filter(array_map(
            'trim',
            explode(',', (string) env('WALLET_FIAT_CURRENCIES', 'USD,EUR,GBP')),
        )),
        // Fee: basis points charged on deposits/withdrawals/conversions
        // (100 bps = 1%). Fixed fee is in major units of the same currency.
        'fee_bps'                       => (int) env('WALLET_FIAT_FEE_BPS', 0),
        'fee_fixed'                     => (string) env('WALLET_FIAT_FEE_FIXED', '0'),
        'fee_min'                       => (string) env('WALLET_FIAT_FEE_MIN', '0'),
        'fee_max'                       => (string) env('WALLET_FIAT_FEE_MAX', '0'),
        'auto_approve_withdrawal_up_to' => (string) env('WALLET_AUTO_APPROVE_WITHDRAWAL_UP_TO', '0'),
    ],

    /*--------------------------------------------------------------------------
    | Digital-Asset Wallet (DigitalAssetWalletService)
    |--------------------------------------------------------------------------
    |
    | Config-driven allow-lists only. `networks` maps a network key to its
    | required on-chain confirmations and the assets tracked on it; a network
    | or asset not listed here is rejected. `custody` selects the custody
    | model: 'non-custodial' (device/Privy-held keys, the production Zelta
    | model) or 'custodial' (server-side keys — fails closed unless a real
    | HSM/KMS is configured, see CustodialKeyGate and config/keymanagement.php).
    | No private key is ever stored by this wallet: custodial keys live only
    | as HSM key references.
    |
    */

    'digital' => [
        'networks' => [
            // network => [required_confirmations, assets]
            'polygon'  => ['confirmations' => 12, 'assets' => ['USDC', 'USDT', 'MATIC']],
            'base'     => ['confirmations' => 12, 'assets' => ['USDC', 'USDT', 'ETH']],
            'arbitrum' => ['confirmations' => 12, 'assets' => ['USDC', 'USDT', 'ETH']],
            'solana'   => ['confirmations' => 32, 'assets' => ['USDC', 'USDT', 'SOL']],
            'ethereum' => ['confirmations' => 12, 'assets' => ['USDC', 'USDT', 'ETH']],
            'tron'     => ['confirmations' => 19, 'assets' => ['USDT', 'TRX']],
        ],
        'custody' => [
            'default' => (string) env('WALLET_CUSTODY_DEFAULT', 'non-custodial'),
            // Name of the registered custodian connector (CustodianRegistry)
            // used for custodial settlement. Empty = no licensed custodian
            // contracted yet; custodial settlement then fails closed.
            'provider' => (string) env('WALLET_CUSTODY_PROVIDER', ''),
        ],
        'auto_approve_withdrawal_up_to_minor' => (int) env('WALLET_DIGITAL_AUTO_APPROVE_UP_TO_MINOR', 0),
    ],

    /*--------------------------------------------------------------------------
    | Conversion Engine (ConversionService)
    |--------------------------------------------------------------------------
    |
    | Pricing is separate from execution: a quote is generated from
    | IExchangeRateProvider (never invented), carries an expiry, and execution
    | only ever settles the exact rate/fee locked in that quote. `ttl_seconds`
    | is the quote lifetime; `max_slippage_bps` is enforced against a fresh
    | rate re-fetch at execution time for provider-executed conversions.
    | Fiat<->crypto conversion is NOT supported in v1 (no licensed off-ramp /
    | settlement provider): same-class conversions only (fiat<->fiat via the
    | ledger, crypto<->crypto via AssetTransferAggregate).
    |
    */

    'conversion' => [
        'ttl_seconds'      => (int) env('WALLET_CONVERSION_QUOTE_TTL_SECONDS', 60),
        'fee_bps'          => (int) env('WALLET_CONVERSION_FEE_BPS', 50),
        'fee_minor_fixed'  => 0,
        'max_slippage_bps' => (int) env('WALLET_CONVERSION_MAX_SLIPPAGE_BPS', 100),
    ],

    /*--------------------------------------------------------------------------
    | Limits (WalletLimitsService)
    |--------------------------------------------------------------------------
    |
    | Configurable, enforced in the services (not only in the UI). Amounts are
    | decimal strings in major units for fiat and in minor units for digital
    | assets. Daily/monthly windows are calendar windows in the app timezone.
    |
    */

    'limits' => [
        'fiat' => [
            'deposit_per_transaction'    => (string) env('WALLET_LIMIT_FIAT_DEPOSIT_TXN', '100000'),
            'deposit_per_day'            => (string) env('WALLET_LIMIT_FIAT_DEPOSIT_DAY', '250000'),
            'withdrawal_per_transaction' => (string) env('WALLET_LIMIT_FIAT_WITHDRAWAL_TXN', '50000'),
            'withdrawal_per_day'         => (string) env('WALLET_LIMIT_FIAT_WITHDRAWAL_DAY', '100000'),
            'transfer_per_transaction'   => (string) env('WALLET_LIMIT_FIAT_TRANSFER_TXN', '50000'),
            'transfer_per_day'           => (string) env('WALLET_LIMIT_FIAT_TRANSFER_DAY', '100000'),
            'conversion_per_transaction' => (string) env('WALLET_LIMIT_FIAT_CONVERSION_TXN', '50000'),
            'conversion_per_day'         => (string) env('WALLET_LIMIT_FIAT_CONVERSION_DAY', '100000'),
        ],
        'digital' => [
            // Minor units, per asset-agnostic cap. WARNING: one minor-unit
            // number cannot fit both 6-decimal stablecoins and 18-decimal
            // ETH (0.01 ETH is already 10^16 wei), so these default to 0
            // (disabled) until an operator sets asset-appropriate caps.
            // A per-asset limit table is a follow-up; do not enable a
            // single small minor-unit cap in production.
            'deposit_per_transaction_minor'    => (int) env('WALLET_LIMIT_DIGITAL_DEPOSIT_TXN_MINOR', 0),
            'withdrawal_per_transaction_minor' => (int) env('WALLET_LIMIT_DIGITAL_WITHDRAWAL_TXN_MINOR', 0),
            'withdrawal_per_day_minor'         => (int) env('WALLET_LIMIT_DIGITAL_WITHDRAWAL_DAY_MINOR', 0),
            'conversion_per_transaction_minor' => (int) env('WALLET_LIMIT_DIGITAL_CONVERSION_TXN_MINOR', 0),
        ],
    ],
];
