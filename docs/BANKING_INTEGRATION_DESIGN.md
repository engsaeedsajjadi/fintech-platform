# Banking Integration Design — Phase Two

> Status: implemented in the `feat/hardening-phase0-9` working tree (uncommitted).
> Scope: `Banking`, `AccountProvisioning`, `Payment`, `PaymentRails`,
> `CardIssuance`, `FinancialInstitution` only. `Compliance`, Ledger internals,
> `Exchange`, `Wallet` and `SafeDeal` were not touched.
>
> This document describes what the code actually does. Where a behaviour is
> simulated or gated, it says so — nothing here claims live connectivity
> that does not exist.

## 1. Provider architecture

### 1.1 Contracts and registry

Every external provider is reached through two small contracts in
`app/Domain/Banking/Providers/Contracts/`:

| Contract | Purpose |
| --- | --- |
| `ExternalProviderInterface` | `getName()`, `getMode(): ProviderMode`, `isAvailable(): bool`, `healthCheck(): ProviderResult` |
| `ProviderResult` | Outcome value object: `success` / `failed` / `unknown`, plus an optional `detail` string. An outcome of **unknown** means the provider did not answer — it is never rendered as success. |
| `ProviderMode` | `SANDBOX` / `PRODUCTION` |

`ProviderRegistry` (`Providers/Support/ProviderRegistry.php`) is the single
place providers are registered and resolved:

- `register(name, provider)`, `resolve(name): ?provider`, `require(name)`
  (throws `ProviderUnavailableException` when unknown), `has(name)`,
  `names()`, `sandboxNames()`.
- The registry is constructed with `sandboxAllowed: bool`. When it is
  `false`, resolving a sandbox-mode provider throws
  `ProviderUnavailableException::sandboxInProduction()`. The application
  binds the registry as a singleton with
  `sandboxAllowed: ! app()->environment('production')`
  (`BankIntegrationServiceProvider`), so a sandbox adapter can never be
  resolved in production by accident.
- Unknown names fail closed: there is no silent fallback to the sandbox.

### 1.2 Provider kernel (`Providers/Support/`)

| Component | Behaviour |
| --- | --- |
| `CircuitBreaker` | Cache-backed. `guard(name)` throws `ProviderUnavailableException::circuitOpen()` while open; `recordSuccess` / `recordFailure` move the state. Thresholds and reset timeout come from `config/providers.php`. |
| `WebhookVerifier` | HMAC-SHA256 over `timestamp.body`, constant-time `hash_equals`, configurable timestamp tolerance, replay guard via `Cache::add`. An empty secret never verifies. Accepts the `t=…,v1=…` header form. |
| `RequestSigner` | Canonical-JSON request signing and verification (sorted keys, recursive). |
| `IdempotencyStore` | Atomic claim via `Cache::add`, `complete(key, result)`, `fetch(key)`. A repeated call with the same key returns the stored result instead of resubmitting. TTL from `config/providers.php`. |
| `ProviderRateLimiter` | Laravel `RateLimiter`, per-provider keys, limits from `config/providers.php`. |
| `ProviderAuditLogger` | Structured audit log. Redacts secret keys and masks account numbers / IBANs to first-2 + `****` + last-4 before logging. |

`config/providers.php` holds the shared knobs: timeouts, retries (idempotent
reads only — never retry a non-idempotent write), circuit-breaker thresholds,
webhook tolerance, rate limits, idempotency TTL.

### 1.3 Provider matrix and gates

| Provider / rail | Mode | Gate |
| --- | --- | --- |
| Sandbox bank adapter (`SandboxBankAdapter`) | Sandbox only | Refuses to run in production; test IBANs only; `sandbox_outcome` test hooks (`pending_then_approved`, …). Implements `ExternalProviderInterface` (`getMode()` → `SANDBOX`, `healthCheck()`). |
| Partner bank (production account opening) | — | **Not contracted.** `BankAccountProviderFactory` resolves through the registry; the default is `sandbox`, `openbanking` is rejected explicitly (PSD2 has no account-opening capability), any other name throws `AccountOpeningException`. No production provider can be silently invented. |
| FedNow / Fedwire / RTP | Simulation only | `RailTransmissionGate::ensureSimulationAllowed()` requires the rail `enabled` flag **and** simulation mode **and** a non-production environment. Otherwise `RailUnavailableException` is thrown and **no row is created**. In simulation the recorded payment is `INITIATED` with `transmitted: false` and `simulation: true` — never `PROCESSING`, never a fabricated external id. |
| SEPA / SWIFT | Message building only | `SepaCreditTransferService` builds the pain.001 message; it is **not sent**. `PaymentRailRouter::dispatchFallback` (which recorded stub `pending` transactions) was removed; SEPA/SWIFT now throw `RailUnavailableException` when no rail can carry. |
| ACH | Gated | `dispatchAch` requires `ach.enabled`. |
| Iranian rails (SATNA / PAYA / PEL / card-to-card) | Sandbox only | Inherited from Phase 0–1: disabled in production, no live endpoints. See §5. |

`POST /v1/payment-rails/send` maps `RailUnavailableException` to HTTP 503
`RAIL_UNAVAILABLE`. The GraphQL `SendPaymentMutation` was left untouched
(out of scope); it surfaces the exception as a GraphQL error.

## 2. Account opening lifecycle

`BankAccountOpeningService` + `BankAccountProviderFactory` + `BankAccount`
model. **No bank account number or IBAN is ever generated locally** — both
come only from the provider's response; a provider that returns neither
leaves the application in manual review.

States: `pending → approved | rejected | unknown | manual_review`.
`unknown` is a first-class outcome: the provider did not answer, so the
application waits for a human instead of being approved or rejected on a
guess.

- **KYC eligibility:** opening is gated on the Compliance KYC result
  (Phase 0–1 hardening). A failed or missing KYC check fails closed.
- **Provider submission:** idempotent (idempotency key per application);
  a retried submission does not open a second account.
- **Reconciliation:** `reconcilePendingApplications(int $limit = 50)`
  re-syncs pending and manual-review applications against the provider,
  per-application try/catch, and returns a summary
  (`processed / approved / pending / rejected / closed / manual_review`).
  `syncStatus` verifies the provider account is actually active when the
  provider reports `approved` — a disagreement (e.g. provider says
  `approved` but the account is frozen) goes to manual review and emits
  an event instead of persisting an active account.
- **Sandbox status mapping:** the sandbox adapter used to map `frozen` →
  `approved`; it now maps `frozen` → `unknown`.

## 3. Unified payment engine

### 3.1 Payment intents

Table `provider_payment_intents` (migration
`2026_11_24_000005_create_provider_payment_intents_table.php`; the
`payment_intents` name was already taken by the MobilePayment merchant
flow). Model `App\Domain\Payment\Models\PaymentIntent`, service
`PaymentIntentService`.

- **Identity:** UUID, idempotency key (unique). Re-creating with the same
  key returns the existing intent.
- **Money:** integer minor units only (`amount_minor`); the exact major
  amount is derived, never stored as float. `majorToMinor` /
  `minorToMajor` honour per-currency exponents (JPY/KRW/VND/CLP → 0,
  BHD/IQD/JOD/KWD/LYD/OMR/TND → 3, default 2). Float amounts are refused.
- **Direction** (`PaymentIntentDirection`): `internal`, `external`,
  `incoming`, `outgoing`. **Kind** (`PaymentIntentKind`): `deposit`,
  `withdrawal`, `transfer`, `refund`, `reversal`.
- **States:** `initiated → pending → processing → successful | failed`,
  plus `reversed` and `unknown`. Terminal intents (`successful`,
  `failed`, `reversed`) are immutable. `unknown_since_at` is set when an
  intent becomes unknown; `resolved_at` when evidence resolves it.
- **Transitions:** a strict legal-transition map in `transition()`.
  Illegal transitions throw; the reason is stored in metadata and
  `failure_reason` is set only for `failed` / `unknown`.
- **Unknown resolution:** `resolveUnknown()` accepts evidence only —
  `successful`, `failed` or `reversed` — never a guess.
- **Reconciliation:** `reconcile()` maps a provider state through
  `TransactionState::fromLegacy()`; an unclassifiable provider state
  goes to manual review, never moves a terminal intent.
- **Refunds:** `refund()` only from `SUCCESSFUL`, amount ≤ original,
  creates a linked refund intent with the opposite direction.
- **Normalized shape:** `normalized()` returns the provider-independent
  transaction model (`amount_minor`, exact major `amount`, currency,
  direction, kind, state, provider reference).

### 3.2 Production / sandbox payment services

`ProductionPaymentService` and `SandboxPaymentService` no longer return
fabricated ids (`txn_…`, `sandbox_txn_…`, `ob_…`). They create a persisted
intent first and return the intent id (UUID); the intent moves to
`PROCESSING` when the workflow starts, and to `FAILED` (with rethrow) if
the start fails. A retried call with the same idempotency key does not
resubmit (key derived from `reference` / `external_reference`, null when
absent). Withdrawal responses include `intent_id`.

`PaymentProcessingService::processDeposit` now reports `processing`
(previously `completed` immediately after starting the workflow).
`refundPayment` now requires a recorded intent in `SUCCESSFUL` state and
creates a real refund intent; otherwise it throws
`RefundNotAllowedException`. No refund is ever fabricated and no cached
payment is flipped to `refunded` without a provider call.

### 3.3 What is still simulated

- `DemoPaymentService` is demo-environment only and actually credits demo
  balances — intentional, documented, not a live path.
- The legacy `PaymentService` mock is unreferenced by live paths; left
  in place, documented here rather than deleted.
- Cumulative refund tracking across multiple partial refunds is **not**
  enforced: each refund is checked against the original amount only.

## 4. Money rules

- **Never float for money.** All decisions use integer minor units or
  bcmath decimal strings (`DecimalString::fromNumeric($v, 4)`,
  `DecimalMath`). Float survives only at legacy API boundaries, where it
  is converted with bcmath at the boundary and documented as such.
- Canonical methods added in this phase (float versions kept as
  delegating legacy wrappers so existing callers and tests keep working):
  - `BankIntegrationService::initiateInterBankTransferExact(string)`,
    `getOptimalBankExact()`, `getAggregatedBalanceExact(): string`.
    Amounts are canonicalized to 4-decimal strings at entry; the
    returned `BankTransfer` carries the same canonical amount (a provider
    echoing `10.10` is normalized to `10.1000`).
  - `SpendLimitEnforcementService::checkLimitCents()` /
    `recordSpendCents()`; `JitFundingService` now decides on integer
    cents (`DEMO_BALANCE_CENTS = 100000`). The float `amount` on the
    authorization events is payload-only; no decision reads it.
  - `PartnerBillingService::getOutstandingBalanceExact(): string`,
    `applyBillingCycleDiscountExact(): string`.
  - `BankRoutingService` and `IntelligentRoutingService` take numeric
    strings; thresholds (`15000`, `10000`) compare with `bccomp`.
    `getCostEstimate` still returns a float — it is a scoring estimate
    only, documented as such.
  - `NachaFileGenerator::toCents` and `SepaDirectDebitService`
    mandate-max comparisons use bcmath.
- **No network calls inside long DB transactions.**
  `BankIntegrationService::connectUserToBank` authenticates outside the
  transaction, writes the connection row in a short transaction, and
  syncs after commit (fail-soft: a failed initial sync keeps the
  connection and logs a warning; the sync is retryable).
  `createBankAccount` calls the provider outside the transaction and
  writes the local row inside it. `initiateInterBankTransfer` does the
  balance check and provider transfer outside the transaction; only the
  local `bank_transfers` insert is transactional.

## 5. Iranian rails — honesty note

Nothing in this phase pretends ISO 20022 or US rails (FedNow / Fedwire /
RTP / SEPA / SWIFT) give live Iranian connectivity. Iranian rails
(SATNA / PAYA / PEL / card-to-card) remain sandbox-only, disabled in
production, with no live endpoints — exactly as in Phase 0–1. The payment
engine is extensible for Iranian APIs: a future Iranian provider only
needs to implement `ExternalProviderInterface` (+ `AccountProviderInterface`
for account opening) and register in `ProviderRegistry`; the intent model,
state machine and reconciliation already handle it. Until a provider is
contracted and wired, any Iranian payment attempt fails closed with an
explicit `unknown` / `RAIL_UNAVAILABLE`, never a fake success.

## 6. Known limitations

1. Cumulative refund tracking across multiple partial refunds is not
   enforced (see §3.2).
2. `PaymentService` (legacy mock) is unreferenced by live paths; kept
   for backward compatibility.
3. GraphQL `SendPaymentMutation` surfaces `RailUnavailableException` as
   a GraphQL error (out of scope for this phase).
4. `connectUserToBank` keeps the connection when the initial sync fails;
   the sync must be retried (it is retryable).
5. No production bank provider is contracted: production account opening
   and production payments have no live provider to call. The sandbox
   adapter is the only registered provider.
6. The `PaymentIntent` model has no factory (`HasFactory` was removed);
   tests create intents through `PaymentIntentService`.
7. The default `phpunit.xml` enforces a 10 s per-test limit that the
   400-migration setup can exceed under CPU throttle; run the touched
   suites with `--configuration=phpunit.ci.xml` (120 s limit). A
   pre-existing failure of `PaymentTransferServiceTest` under the default
   config (migration `2026_11_20_130000`, `alter table "__temp__trades"`)
   is unrelated to this work.

## 7. Tests

New tests (all written before/with the fixes, Pest):

| File | Tests |
| --- | --- |
| `tests/Domain/Banking/Providers/ProviderKernelTest.php` | Registry, sandbox refusal, provider outcomes, circuit breaker, webhook verifier (incl. replay + stale timestamp), request signer, idempotency store, rate limiter, audit-logger redaction |
| `tests/Unit/Domain/PaymentRails/Services/RailTransmissionGateTest.php` | Disabled rails throw, simulation records `INITIATED` + untransmitted, RTP boundary `1000000.01` rejected, router fails closed, `getAvailableRails` |
| `tests/Domain/Payment/Services/PaymentIntentServiceTest.php` | Create / idempotency / validation, lifecycle, unknown resolution, reconcile, refund rules, normalized shape, reversal |
| `tests/Feature/Banking/BankIntegrationHardeningTest.php` | Provider calls run outside transactions (depth equals the test-harness baseline), exact transfer `10.10` → `10.1000`, `0.10 + 0.20 = 0.3000`, `BankConnection::fromArray` accepts `user_uuid` |
| `tests/Feature/Banking/BankAccountOpeningReconciliationTest.php` | Pending → approved over two passes, missing external id → manual review, disagreeing provider never approves |
| `tests/Domain/CardIssuance/Services/SpendLimitExactTest.php` | `19.99` → 1999 cents exactly, limit boundary in minor units |

Updated tests: `tests/Domain/Banking/Services/BankRoutingServiceTest.php`
(string amounts), `tests/Unit/Domain/Payment/Services/SandboxPaymentServiceTest.php`
(persisted intent in `PROCESSING` instead of a fabricated id).

Results (all with `--configuration=phpunit.ci.xml`):

| Suite | Result |
| --- | --- |
| Banking (Domain + Unit + Feature) | 196 passed |
| Payment (Domain + Unit) | 134 passed, 1 risky |
| PaymentRails (Unit) | 96 passed |
| CardIssuance (Domain + Unit + Feature) | 454 passed |
| FinancialInstitution (Domain + Unit) + AccountProvisioning (Feature) | 228 passed |
| API Banking + API CardIssuance | 44 passed |
| **Total** | **1,152 passed, 1 risky, 0 failed** |

PHPStan on all touched files: **no errors**. `php-cs-fixer` applied to
the touched files (26 files reformatted).

## 8. Files changed (uncommitted)

New: `app/Domain/Banking/Providers/{Contracts,Exceptions,Support}/`,
`config/providers.php`, `app/Domain/Payment/{Enums,Models/PaymentIntent.php,
Services/PaymentIntentService.php}`, `app/Domain/PaymentRails/{Exceptions/
RailUnavailableException.php, Services/RailTransmissionGate.php}`,
`database/migrations/2026_11_24_000005_create_provider_payment_intents_table.php`,
`docs/BANKING_INTEGRATION_DESIGN.md` (this file), the six new test files
above.

Modified: `Banking` — `Connectors/SandboxBankAdapter.php`,
`Contracts/IBankIntegrationService.php`, `Models/BankConnection.php`,
`Services/{BankAccountOpeningService, BankAccountProviderFactory,
BankIntegrationService, BankRoutingService, IntelligentRoutingService,
SepaCreditTransferService, SepaDirectDebitService}.php`;
`CardIssuance/Services/{JitFundingService, SpendLimitEnforcementService}.php`;
`FinancialInstitution/Services/PartnerBillingService.php`;
`Payment/Services/{PaymentProcessingService, ProductionPaymentService,
SandboxPaymentService}.php`, `Payment/Exceptions/RefundNotAllowedException.php`;
`PaymentRails/{Routes/api.php, Services/FedNowService.php,
Services/FedwireService.php, Services/NachaFileGenerator.php,
Services/PaymentRailRouter.php, Services/RtpService.php}`;
`app/Providers/BankIntegrationServiceProvider.php`; the two updated test
files above.

Not touched by this phase (present in the shared working tree from sibling
work — do not attribute to this phase): `Asset`, `International`,
`SafeDeal`, `Wallet`, `Treasury`, `bootstrap/providers.php`,
`config/wallet.php`, and their tests/migrations/docs.
