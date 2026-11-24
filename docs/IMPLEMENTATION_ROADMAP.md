# Implementation Roadmap — FinTech Platform (FinAegis)

> **Baseline:** `v7.18.2`, commit `b8e521f`, branch `feat/fintech-audit-implementation`.
> **Companion document:** `docs/TARGET_ARCHITECTURE.md` — this roadmap is the sequenced
> plan for reaching it. It does not change the stack (Laravel 12 / PHP `^8.4`, DDD,
> spatie event sourcing, Filament) and it does not rewrite existing domains.
>
> **Ordering principle:** financial correctness > security > dependencies (provider seams)
> > banking rails > core features > UX. A phase does not start until the phase it depends
> on meets its exit criteria. **Nothing in this roadmap invents an external endpoint,
> credential, or fee schedule.**
>
> **Status labels used throughout:**
> - ✅ **Implementable now** — code + tests can be written with what is in the repo today.
> - ⛔ **Blocked — external** — cannot be completed until a named external input arrives
>   (bank API docs, sandbox credentials, signed contract, regulatory approval, licensed
>   provider). The phase lists exactly what is blocked and what *can* be done meanwhile.

## Phase map

| Phase | Name | Priority | Status | Depends on |
|---|---|---|---|---|
| 0 | Ledger & financial correctness hardening | P0 | ✅ Implementable now | — |
| 1 | Security hardening | P0 | ✅ Implementable now | 0 (money rules first) |
| 2 | Provider seams & dependency cleanup | P1 | ✅ Implementable now | 0, 1 |
| 3 | Banking rails (incl. Iranian rails) | P1 | ✅ sandbox now / ⛔ production blocked | 2 |
| 4 | Core: accounts, payments, cards, exchange | P1 | ✅ Implementable now | 0–2 |
| 5 | Compliance: KYC / AML / liveness / video | P1 | ✅ core now / ⛔ licensed vendors blocked | 2, 4 |
| 6 | Custody & blockchain | P2 | ✅ sandbox now / ⛔ licensed custody blocked | 2, 3 |
| 7 | UX: Filament admin, mobile/web surfaces | P2 | ✅ Implementable now | 4, 5 |
| 8 | Production hardening & launch readiness | P2 | ✅ Implementable now | 0–7 |

---

## Phase 0 — Ledger & financial correctness hardening ✅

**Goal:** make it impossible for the system to create, lose, or double-count money,
including under concurrency, retries, and provider timeouts. This is the foundation
everything else stands on.

### Deliverables

1. **Canonical transaction state machine** (`TARGET_ARCHITECTURE.md` §3.3):
   `initiated / pending / processing / successful / failed / reversed / unknown /
   requires-manual-review`. One shared enum (e.g.
   `App\Domain\Shared\Enums\CanonicalTransactionState`) replacing the loose status
   strings in `PaymentTransaction` (`pending`/`completed`/`failed`), payment deposits/
   withdrawals, bank transfers, and custodian transfers. Every adapter maps its vendor
   statuses onto it; unmappable statuses map to `unknown`, never to `failed`.
2. **Timeout ≠ failure, enforced in code:** any provider timeout / ambiguous response /
   unclassifiable response sets `unknown` and starts the resolution sweeper; the balance
   hold is **not** released. Add the sweeper job that re-queries `unknown` intents older
   than the provider SLA via `get*Status()`.
3. **Idempotency everywhere:** `Idempotency-Key` header accepted on all mutating API
   routes; `idempotency_key` UNIQUE column on `payment_deposits`,
   `payment_withdrawals`, `payment_transactions`, `bank_transfers`,
   `custodian_transfers`, card top-ups; duplicate key returns the original intent
   without re-execution. Keys forwarded to providers on every mutating adapter call.
4. **Explicit held balances:** add `held_balance` (bigint minor units) to
   `account_balances`; reservation (hold) before any provider call, release/consume on
   outcome. No provider call may run against unreserved funds.
5. **Row-level locking / optimistic concurrency:** `SELECT … FOR UPDATE` on the account
   row inside the balance-mutation transaction; aggregate-version conflict on
   event-sourced writes retries the command instead of overwriting.
6. **Ledger currency discipline:** `JournalLine.currency` enforced per line (no silent
   default from `config('ledger.default_currency')` for multi-currency entries);
   rounding rule defined per currency in `PostingRuleEngine`, rounding differences
   posted to a dedicated rounding account.
7. **Reversal discipline:** every reversal goes through `LedgerService::reverse()`
   (mirror entry + `reversed_by` link); audit that no code path updates a `POSTED`
   entry in place. Add a test that attempts it and fails.
8. **Daily reconciliation, generalised:** extend the existing custodian pattern
   (`DailyReconciliationService`, `ReconciliationService`, `ReconciliationReport`,
   `PerformDailyReconciliation`) to payments, banking, and cards: ledger vs provider
   statement vs bank statement; mismatches → `requires-manual-review`, never
   auto-corrected.
9. **Ledger driver decision input:** load/consistency test of `EloquentDriver` vs
   `TigerBeetleDriver` (`LEDGER_DRIVER`) to inform the production choice (decision
   owned by Phase 8, not this phase).

### Files to touch / create

- Create: `app/Domain/Shared/Enums/CanonicalTransactionState.php`,
  `app/Domain/Shared/Services/IdempotencyService.php` (or middleware),
  `app/Domain/Payment/Jobs/ReconcileUnknownIntentsJob.php`,
  `app/Domain/Ledger/Services/ReconciliationService.php` (generalise existing),
  migrations for `idempotency_key` + `held_balance` + `unknown_since_at` +
  `provider_reference` columns.
- Touch: `app/Domain/Ledger/Services/LedgerService.php` (currency enforcement),
  `app/Domain/Ledger/Services/PostingRuleEngine.php` (rounding),
  `app/Domain/Account/Actions/CreditAccount.php`, `DebitAccount.php`,
  `app/Domain/Account/Aggregates/TransactionAggregate.php`,
  `TransferAggregate.php`, `app/Domain/Payment/Aggregates/*`,
  `app/Domain/Payment/Models/*`, `app/Domain/Banking/Models/BankTransfer.php`,
  `app/Domain/Custodian/Models/CustodianTransfer.php`,
  `app/Domain/*/Routes/api.php` (idempotency middleware),
  `app/Domain/Custodian/Console/Commands/PerformDailyReconciliation.php`.

### Dependencies

- None external. Needs only the existing `LedgerService`, `LedgerDriverInterface`,
  event store, and the custodian reconciliation pattern to generalise.

### Exit criteria

- Pest tests: double-posting the same idempotency key moves money once; concurrent
  debits cannot overdraw (row-lock test); a simulated provider timeout leaves the
  intent `unknown` with the hold intact; a reversal creates a mirror entry and the
  original is `REVERSED`; trial balance is zero after every test scenario.
- `phpstan` + `php-cs-fixer` clean per repo `AGENTS.md` (run `./vendor/bin/pest
  --parallel` before any commit — docs-only work in this branch is exempt, code
  phases are not).

---

## Phase 1 — Security hardening ✅

**Goal:** close the security gaps that would turn a correctness bug into a loss or a
breach. No new money features ship before this phase.

### Deliverables

1. **MFA + step-up auth:** MFA mandatory for Filament admin, money-approval, API-key
   management, and provider credential rotation (Fortify 2FA already present via
   `TwoFactorAuthController`). Add step-up confirmation (password/TOTP re-check) for
   withdrawals above a configured threshold.
2. **Four-eyes approval:** above-threshold money instructions require a second,
   different approver; initiator cannot self-approve. Enforced in the domain service,
   not only in Filament.
3. **RBAC completion:** audit every route against `spatie/laravel-permission`;
   permissions named `{domain}.{resource}.{action}` (e.g.
   `payment.withdrawals.approve`, `ledger.entries.view`,
   `custodian.reconciliation.run`); remove any hard-coded admin checks; Filament
   resources enforce the same permissions as the API.
4. **Webhook verification audit:** every inbound webhook controller verified to check
   the signature on the **raw body** with `hash_equals()` *before* parsing/state change
   (pattern already exists in `Custodian\WebhookVerificationService`,
   `FinCardWebhookController`, `KycProviderInterface::getWebhookValidator()`).
   Add a test per provider that a bad signature is rejected with no state change.
5. **Secrets & data hygiene sweep:** grep-enforced rules — no CVV/PIN/full-PAN/private
   key columns, no secrets in logs or exceptions; `ops:verify-env` extended to fail
   the deploy if any production provider credential is a demo/placeholder value.
6. **Rate-limit tuning + outbound throttling:** confirm `config/rate_limiting.php`
   limits (auth 3/min, transaction 30/min, query 100/min) on all money routes;
   per-provider outbound throttling so retries cannot trigger vendor bans.
7. **Audit trail completeness:** money movement, approval, permission change, and
   credential rotation all produce append-only audit events, visible read-only in
   Filament.

### Files to touch / create

- Touch: `config/fortify.php`, `config/permission.php`, `config/rate_limiting.php`,
  `app/Domain/*/Routes/api.php`, `app/Filament/**` (permission gates),
  `app/Domain/Payment/Services/*`, `app/Domain/Custodian/Services/WebhookVerificationService.php`,
  `app/Domain/Webhook/Services/*`, `app/Domain/CardIssuance/Http/Controllers/*WebhookController.php`.
- Create: `app/Domain/Shared/Services/StepUpAuthService.php`,
  `app/Domain/Payment/Services/ApprovalService.php` (four-eyes),
  tests under `tests/Domain/*/Security*`.

### Dependencies

- Phase 0 (approval rules depend on the canonical state machine and idempotency).
- No external dependency.

### Exit criteria

- Security test suite: bad webhook signature → no state change; self-approval rejected;
  step-up required above threshold; a token with `scope:read` cannot mutate; demo
  credentials fail `ops:verify-env` in production mode.

---

## Phase 2 — Provider seams & dependency cleanup ✅

**Goal:** every external system behind exactly one interface, selected by config —
so swapping a vendor is a config change, and a missing vendor is a demo adapter,
never a code branch in domain logic.

### Deliverables

1. **One canonical name per seam** (decision from `TARGET_ARCHITECTURE.md` §11.1):
   rename-or-alias `IBankConnector` → `BankProviderInterface`,
   `ICustodianConnector` → `CustodyProviderInterface`,
   `CardIssuerInterface` → `CardProviderInterface`,
   `IExchangeRateProvider` → `ExchangeRateProviderInterface`,
   `KycProviderInterface` → `IdentityVerificationProviderInterface`,
   `PaymentServiceInterface` → provider-shaped `PaymentProviderInterface` behind the
   existing application service. **One name only — no duplicate seams.**
2. **New seams (interfaces + DTOs + demo adapters only):**
   `AccountProviderInterface`, `BlockchainProviderInterface`,
   `MarketDataProviderInterface`, `VideoMeetingProviderInterface`
   (`TARGET_ARCHITECTURE.md` §4.2, §4.7, §4.9, §4.10). Each ships with a
   `Demo*`/`Mock*` adapter implementing the full state machine locally.
3. **DTO-ify provider boundaries:** replace the `array $data` shapes in
   `PaymentServiceInterface` (and equivalents) with typed DTOs
   (`spatie/laravel-data` is already a dependency); money as int minor units +
   currency on every boundary, never float.
4. **Outbox pattern:** generalise `WebhookDelivery` into a transactional outbox so
   "event stored but notification lost" cannot happen; relay worker delivers webhooks
   and provider notifications from the outbox.
5. **Registry unification:** every provider family follows the established pattern
   (`ExchangeRateProviderRegistry`, `KycProviderRouter`, `CustodianRegistry`):
   config registration (`driver` + `enabled` + env keys), capability + priority
   selection, health monitoring, circuit breaker (generalise
   `Custodian\CircuitBreakerService`).
6. **Vendor SDK isolation audit:** no `use` of a vendor SDK outside
   `app/Domain/*/Adapters|Connectors|Providers`; CI grep check to keep it that way.

### Files to touch / create

- Create: `app/Domain/Banking/Contracts/BankProviderInterface.php` (rename/alias),
  `app/Domain/Custodian/Contracts/CustodyProviderInterface.php`,
  `app/Domain/CardIssuance/Contracts/CardProviderInterface.php`,
  `app/Domain/Exchange/Contracts/ExchangeRateProviderInterface.php`,
  `app/Domain/Compliance/Kyc/Contracts/IdentityVerificationProviderInterface.php`,
  `app/Domain/Payment/Contracts/PaymentProviderInterface.php`,
  `app/Domain/AccountProvisioning/Contracts/AccountProviderInterface.php`,
  `app/Domain/CrossChain/Contracts/BlockchainProviderInterface.php` (or under a new
  `app/Domain/Blockchain/`),
  `app/Domain/Exchange/Contracts/MarketDataProviderInterface.php`,
  `app/Domain/Compliance/Contracts/VideoMeetingProviderInterface.php`,
  demo adapters for each new seam, `app/Domain/Shared/Outbox/*`.
- Touch: all existing adapters (rename only — no behaviour change),
  `config/exchange.php`, `config/custodian.php`, `config/custodians.php`,
  `config/kyc.php`, `config/cardissuance.php`, `config/baas.php`,
  `config/payment_rails.php`, service providers binding interfaces → adapters.

### Dependencies

- Phases 0–1 (interfaces carry the canonical state + idempotency contract).
- No external dependency: demo adapters need no vendor.

### Exit criteria

- `grep` proves no vendor SDK import outside adapter directories; every seam has a
  demo adapter passing the same contract test suite as production adapters will;
  swapping an adapter is a config-only change (test: flip env, same tests pass).

---

## Phase 3 — Banking rails ✅ sandbox now / ⛔ production blocked

**Goal:** real money in and out over bank rails, with reconciliation — first in
sandbox, then production per rail.

### Deliverables

1. **Rail enum + config:** `TransferRail` enum (`sepa`, `swift`, `ach`, `fedwire`,
   `rtp`, `fednow`, `satna`, `paya`, `pel`); `config/payment_rails.php` entries for
   every rail, all `enabled => false` by default; a rail is enabled only with real
   credentials + named owner + reconciliation configured.
2. **SEPA hardening (existing capability):** `SepaCreditTransferService` /
   `SepaDirectDebitService` / `SepaMandateService` moved behind
   `BankProviderInterface::initiateTransfer()` with idempotency key + canonical
   state; mandate lifecycle tested end-to-end in sandbox.
3. **SWIFT / ACH / Fedwire / RTP / FedNow:** one adapter per rail behind the same
   interface, sandbox-first, following the `config/payment_rails.php` gating already
   in place (all currently `enabled => false`).
4. **Intelligent routing:** `BankRoutingService` / `IntelligentRoutingService` select
   rail by currency, amount, cost, and cutoff (`ACH_CUTOFF_TIME` precedent); routing
   decisions are logged and auditable.
5. **Iranian rails — sandbox only (see note below).**

### Files to touch / create

- Create: `app/Domain/Shared/Enums/TransferRail.php`,
  `app/Domain/Banking/Adapters/SandboxIranianRailAdapter.php`,
  `app/Domain/Banking/Adapters/{Sepa,Swift,Ach,Fedwire,Rtp,FedNow}Adapter.php`
  (as needed — several exist in service form today and are moved behind the interface),
  migrations adding `rail` + `idempotency_key` to `bank_transfers`.
- Touch: `app/Domain/Banking/Services/BankTransferService.php`,
  `SepaCreditTransferService.php`, `SepaDirectDebitService.php`,
  `BankRoutingService.php`, `IntelligentRoutingService.php`,
  `config/payment_rails.php`, `app/Domain/Banking/Contracts/IBankConnector.php`
  (→ `BankProviderInterface`, Phase 2).

### Dependencies

- Phase 2 (provider seams). Phase 0 (idempotency, reconciliation).

### ⛔ Blocked — external (production rails)

| Item | Blocked on | What can be done meanwhile |
|---|---|---|
| Production SEPA/SWIFT/ACH/Fedwire/RTP/FedNow | Bank partnership / sponsor-bank agreement, production API credentials, account opening | Sandbox adapters, routing, reconciliation — all ✅ now |
| **SATNA / PAYA / PEL (Iranian rails)** | **Official bank / Central Bank of Iran API documentation, sandbox credentials, settlement-account arrangement, regulatory approval path** | Rail enum cases (reserved), config entries (disabled), `SandboxIranianRailAdapter` simulating the canonical state machine locally — all ✅ now |

> **Iranian payment rails note — no invented endpoints.** There is no SATNA, PAYA,
> PEL, Shaparak, or Shetab endpoint, message format, credential, or fee schedule
> anywhere in this codebase (verified by search at roadmap time). This roadmap
> therefore **does not specify any Iranian rail endpoint, field, or behaviour**.
> The only Iranian-rail deliverables are the reserved enum cases, the disabled config
> entries, and a sandbox adapter that exercises the *platform's* state machine
> (initiate → pending → processing → successful/failed/unknown) without touching any
> real network. A production Iranian adapter may only be written once official
> documentation and sandbox credentials are in hand, and its first version is
> sandbox-only behind the same `BankProviderInterface`. Any document, ticket, or
> estimate that names a SATNA/PAYA/PEL URL or payload before that point is inventing
> it and must be rejected.

### Exit criteria

- Each rail: sandbox end-to-end (initiate → webhook/status → ledger → reconciliation)
  green; production enablement is a separate, per-rail checklist (credentials, owner,
  reconciliation, runbook) signed off outside this roadmap.

---

## Phase 4 — Core: accounts, payments, cards, exchange ✅

**Goal:** the user-facing money features, built on the hardened ledger and seams.

### Deliverables

1. **Accounts:** account opening/closing/freezing via `Account` aggregates; per-asset
   balances with holds (§Phase 0); internal transfers (`TransferAggregate`) with
   instant settlement; turnover/limits (`UpdateTurnover`, `AccountLimitHit`).
2. **Payments:** deposit/withdrawal intents over `PaymentProviderInterface`
   (Stripe, HyperSwitch, Paysera, OpenBanking, demo); saved payment methods;
   refunds via reversal entries only; payment-method tokenisation (no PAN storage).
3. **Cards:** card lifecycle over `CardProviderInterface` (create/freeze/unfreeze/
   cancel, Apple/Google Pay provisioning via `getProvisioningData`, spend limits,
   top-up with idempotency + status query); authorisation decisions
   (`AuthorizationDecision`); 3-DS flow; card webhooks verified before processing.
4. **Exchange:** order placement/matching (`Order`, `OrderBook`, `PlaceOrderActivity`,
   `MatchOrderActivity`, `ValidateOrderActivity`), quotes locked at order time,
   fees (`FeeCalculator`, `FeeTierService`), liquidity pools; FX via
   `ExchangeRateProviderRegistry` with fallback and stale-rate rejection
   (`RefreshExchangeRatesJob`).
5. **API surface:** versioned routes (`routes/api.php` orchestrator +
   `app/Domain/*/Routes/api.php`, v1/v2 prefixes already in use), OpenAPI
   regeneration (`php artisan l5-swagger:generate`), scope + rate-limit on every new
   route.

### Files to touch / create

- Touch: `app/Domain/Account/Actions/*`, `Aggregates/*`, `Models/Account.php`,
  `AccountBalance.php`, `app/Domain/Payment/Services/*`,
  `app/Domain/CardIssuance/Adapters/*`, `Services/FinCardAccountService.php`,
  `app/Domain/Exchange/Services/OrderService.php`, `ExchangeService.php`,
  `app/Domain/Exchange/Activities/*`, `app/Domain/*/Routes/api.php`.
- Create: card top-up status query + spend-limit endpoints, payment-method
  tokenisation service, exchange quote-lock value object.

### Dependencies

- Phases 0–2. Card production issuance additionally ⛔ blocked (see Phase 4 note
  below); demo/sandbox cards are ✅ now.

### ⛔ Blocked — external (production cards only)

| Item | Blocked on |
|---|---|
| Production card issuance (Marqeta / Rain / FinCard / other) | Commercial agreement + production credentials + card-program approval. Adapters exist (`MarqetaCardIssuerAdapter`, `RainCardIssuerAdapter`, `FinCardCardIssuerAdapter`); production enablement is contracting, not code. |

### Exit criteria

- Pest coverage ≥ 50% on new code (repo rule); happy path + timeout + duplicate
  webhook + reversal scenarios green for each feature; OpenAPI docs regenerated.

---

## Phase 5 — Compliance: KYC / AML / liveness / video ✅ core now / ⛔ licensed vendors blocked

**Goal:** onboarding that a regulator would accept, with the platform storing verdicts —
never raw biometrics or documents.

### Deliverables

1. **KYC orchestration:** per-purpose provider routing (`KycProviderRouter`,
   `KycPurpose`) over `IdentityVerificationProviderInterface`; hosted-link flow
   (`getHostedLink`), status normalisation (`not_started/pending/approved/rejected`),
   webhook normalisation; per-provider partitioning of KYC state.
2. **AML / sanctions:** `AmlScreeningAggregate` + `CompositeScreeningAdapter`
   (Chainalysis / GoPlus / Internal) on every onboarding and on transaction
   monitoring thresholds; alerts → cases (`AlertManagementService`,
   `ComplianceAlertService`); SAR drafting (`SuspiciousActivityReportService`);
   EDD flow (`EnhancedDueDiligenceService`).
3. **Liveness / biometric (target interface, sandbox first):**
   `verifyLiveness()` / `verifyDocument()` on `IdentityVerificationProviderInterface`
   (§4.5); evidence handled by the vendor; platform stores verdict + reference only.
4. **Video KYC (target interface, sandbox first):** `VideoMeetingProviderInterface`
   (§4.10) with a Jitsi-compatible demo adapter; recordings opt-in, encrypted,
   retention-limited, deletable; the KYC *verdict* still comes from §5.1, video is
   evidence only.
5. **GDPR:** erasure/export flows (`GdprService`) extended to every new PII store
   added by Phases 3–4.

### Files to touch / create

- Touch: `app/Domain/Compliance/Kyc/Providers/OndatoKycProvider.php`,
  `BridgeKycProvider.php`, `Registries/KycProviderRouter.php`,
  `app/Domain/Compliance/Services/KycService.php`, `EnhancedKycService.php`,
  `IdentityVerificationService.php`, `BiometricVerificationService.php`,
  `AmlScreeningService.php`, `app/Domain/Compliance/Aggregates/*`.
- Create: `app/Domain/Compliance/Kyc/Providers/DemoLivenessProvider.php` (adapter
  implementing the new liveness methods in sandbox),
  `app/Domain/Compliance/Video/Adapters/DemoVideoMeetingAdapter.php`,
  `app/Domain/Compliance/Video/Contracts/` (or under `Kyc/`).

### Dependencies

- Phases 2, 4 (KYC gates money features; money features define what must be gated).

### ⛔ Blocked — external

| Item | Blocked on | Meanwhile |
|---|---|---|
| Production KYC/liveness vendor (Ondato / Bridge / other) | Signed contract, production credentials, DPA, data-residency confirmation | Sandbox providers + demo liveness adapter ✅ now |
| Production sanctions vendor (Chainalysis / other) | Commercial agreement + credentials | `InternalSanctionsAdapter` + `GoPlusAdapter` ✅ now |
| Video-KYC vendor / Jitsi deployment | Vendor selection (self-hosted Jitsi vs managed video-KYC provider), recording-retention policy | Demo video adapter ✅ now; **no Jitsi endpoint is assumed — the interface is vendor-neutral** |

### Exit criteria

- KYC happy path + rejection + webhook-replay scenarios green in sandbox; sanctions
  screening runs on onboarding and on threshold transactions; no raw biometric or
  document bytes stored by the platform (verified by a data-hygiene test).

---

## Phase 6 — Custody & blockchain ✅ sandbox now / ⛔ licensed custody blocked

**Goal:** safeguarded assets with provable segregation, and chain access that cannot
lose funds to a timeout.

### Deliverables

1. **Custody over `CustodyProviderInterface`:** transfers with idempotency key,
   settlement status, balance synchronisation as a *check* (never an overwrite),
   circuit breaker + fallback (`CircuitBreakerService`, `FallbackService`,
   `RetryService`), health monitoring (`CustodianHealthMonitor`), daily reconciliation
   (already exists — extend to all custodians).
2. **Key management separation:** signing only via `KeyManagement\HsmProviderInterface`
   (AWS KMS / Azure Key Vault / Demo); custody providers never receive private keys;
   Shamir shares for recovery (`ShamirServiceInterface`).
3. **Blockchain provider seam:** `BlockchainProviderInterface` (§4.7) with demo
   adapter; balance/status/fee-estimation/broadcast; confirmations policy per chain;
   bridge adapters (`BridgeAdapterInterface`: Axelar, CCTP, LayerZero, Wormhole) stay
   separate and sit *behind* the chain seam.
4. **Stablecoin / Ramp:** reserve accounting via the ledger (every mint/burn is a
   journal entry); ramp providers behind `RampProviderInterface` (existing).

### Files to touch / create

- Touch: `app/Domain/Custodian/Services/*`, `Connectors/*`,
  `app/Domain/Custodian/Contracts/ICustodianConnector.php` (→ `CustodyProviderInterface`),
  `app/Domain/KeyManagement/HSM/*`, `app/Domain/CrossChain/Services/Adapters/*`,
  `app/Domain/Ramp/Contracts/RampProviderInterface.php`.
- Create: `app/Domain/Blockchain/Contracts/BlockchainProviderInterface.php` +
  `Adapters/DemoBlockchainProvider.php`, settlement-status support on
  `CustodianTransfer`, confirmations policy config.

### Dependencies

- Phases 2–3 (seams, rails). Chain work is independent of Iranian rails.

### ⛔ Blocked — external

| Item | Blocked on | Meanwhile |
|---|---|---|
| Licensed production custodian | Custody licence / partnership, contract, production credentials, segregation audit | `DemoBankConnector` / `MockBankConnector` + Paysera/Santander sandbox connectors ✅ now |
| Production HSM (AWS KMS / Azure Key Vault) | Cloud account + KMS/Key Vault provisioning, key ceremony | `DemoHsmProvider` ✅ now (never in production) |

### Exit criteria

- Custodian transfer timeout → `unknown` → resolved by status query, never double-sent
  (idempotency test against the demo adapter); daily reconciliation green across all
  configured custodians; no private key material in the database (hygiene test).

---

## Phase 7 — UX: Filament admin, mobile/web surfaces ✅

**Goal:** operators and users can do everything the platform supports — with the same
permissions and the same domain code as the API. No admin bypass.

### Deliverables

1. **Filament admin (existing `app/Filament/`):** resources for ledger entries
   (read-only + reversal action), reconciliation queue (`requires-manual-review`
   resolution with four-eyes), compliance alerts/cases, provider health + credential
   status, KYC review, card/customer lookup. Admin behind RBAC + MFA + IP allow-list.
2. **User surfaces:** account/transfer/payment/card/exchange screens consuming the
   versioned API only (no direct domain calls from Blade/Livewire); consistent
   error vocabulary from the canonical state machine (`unknown` shown as "checking
   with your bank — we'll update you", never as "failed").
3. **Notifications:** transaction, KYC, and reconciliation events → user + operator
   notifications (queued, outbox-backed); webhook delivery status visible to operators.
4. **Accessibility / i18n:** Persian (RTL) + English for Iranian-market surfaces where
   applicable; Jalali calendar for Iranian-market displays (existing convention for
   Iranian-market apps).

### Files to touch / create

- Touch: `app/Filament/**`, `resources/views/**`, `app/Domain/*/Http/Controllers/*`,
  `routes/web.php`, `routes/api*.php`.
- Create: Filament resources/pages listed above, notification templates.

### Dependencies

- Phases 4–5 (the features being surfaced must exist first). Filament work can start
  in parallel with Phase 4 for read-only views.

### Exit criteria

- Every admin action maps to an API-equivalent domain call with the same permission
  check (test: same permission matrix passes for API and Filament); manual-review
  queue is operable end-to-end by a non-developer.

---

## Phase 8 — Production hardening & launch readiness ✅

**Goal:** prove the system is safe to run with real money, then launch rail by rail.

### Deliverables

1. **Load + consistency testing:** ledger driver decision (Eloquent vs TigerBeetle,
   Phase 0 input); concurrent-transfer soak test; projector replay test (rebuild read
   models from events and diff); backup/restore drill (event store first).
2. **Observability:** Horizon dashboards + alerts (queue depth, `unknown`-intent age,
   reconciliation mismatches, provider health); Prometheus metrics; log hygiene
   (no PII/secrets — enforced by test).
3. **Deployment:** production compose/Helm (`helm/` exists — complete it), managed
   MySQL/Redis, scheduler as a single cron container, Horizon supervisors per queue,
   `ops:verify-env` in the deploy pipeline, secret rotation runbook, incident
   runbooks (provider down, reconciliation mismatch, `unknown` backlog).
4. **Compliance pack:** audit-trail export, GDPR erasure proof, data-retention policy,
   penetration test, dependency audit.
5. **Phased launch:** one rail + one provider at a time, behind Pennant flags, with
   reconciliation running from day one; demo direct-funding OFF in production
   (`LEDGER_DIRECT_FUNDING_ENABLED=false`, no acknowledgement override).

### Files to touch / create

- Touch: `helm/`, `docker-compose.yml`, `docker/Dockerfile`, `config/horizon.php`,
  `config/queue.php`, `config/backup.php`, `config/pennant.php`, `routes/console.php`
  (scheduler), `docs/operations/*`.
- Create: runbooks under `docs/operations/`, launch checklists per rail/provider.

### Dependencies

- Phases 0–7. External: every production credential/contract listed as ⛔ above.

### Exit criteria

- Soak + replay + restore drills pass; `unknown`-intent SLO defined and alerted;
  launch checklist signed per rail; first production rail runs one week with zero
  unreconciled entries before the second rail is enabled.

---

## Blocked register (single view)

| # | Item | Blocked on | Earliest phase it unblocks |
|---|---|---|---|
| B1 | SATNA / PAYA / PEL production adapter | Official CBI/bank API docs + sandbox credentials + settlement arrangement + regulatory approval | Phase 3 (production Iranian rails) |
| B2 | Production card issuance | Card-program agreement + production credentials | Phase 4 (production cards) |
| B3 | Production KYC / liveness vendor | Contract + production credentials + DPA | Phase 5 (production onboarding) |
| B4 | Production sanctions vendor | Commercial agreement + credentials | Phase 5 (production screening) |
| B5 | Video-KYC vendor / Jitsi deployment | Vendor selection + recording-retention policy | Phase 5 (production video KYC) |
| B6 | Licensed production custodian | Licence/partnership + contract + segregation audit | Phase 6 (production custody) |
| B7 | Production HSM | Cloud KMS/Key Vault provisioning + key ceremony | Phase 6 (production signing) |
| B8 | Production bank rails (SEPA/SWIFT/ACH/Fedwire/RTP/FedNow) | Sponsor-bank agreement + production credentials | Phase 3 (production rails) |

**Rule for all blocked items:** the sandbox/demo implementation ships first and the
blocked item is tracked here — never by inventing the external interface. When an
external input arrives, the work is: read the official docs, implement the adapter
behind the existing interface, run the shared contract test suite, enable by config.
No domain code changes should be needed; if they are, the seam was wrong and Phase 2
must be revisited first.

## How to use this roadmap

1. Work top-down: do not start Phase 4 features while Phase 0 exit criteria are red.
2. Each phase lands as small, reviewable PRs on `feat/fintech-audit-implementation`
   (or a phase branch), with Pest tests, PHPStan, and `php-cs-fixer` per repo
   `AGENTS.md`. Docs-only changes (like this roadmap) are exempt from the test run;
   code changes are not.
3. Update this file when a blocked item unblocks: move it from the register into the
   phase, with the date and the source of the docs/credentials — and never backfill an
   endpoint that was not in the official documentation.
