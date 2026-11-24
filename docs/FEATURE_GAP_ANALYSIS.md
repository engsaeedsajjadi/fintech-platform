# Feature Gap Analysis — 7 Target Modules

> **Scope / method.** Same checkout and method as
> `docs/REPOSITORY_AUDIT.md` (branch `feat/fintech-audit-implementation`,
> commit `b8e521f`, v7.18.2; read-only grep/read, **no code executed, no
> tests run, no live API calls**). A capability is **FULLY IMPLEMENTED**
> only when non-simulated code, a backing migration, and an exposed
> route/endpoint were all found. A capability that exists only as a mock,
> fake, sandbox default, or simulated service is labelled as such —
> **never marked complete from docs alone**. "Iranian rails" means
> SATNA / PAYA / PEL (پایا/ساتنا/پل) / Shaparak / Shetab / card-to-card
> as operated by Iranian banks under Central Bank of Iran rules.
>
> **Classification key:** `FULLY IMPLEMENTED` · `PARTIALLY IMPLEMENTED` ·
> `MISSING` · `BROKEN` · `REQUIRES EXTERNAL PROVIDER` · `BLOCKED BY
> REGULATORY`. A capability can carry two labels (e.g. PARTIALLY +
> REQUIRES EXTERNAL PROVIDER).

## Summary matrix

| # | Module | Real today | Verdict in one line |
|---|---|---|---|
| 1 | KYC / AML | Workflow, tiers, sanctions adapters, Ondato + Bridge HTTP | **PARTIALLY IMPLEMENTED** — real vendor paths exist, but in-house liveness/document/identity checks are simulated; no Iranian KYC |
| 2 | Cards | Adapters, lifecycle, JIT, 3DS, top-ups (code) | **PARTIALLY IMPLEMENTED** — Marqeta/Rain HTTP real, FinCard DB-mirror + throwing generic methods, Lepo top-ups OFF, default issuer is demo |
| 3 | Bank account opening | Internal ledger accounts only | **MISSING** — no external bank account can be opened; Open Banking explicitly throws |
| 4 | Deposits / transfers (SATNA/PAYA/PEL/card-to-card) | Internal transfers, SEPA/ACH/Fedwire file layer, Paysera/HyperSwitch deposits | **MISSING for Iranian rails; PARTIALLY for EU/US rails** — SATNA/PAYA/PEL/Shaparak/Shetab/card-to-card: zero code |
| 5 | Trading room | Order book, matching workflow, rates, pools | **PARTIALLY IMPLEMENTED (demo)** — real event-sourced book, simulated prices/matching in demo path, gated off unless `TRADING_ENABLED` |
| 6 | Fiat + crypto wallets + conversion | Privy non-custodial, multisig, hardware, sends, conversion workflow | **PARTIALLY IMPLEMENTED** — real on-chain send path; custodial keys in DB; fiat on/off-ramp is Bridge-only onramp, no off-ramp in v1 |
| 7 | Meetings / comms | Microfinance group-meeting records only | **MISSING** — no video, no Jitsi/Zoom/WebRTC, no scheduling, no in-app chat/call |

---

## 1. KYC / AML

### 1.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| KYC tiers (none/basic/enhanced/full) + document requirements + limits | PARTIALLY IMPLEMENTED | `app/Models/User.php:81-89,141-145` (`kyc_level`, `kyc_data` encrypted:array); `app/Domain/Compliance/Services/KycService.php:187-220` `getRequirements()` — basic: national_id+selfie, $1k/day; enhanced: passport+utility_bill+selfie, $10k/day; full: +bank_statement+proof_of_income, unlimited. `verifyKyc()` sets level (default `'enhanced'`, line 83). Migrations: `2025_06_20_235735_add_kyc_fields_to_users_table.php`, `2025_06_29_001000_create_kyc_verifications_table.php`, `2025_06_29_002000…` etc. (12 kyc + 13 compliance migrations by name). Routes: `GET /compliance/kyc/status|requirements`, `POST /compliance/kyc/submit|documents` (`app/Domain/Compliance/Routes/api.php:57-66`). **Gap:** tier assignment is a staff/manual `verifyKyc()` call; no automated rule engine ties a vendor verdict to a tier transition, and no expiry/re-verification scheduler was verified. |
| Document upload + storage | PARTIALLY IMPLEMENTED | `KycController::upload`, `KycDocument` model, `KycDocumentUploaded` event. Storage backend / retention / PII encryption at rest for the *files* (vs `kyc_data` encrypted:array) was not traced — verify before launch. |
| Document authenticity / OCR / data extraction | **BROKEN (simulated)** | `app/Domain/Compliance/Services/DocumentAnalysisService.php:15,88` *"For demonstration, simulate data extraction"*; `:221` simulate security-feature detection; `:313-316` `compareDocumentWithSelfie()` returns a hardcoded array. **No OCR, no MRZ/NFC, no forgery detection in code.** |
| Liveness / selfie + face-match | **BROKEN (simulated)** | `app/Domain/Compliance/Services/BiometricVerificationService.php:10-60` — every check hardcoded (`face_detected=>true`, `screen_detected=>false`, …), confidence computed from constants; `matchFaces()` hardcoded `similarity=>92.5` (line 79). Called by `EnhancedKycService::verifyBiometrics()` (`EnhancedKycService.php:125-160`) — so the "enhanced" path auto-passes today unless the caller routes to Ondato/Bridge instead. |
| Identity verification vs national DB / credit bureaus | **BROKEN (simulated)** | `app/Domain/Compliance/Services/IdentityVerificationService.php:47` *"simulate the verification"*; `:132` *"Simulate positive match for testing"*; credit bureaus simulated (`:151-155`); Jumio/Onfido/SmileID return *"Simulated response"* (`:191-365`) with `api_key=>null` defaults (`:9-28`). |
| Ondato (real vendor KYC) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | **Real HTTP — the one genuine in-house KYC integration.** `app/Domain/Compliance/Services/OndatoService.php` (704 lines): `Http::asForm()->post(…/v3/oauth/token)` (line 78), token-authenticated calls (lines 134/185/208); `OndatoKycProvider.php` (139 lines) implements `KycProviderInterface` (`getHostedLink`, `getStatus`, webhook validator/signature header, `normalizeWebhookPayload`). Defaults: `config('services.ondato.sandbox', true)`, `kyc_api_url` default `https://sandbox-kycapi.ondato.com` (`OndatoService.php:59-65`). Routes: `POST /compliance/kyc/ondato/start`, `GET /compliance/kyc/ondato/status/{verificationId}`. Docs: `docs/ondato/`. **Gaps:** sandbox by default; no evidence in code of a production Ondato contract, webhook secret rotation, or Iranian-document support (Ondato's supported-document list is vendor-side — verify). `KycProviderRouter` partitions purposes so TrustCert/Ondato and Ramp/Bridge are not conflated (`config/kyc.php: routing trustcert=>ondato, ramp=>bridge, cards=>bridge`). |
| Bridge.xyz KYC (ramp/cards) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `app/Domain/Compliance/Kyc/Providers/BridgeKycProvider.php` (204 lines): creates/persists `bridge_customers`, requests a hosted KYC link with idempotency key `bridge_kyc_link:{user}:{ts}` (lines 66-115), webhook normalisation (lines 191+). Config `config/kyc.php: providers.bridge` (`BRIDGE_API_KEY`, `BRIDGE_API_BASE_URL=https://api.bridge.xyz`, dev fee 75 bps). **Gap:** Bridge is a US/EU-oriented provider; Iranian nationals / Iranian residency are subject to Bridge + sanctions screening — see §1.2. |
| AML / sanctions screening (name + chain address) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | Adapters are real HTTP: `ChainalysisAdapter.php` (sanctions v2, `Http::withHeaders`, lines 333-364); `CompositeScreeningAdapter.php` (internal list first, then optional external); `GoPlusAdapter.php`; `InternalSanctionsAdapter.php`. Models/events: `AmlScreening`, `AmlScreeningAggregate`, `ScreeningCompleted/MatchFound`, `TransactionMonitoring*`. Route: `GET /v1/compliance/check-address`. **Gaps:** no OFAC/UN/EU list *feed* is vendored in-repo (relies on Chainalysis/GoPlus keys); screening *policy* (what score blocks vs reviews, PEP handling, false-positive workflow) lives in `ComplianceService`/`CustomerRiskService` and was not fully traced; no Iranian-list (e.g. CBI / domestic) source exists. |
| Transaction monitoring / SAR / CTR | PARTIALLY IMPLEMENTED | `TransactionMonitoringAggregate`, `MonitoringRule`, `SuspiciousActivityReport` model + `SARCreated/SARSubmitted` events, `ComplianceAlert/Case` workflow with staff-only routes (`app/Domain/Compliance/Routes/api.php:28-52`). **Gap:** SAR *filing* to a regulator is an event + record, not a verified filing integration; thresholds/rules are configurable, not proven against any regulator's schema. |
| Risk scoring / EDD | PARTIALLY IMPLEMENTED | `CustomerRiskService` (kyc_level → risk), `EnhancedKycService::assessVerificationRisk()` (line 319), `RiskScoreCalculated` event. EDD is a flag/event, not a documented EDD procedure. |
| Iranian KYC (national ID / Shenasnameh / mobile + bank-card ownership check) | **MISSING + BLOCKED BY REGULATORY** | Zero code: no Iranian document type, no `iran`/`shenasnameh`/`national_code` string outside the sanctions list (`CgoKycService.php:288` lists `IR` as sanctioned). See §1.2. |
| GDPR / consent / data-retention | PARTIALLY IMPLEMENTED | `GdprService`, `ConsentRecord`, `DataBreach`, retention policies, `GdprDataDeleted/Exported` events. Not a substitute for Iranian data-protection rules (see §1.2). |

### 1.2 Required changes, dependencies, tests, priority

1. **P0 — Kill the simulated path or gate it out of production.** Remove
   `BiometricVerificationService`, `DocumentAnalysisService`,
   `IdentityVerificationService` (Jumio/Onfido/SmileID branches) from any
   production-resolvable binding; or make them throw outside
   `local/testing/demo`. Add a test asserting `APP_ENV=production`
   resolves liveness/document checks only to Ondato/Bridge (or the new
   provider). *Depends on:* provider contract decision. *Tests:* Pest
   feature test on `POST /compliance/kyc/submit` + `verifyBiometrics`
   with the simulated services disabled.
2. **P0 — Decide the KYC vendor for the target market and contract it.**
   Ondato (EU) and Bridge (US) are the only wired vendors. Neither is an
   Iranian KYC provider. For an Iran-facing product this is a
   **regulatory + sanctions** decision first, an engineering decision
   second: (a) which jurisdiction's KYC is legally usable for the
   intended users, (b) whether the vendor supports Iranian documents,
   (c) whether sanctions screening would block the intended user base
   outright (note `CgoKycService.php:288` already treats `IR` as
   sanctioned). *Depends on:* legal advice, vendor sales/contract.
   *Tests:* vendor sandbox end-to-end (hosted link → webhook →
   tier transition), webhook-signature negative tests.
3. **P1 — Wire vendor verdict → tier transition + expiry.**
   `KycService::verifyKyc()` is manual; add a listener on
   `KycVerificationCompleted/Failed/Rejected` that sets
   `kyc_level/kyc_status/kyc_expires_at` and emits the audit trail.
   *Tests:* unit tests for each verdict × tier, expiry job test.
4. **P1 — Document storage audit.** Trace where `KycDocument` files land
   (disk/S3, encryption, retention, access logging); add retention +
   deletion job tests. *Depends on:* storage decision.
5. **P2 — AML policy as code.** Codify block/review thresholds, PEP,
   and alert-routing rules with tests; add a sanctions-list freshness
   check (Chainalysis key present, last successful screen timestamp).

## 2. Cards (virtual / physical, issuance, lifecycle)

### 2.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Card model + lifecycle (issue/freeze/unfreeze/cancel) | PARTIALLY IMPLEMENTED | Models `Card`, `Cardholder`, `CardTransaction` (migrations `2026_03_17_000001_create_cardholders_table.php`, `…_000002_create_cards_table.php`, `2026_03_18_000001_create_card_transactions_table.php`, `2026_03_20_000001_create_visa_cli_enrolled_cards_table.php`; 17 card migrations by name). Routes: generic `v1/cards` (`index/store/provision/show/update/transactions/freeze/unfreeze/cancel`), FinCard `v1/cards/fincard/*`, issued-cards `v1/cards/issued/*` (`app/Domain/CardIssuance/Routes/api.php:45-153`). `CardStatus` enum, `AuthorizationApproved/Declined` events, JIT funding (`JitFundingService.php`). |
| Marqeta issuer adapter | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | **Real HTTP.** `MarqetaCardIssuerAdapter.php` (391+ lines): `Http::baseUrl($this->baseUrl)` (line 391), requires `base_url + application_token + admin_access_token` or throws (lines 45-47). Config defaults to sandbox: `MARQETA_BASE_URL=https://sandbox-api.marqeta.com/v3` (`config/cardissuance.php:80-86`). **Gap:** sandbox by default; no evidence of a production Marqeta programme, card-product, or funding-account wiring in code. |
| Rain issuer adapter | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | **Real HTTP, not wired.** `RainCardIssuerAdapter.php`: `base_url` default `https://api.raincards.xyz/v1` (line 43), `Http::baseUrl` (line 406), CVV read transiently (line 311). README:65: *"A Rain adapter class exists but is not wired in."* No route/config selects Rain. |
| FinCard issuer | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `FinCardCardIssuerAdapter.php` (implements `CardIssuerInterface`, holds a `FinCardClient` from `App\Infrastructure\FinCard`); **but** `createCard()` and `getProvisioningData()` throw `UnsupportedCardOperationException` (lines 44-66) — *"use POST /v1/cards/fincard/open"* / *"FinCard v1 has no Apple/Google Pay push provisioning"*; reads come from the local `Card` mirror; state changes delegate to `FinCardCardService`. Rich ops live only on the FinCard-specific endpoints (`/open`, `/sensitive`, `/topup`, `/withdraw`, `/account/coins`, `/account/deposit-address`). Config: `FINCARD_BASE_URL=https://sandbox.finhub.cloud/api/v2.1/fincard/virtual` (`config/cardissuance.php:109`). **Gap:** generic issuer abstraction is incomplete for FinCard; Apple/Google Pay provisioning is absent for FinCard. |
| Lepo (Interlace) issuer | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `app/Domain/CardIssuance/Providers/Lepo/` (`LepoCardLifecycle`, `LepoCardOnboarding`, `LepoTopUpPipeline`, `LepoTopUps`, `CardProviderRegistry/Selector`); config `cardissuance.php:127-135` (base `https://api.lepofinance.com/api/v2`, `x-api-key`, webhook signature). Pilot-gated: `lepo_pilot_only=true` default, `lepo_pilot_users` allow-list (lines 35-46); default provider `lepo` (`CARD_PROVIDER_DEFAULT=lepo`, line 43). **Gap:** top-ups OFF — see next row. |
| Card top-up ("add to card") | **BROKEN / disabled by config** | `config/cardissuance.php:59-63`: *"Add to card: off until Lepo confirms the money path"*, `lepo_topups_enabled = env('CARD_TOPUPS_ENABLED', false)`; `LepoTopUps.php:39` returns that flag. Top-up *endpoints* exist (`POST /{card}/topups`, estimate/index/show/cancel) but resolve to disabled unless the flag is flipped — and the money path itself is unconfirmed by the provider. |
| Demo issuer | FULLY IMPLEMENTED **as a demo** — must not be mistaken for issuance | `DemoCardIssuerAdapter.php`: token `card_demo_…`, *"Store in cache for demo purposes"*, fake `demo_encrypted_pass_data_*`, fake certificates, 5 deterministic demo transactions, and an explicit refusal: *"Card issuing is not available. The demo card issuer does not issue cards in production."* (line 245). **Default:** `CARD_ISSUER` defaults to `'demo'` (`config/cardissuance.php:26`). |
| JIT funding | PARTIALLY IMPLEMENTED | `JitFundingService.php` (notes demo-issuer behaviour at line 30). Live JIT depends on the selected issuer's webhook/funding model — not verified end-to-end for any live issuer. |
| 3DS | PARTIALLY IMPLEMENTED | `GET /{card}/3ds/code` (`CardThreeDsCodeReady` event). No 3DS provider / challenge-flow code was verified; treat as issuer-delegated until proven. |
| PAN / CVV handling | PARTIALLY IMPLEMENTED | **Good design:** *"Sensitive PAN/CVV is fetched on demand, never stored"* (`app/Domain/CardIssuance/Routes/api.php:105`); *"Ephemeral PAN/CVV/expiry — returned to the caller, never persisted"* (`FinCardCardService.php:129`); CVV only transient (`Marqeta…:282`, `Rain…:311`). **Gap:** log-redaction + cache handling of `VirtualCard::$cvv` (`ValueObjects/VirtualCard.php:27`) not verified; PCI scope needs an external assessor. |
| Physical cards / fulfilment / PIN | **MISSING** | No fulfilment, shipping, PIN-set/change, or PIN-storage code found (CVV/PIN grep: only transient CVV reads). PIN, if ever needed, must be issuer-side only. |
| Iranian cards (Shetab debit, Bank Mellat/Melli etc.) | **MISSING + BLOCKED BY REGULATORY** | Zero code. Iranian card issuance requires a licensed Iranian bank / PSP; sanctions + scheme rules apply. No adapter, no config, no migration. |

### 2.2 Required changes, dependencies, tests, priority

1. **P0 — Choose ONE live issuer and contract it; remove demo as default
   for any non-demo deploy.** Marqeta (sandbox default), FinCard
   (sandbox default), Lepo (pilot), Rain (unwired) cannot all be "the"
   issuer. Decision must cover: programme/card-product, funding account,
   KYC routing (`config/kyc.php: cards=>bridge`), webhook endpoints,
   3DS, and Apple/Google Pay (FinCard has none in v1).
   *Tests:* sandbox issue → freeze → top-up (once enabled) → cancel,
   webhook replay, idempotency on every mutation (already
   `idempotency.required` on card mutations — verify it is enforced).
2. **P0 — Resolve the Lepo top-up money path or remove the endpoints.**
   Either Lepo confirms the path and `CARD_TOPUPS_ENABLED` is flipped
   with a funded test, or the top-up routes return 501/ disabled with a
   clear error instead of silently depending on a flag.
3. **P1 — Wire Rain or delete it.** An unwired adapter that looks live
   is a trap for the next developer; either add it to the provider
   registry + config or remove it.
4. **P1 — FinCard generic-adapter gap.** Either implement
   `createCard`/`getProvisioningData` for FinCard or document that
   FinCard is only usable via its specific endpoints (and make the
   generic `CardController` reject FinCard explicitly).
5. **P2 — PCI:** external PCI scoping for PAN/CVV display, logging
   audit, and 3DS challenge flow.

## 3. Bank account opening

### 3.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Internal ledger account (platform account) | FULLY IMPLEMENTED | `POST /accounts`, `GET /accounts/{uuid}`, freeze/unfreeze, balances, statements (`app/Domain/Account/Routes/api.php:34-61`); `Account` aggregate + `AccountProvisioning` profiles. This is a *platform* account, not a bank account. |
| Open an account at an external bank (any bank) | **MISSING** | No `createAccount` success path to a real bank was found. `OpenBankingConnector::createAccount()` **throws**: *"Account creation is not supported via Open Banking PSD2 APIs"* (`app/Domain/Banking/Connectors/OpenBankingConnector.php:507-512`). `BankConnectorAdapter::createAccount()` exists as an interface method (line 100) but no live bank's account-opening API is wired behind it in the sampled code. |
| `AccountProvisioning` domain | FULLY IMPLEMENTED **for the wrong thing** | `AccountProvisioningService::apply()` provisions **reviewer/demo platform accounts** (creates a `User`, sets `AccountFlag`, runs sub-seeders; lines 28-106). It is not bank-account opening and must not be cited as such. |
| Open Banking consent + account access (AISP) | PARTIALLY IMPLEMENTED | `OpenBanking` domain (14 files, 1,528 LOC): `Consent`, `ConsentService`, `AispService::getAccounts/getBalances/getTransactions`, Berlin Group + UK OB formatters, routes `v1/open-banking/consents|accounts|payments`. This is *read access to an existing* external account with user consent — not account creation. No live bank connection is configured in-repo; `Banking/Connectors/OpenBankingConnector` needs a bank's `base_url`/token config. |
| Bank connections / routing / health | PARTIALLY IMPLEMENTED | `BankIntegrationService`, `BankRoutingService`, `IntelligentRoutingService`, `BankHealthMonitor`, `BankConnectorAdapter`/`BaseBankConnector` (HTTP + token auth, IBAN validation). Connectors are adapters; live banks are configuration, not code. |
| KYC-gated account opening flow (apply → verify → approve → open) | **MISSING** | No onboarding workflow ties KYC approval to external account creation. The internal `POST /accounts` does not require KYC approval in the sampled route (auth + scope only). |
| Iranian bank account opening | **MISSING + BLOCKED BY REGULATORY** | Zero code; requires an Iranian licensed bank partnership, CBI approval, and in-person/verified identity (see §1.2). |

### 3.2 Required changes, dependencies, tests, priority

1. **P0 — Stop describing this as "bank account opening".** The product
   today opens *platform ledger accounts*. Any "open a bank account"
   promise needs a named partner bank, a BaaS contract, and that bank's
   account-opening API (or a manual/branch process) — none exists.
2. **P1 — If a partner bank is chosen,** implement its opening API
   behind `IBankConnector::createAccount()`, with KYC-gating
   (KYC `approved` + tier required), idempotency, and a
   pending → approved → opened state machine with tests for every
   rejection path (KYC fail, sanctions hit, bank reject, timeout).
3. **P2 —** Keep Open Banking as read-only aggregation; do not present
   AISP consent as account opening.

## 4. Deposits / transfers — incl. SATNA / PAYA / PEL / card-to-card

### 4.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Internal deposit / withdraw / transfer | PARTIALLY IMPLEMENTED | `POST /accounts/{uuid}/deposit|withdraw`, `POST /transfers` with `idempotency` + rate-limit middleware (`app/Domain/Account/Routes/api.php:43-53`); `TransactionController`, `TransferController`, `BalanceController`. **Gap:** "deposit" here credits a ledger account; the *external* funding leg (how money arrives) is a separate capability below. Demo mode makes deposits instant (`DEMO_INSTANT_DEPOSITS=true`, `.env.example:46`). |
| SEPA credit transfer / direct debit | PARTIALLY IMPLEMENTED (message layer) | `SepaCreditTransferService` builds ISO 20022 **pain.001** (SCT) and **pacs.008** (SCT Inst) (`app/Domain/Banking/Services/SepaCreditTransferService.php:16-17,139-173`); `SepaDirectDebitService`, `SepaMandateService`, `SepaMandate` model (1 sepa migration by name). **No live SEPA clearing/settlement connection** — a pain.001 file is not a transfer until a bank/CSM accepts it. |
| SWIFT | PARTIALLY IMPLEMENTED (message layer) | Modelled in Banking/ISO20022; 0 swift migrations by name. No SWIFT gpi / bank connection verified. |
| ACH / FedNow / Fedwire (US) | PARTIALLY IMPLEMENTED (file layer) | `PaymentRails`: `AchService` (batch, NACHA file generation, returns), `NachaFileGenerator/Parser`, `FedNowService` (send + status report XML), `FedwireService`, `PaymentRailRouter`, `PaymentRail` enum (ACH/Fedwire/SEPA/SEPA_INSTANT/SWIFT/RTP/FedNow). 5 ach migrations. **No live US rail connection** — file generation ≠ submission to the Fed/ODFI. |
| Paysera deposits | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `PayseraDepositService.php` (signed callbacks, `handleServerCallback`, `getOrderStatus/cancelOrder`); `DemoPayseraDepositService` alongside. Paysera sandbox URL in `config/demo.php: sandbox.paysera`. Contract/account status not verified. |
| HyperSwitch deposits (card / APM orchestration) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `HyperSwitchClient.php` (create/get/confirm/capture/refund, `Http::timeout`, lines 211-296) + `HyperSwitchPaymentService.php` (`initiateDeposit`, `startDeposit`, `getPaymentStatus`, `refund`). Requires a HyperSwitch merchant + connector configuration (`config/hyperswitch.php`). Not verified live. |
| Stripe / Bridge ramp on-ramp (fiat → crypto) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `Ramp` domain: `BridgeProvider`, `OnramperProvider`, `StripeCryptoOnrampProvider` (disabled by default in v1), `MockRampProvider`; `RampService`, `StripeCryptoOnrampService`. ADR-0005/0006. **Off-ramp is not in v1** (`OfframpNotAvailableException.php:11`: *"only ships onramp in v1"*). See §6. |
| Custodian deposits (Deutsche/Santander/Paysera/Flutterwave) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `Custodian/Connectors/`: `DeutscheBankConnector`, `SantanderConnector`, `PayseraConnector`, `FlutterwaveConnector` (real `Http::` calls), plus `DemoBankConnector` and `MockBankConnector` (environment-gated, `MockBankConnector::isPermitted()`). `CustodianAccountService`, reconciliation, webhook verification. Contract/account status for each custodian not verified. |
| **SATNA** | **MISSING** | **0 hits** in `app/ routes/ config/ database/migrations/` (grep, case-insensitive). No message format, no CBI connection, no settlement account. |
| **PAYA** | **MISSING** | **0 hits** (same grep). |
| **PEL (پل / instant, Iranian)** | **MISSING** | 38 `PEL` hits are false positives (substrings in `Lepo`, etc.); no Iranian instant-payment code. |
| **Shaparak / Shetab** | **MISSING** | **0 hits** for both. |
| **Card-to-card (کارت به کارت)** | **MISSING** | **0 hits** for `card-to-card` / `card_to_card`. No Iranian card-transfer API, no destination-card validation, no CBI limits. |
| **IRR / Rial as a transfer currency** | **MISSING (as a rail)** | `IRR` appears only as a currency-code list entry (`FixerIoProvider.php:24`); `Rial` 288 hits are overwhelmingly `jsonSerialize`/`trial` substrings. No IRR account, no IRR settlement, no IRR rate source wired for transfers. |

### 4.2 Required changes, dependencies, tests, priority

1. **P0 — Iranian rails are a new build, not an integration tweak.**
   SATNA/PAYA/PEL/Shaparak/Shetab/card-to-card each need: (a) a licensed
   Iranian bank/PSP partner + CBI approval, (b) that partner's API/spec
   (message format, auth, limits, cut-off times, return codes),
   (c) a new `PaymentRails` rail + `Banking` connector behind the
   existing `IBankConnector`/`PaymentRailRouter` seams, (d) IRR ledger
   accounts + decimal handling (storage already decimal — §5 of the
   audit), (e) reconciliation + return handling. **Do not estimate this
   as "add a rail" until (a) and (b) exist.** *Tests:* partner sandbox
   happy path + every return code, idempotency/replay, cut-off and
   limit tests, reconciliation tests.
2. **P1 — For EU/US rails, prove one live path.** Pick SEPA *or* ACH
   and complete bank/CSM onboarding, then run a real (sandbox, then
   small-value live) transfer end-to-end with reconciliation. Until
   then, SEPA/ACH/FedNow are file builders, not transfers.
3. **P1 — Deposit funding-leg matrix.** Document, per deposit method
   (Paysera / HyperSwitch / Bridge / custodian), who holds the money,
   settlement timing, fees, and failure/reversal handling — then test
   each callback/webhook path including replay and bad signatures.
4. **P2 —** IRR pricing: if IRR is ever offered, a lawful IRR rate
   source + conversion audit trail is required; the demo fixed rates
   (`config/demo.php`: EUR/USD 1.10, BTC 45,000) must never be used.

## 5. Trading room

### 5.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Order placement / cancellation / listing | PARTIALLY IMPLEMENTED | `ExchangeService::placeOrder()` validates account, asset tradeability, limit/stop price, minimum order value (`bccomp` 18dp), persists an `Order` aggregate, ensures the order book exists, and starts `OrderMatchingWorkflow` (max 100 iterations) (`app/Domain/Exchange/Services/ExchangeService.php:26-117`); `cancelOrder()` (line 119); routes `POST /exchange/orders`, `DELETE /orders/{id}`, `GET /orders|trades` (`app/Domain/Exchange/Routes/api.php:37-50`). |
| Order book (in-memory/event-sourced) | PARTIALLY IMPLEMENTED | `OrderBook` aggregate (`Aggregates/OrderBook.php`: `addOrder/removeOrder/takeSnapshot`, best bid/ask as `BigDecimal`), `OrderBook` projection + projector, `OrderBookUpdated/Added/Removed/Snapshot` events, `GET /exchange/orderbook/{base}/{quote}`. Real structure; depth/liquidity depend on real orders. |
| Matching engine | PARTIALLY IMPLEMENTED | `Order::matchOrder()` records an `OrderMatched` event (`Aggregates/Order.php:81-105`); `OrderMatchingWorkflow` + `MatchOrderActivity` + `OrderFulfillmentSaga::matchOrder()` exist. **Not verified end-to-end:** whether a live (non-demo) match actually finds a counterparty, locks balances (`LockAccountBalanceActivity`), settles both legs, and handles partial fills — no execution was performed and the matching-activity internals were not fully traced. Treat as "engine scaffolding present, settlement unproven". |
| Demo trading (simulated prices + instant matching) | FULLY IMPLEMENTED **as a demo** | `DemoExchangeService.php`: *"Place a demo order with simulated instant matching"* (line 28), `simulateOrderMatching()` (line 176), `getSimulatedPrice()` (line 229); demo rates in `config/demo.php`. This is what a demo deployment actually trades against. |
| Market data / rates | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `ExchangeRateService`, `EnhancedExchangeRateService`, `ExchangeRateProviderRegistry`, providers incl. `FixerIoProvider`, `MockExchangeRateProvider`; routes `GET /exchange-rates[/…/convert]`, provider compare/aggregated endpoints. Real rates need a contracted provider key; the mock/fixed-rate path is the default in demo. |
| External exchange connectors (Binance/Kraken) | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | `BinanceConnector.php`, `KrakenConnector.php`, `ExternalExchangeService`, routes `/external-exchange/ticker|orderbook|arbitrage`. Keys, rate limits, and whether they trade or only read prices were not verified. |
| Liquidity pools (list/swap/positions/rewards/IL protection) | PARTIALLY IMPLEMENTED — **BROKEN history** | Routes + `LiquidityPoolService` + `AutomatedMarketMakerService` exist; README:47: liquidity *"add, remove and rebalance … never worked and was removed in v7.18.0"*. Swap/positions/rewards/IL-protection endpoints remain (`app/Domain/Exchange/Routes/api.php:63-80`) — their live correctness is **unproven** and must be re-tested before any claim. |
| Trading enabled flag | PARTIALLY IMPLEMENTED | `config/trading.php:18`: `TRADING_ENABLED` defaults to true only in `local/testing/demo`; all exchange/liquidity routes sit behind `feature:trading.enabled`. Production Zelta disables the standards/demo modules (README:112, `MODULES_DISABLED`). |
| Trading room UX (charts, room, social, analyst calls) | **MISSING** | No "trading room" UI, charting, watchlist-room, or trader-chat code was found. The repo is an API + admin (Filament) platform; any room UI is a frontend build. "Trading room" as a *venue with membership, permissions, and live collaboration* does not exist. |
| Regulated trading (licence, best execution, market abuse) | **BLOCKED BY REGULATORY** | Operating a trading venue / matching client orders is a regulated activity in the EU (MiFID), UK, US, and Iran. No licence, no best-execution policy, no market-abuse surveillance was found in code. |

### 5.2 Required changes, dependencies, tests, priority

1. **P0 — Prove matching + settlement with a test, not a demo.**
   Pest/Feature test: two opposing limit orders → one trade, both
   balances settled, partial-fill case, cancel-after-partial case.
   Until that test exists and passes on the *non-demo* service, do not
   present matching as working.
2. **P0 — Decide what "trading room" means.** If it is a UI room
   (charts + order ticket + positions), it is a frontend build on the
   existing API. If it is a *venue*, it is a regulatory project first.
   Record the decision before estimating.
3. **P1 — Rate source:** contract one real rate provider, remove the
   fixed demo rates from any non-demo path, and add a stale-rate
   circuit breaker (`CircuitBreakerService` exists in Exchange).
4. **P1 — Liquidity pools:** re-test swap/positions/rewards/IL
   protection after the v7.18.0 removal; either restore add/remove/
   rebalance with tests or remove the remaining pool endpoints that
   depend on them.
5. **P2 —** External connectors: clarify read-only vs trading, add
   key scoping (no withdrawals) and per-connector kill switches.

## 6. Fiat + crypto wallets + conversion

### 6.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Non-custodial wallet (Privy, device-held keys) | PARTIALLY IMPLEMENTED | `PrivyAddressRegistrar.php` (Privy-derived addresses, ownership verification via `PrivyWalletOwnershipVerifier`, max addresses/chain), `config/privy.php`; README: *"device holds the keys through Privy, using passkey-controlled smart accounts on EVM and a device-bound key on Solana; backend prepares an unsigned transaction and the device signs it; gas/fees can be sponsored with daily caps"* — the sponsorship budget/cost tracker exists (`SponsorshipBudget.php`, `SponsorshipCostTracker.php`). Chains: Polygon, Base, Arbitrum, Solana (README). **Gap:** Privy contract/account status not verified; sponsorship caps are config, not a proven budget control. |
| Custodial blockchain wallet (server-held keys) | PARTIALLY IMPLEMENTED | `BlockchainWalletService::createWallet(type='custodial'|'non-custodial')` (lines 66-97); mnemonic generation; `encrypted_private_key` column (see audit §9.11); `KeyManagementService::decrypt()` used at withdrawal (`BlockchainWithdrawalActivities.php:215-231`). **Risk:** custodial keys in the app DB are a high-value target; the non-custodial path is the stated production model — clarify which path the product actually ships. |
| Crypto send (EVM + Solana) | PARTIALLY IMPLEMENTED | `MobileWalletController::prepareTransaction/submitTransaction`, `EvmTransactionProcessor`, `SolanaSendSubmitter` (*"a send with an unclear network result stays submitted until the chain settles it"* — README), Helius (Solana) + Alchemy (EVM) webhooks for incoming transfers. Gas sponsorship with caps. **Gap:** chain-confirmation / reorg handling and stuck-transaction recovery were not traced. |
| Incoming-transfer detection | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | Helius + Alchemy webhooks (`HeliusTransactionProcessor`, `HeliusWebhookSyncService`, `AlchemyWebhookManager`). Requires webhook registration + secrets for each provider. |
| Hardware wallets (Ledger/Trezor) | PARTIALLY IMPLEMENTED | `HardwareWalletController` (register, signing-request create/submit/cancel, associations), `LedgerSignerService` / `TrezorSignerService`. Note float casts for wei/satoshi in both signers (audit §9.9) — fix before relying on them for amounts. |
| Multisig wallets | PARTIALLY IMPLEMENTED | `MultiSigWalletService`, `MultiSigApprovalService`, routes `multi-sig/wallets|approval-requests|broadcast`, `getPendingApprovals`. Quorum/percentage value objects exist. On-chain deployment/broadcast path not verified live. |
| Fiat wallet (EUR/USD balance) | PARTIALLY IMPLEMENTED | The *ledger* fiat balance is real (`Account` multi-asset, `account_balances`); a *funded* fiat wallet (money in via bank/PSP, held at a custodian/EMI) depends on §4 deposit rails + a custodian/EMI contract. No EMI account is configured in-repo. |
| Fiat → crypto on-ramp | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | Bridge provider is the v1 on-ramp (bank-rail onramp only, per `BridgeProvider.php` + ADR-0005); Onramper + Stripe Crypto Onramp also exist (Stripe disabled by default in v1). `MockRampProvider` for tests/demo. Requires Bridge contract + KYC (Bridge KYC, §1). |
| Crypto → fiat off-ramp | **MISSING** | `OfframpNotAvailableException`: *"only ships onramp in v1 (Bridge: bank-rail onramp only…; Stripe…)"*. No off-ramp provider, route, or settlement path. |
| Fiat ↔ crypto conversion (in-platform) | PARTIALLY IMPLEMENTED | `WalletConvertWorkflow` + `ConvertAssetActivity` (amounts as `int`, rate as `float` — lines 30-80; rate precision needs review), `WalletTransferService::getTransferQuote/getTransactionQuote`, `POST /exchange/convert` (`app/Domain/Account/Routes/api.php:65`), `GET /exchange-rates/{from}/{to}/convert`. Conversion *books* a transfer at a quoted rate; the rate source (§5) and the fiat leg's settlement are the gaps. |
| Crypto ↔ crypto conversion / swap | PARTIALLY IMPLEMENTED | Via Exchange liquidity-pool swaps (`POST /liquidity/swap`) and the wallet conversion workflow; same liquidity caveats as §5 (add/remove/rebalance removed in v7.18.0). |
| Key management / HSM | PARTIALLY IMPLEMENTED + REQUIRES EXTERNAL PROVIDER | AWS KMS + Azure Key Vault providers are real SDK code (`AwsKmsHsmProvider`, `AzureKeyVaultHsmProvider`); **Demo HSM is simulated with a hardcoded key** (audit S1). Shamir sharing (`ShamirService`, `ShardDistributionService`) exists. **Gap:** which HSM is actually configured in production, key rotation (`KeyRotationSchedule` model exists), and the custodial-key encryption key's own custody were not verified. |

### 6.2 Required changes, dependencies, tests, priority

1. **P0 — Pick custodial vs non-custodial and enforce it.** If the
   product is non-custodial (Zelta's stated model), the custodial
   wallet creation + `encrypted_private_key` path should be disabled
   in production and its key-custody story documented; if custodial
   is intended, it needs an HSM (not Demo HSM), key rotation, and a
   custody audit. *Tests:* production-config test asserting the
   custodial path is unreachable / HSM provider is not `demo`.
2. **P0 — Off-ramp decision.** Either contract an off-ramp provider
   (Bridge off-ramp, Onramper, or other) or explicitly scope the
   product as on-ramp-only. Do not imply "fiat ↔ crypto" while only
   fiat → crypto exists.
3. **P1 — Conversion rate integrity.** Replace `float` exchange rates
   in `ConvertAssetActivity` with decimal/string rates, add a
   quote-expiry + slippage check, and test stale-rate rejection.
4. **P1 — Send reliability.** Test unclear-network-result, stuck,
   and reorg cases for EVM + Solana sends; verify Helius/Alchemy
   webhook signature verification is enforced (not just present).
5. **P2 —** Hardware-wallet float casts (wei/satoshi) → integer/
   `BigDecimal` before any amount is relied on.

## 7. Meetings / communications

### 7.1 Capability table

| Capability | Classification | Evidence |
|---|---|---|
| Video meeting (Jitsi / Zoom / WebRTC / Daily / Twilio Video) | **MISSING** | **0 hits** for `Jitsi`, `Zoom`, `WebRTC` in `app/ routes/ config/ database/migrations/`; `grep -i "jitsi\|zoom\|google.meet\|teams"` across `app/ config/ resources/` returned 0 in the sampled check (the only "meeting" hits are microfinance — next row). No video SDK, no room token, no TURN/STUN config, no recording. |
| Audio / voice call | **MISSING** | No voice-call code found. (SMS exists as a domain — `app/Domain/SMS/`, 12 files — for text messages, not calls.) |
| In-app chat / messaging | **MISSING** | No chat domain, no message thread model for users, no WebSocket chat room. `AgentProtocol` messaging (A2A) is agent-to-agent protocol messaging, not user chat. `Contact` domain (2 files) is a contact record, not a chat. |
| Meeting scheduling / calendar / booking | **MISSING** | No scheduling domain, no calendar integration (Google Calendar connector is listed in the platform's connector catalogue but no meeting-booking use was found in this repo's code), no booking/appointment model for users. |
| Microfinance group meetings (record-keeping) | FULLY IMPLEMENTED **for its narrow purpose** | `GroupMeeting` model (`mfi_group_meetings`, `meeting_date`, `next_meeting_date`), `MeetingFrequency` enum, `GroupLendingService::recordMeeting()`, route `POST /groups/{id}/meetings`, console `mfi:create-group --meeting-day=…`. **This records that a (physical) group meeting happened; it is not a video meeting, not scheduling, and not communications.** Do not cite it as meeting functionality for the target product. |
| Push / email / SMS notifications (adjacent) | PARTIALLY IMPLEMENTED | Push (Mobile domain, Firebase config `config/firebase.php`), email (Resend, mail config), SMS domain. These are notification channels, not a meeting/comms product. |
| Screen share / recording / transcription | **MISSING** | Zero code. |

### 7.2 Required changes, dependencies, tests, priority

1. **P0 — This module is a greenfield build.** Choose the model
   first: (a) embedded video SDK (Jitsi self-hosted, Daily, Twilio,
   Zoom SDK, or Google Meet links), (b) simple "book a call → send a
   Meet/Zoom link" (no in-app video), or (c) async messaging only.
   Each has different cost, privacy, and (for recorded financial
   advice) regulatory implications. *Depends on:* product decision,
   vendor contract, data-residency decision for recordings.
2. **P1 — If in-app video is chosen,** implement: room creation +
   token auth (per-user, expiring), join/leave, recording consent +
   storage + retention, and tests for unauthorised join, expired
   token, and recording-consent enforcement. Reuse the existing
   `auth:sanctum` + idempotency patterns; do not build a parallel
   auth system.
3. **P1 — If scheduling is needed,** add an appointment model +
   availability + reminders (email/SMS/push already exist as
   channels) with timezone handling (the platform already uses
   Carbon; Jalali calendar is a separate requirement if
   Iranian users are in scope — no Jalali code was found).
4. **P2 —** Do not repurpose the microfinance `GroupMeeting` model
   for this; it is a lending-domain record with its own semantics.

---

## Cross-cutting gaps (apply to all 7 modules)

| # | Gap | Why it blocks the implementation phase |
|---|---|---|
| X1 | **Demo mode is the default, not the exception.** `config/demo.php` defaults (`skip_kyc`, `auto_approve`, `mock_external_apis`, `fixed_exchange_rates`, `instant_deposits` all `true`) + `.env.example` demo flags + `CARD_ISSUER=demo` + Ondato/Marqeta/FinCard sandbox defaults. | Any new module built on top inherits fake KYC, fake rates, and a demo issuer unless the implementer explicitly flips every flag. The implementation phase must start with a **production-config checklist** (and a failing test if any demo flag is on in production). |
| X2 | **Simulated services are importable from production code paths.** Liveness, document, identity, demo exchange, demo HSM, mock ramp/bank/payment services coexist with real ones behind config. | A wrong env value silently swaps a real check for a fake one. Gate simulated services by environment at the container-binding level, not by convention. |
| X3 | **No Iranian anything.** SATNA/PAYA/PEL/Shaparak/Shetab/card-to-card, Iranian KYC documents, IRR settlement, Jalali calendar: all zero. Iranian users are additionally in the sanctions list (`CgoKycService.php:288`). | An Iran-facing launch is not a feature gap — it is a **jurisdiction + sanctions + partner-bank** decision that precedes engineering. Do not start building rails before legal/compliance clears the model. |
| X4 | **Float for money in hot paths** (audit §9.9). | Refunds, conversions, TigerBeetle mirroring, hardware-wallet amounts. Standardise on string/`BigDecimal` amounts at every boundary; add a static-analysis rule (or grep gate) banning `float $amount` in money paths. |
| X5 | **Idempotency / replay coverage is unproven end-to-end.** Present on Account + Card mutations; not verified for Exchange, Ramp, Wallet sends, or all webhooks. | Every new money endpoint must carry `idempotency` + a replay test (duplicate request → same result, single effect). |
| X6 | **External-provider status is unknown.** Ondato, Bridge, Marqeta, Lepo, FinCard, Paysera, HyperSwitch, Chainalysis, Helius, Alchemy, custodian banks: code exists, contracts/keys/production status were not verified (no live calls). | The implementation phase must inventory each provider (contracted? sandbox? production key present? webhook registered?) before relying on it. |
| X7 | **Test thin spots** (audit §7): Ledger 13, OpenBanking 8, Exchange Domain 4, CardIssuance Domain 4 tests; no coverage % measured. | New modules need tests *in the same PR*, mirroring source structure per repo `AGENTS.md` (≥50% for new code), plus the matching/settlement, KYC-verdict, and top-up tests named above. |
| X8 | **Regulatory:** no licence, no trading-venue authorisation, no EMI/bank partnership, no PCI assessment in-repo. | `docs/REGULATORY-CLAIMS.md` is the controlling doc. Every module's "launch" needs a named legal basis; engineering cannot supply it. |

## Top 10 gaps the implementation phase must fix (prioritised)

1. **Simulated KYC is live-callable** — liveness, face-match, document
   authenticity, and identity/credit-bureau checks are hardcoded
   simulations (`BiometricVerificationService.php:10-60,79`,
   `DocumentAnalysisService.php:15,88,221`,
   `IdentityVerificationService.php:47,132,151-155,191-365`). Gate or
   delete them before any real user is onboarded. (Module 1)
2. **Iranian payment rails do not exist** — SATNA/PAYA/PEL/Shaparak/
   Shetab/card-to-card: 0 hits in code, config, routes, and migrations.
   This is a partner-bank + CBI project, not a code task. (Module 4)
3. **Bank account opening does not exist** — Open Banking
   `createAccount()` explicitly throws
   (`OpenBankingConnector.php:507-512`); `AccountProvisioning` only
   provisions demo platform accounts. (Module 3)
4. **Meetings/comms do not exist** — Jitsi/Zoom/WebRTC: 0 hits; the
   only "meeting" code is microfinance group-meeting *record-keeping*.
   Greenfield build; decide embedded-video vs link-based first.
   (Module 7)
5. **Cards: default issuer is demo; top-ups are off; FinCard generic
   methods throw; Rain is unwired** — `CARD_ISSUER` defaults to `demo`
   (`config/cardissuance.php:26`); `CARD_TOPUPS_ENABLED=false`
   (lines 59-63); `FinCardCardIssuerAdapter.php:44-66`; Rain unwired
   (README:65). Pick one live issuer and prove issue → top-up →
   freeze → cancel in sandbox. (Module 2)
6. **Demo flags default ON** — `DEMO_SKIP_KYC`, `DEMO_AUTO_APPROVE`,
   `DEMO_MOCK_EXTERNAL_APIS`, `DEMO_FIXED_EXCHANGE_RATES`
   (`config/demo.php:18-24`, `.env.example:46-55`). A production
   checklist + failing test is required before launch. (All modules)
7. **Trading settlement is unproven; liquidity add/remove/rebalance
   was removed** — matching workflow exists but no executed test was
   run here; README:47 admits the liquidity flow *"never worked"*.
   Prove matching + settlement with tests; re-test pools. (Module 5)
8. **No crypto off-ramp** — `OfframpNotAvailableException`: on-ramp
   only in v1. "Fiat ↔ crypto" is currently one-way. (Module 6)
9. **Hardcoded demo secrets + custodial keys in DB** —
   `DemoHsmProvider.php:21` (`demo-hsm-encryption-key-32bytes!`),
   `BiometricJWTService.php:420` (demo JWT secret), `demo123`/`admin123`
   seeds, `encrypted_private_key` column
   (`2025_09_23_122412_create_transaction_security_tables.php:118`).
   Rotate/remove; decide custodial vs non-custodial. (Modules 1, 6;
   audit S1–S6)
10. **Float money + scale-4 ledger** — `LedgerService` uses `bcadd/
    bccomp` at scale 4 while crypto needs 18dp; `(float)` casts in
    `JournalEntry:70-71`, `TigerBeetleDriver:39-52`,
    `PaymentService::refundPayment(float $amount)`,
    `WalletTransferService:107`, hardware signers. Standardise on
    string/`BigDecimal`. (Modules 4, 5, 6)

## What was NOT verified (do not treat as done)

- No test suite was run; no coverage % is claimed.
- No migration, seeder, or artisan command was executed.
- No live call was made to Ondato, Bridge, Marqeta, Lepo, FinCard,
  Paysera, HyperSwitch, Chainalysis, Binance, Kraken, Helius, Alchemy,
  or any custodian bank. Contract, key, sandbox/production, and webhook
  status for every provider is **unknown** to this analysis.
- Matching-engine settlement, JIT funding, 3DS challenge flow,
  webhook signature enforcement, and log redaction for PAN/CVV were
  read but not executed — they are "code present, behaviour unproven".
