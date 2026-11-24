# Repository Audit — FinAegis Core Banking Platform

> **Scope / method.** Audit of `~/workspace/fintech-platform/repo`, branch
> `feat/fintech-audit-implementation`, commit `b8e521f` (v7.18.2), carried out
> 2026-11-24. **Read-only, grep/read only — no application code was executed,
> no test suite was run, no push was made.** Every number below was measured
> with `find` / `wc -l` / `grep -c` on that checkout; every behavioural claim
> cites a file path. Where a claim could not be verified from code alone it
> is labelled **not verified**. Docs were treated as claims, not evidence.
>
> **One-line verdict:** a large, real, event-sourced Laravel DDD codebase
> (61 domain modules, ~144k LOC in `app/`) that is explicitly a
> **demonstration / prototype, not a licensed bank and not production-ready
> as a regulated service** — see §1. The strongest parts are the internal
> ledger, account/transfer plumbing, KYC *workflow scaffolding*,
> Open Banking consent modelling, and the card-issuer adapter layer. The
> weakest parts are exactly the parts a real fintech launch needs most:
> identity verification, liveness, document authenticity, bank-account
> creation, and Iranian payment rails are simulated, demo-gated, or absent;
> demo flags default to ON; and several hardcoded demo secrets exist.

## 1. What this repository actually is

| Item | Evidence |
|---|---|
| Name / package | `finaegis/core-banking-prototype-laravel`, `composer.json:2` — description: *"Core Banking Prototype - Educational Demonstration"* |
| Version | v7.18.2, commit `b8e521f` (`chore(release): v7.18.2 — top-up path, GCU prices and clearer texts (#1260)`). README header still says v7.18.1 (`README.md:18`) — stale by one patch. |
| Licence | Apache-2.0 |
| Self-description | `README.md:16`: *"an open-source reference platform … It is not a bank and it is not a licensed financial service."* `README.md:28`: standards modules *"run in demo mode … They do not connect to any live bank, card scheme or settlement system."* `SECURITY.md:5,161`: *"currently in prototype/demonstration phase … Not Production-Ready: This is a demonstration platform."* |
| Zelta vs FinAegis | `README.md:20`: FinAegis is the open-source platform; Zelta is the hosted non-custodial wallet / mobile app built on top of it. Production/hosted surfaces carry the Zelta brand. |
| Regulatory posture | `docs/REGULATORY-CLAIMS.md` records 1,408 wording changes / 319 high-risk items and 9 withdrawn documents, and states Zelta *"is not a bank, electronic money institution, payment institution or crypto-asset service provider, and do[es] not hold, exchange or transmit users' funds"*; GCU *"is a software demonstration … not issued, offered or sold … no monetary value … no MiCA authorisation is held."* |

## 2. Tech stack — exact versions from `composer.json`

| Layer | Version / package (from `composer.json`) |
|---|---|
| PHP | `^8.4` (`"php": "^8.4"`), extensions `ext-gmp, ext-intl, ext-mbstring, ext-pdo`. Note: repo `AGENTS.md` still says "PHP 8.3+" — stale. |
| Framework | `laravel/framework ^12.0` |
| Auth / API | `laravel/sanctum ^4.0`, `laravel/passport ^13.0`, `laravel/jetstream ^5.4`, `laravel/socialite ^5.24`, `laravel/fortify` (config), `kreait/laravel-firebase ^7.0` |
| Event sourcing / DDD | `spatie/laravel-event-sourcing ^7.7`, `spatie/laravel-data ^4.0`, `spatie/laravel-permission ^8.0`, `spatie/laravel-backup ^10.3`, `stancl/tenancy ^3.9`, `durable-workflow/workflow ^1.0` + `waterline ^1.0` |
| Money / crypto math | `brick/math` (in `composer.lock:703`, transitive — **no `brick/money` in `composer.json`**); `kornrunner/keccak ^1.1`, `simplito/elliptic-php ^1.0` |
| Frontend / admin | `filament/filament ^3.0`, `livewire/livewire ^3.6.4`, Vite + Tailwind (`package.json`, `vite.config.js`, `tailwind.config.js`) |
| Queues / realtime | `laravel/horizon ^5.44`, `laravel/pulse ^1.5`, `predis/predis ^2.2`, `pusher/pusher-php-server ^7.2`, `laravel/scout ^11.2` + Meilisearch |
| API surface | `nuwave/lighthouse ^6.64` (GraphQL), `darkaonline/l5-swagger ^11.0` (OpenAPI), `laravel/cashier ^15.4` (Stripe billing) |
| Observability | OpenTelemetry (`api ^1.4`, `sdk ^1.7`, `exporter-otlp ^1.3`), `promphp/prometheus_client_php ^2.14`, `laravel/telescope ^5.2` |
| Testing (dev) | `pestphp/pest 3.8.5` + `pest-plugin-laravel ^3.0` + `pest-plugin-livewire ^3.0`, `phpunit` via Pest, `behat/behat ^3.22` (+ Mink), `laravel/dusk ^8.3`, `mockery/mockery ^1.6`, `phpstan/phpstan ^2.1` + `larastan/larastan ^3.0`, `friendsofphp/php-cs-fixer ^3.80`, `laravel/pint ^1.27` |
| Other notable | `aws/aws-sdk-php ^3.369.35`, `barryvdh/laravel-dompdf ^3.1`, `endroid/qr-code ^6.0`, `resend/resend-php ^1.0`, `symfony/http-client ^7.3` |

No test run was performed, so no pass/fail claim is made here. `composer.lock` is present (843,333 bytes).

## 3. Size — measured counts (not estimates)

| Area | Files | LOC / notes |
|---|---|---|
| `app/` PHP | **3,390 files** | **144,387 LOC** (`find app -name '*.php' -exec wc -l`) |
| `app/` + `database/` + `routes/` + `tests/` + `config/` PHP | 5,308 files | 109,694 LOC by the second (overlapping) `wc` invocation — do **not** add to the `app/` figure; the two invocations used different file sets. The authoritative per-domain table below sums from the per-domain run. |
| Domain modules | **61 modules** (`ls app/Domain` = 62 entries incl. `AGENTS.md`) — matches `README.md:26` ("61 domain modules") | See §4 |
| Eloquent models | 32 files under `app/Models/` (+ `app/Models/Domain/`), **270 `*Model*` PHP files under `app/Domain/`** (`find app -path '*Models*'`) | Domain models live per-domain, not centrally |
| Migrations | **378 files** in `database/migrations/`; **442 `Schema::create` calls** (439 `create('…')` matches) — some migrations create several tables, most alter/add columns | §5 |
| Config | **96 files** in `config/` | `.env.example` has **463 keys**; `.env.zelta.example` 424; `.env.production.example` 359 |
| Routes | `routes/*.php` 2,462 LOC; domain `Routes/api.php` files add 4,318 LOC; **903 `Route::` lines in domain routes, 1,477 including `routes/*.php`** | §6 |
| Tests | **1,359 files** under `tests/` (1,340 PHP); `features/` has **27** Behat `.feature` files separately | §7 |
| Docs | **293 files** under `docs/` (232 Markdown) | §8 |
| GraphQL | **46 files** under `graphql/` | e.g. `account.graphql`, `banking.graphql`, `card-issuance.graphql` |
| Frontend assets | `resources/`, `public/`, `frontend-assets/` (near-empty), Vite/Tailwind | Not audited in depth |

### 4. Modules — file + LOC counts (measured)

Per-domain `find … -name '*.php' | wc -l` and `wc -l` sums. Only the
largest / most relevant are tabulated; the full list was measured and is
summarised after the table.

| Domain | Files | LOC | What it is (verified by listing, not by docs) |
|---|---|---|---|
| AgentProtocol | 208 | 29,996 | AP2/A2A agent payments, DID, escrow |
| Compliance | 147 | 23,596 | KYC/AML, sanctions, SAR, GDPR, cases/alerts |
| Wallet | 125 | 19,865 | Blockchain wallets, Privy, multisig, hardware, sends |
| Exchange | 136 | 15,561 | Order book, matching workflow, rates, liquidity pools, Binance/Kraken connectors |
| Account | 111 | 7,659 | Multi-asset accounts, deposits/withdrawals/transfers, balances |
| AI | 107 | 19,812 | Agents, MCP tools, LLM orchestration |
| CardIssuance | 80 | 10,837 | Marqeta/FinCard/Rain/Lepo adapters, JIT funding, 3DS, top-ups |
| Treasury | 66 | 10,417 | Portfolio / cash allocation |
| Stablecoin | 100 | 10,641 | Mint/burn/liquidation, collateral |
| Payment | 62 | 5,108 | Gateways, HyperSwitch, Paysera, deposits, transfers |
| Banking | 38 | 6,420 | SEPA/SWIFT modelling, bank connectors, routing |
| Custodian | 44 | 8,174 | Deutsche Bank / Santander / Paysera / Flutterwave / Mock / Demo connectors |
| Fraud | 26 | 7,600 | Rules, behavioural/statistical checks, cases |
| Ledger | 17 | 1,298 | Double-entry journal (small but central) |
| KeyManagement | 23 | 2,715 | HSM (AWS KMS / Azure / Demo), Shamir, encryption |
| OpenBanking | 14 | 1,528 | Consent, AISP/PISP, Berlin Group / UK OB adapters (formatters) |
| PaymentRails | 15 | 2,216 | ACH/NACHA, FedNow, Fedwire, SEPA router |
| Ramp | 12 | 1,964 | Bridge / Onramper / Stripe Crypto Onramp / Mock providers |
| AccountProvisioning | 14 | 860 | **Reviewer/demo account provisioning only** — see §9 |
| Microfinance | 21 | 1,874 | Group lending; the only "meeting" code in the repo (see §9) |
| ISO20022 / ISO8583 | 16 / 12 | 1,356 / 656 | Message-layer standards |

All other domains (Asset 36/3,531; Auth 6/959; Basket 24/3,757; Batch 25/1,322;
Cgo 36/4,226; Commerce 27/3,092; Contact 2/191; CrossChain 33/3,766;
DeFi 28/2,438; FinancialInstitution 25/5,718; Governance 31/3,349;
Interledger 7/572; Lending 50/4,966; MCP 31/3,069; MachinePay 36/2,740;
Mobile 42/6,951; MobilePayment 29/2,383; Monitoring 33/4,072; Newsletter
10/1,419; Performance 11/1,354; Pricing 16/2,356; Privacy 48/6,882;
Product 15/1,645; Referral 2/248; RegTech 16/3,131; Regulatory 13/4,026;
Relayer 32/4,489; Rewards 14/955; SMS 12/1,648; Security 27/2,589;
Subscription 49/7,352; TrustCert 27/3,442; User 26/2,211; VirtualsAgent
15/1,371; VisaCli 26/1,724; Webhook 9/979; X402 38/3,585; Activity 2/181;
Shared 40/4,874) were counted in the same run. **LOC is a size proxy only —
it says nothing about correctness, liveness, or regulatory standing.**

Architecture pattern (verified): DDD bounded contexts under `app/Domain/`
each with `Aggregates/ Events/ Services/ Sagas/ Workflows/ ValueObjects/
Contracts/` (per `app/Domain/AGENTS.md`); event sourcing via Spatie
(`ShouldBeStored` events); CQRS/sagas; multi-tenancy via `stancl/tenancy`
(team-based isolation); `BigDecimal` mandated for money by
`app/Domain/AGENTS.md` ("Use `BigDecimal` for all monetary calculations").

## 5. Database design

- **378 migration files; ~440 tables created** (`Schema::create` count 442).
  Filename-keyword counts (overlapping, by name only): `account` 16,
  `card` 17, `kyc` 12, `compliance` 13, `wallet` 8, `exchange` 5,
  `ledger` 3, `bank` 7, `custodian` 5, `fraud` 7, `payment` 16,
  `sepa` 1, `swift` 0, `ach` 5, `openbanking` 0, `ramp` 3, `bridge` 3,
  `ondato` 1, `lepo` 0, `fincard` 5, `marqeta` 0.
- **Money columns are decimal, not float:** 417 `decimal(` occurrences in
  migrations (e.g. `decimal('amount', 36, 18)` ×8, `decimal('amount', 20, 8)`
  ×8, `decimal('amount', 20, 2)` ×8); **0 `->float(` / `->double(` columns**.
  That is a genuine strength at the storage layer.
- Key tables (sampled by name): `accounts`, `account_balances`,
  `kyc_documents`, `kyc_verifications`, `compliance_alerts/cases/events/
  snapshots/evidence`, `cards`, `cardholders`, `card_transactions`,
  `bank_connections`, `bank_accounts`, `bank_transfers`,
  `blockchain_wallet_tables` (2025-07-04), `exchange_tables` /
  `exchange_events_tables`, `ach_batches`, `ledger_accounts_table`
  (2026-03-30), `smart_accounts`, `railgun_wallets`, `ramp_sessions`.
- **Caveat:** migration count ≠ live schema. No migration run was performed;
  ordering conflicts, failed migrations, and tenant-connection behaviour
  were not verified. `AccountProvisioningService::apply()` explicitly avoids
  a single DB transaction because sub-seeders write across default +
  tenant connections (`app/Domain/AccountProvisioning/Services/
  AccountProvisioningService.php:28-51`) — atomicity relies on per-seeder
  `firstOrCreate`/`updateOrCreate` idempotency, not transactions.

## 6. API structure — route counts

| File | LOC | `Route::` lines |
|---|---|---|
| `routes/web.php` | 985 | (part of 574 in `routes/` — see below) |
| `routes/api.php` | 472 | — |
| `routes/api-v2.php` | 236 | — |
| `routes/api-bian.php` / `api-modules.php` / `api-subdomain.php` / `tenant.php` / `channels.php` / `console.php` | 70 / 61 / 18 / 29 / 164 / 427 | — |
| **All domain `Routes/api.php`** | 4,318 | **903** |
| **Total (`routes/` + domains)** | — | **1,477** |

Verb counts in `routes/` only: `Route::get` 350, `Route::post` 205,
`Route::put` 20, `Route::delete` 14, `Route::patch` 1, `Route::resource` 1,
`Route::group` 1, `Route::middleware` 31.

Largest domain route files (`Route::` lines): Compliance 76, CardIssuance
67, Banking 66, AgentProtocol 62, Mobile 57, Exchange 53, Wallet 48,
Account 35, Privacy 31, Stablecoin 29, FinancialInstitution 29,
Treasury 26, Security 25, Governance 24, TrustCert 22, Basket 22.
Smallest relevant: Ledger 10, PaymentRails 4, ISO8583 4, MCP 3.
Plus GraphQL (46 files, Lighthouse) and Swagger (`l5-swagger`, generated
via `php artisan l5-swagger:generate`).

Representative endpoints (verified in code):
- Accounts: `POST /accounts`, `GET /accounts/{uuid}`, freeze/unfreeze,
  `POST /accounts/{uuid}/deposit|withdraw`, `POST /transfers`
  (`app/Domain/Account/Routes/api.php:34-53`) — deposit/withdraw/transfer
  carry `idempotency` + rate-limit middleware.
- Compliance/KYC: `GET /compliance/kyc/status|requirements`,
  `POST /compliance/kyc/submit|documents`,
  `POST /compliance/kyc/ondato/start`,
  `GET /compliance/kyc/ondato/status/{verificationId}`
  (`app/Domain/Compliance/Routes/api.php:57-66`); alerts/cases under
  staff-only `RoleMiddleware::using(StaffRoles::COMPLIANCE)`.
- Cards: three parallel surfaces — generic `v1/cards`, FinCard
  `v1/cards/fincard/*` (`/open`, `/sensitive`, `/topup`, `/withdraw`),
  Lepo/FinCard onboarding `v1/cards` (`/onboarding`, `/cardholder`,
  `/kyc/session`, `/account/coins`, `/account/deposit-address`) and a
  waitlist `v1/cards/waitlist/deposit` (`app/Domain/CardIssuance/Routes/
  api.php:26-153`). Mutations use `idempotency.required`.
- Exchange: `GET /exchange/orderbook/{base}/{quote}`,
  `POST /exchange/orders`, `GET /exchange/markets|orders|trades`,
  external-exchange ticker/orderbook/arbitrage, liquidity pools + swaps —
  all behind `feature:trading.enabled`
  (`app/Domain/Exchange/Routes/api.php:37-80`).
- Wallet: `blockchain-wallets`, `hardware-wallet`, `multi-sig`,
  `v1/wallet/*` (tokens, balances, addresses, `transactions/prepare|submit`)
  (`app/Domain/Wallet/Routes/api.php:12-162`).
- OpenBanking: `v1/open-banking/consents`, `/accounts`, `/payments`
  (`app/Domain/OpenBanking/Routes/api.php:14-118`).

## 7. Tests — counts and runners (not results)

- **Total: 1,359 files under `tests/` (1,340 PHP) + 27 Behat feature
  files under `features/`.** No suite was run; coverage % is **unknown**
  and no pass/fail claim is made.
- By top-level directory (files): `tests/Unit/` **534**, `tests/Feature/`
  **506**, `tests/Domain/` **184**, `tests/Integration/` 39,
  `tests/Security/` 13, `tests/Filament/` 12, `tests/Console/` 10,
  `tests/MultiConnection/` 9, `tests/Browser/` 7, `tests/Actions/` 6,
  `tests/Smoke/` 5, `tests/Traits/` 5, `tests/Fixtures/` 3,
  `tests/Behat/` 2, `tests/Models/` 2, `tests/Http/` 1,
  `tests/Infrastructure/` 1, `tests/Providers/` 1, `tests/Values/` 1,
  `tests/View/` 1, `tests/k6/` 5.
- By target domain (all of `tests/` matching the name): Account 119,
  Payment 72, Compliance 64, Wallet 62, CardIssuance 46, Exchange 34,
  Banking 23, Fraud 19, KeyManagement 14, Ledger 13, OpenBanking 8.
  **Thin spots relative to code size:** Ledger 13 tests for the
  double-entry core; OpenBanking 8; CardIssuance 46 tests for 80 source
  files; Exchange 34 tests for 136 source files (of which
  `tests/Domain/Exchange/` is only 4 files — most Exchange tests live in
  Feature/Unit). `tests/Domain/` per-domain: Account 44, Payment 25,
  Compliance 15, Wallet 8, Exchange 4, CardIssuance 4.
- Runners present: **Pest 3.8.5** (primary; `composer.json` script
  `"test": "php -d max_execution_time=300 -d memory_limit=2G
  ./vendor/bin/pest --parallel"`, `Makefile: test/test-unit/test-feature`),
  **PHPUnit** via Pest with configs `phpunit.xml`, `phpunit.ci.xml`,
  `phpunit.ci-optimized.xml`, `phpunit.coverage.xml`,
  `phpunit.security.xml`, `phpunit.unit.xml`; **Behat 3.22** (`behat.yml`,
  `behat.yml.dist`, `features/*.feature` incl. `kyc_compliance.feature`,
  `exchange.feature`, `wallet_conversion.feature`, `wallet_e2e.feature`);
  **Laravel Dusk 8.3** (`tests/Browser/` 7 files); **k6** (`tests/k6/` 5).
- Static analysis: PHPStan 2.1 / Larastan 3.0, config `phpstan.neon`
  (210 lines). **21 `phpstan-baseline*.neon` files totalling 61,680 lines**
  — a large suppressed-findings baseline; level/target in `AGENTS.md`
  says "PHPStan Level 5 (minimum)" while the repo ships level-6/7/8
  baselines (`phpstan-baseline-level6.neon`, `-level7`, `-level8`). The
  baseline size is a tech-debt signal, not a pass/fail result.
- Repo `AGENTS.md` requires ≥50% coverage for new code and Pest before
  committing; whether that is met was **not verified**.

## 8. Docs vs code — where docs over-claim

`docs/` has 293 files / 232 Markdown files, plus `docs/adr/`,
`docs/ondato/`, `docs/BACKEND_HANDOVER_*.md`, `docs/FINCARD_MOBILE_
INTEGRATION.md`, `docs/REGULATORY-CLAIMS.md`, `docs/THREAT_MODEL.md`.
Spot-checks:

- **README's "Compliance — KYC checks in three tiers"** is true as a
  *data model* (`User::$kyc_level` none/basic/enhanced/full;
  `KycService::getRequirements()` maps basic/enhanced/full to document
  lists and limits, `app/Domain/Compliance/Services/KycService.php:
  187-220`), but the *verification* behind those tiers is largely
  simulated (§9) — the README does not say that on the same line.
- **README's "Exchange — demo trading engine with order matching"** is
  true as an event-sourced order book + matching workflow, but
  `DemoExchangeService` simulates instant matching and prices
  (`app/Domain/Exchange/Services/DemoExchangeService.php:28,63,104,176`),
  and README itself admits liquidity add/remove/rebalance *"never worked
  and was removed in v7.18.0"* (`README.md:47`).
- **README's "Card issuance adapters — Marqeta and FinCard … and a demo
  issuer … A Rain adapter class exists but is not wired in"**
  (`README.md:65`) — verified: `RainCardIssuerAdapter` exists with live
  HTTP (`Http::baseUrl`, line 406) but no wiring was found in
  `config/cardissuance.php` routing; `CARD_ISSUER` defaults to `demo`
  (`config/cardissuance.php:26`).
- **"Banking — Models SEPA and SWIFT transfers"** (`README.md:35`) —
  true at message/file layer (SEPA pain.001/pacs.008 builders,
  ACH NACHA generator/parser, FedNow/Fedwire services), **not** a live
  settlement connection; README itself adds *"Bank connections are
  adapters and demo implementations."*
- **`TODO.md`** (last updated 2025-12-17, 23,612 bytes) is mostly a
  completed AP2/A2A checklist; it still lists a *"DID verification
  service (placeholder)"* as done. `TODO`/`FIXME` in `app/` total only
  **15 occurrences** — the real incompleteness is labelled
  "simulated"/"demo"/"mock" instead: **376 "simulat*" / 251 "mock" /
  1,218 "demo" occurrences in `app/` PHP** (case-insensitive grep).
- **`docs/REGULATORY-CLAIMS.md`** is the most honest doc in the repo and
  should be treated as the controlling statement on regulated claims.

## 9. Known bugs / incomplete code — with file:line evidence

1. **Liveness is fake.** `BiometricVerificationService::checkLiveness()`
   hardcodes every check to pass (`face_detected => true`,
   `screen_detected => false`, …) and computes confidence from those
   constants — `app/Domain/Compliance/Services/
   BiometricVerificationService.php:10-60`, comment line 19:
   *"Simulated liveness check"*. `matchFaces()` returns a hardcoded
   `similarity => 92.5` (line 79). Any flow relying on it
   (`EnhancedKycService::verifyBiometrics`, lines 125-160) auto-passes.
2. **Identity verification is fake.** `IdentityVerificationService::
   verifyIdentity()` — *"For demonstration, simulate the verification"*
   (line 47); `checkIdentityDatabase()` *"Simulate positive match for
   testing"* (line 132); credit-bureau check simulated (lines 151-155);
   Jumio/Onfido/SmileID session/result methods return *"Simulated
   response"* (lines 191-365); provider `api_key`s default to `null`
   (lines 9-28). Only Ondato has a real HTTP path (see §10).
3. **Document analysis is fake.** `DocumentAnalysisService::
   extractDocumentData()` / `extractAddressData()` / `verifyAuthenticity()`
   — *"For demonstration, simulate data extraction"* (lines 15, 88);
   security-feature detection simulated (line 221); `compareDocumentWith
   Selfie()` returns a hardcoded array (lines 313-316).
4. **Demo exchange simulates prices and matching.**
   `DemoExchangeService::getSimulatedPrice()`, `simulateOrderMatching()`
   (lines 176-229); `config/demo.php` hardcodes demo rates
   (EUR/USD 1.10, BTC/USD 45,000, ETH/USD 2,500) and a 0.1% spread /
   10× liquidity multiplier.
5. **Liquidity add/remove/rebalance removed.** README:47 — the flow
   *"never worked and was removed in v7.18.0"*. Pool list/swap/positions/
   rewards/IL-protection endpoints still exist in
   `app/Domain/Exchange/Routes/api.php:63-80`.
6. **Rain card adapter unwired; FinCard generic adapter throws.**
   `FinCardCardIssuerAdapter::createCard()` and `::getProvisioningData()`
   throw `UnsupportedCardOperationException`
   (`app/Domain/CardIssuance/Adapters/FinCardCardIssuerAdapter.php:
   44-66`); reads come from the local `Card` mirror. Lepo card top-ups
   are **off by default** — `CARD_TOPUPS_ENABLED=false`,
   `config/cardissuance.php:59-63` (*"off until Lepo confirms the money
   path"*).
7. **Bank-account creation is explicitly unsupported over Open Banking.**
   `OpenBankingConnector::createAccount()` throws
   `BankOperationException('Account creation is not supported via Open
   Banking PSD2 APIs')` (`app/Domain/Banking/Connectors/
   OpenBankingConnector.php:507-512`). `AccountProvisioning` is **not**
   bank-account opening at all — it provisions reviewer/demo *platform*
   accounts (`AccountProvisioningService::apply()`, lines 28-106).
8. **No Iranian rails, no video meetings — zero hits.** `SATNA` 0,
   `PAYA` 0, `Shaparak` 0, `Shetab` 0, `card-to-card` / `card_to_card` 0,
   `Jitsi` 0, `Zoom` 0, `WebRTC` 0 in `app/ routes/ config/
   database/migrations/`. `PEL` 38 hits are false positives (substrings
   in `Lepo`, `propel`-style words, etc.). `IRR` 51 hits are almost
   entirely a currency-code list (`FixerIoProvider.php:24`); `Rial`
   288 hits are mostly `jsonSerialize`/`trial` substrings. The single
   `Iran` hit is a sanctions list: `CgoKycService.php:288`
   (`['IR','KP','SY','CU']`).
9. **Float is used for money in several hot paths** (storage is decimal,
   code is not always): `LedgerService` balances with `bcadd/bccomp`
   at **scale 4** (`app/Domain/Ledger/Services/LedgerService.php:35-50`)
   while crypto amounts need 18dp; `JournalEntry` balance check casts
   to `float` (lines 70-71); `TigerBeetleDriver` casts to `(float)` and
   `round((float)… * 10000)` (lines 39-52); `PaymentService::
   refundPayment(string $transactionId, float $amount, …)` (line 44);
   `BaseBankConnector::formatAmount(float)/parseAmount()` (lines
   196-205); `WalletTransferService` does `(float) $amount + $gasCostUsd`
   (line 107); `EvmTransactionProcessor` does
   `bcadd(sprintf('%.8f', (float) $value), '0', 8)` (line 182);
   Ledger/Trezor signers cast wei/satoshi to `(float)` (lines 461-490).
   `grep float|double` in Ledger+Payment alone: 7 hits; across
   Ledger/Payment/Account/Wallet the pattern is pervasive for rates,
   fees, and display — rates as `float` are acceptable, **amounts are
   not**.
10. **Demo mode defaults are unsafe if copied to production.**
    `config/demo.php:18-24`: `skip_kyc => env('DEMO_SKIP_KYC', true)`,
    `auto_approve => true`, `mock_external_apis => true`,
    `fixed_exchange_rates => true`, `instant_deposits => true`;
    `.env.example:46-55`: `DEMO_SKIP_KYC=true`,
    `DEMO_AUTO_APPROVE_TRANSACTIONS=true`, `DEMO_SUCCESS_RATE=100`,
    `DEMO_KYC_STATUS=verified`. `OpsVerifyEnvCommand.php:321` warns
    these *"default to true — set … to false in production"*.
11. **Custodial key material exists in the DB.**
    `encrypted_private_key` text column
    (`database/migrations/2025_09_23_122412_create_transaction_security_
    tables.php:118`); `BlockchainWithdrawalActivities.php:215-231`
    decrypts it via `KeyManagementService::decrypt()` and holds the
    cleartext key in memory. The non-custodial (Privy) path is the
    production Zelta path; the custodial path is a separate, higher-risk
    surface (see §10).
12. **PHPStan baseline is very large** — 21 baseline files, 61,680 lines
    (§7). Not a bug per se, but suppressed findings at that scale hide
    real defects; any "PHPStan passes" claim must be read with that in
    mind.

## 10. Security concerns

| # | Severity | Issue | Evidence |
|---|---|---|---|
| S1 | **High** | Hardcoded demo HSM key + simulated encryption | `DemoHsmProvider.php:21`: `DEMO_KEY = 'demo-hsm-encryption-key-32bytes!'`; line 15: *"Uses cache storage with encryption simulation"*; `encrypt()` *"Simple encryption for demo purposes"* (line 32). If `HsmProviderFactory` ever resolves `demo` outside local/demo, key material is protected by a public constant. |
| S2 | **High** | Hardcoded demo JWT secret | `app/Domain/Mobile/Services/BiometricJWTService.php:420`: `$secret = 'demo-biometric-jwt-secret-key-32b!'` |
| S3 | **High** | Demo credentials in code/seeds | `demo123` for 5 demo users (`database/seeders/DemoDataSeeder.php:83-154`, `config/demo.php: default_password demo123`); `admin123` in `PopulateDemoDataCommand.php:81`. Documented demo passwords, but they ship in the same tree as production config examples. |
| S4 | **High** | Demo flags default ON (KYC skip, auto-approve, mock APIs, fixed rates) | §9.10. A deployment that copies `.env.example`/`.env.demo` without flipping 5+ flags silently disables KYC and approves transactions. |
| S5 | **High** | Simulated liveness / face-match / document checks can pass a non-person | §9.1-9.3. These services are callable from `EnhancedKycService`; if any production route still resolves them instead of Ondato/Bridge, KYC is theatre. Verify the runtime binding before any launch. |
| S6 | **Medium** | Custodial private keys stored (encrypted) in DB | §9.11. Encryption key management for `KeyManagementService` / `SecureKeyStorageService::storePrivateKey temporarily with TTL` was not fully traced; HSM vs local key, rotation, and access audit need a dedicated review. |
| S7 | **Medium** | PAN/CVV handling — good design, verify the edges | Positive: *"Sensitive PAN/CVV is fetched on demand, never stored"* (`app/Domain/CardIssuance/Routes/api.php:105`); *"Ephemeral PAN/CVV/expiry — returned to the caller, never persisted"* (`FinCardCardService.php:129`). CVV is only read transiently from issuer responses (`MarqetaCardIssuerAdapter.php:282`, `RainCardIssuerAdapter.php:311`). **Not verified:** logging/redaction of those responses, and whether `VirtualCard::$cvv` (`ValueObjects/VirtualCard.php:27`) ever reaches logs or caches. PCI scope needs an external assessor, not this audit. |
| S8 | **Medium** | Float money paths (§9.9) | Rounding / precision loss risk on refunds, conversions, and TigerBeetle mirroring. |
| S9 | **Medium** | Idempotency is present but not universal | 328 `idempotency` hits; middleware `idempotency` / `idempotency.required` on Account deposit/withdraw/transfer and Card mutations. **Not verified** for every money-moving route (e.g. all Exchange/Ramp/Wallet send paths, webhook replays). `DepositReplayGuard` exists in Payment — replay coverage was not traced end-to-end. |
| S10 | **Low–Medium** | Mock/demo connectors are environment-gated, but the gate is config | `MockBankConnector::isPermitted()` requires `local/testing/demo` + `CUSTODIAN_MOCK_ENABLED` (`app/Domain/Custodian/Connectors/MockBankConnector.php:26-32`); `MockRampProvider`, `DemoPaymentGatewayService`, `SandboxPaymentService`, `DemoBlockchainService` exist alongside production services. A mis-set `APP_ENV` could enable them. |
| S11 | **Low** | `.env.*.example` files (463/424/359 keys) are large and copy-paste-prone | Secrets are placeholders there (verified by inspection of the Ondato/Bridge/Marqeta blocks), but the sheer size invites a real secret being pasted into an example file. `.gitleaks.toml` + `.gitleaksignore` exist — whether CI enforces them was not verified. |

No live secret was found by the grep patterns used (hardcoded
`password = '…'`, `api_key = '…'`, `secret = '…'` excluding tests/demo
passwords). That is **not** a clean bill of health — it is the result of
one grep pass, not a secrets scan.

## 11. Deployment

- **Docker:** `docker/Dockerfile` (207 lines), `docker/nginx/`,
  `docker/php/php-fpm.conf` + `php.ini`, `docker/supervisor/supervisord.conf`;
  `docker-compose.yml` (63 lines) — `app` (port 8000) + `mysql:8.0` +
  `redis:7-alpine` + Mailpit; healthchecks on MySQL/Redis; bind-mounts
  the repo into the container. `docker-compose.websocket.yml` exists
  separately. No production-grade compose (no TLS, no secrets manager,
  no backup sidecar in compose itself — backups are via
  `spatie/laravel-backup`).
- **Helm:** `helm/finaegis/` — `Chart.yaml`, 16 templates
  (`deployment-web/horizon/scheduler`, `hpa-*`, `ingress`,
  `service`, `configmap`, `external-secrets.yaml`, Istio
  `DestinationRule/PeerAuthentication/VirtualService`,
  `networkpolicy`, `poddisruptionbudget`, `servicemonitor`),
  `values.yaml` (358 lines) + `values-staging.yaml` (143) +
  `values-production.yaml` (159).
- **Observability:** `infrastructure/observability/` — Grafana dashboards
  (accounts, exchange, compliance-monitoring, wallet, stablecoin,
  treasury, lending, financial-reconciliation, platform-health, …) +
  Prometheus `alerting_rules.yml`; `infrastructure/railgun-bridge/`
  (Node bridge, own Dockerfile).
- **CI:** `.github/` workflows (not enumerated here); `Makefile` targets
  `test / test-unit / test-feature / lint / fix / analyse / serve /
  swagger / clean`. `.env.ci`, `.env.test`, `.env.testing`,
  `.env.performance` exist.
- **What deployment does NOT give you:** a licence, a banking partner,
  a card scheme membership, a KYC vendor contract, or settlement
  connectivity. All of those are external-provider / regulatory work —
  see the companion `FEATURE_GAP_ANALYSIS.md`.

## 12. Reusable code (genuinely useful for the implementation phase)

- **Ledger:** `LedgerService::post()` enforces the balanced-entry
  invariant with `bcadd/bccomp` and throws on unbalanced/zero entries;
  `reverse()` creates mirror entries
  (`app/Domain/Ledger/Services/LedgerService.php:29-90`). Reuse the
  invariant; fix the scale-4 / float edges around it.
- **Idempotency middleware + `DepositReplayGuard`** — reuse as the
  pattern for every new money route.
- **Money value objects / `BigDecimal` discipline** in Exchange
  (`Order` aggregate uses `BigDecimal::of`, `OrderBook` best bid/ask as
  `BigDecimal`) and `FinancialInputValidator` (max 18dp).
- **KYC provider router:** `KycProviderInterface` + `KycProviderRouter`
  + per-purpose routing in `config/kyc.php` (trustcert→ondato,
  ramp→bridge, cards→bridge) — the right seam for adding a new
  (e.g. Iranian) KYC provider without touching callers.
- **Card issuer interface:** `CardIssuerInterface` + Marqeta/FinCard/
  Rain/Demo adapters — the right seam for a new issuer; note Rain is
  unwired and FinCard's generic `createCard` throws.
- **Payment rails file builders:** NACHA generator/parser, SEPA
  pain.001/pacs.008 builders, FedNow/Fedwire services — reusable as
  *file-format* libraries; they are not settlement connections.
- **Open Banking consent/AISP/PISP modelling** (`Consent`,
  `ConsentService`, `AispService`, `PispService`, Berlin Group + UK OB
  formatters) — reusable for any future account-aggregation feature.
- **Custodian connector abstraction** (`BaseCustodianConnector`,
  registry, health monitor, reconciliation) — reusable if a real
  custodian is ever contracted.
- **Ramp provider abstraction** (Bridge/Onramper/Stripe/Mock) and
  **exchange-rate provider registry** (Fixer.io, mock, aggregated) —
  reusable for fiat↔crypto conversion pricing, once a real rate source
  is contracted.

## 13. Audit limitations

- No code was executed; no tests, migrations, seeders, or artisan
  commands were run; no HTTP request was made to any external API.
  "Real" below means "there is non-simulated code that calls a real
  HTTP API or enforces a real invariant", as judged by reading the code.
- Counts are from the stated commands on this checkout; re-running
  `wc`/`grep` with different flags will differ by a few units (e.g.
  the two total-LOC invocations in §3).
- External-provider behaviour (Ondato/Bridge/Marqeta/Lepo/FinCard
  sandbox vs production, webhook delivery, rate limits) was **not**
  verified live. Contract/account status is unknown to this audit.
- Security §10 is a code-reading audit, not a penetration test, not a
  PCI assessment, and not legal advice.
