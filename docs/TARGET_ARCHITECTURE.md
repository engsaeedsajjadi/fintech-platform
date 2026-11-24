# Target Architecture — FinTech Platform (FinAegis)

> **Status:** Target (normative for new work). **Baseline:** `v7.18.2`, commit `b8e521f`,
> branch `feat/fintech-audit-implementation`. **Stack:** Laravel 12 / PHP `^8.4`,
> DDD under `app/Domain/*`, `spatie/laravel-event-sourcing ^7.7`,
> `spatie/laravel-permission ^8.0`, Filament 3 admin, Horizon 5.44, Sanctum + Passport.
>
> **Rule of this document:** it describes where the *existing* code is going. It does not
> propose a rewrite or a different stack. Where a target interface already exists under a
> different name, the existing interface is named and the target name is the rename/alias —
> never a second, parallel seam. Where no provider exists yet (Iranian rails, licensed
> custody, liveness, video KYC), the target is a **sandbox-only adapter behind the same
> interface** until official docs, credentials, or regulatory approval exist. No endpoint,
> credential, or fee schedule is invented in this document.

## 0. Principles

1. **Financial correctness before features.** A wrong balance is a defect; a missing
   feature is a backlog item. Every money movement is a balanced, immutable journal entry
   (see §6) before any provider is called.
2. **The ledger is the source of truth; providers are replaceable.** All external systems
   (banks, card issuers, custodians, exchanges, KYC vendors) sit behind one interface per
   capability (§4). Domain code never calls a vendor SDK directly.
3. **Idempotency and reconciliation are features, not afterthoughts.** Every mutating
   operation carries an idempotency key; every provider outcome is reconciled against the
   ledger daily (§6.7, §3.5).
4. **Timeout ≠ failure.** A provider call that times out is `unknown`, never `failed`.
   Unknown outcomes are resolved only by a status query, a webhook, or manual review —
   never by retrying the money movement (§6.6).
5. **Sandbox by default, production by explicit opt-in.** Demo/sandbox adapters are the
   default; production adapters require env-gated credentials and an explicit enable flag
   (§5). Demo money paths are disabled outside `local`/`testing`/`demo` unless an operator
   acknowledges the risk (existing precedent: `config/ledger.php`,
   `LEDGER_DIRECT_FUNDING_ENABLED` / `LEDGER_DIRECT_FUNDING_ACKNOWLEDGED`).
6. **No secrets in code, no sensitive data at rest.** Secrets come from env/secret manager
   only. CVV, PIN, and private keys are never stored (§7.6).
7. **Events first, projections second.** State changes are recorded as domain events
   (spatie event sourcing); read models are projections that can be rebuilt (§9).

## 1. System overview

```
                         ┌──────────────────────────────────────────────────┐
                         │  Clients: web / mobile / partner API / Filament │
                         └──────────────┬───────────────────────────────────┘
                                        │ HTTPS, Sanctum / Passport, scope + RBAC
                         ┌──────────────▼───────────────────────────────────┐
                         │  API layer: routes/api.php (orchestrator) +       │
                         │  app/Domain/{Name}/Routes/api.php (per domain)    │
                         │  FormRequests · api.rate_limit · scope middleware │
                         └──────────────┬───────────────────────────────────┘
                                        │
        ┌───────────────┬───────────────┼────────────────┬──────────────────┐
        ▼               ▼               ▼                ▼                  ▼
   Account          Payment         Ledger           Compliance        Exchange / Card /
   (balances,       (deposits,      (double-entry    (KYC/AML,          Custodian / Banking
    transfers)      withdrawals)     journal,          sanctions,         (provider adapters,
                                         reconciliation)  screening)        webhooks)
        │               │               │                │                  │
        └───────────────┴───────┬───────┴────────────────┴──────────────────┘
                                ▼
                 ┌──────────────────────────────┐
                 │  Event store (stored_events │
                 │  / ledgers) + projections    │
                 │  Queue (Redis/Horizon) +     │
                 │  Scheduler                   │
                 └──────────────────────────────┘
                                │
                 ┌──────────────▼───────────────────────────────┐
                 │  Provider adapters (§4): bank · card · pay · │
                 │  KYC · custody · chain · FX · market · video │
                 └──────────────────────────────────────────────┘
```

Deployment shape (see §10): one Laravel app, MySQL 8, Redis 7, Horizon workers,
scheduler — `docker-compose.yml` today (`app` + `mysql:8.0` + `redis:7-alpine` +
`mailpit`); same topology in production with managed equivalents.

## 2. Module boundaries

One domain = one bounded context = one directory under `app/Domain/*`, with its own
`Models/`, `Aggregates/`, `Events/`, `Projectors/`, `Services/`, `Contracts/`,
`Adapters|Connectors|Providers/`, `Routes/api.php`, `Http/` (where it owns endpoints).
Cross-domain communication goes through **domain events or a contract interface** —
never by importing another domain's Eloquent model into business logic, and never by
calling another domain's service class directly from a controller.

| Domain | Owns (source of truth) | Must NOT own | Key existing classes |
|---|---|---|---|
| `Ledger` | Double-entry journal, chart of accounts, posting rules, trial balance, reconciliation | Provider calls, user balances as a concept (those live in `Account`) | `LedgerService`, `LedgerDriverInterface`, `EloquentDriver`, `TigerBeetleDriver`, `JournalEntry`/`JournalLine`, `EntryStatus` (`draft`/`posted`/`reversed`), `PostingRuleEngine`, `ReconciliationService` |
| `Account` | User accounts, per-asset balances, internal transfers, account lifecycle (create/freeze/delete), turnover | External rail movement, journal posting rules | `TransactionAggregate`, `TransferAggregate`, `LedgerAggregate`, `AssetTransactionAggregate`, `Account` (`balance` int, minor units), `AccountBalance`, `Money` (int minor units), `CreateAccount`/`CreditAccount`/`DebitAccount`/`FreezeAccount` actions |
| `Payment` | Deposit/withdrawal intent lifecycle, payment transactions, idempotency of payment intents | Ledger posting rules, bank rail protocol | `PaymentDepositAggregate`, `PaymentWithdrawalAggregate`, `PaymentDeposit`/`PaymentWithdrawal`/`PaymentTransaction`, `PaymentServiceInterface`, `DemoPaymentGatewayService`, `HyperSwitchDepositIntent` |
| `Banking` | Bank connections, bank accounts, SEPA transfers/mandates, bank health, routing | Ledger, card issuing | `IBankConnector`, `BankIntegrationService`, `BankTransferService`, `SepaCreditTransferService`, `SepaDirectDebitService`, `BankHealthMonitor`, `BankAccount`/`BankTransfer`/`BankStatement` |
| `CardIssuance` | Cards, funding accounts, authorisations, top-ups, 3-DS, card webhooks | KYC verdicts (consumes them), ledger | `CardIssuerInterface`, adapters `Marqeta`/`Rain`/`FinCard`/`Demo`, `Card`, `CardFundingAccount`, `FinCardWebhookController` |
| `Compliance` | KYC/KYB state, AML screening, sanctions, alerts/cases, SAR, GDPR, audit trail | Provider SDK calls (behind `KycProviderInterface` / `SanctionsScreeningInterface`) | `ComplianceAggregate`, `AmlScreeningAggregate`, `KycProviderInterface`, `KycProviderRouter`, `OndatoKycProvider`, `BridgeKycProvider`, `SanctionsScreeningInterface` + `Chainalysis`/`GoPlus`/`Internal`/`Composite` adapters |
| `Custodian` | Custodian accounts, custodian transfers, settlement, custodian health/circuit breaker, custodian webhooks | Key material (see `KeyManagement`), ledger | `ICustodianConnector`, connectors `Paysera`/`Santander`/`DeutscheBank`/`Flutterwave`/`DemoBank`/`MockBank`, `CustodianRegistry`, `CircuitBreakerService`, `DailyReconciliationService`, `WebhookVerificationService` |
| `Exchange` | Orders, order book, matching, liquidity pools, FX rates, fees | Custody of assets, ledger | `Order`/`OrderBook` aggregates, `IExchangeRateProvider`, `ExchangeRateProviderRegistry`, `FixerIoProvider`, `MockExchangeRateProvider`, `BinanceConnector`, `KrakenConnector`, `PriceAggregator` |
| `KeyManagement` | Key custody policy, HSM access, Shamir shares | Business logic of any domain | `HsmProviderInterface` (`AwsKmsHsmProvider`, `AzureKeyVaultHsmProvider`, `DemoHsmProvider`), `ShamirServiceInterface` |
| `Webhook` | Inbound webhook receipt/verification/dispatch, outbound webhook delivery + replay | Provider-specific payload semantics (those live in the owning domain) | `Webhook`, `WebhookDelivery`, `WebhookService`, `WebhookReplayService`, `PayloadSanitizer` |
| `PaymentRails` | Rail capability flags + routing policy (ACH/Fedwire/RTP/FedNow today; SATNA/PAYA/PEL later — §4.1, §11) | Rail protocol implementations (those are `Banking`/`Custodian` adapters) | `config/payment_rails.php` (all rails env-gated, disabled by default) |
| `Auth` / `User` | Identity, credentials, MFA, sessions, teams/tenancy | Any money logic | Fortify + Jetstream, `TwoFactorAuthController`, `PasskeyController`, `spatie/laravel-permission` |
| `Fraud` / `RegTech` / `Monitoring` | Risk scoring, regulatory filings, observability | Money movement | Existing domains; consume domain events only |

**Dependency direction (allowed):**

```
Http/Routes → Services/Actions → Aggregates (events) → Projectors → Read models
Services → Contracts (interfaces) → Adapters/Connectors (providers)
Ledger ← Account, Payment, Exchange, CardIssuance, Banking   (they post entries; Ledger never calls them)
Compliance ← everything (screening is a gate, called before money moves; it never moves money)
```

**Forbidden:** `Account` → `Banking` concrete class; any domain → vendor SDK;
controllers → adapters; any write path that bypasses the ledger (except the
explicitly gated demo direct-funding path, §5).

## 3. Data flow

### 3.1 Canonical flow — deposit / withdrawal / transfer

```
1. Request        Client → POST /v2/... with Idempotency-Key header
                  auth:sanctum → scope (read/write) → api.rate_limit → FormRequest
2. Validation     FormRequest (shape, amounts > 0, currency allow-list, account exists,
                  account not frozen) → Compliance gate (KYC status, sanctions screen,
                  limits/velocity, fraud score). Any gate failure → 4xx, NO ledger write.
3. Intent         Create intent row (PaymentDeposit / PaymentWithdrawal / Transfer) in
                  state `initiated`, with idempotency_key UNIQUE. Duplicate key →
                  return the original intent (201/200), do not re-execute. Aggregate
                  records DepositInitiated / WithdrawalInitiated event.
4. Reservation    Account balance hold: debit available, credit held (internal transfer
                  via TransferAggregate). Funds are reserved BEFORE the provider call,
                  so a provider success can never overdraw.
5. Ledger         LedgerService::post() — balanced journal entry (debits == credits,
                  bcmath scale 4, string amounts), inside DB::transaction, with
                  source_domain + source_event_id. Entry is POSTED and immutable.
                  (For withdrawals the ledger entry is posted on provider success;
                  the reservation in step 4 is the pre-provider guarantee.)
6. Provider       Adapter call via the §4 interface (never the SDK), with the
                  idempotency key forwarded, timeout + retry budget (idempotent
                  retries only — see §6.6), circuit breaker (Custodian pattern).
                  Provider response → map to canonical state (§3.3).
7. Webhook        Provider webhook → raw body stored FIRST (CustodianWebhook /
                  WebhookDelivery) → signature verified with hash_equals on the RAW
                  body (existing: Custodian WebhookVerificationService, FinCard
                  webhook verifier, KycProviderInterface::getWebhookValidator) →
                  idempotency check on provider event id → aggregate event →
                  projector updates read model → ledger completion/reversal entry.
8. Reconciliation Daily job (Custodian DailyReconciliationService pattern, generalised):
                  ledger vs provider statement vs bank statement; mismatches →
                  ReconciliationReport + requires-manual-review, never auto-corrected.
```

### 3.2 Internal transfer (no provider)

Steps 1–4 and 5 only: `TransferAggregate` emits `MoneyTransferred`, projectors update
both `AccountBalance` rows in the same DB transaction as the journal entry. No step 6–8.

### 3.3 Canonical transaction state machine

One enum per intent aggregate; the values below are the **canonical vocabulary** every
adapter must map its provider-specific statuses onto. (Existing code uses looser
strings today — e.g. `PaymentTransaction` `pending`/`completed`/`failed` — the roadmap
hardens these into this enum; see `docs/IMPLEMENTATION_ROADMAP.md` Phase 0.)

```
initiated ──► pending ──► processing ──► successful
   │            │             │               │
   │            │             ├───────────────┤
   │            │             ▼               ▼
   │            │          failed ◄── (provider definitive failure)
   │            │             │
   │            └────────► reversed (compensating entry posted; original kept)
   │
   ├─► unknown ──► (status query / webhook / manual review) ──► successful | failed | reversed
   │
   └─► requires-manual-review ──► (human decision in Filament) ──► successful | failed | reversed
```

Rules:

- `initiated`, `pending`, `processing`, `unknown` are **non-terminal**; everything else
  is terminal. A terminal state never transitions again — a correction is a new
  (reversing) entry, never an edit.
- `unknown` is entered on **timeout, ambiguous response, or lost webhook**, and on any
  provider response the code cannot classify. It is resolved only by evidence.
- `requires-manual-review` is entered on reconciliation mismatch, duplicate webhook with
  conflicting amounts, or a compliance hold. It blocks settlement of that intent.
- `reversed` means a compensating journal entry exists and the original entry's status
  is `REVERSED` (`EntryStatus::REVERSED`, linked via `reversed_by`) — the original row
  is never deleted or mutated in place.

### 3.4 Event flow (event sourcing)

Aggregates (`TransactionAggregate`, `PaymentDepositAggregate`, `PaymentWithdrawalAggregate`,
`ComplianceAggregate`, `AmlScreeningAggregate`, `Order`, …) extend spatie
`AggregateRoot`; events (`DepositInitiated/Completed/Failed`, `MoneyTransferred`,
`KycVerificationCompleted`, …) are stored in the event store (`ledgers` /
`stored_events` tables, `aggregate_uuid` + `aggregate_version` unique) and applied by
projectors auto-discovered from `app/Domain/*/Projectors` (`config/event-sourcing.php`).
Reactors perform side effects (notify, call provider, enqueue reconciliation) **after**
the event is stored. Projectors must be idempotent and replayable — replay is the
recovery mechanism for a corrupted read model.

### 3.5 Reconciliation flow

```
provider statement / bank statement / custodian balances
        │  (fetched by scheduled job; stored raw)
        ▼
ReconciliationService: match on provider reference + amount + currency + value date
        ├─ matched            → mark reconciled
        ├─ ledger-only        → requires-manual-review (money left, provider silent)
        ├─ provider-only      → requires-manual-review (money arrived, ledger silent)
        └─ amount mismatch    → requires-manual-review (never auto-adjust)
```

## 4. Provider adapter interfaces

**Contract rules for every interface in this section:**

- All methods are synchronous in signature but must be safe to call from a queued job;
  long-running provider work is always queued, never in the HTTP request.
- All money is **integer minor units + ISO-4217 currency** on the boundary
  (matching `Account\DataObjects\Money::__construct(int $amount)`), or exact decimal
  strings (`numeric-string`) where the ledger requires it. **Never `float` for money**
  on a provider boundary. (Note: `IExchangeRateProvider` and the FX registry return
  rates as `BigDecimal`/`ExchangeRateQuote` — rates are not money; that is the one
  sanctioned exception.)
- Every mutating method takes a `string $idempotencyKey` and forwards it to the provider.
- Every method throws a typed domain exception (`BankOperationException`,
  `TransferException`, `CustodianNotAvailableException`, `RateProviderException`, …);
  adapters never leak vendor SDK exceptions or raw HTTP errors.
- Every adapter implements `getName(): string`, `isAvailable(): bool`, and a
  `getCapabilities()`-style capability declaration where the existing interface has one.
- Webhook handling is **not** part of these interfaces: signature verification and
  payload normalisation live in the owning domain's webhook controller/service, following
  the existing `KycProviderInterface::getWebhookValidator()` /
  `normalizeWebhookPayload()` pattern.

### 4.1 `BankProviderInterface` — target name; existing seam: `IBankConnector`

Existing: `app/Domain/Banking/Contracts/IBankConnector.php` (implemented by
`OpenBankingConnector`, `BankConnectorAdapter`; base `BaseBankConnector`). The target
interface is this contract, renamed to `BankProviderInterface` (or kept as
`IBankConnector` with `BankProviderInterface` as an alias — one canonical name, decided
in Roadmap Phase 2; no duplicate seam). Method set (already present, to be kept):

```php
interface BankProviderInterface
{
    public function getBankCode(): string;
    public function getBankName(): string;
    public function isAvailable(): bool;
    public function getCapabilities(): BankCapabilities;
    public function authenticate(): void;                                    // throws BankAuthenticationException
    public function createAccount(array $accountDetails): BankAccount;       // throws BankOperationException
    public function getAccount(string $accountId): BankAccount;              // throws AccountNotFoundException
    public function getBalance(string $accountId, ?string $currency = null): BankBalance|Collection;
    public function initiateTransfer(array $transferDetails): BankTransfer;  // throws TransferException
    public function getTransferStatus(string $transferId): BankTransfer;
    public function cancelTransfer(string $transferId): bool;
    public function getTransactions(string $accountId, DateTime $from, DateTime $to, int $limit = 100): Collection;
    public function getStatement(string $accountId, DateTime $from, DateTime $to, string $format = 'JSON'): BankStatement;
    public function validateIBAN(string $iban): bool;
    public function getSupportedCurrencies(): array;
    public function getTransferLimits(string $accountId, string $transferType): array;
    public function verifyWebhookSignature(string $payload, string $signature, array $headers): bool;
    public function processWebhook(string $payload): array;
}
```

**Target additions (Phase 3):** `initiateTransfer()` / `getTransferStatus()` to accept an
explicit `string $idempotencyKey` and a `TransferRail` enum (`sepa`, `swift`, `ach`,
`fedwire`, `rtp`, `fednow`, `satna`, `paya`, `pel` — the last three **reserved enum
cases only**; see Iranian rails note below); `getTransferStatus()` must return the
canonical state (§3.3), never a raw vendor string.

> **Iranian payment rails — SATNA / PAYA / PEL.** No SATNA, PAYA, PEL, Shaparak, or
> Shetab endpoint, credential, or message format exists anywhere in this codebase
> (verified by search). Therefore: **no Iranian rail adapter is written, and no
> endpoint URL, field, or behaviour is invented.** The rail enum cases, the
> `config/payment_rails.php` entries (`satna`/`paya`/`pel`, all `enabled => false`),
> and a `SandboxIranianRailAdapter` that simulates the state machine locally are the
> complete deliverable until official bank/Central Bank documentation and sandbox
> credentials exist. A production Iranian adapter is a Roadmap Phase 3 item explicitly
> blocked on external docs/credentials (see `docs/IMPLEMENTATION_ROADMAP.md`).

### 4.2 `AccountProviderInterface` — new seam (account provisioning at partner banks)

Covers opening/closing **external** accounts held for users at partner banks
(internal accounts are `Account` domain aggregates, not a provider concern). No
equivalent exists today; `AccountProvisioningService`
(`app/Domain/AccountProvisioning/`) is the service to sit behind it.

```php
interface AccountProviderInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    public function openAccount(string $userId, array $accountDetails, string $idempotencyKey): ExternalAccount;
    public function getAccount(string $externalAccountId): ExternalAccount;
    public function closeAccount(string $externalAccountId, string $reason, string $idempotencyKey): bool;
    public function freezeAccount(string $externalAccountId, string $reason): bool;
    public function unfreezeAccount(string $externalAccountId): bool;
    public function getBalance(string $externalAccountId, string $currency): Money;
    /** @return array{status: CanonicalTransactionState, provider_reference: ?string} */
    public function getAccountStatus(string $externalAccountId): array;
}
```

### 4.3 `CardProviderInterface` — target name; existing seam: `CardIssuerInterface`

Existing: `app/Domain/CardIssuance/Contracts/CardIssuerInterface.php`, implemented by
`MarqetaCardIssuerAdapter`, `RainCardIssuerAdapter`, `FinCardCardIssuerAdapter`,
`DemoCardIssuerAdapter`. Target = this contract, renamed/aliased to
`CardProviderInterface`; method set kept, plus the additions below.

```php
interface CardProviderInterface
{
    public function getName(): string;
    public function createCard(string $userId, string $cardholderName, array $metadata = [],
        ?CardNetwork $network = null, ?string $label = null): VirtualCard;
    public function getProvisioningData(string $cardToken, WalletType $walletType,
        string $deviceId, array $certificates = []): ProvisioningData;   // Apple/Google Pay; PAN never returned
    public function freezeCard(string $cardToken): bool;
    public function unfreezeCard(string $cardToken): bool;
    public function cancelCard(string $cardToken, string $reason): bool;
    public function getCard(string $cardToken): ?VirtualCard;
    /** @return array<VirtualCard> */
    public function listUserCards(string $userId): array;
    /** @return array{transactions: array<CardTransaction>, next_cursor: string|null} */
    public function getTransactions(string $cardToken, int $limit = 20, ?string $cursor = null): array;
    // Target additions (Phase 4):
    public function topUp(string $cardToken, Money $amount, string $idempotencyKey): CardTransaction;
    /** @return array{status: CanonicalTransactionState, provider_reference: ?string} */
    public function getTopUpStatus(string $providerReference): array;
    public function setSpendLimit(string $cardToken, Money $limit, string $period): bool;
}
```

Card authorisation decisions use the existing `AuthorizationDecision` enum; card
webhooks are handled by `FinCardWebhookController` / `LepoWebhookController` with
signature verification **before** any state change.

### 4.4 `PaymentProviderInterface` — target name; existing seam: `PaymentServiceInterface`

Existing: `app/Domain/Payment/Contracts/PaymentServiceInterface.php`
(`processStripeDeposit`, `processBankWithdrawal`, `processOpenBankingDeposit`;
amounts already `int` minor units). Target = a provider-shaped contract behind which
Stripe / HyperSwitch / Paysera / demo gateways sit as adapters; the existing service
interface becomes the application service that orchestrates it.

```php
interface PaymentProviderInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    /** @return array{provider_reference: string, status: CanonicalTransactionState, redirect_url: ?string} */
    public function initiateDeposit(array $data, string $idempotencyKey): array;
    /** @return array{provider_reference: string, status: CanonicalTransactionState} */
    public function initiateWithdrawal(array $data, string $idempotencyKey): array;
    /** @return array{status: CanonicalTransactionState, provider_reference: ?string, settled_at: ?DateTimeImmutable} */
    public function getPaymentStatus(string $providerReference): array;
    public function cancelPayment(string $providerReference, string $idempotencyKey): bool;
    /** @return array<string> */
    public function getSupportedCurrencies(): array;
    /** @return array<string> */
    public function getSupportedPaymentMethods(): array;
}
```

`$data` is a typed DTO in the implementation (existing array shapes in
`PaymentServiceInterface` are the starting point — `account_uuid`, `amount` int minor
units, `currency`, `reference`, `external_reference`, `payment_method(_type)`,
`metadata?`); the roadmap converts these arrays to DTOs (Phase 0/4).

### 4.5 `IdentityVerificationProviderInterface` — target name; existing seam: `KycProviderInterface`

Existing: `app/Domain/Compliance/Kyc/Contracts/KycProviderInterface.php`
(`OndatoKycProvider`, `BridgeKycProvider`, selected per-purpose by `KycProviderRouter`).
This is the most complete provider seam in the codebase and the template for the others.
Target = this contract, renamed/aliased to `IdentityVerificationProviderInterface`.

```php
interface IdentityVerificationProviderInterface
{
    public function getName(): string;   // stable id: 'ondato', 'bridge', …
    /** @throws UnsupportedKycPurposeException */
    public function getHostedLink(int $userId, KycPurpose $purpose, array $context = []): string;
    /** @return array{status: 'not_started'|'pending'|'approved'|'rejected', metadata: array<string, mixed>} */
    public function getStatus(int $userId): array;
    /** @return callable(string $rawBody, string $signatureHeader): bool  — MUST use hash_equals() */
    public function getWebhookValidator(): callable;
    public function getWebhookSignatureHeader(): string;
    public function normalizeWebhookPayload(array $payload): ?array;  // canonical shape or null = ignore (200 + no-op)
    // Target additions (Phase 5 — blocked on licensed provider, see Roadmap):
    /** Liveness / biometric check. Returns canonical result; raw biometrics are never stored. */
    public function verifyLiveness(int $userId, array $evidence): LivenessResult;
    /** Document authenticity check. */
    public function verifyDocument(int $userId, array $document): DocumentVerificationResult;
}
```

Sanctions screening stays a separate seam — existing
`SanctionsScreeningInterface` (`screenIndividual`, `screenAddress`, `getName`),
implemented by `ChainalysisAdapter`, `GoPlusAdapter`, `InternalSanctionsAdapter`,
composed by `CompositeScreeningAdapter`. It is **not** folded into this interface.

### 4.6 `CustodyProviderInterface` — target name; existing seam: `ICustodianConnector`

Existing: `app/Domain/Custodian/Contracts/ICustodianConnector.php`, implemented by
`PayseraConnector`, `SantanderConnector`, `DeutscheBankConnector`,
`FlutterwaveConnector`, `DemoBankConnector`, `MockBankConnector`; selected by
`CustodianRegistry`; guarded by `CircuitBreakerService`, `RetryService`,
`FallbackService`; health via `CustodianHealthMonitor`. Target = this contract,
renamed/aliased to `CustodyProviderInterface`.

```php
interface CustodyProviderInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    public function getBalance(string $accountId, string $assetCode): Money;
    public function getAccountInfo(string $accountId): AccountInfo;
    public function initiateTransfer(TransferRequest $request): TransactionReceipt;
    public function getTransactionStatus(string $transactionId): TransactionReceipt;
    public function cancelTransaction(string $transactionId): bool;
    /** @return array<string> */
    public function getSupportedAssets(): array;
    public function validateAccount(string $accountId): bool;
    /** @return array<int, mixed> */
    public function getTransactionHistory(string $accountId, ?int $limit = 100, ?int $offset = 0): array;
    // Target additions (Phase 6):
    public function initiateTransferWithIdempotency(TransferRequest $request, string $idempotencyKey): TransactionReceipt;
    public function getSettlementStatus(string $transactionId): SettlementStatus;
}
```

Custody of **key material** is separate: `KeyManagement\HsmProviderInterface`
(AWS KMS / Azure Key Vault / Demo). A custody provider never receives, stores, or
returns a private key.

### 4.7 `BlockchainProviderInterface` — new seam (consolidates chain access)

No single chain seam exists today; chain access is spread across `CrossChain` bridge
adapters (`BridgeAdapterInterface`: Axelar, Circle CCTP, LayerZero, Wormhole, Demo),
`Relayer` (`WalletBalanceProviderInterface`), and `Account` blockchain models
(`BlockchainAddress`, `BlockchainTransaction`). Target: one read/write seam per chain,
with bridges staying a separate seam.

```php
interface BlockchainProviderInterface
{
    public function getName(): string;                 // e.g. 'ethereum', 'bitcoin', 'polygon'
    public function getChainId(): int|string;
    public function isAvailable(): bool;
    public function getBalance(string $address, ?string $assetCode = null): Money;
    public function getTransactionStatus(string $txHash): TransactionStatus;  // canonical state + confirmations
    public function broadcastTransaction(string $signedTransaction, string $idempotencyKey): string; // tx hash
    public function estimateFee(array $transaction): Money;
    public function validateAddress(string $address): bool;
    public function getBlockHeight(): int;
}
```

Signing is out of scope for this interface: transactions are signed in
`KeyManagement` (HSM) and only the signed payload crosses this boundary.

### 4.8 `ExchangeRateProviderInterface` — target name; existing seam: `IExchangeRateProvider`

Existing: `app/Domain/Exchange/Contracts/IExchangeRateProvider.php`, implemented by
`FixerIoProvider`, `MockExchangeRateProvider` (base `BaseExchangeRateProvider`),
composed by `ExchangeRateProviderRegistry` (priority-ordered, fallback across providers,
`getRateWithFallback`). Target = this contract, renamed/aliased.

```php
interface ExchangeRateProviderInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    /** @throws RateProviderException */
    public function getRate(string $fromCurrency, string $toCurrency): ExchangeRateQuote;
    /** @param array<string> $pairs e.g. ['USD/EUR', 'BTC/USD'] @return array<string, ExchangeRateQuote> */
    public function getRates(array $pairs): array;
    /** @return array<string, ExchangeRateQuote> */
    public function getAllRatesForBase(string $baseCurrency): array;
    public function getCapabilities(): RateProviderCapabilities;
    /** @return array<string> */
    public function getSupportedCurrencies(): array;
    public function supportsPair(string $fromCurrency, string $toCurrency): bool;
    public function getPriority(): int;   // higher = preferred by the registry
}
```

### 4.9 `MarketDataProviderInterface` — new seam (prices, order books, candles)

Distinct from FX rates (§4.8): market data is streaming/near-real-time price discovery
for trading. Existing partial coverage: `Exchange\PriceAggregator`,
`ExternalExchangeService`, `BinanceConnector`, `KrakenConnector`
(`ExternalExchangeServiceInterface`, `IExternalExchangeConnector`).

```php
interface MarketDataProviderInterface
{
    public function getName(): string;
    public function isAvailable(): bool;
    public function getTicker(string $symbol): Ticker;                    // last, bid, ask, volume
    public function getOrderBook(string $symbol, int $depth = 20): OrderBookSnapshot;
    /** @return array<int, Candle> */
    public function getCandles(string $symbol, string $interval, DateTimeImmutable $from, DateTimeImmutable $to): array;
    /** @return array<string> */
    public function getSupportedSymbols(): array;
}
```

Market data is **advisory only**: execution prices are locked by quote at order time
(`CalculateOptimalQuotesActivity`), and settlement always uses the ledger (§6).

### 4.10 `VideoMeetingProviderInterface` — new seam (video KYC / assisted onboarding)

**No video-meeting provider exists in this codebase** (searched: no Jitsi or video-call
provider; the closest code is `Compliance\Services\BiometricVerificationService` and
`AgentProtocol\Workflows\Activities\PerformBiometricVerificationActivity`). The target
is therefore a new seam, sandbox-only until a vendor (e.g. Jitsi self-hosted, or a
licensed video-KYC vendor) is selected and contracted.

```php
interface VideoMeetingProviderInterface
{
    public function getName(): string;   // e.g. 'jitsi', 'vendor-name'
    public function isAvailable(): bool;
    /** Create a meeting room for a KYC session; returns join URLs (agent + customer) and expiry. */
    public function createMeeting(string $sessionId, array $participants, DateTimeImmutable $expiresAt): Meeting;
    public function getMeeting(string $meetingId): Meeting;
    public function endMeeting(string $meetingId): bool;
    /** Recording is opt-in, encrypted, retention-limited; never stored unencrypted. */
    public function getRecording(string $meetingId): ?RecordingReference;
    public function deleteRecording(string $meetingId): bool;
}
```

Recordings are evidence, not identity: the KYC **verdict** still comes from
`IdentityVerificationProviderInterface` (§4.5). Video sessions must not be the sole
store of a KYC decision.

### 4.11 Provider registry & selection (cross-cutting)

Every provider family follows the same pattern, already established by
`ExchangeRateProviderRegistry`, `KycProviderRouter`, and `CustodianRegistry`:

- Adapters are registered in a config file (`config/exchange.php`,
  `config/custodian.php` / `config/custodians.php`, `config/kyc.php`,
  `config/cardissuance.php`, `config/baas.php`, …) with `driver` + `enabled` + env keys.
- Selection is by **capability + priority**, never by hard-coded class in domain code.
- `Demo*` / `Mock*` adapters are first-class implementations of the same interface and
  are the default when no production credentials are configured.
- Health is monitored (`BankHealthMonitor`, `CustodianHealthMonitor`) and a failing
  provider is circuit-broken (`Custodian\CircuitBreakerService`) rather than retried
  into the ground.

## 5. Sandbox vs production

| Aspect | Sandbox / demo | Production |
|---|---|---|
| Provider adapters | `Demo*` / `Mock*` adapters (existing: `DemoCardIssuerAdapter`, `DemoBankConnector`, `MockBankConnector`, `DemoPaymentGatewayService`, `MockExchangeRateProvider`, `DemoHsmProvider`, `DemoBridgeAdapter`, `DemoRailAdapter`) | Licensed vendor adapters only; selected by config, never by code change |
| Money movement | Simulated; `config/ledger.php` `direct_funding_enabled` is ON only for `APP_ENV` in `local`/`testing`/`demo` | Direct funding OFF; `ops:verify-env` FAILS a production deploy while it is on unless `LEDGER_DIRECT_FUNDING_ACKNOWLEDGED=true` |
| Rails | All rails disabled by default (`config/payment_rails.php`: ACH/Fedwire/RTP/FedNow `enabled => false`); Iranian rails reserved, disabled, sandbox-adapter only | A rail is enabled only with real credentials + a named owner + reconciliation configured |
| Secrets | `.env` / local vault; demo keys only | Secret manager / env injected by deployment; keys rotated; per-provider secrets, never shared |
| KYC / liveness | `BridgeKycProvider` sandbox / Ondato sandbox; liveness simulated | Licensed provider, production contract, data-processing agreement |
| Webhooks | Public tunnel / test endpoints; signature verification still enforced (same code path) | Registered webhook URLs, secret per provider, replay protection |
| Data | Seed/fake data; no real PII | Real PII encrypted at rest, GDPR erasure supported (`GdprService`) |
| Admin | Filament open in local | Filament behind RBAC + MFA + IP allow-list (see §7) |

**Rule:** sandbox and production must execute the **same domain code path** — only the
adapter and the config differ. A bug that exists only in production because the demo
path skipped validation is an architecture violation. `DemoPaymentService` /
`DemoPaymentGatewayService` exist precisely so the demo path can be exercised end to
end without real money.

## 6. Financial ledger architecture

### 6.1 Double-entry, always

- Every money movement is a `JournalEntry` with ≥ 2 `JournalLine`s; debits == credits
  (validated in `LedgerService::post()` with `bccomp(..., 4)`); zero-total entries are
  rejected. The chart of accounts is owned by `ChartOfAccountsService` /
  `LedgerAccount`; posting rules by `PostingRuleEngine` / `PostingRule` — new movement
  types add a posting rule, not ad-hoc lines in a controller.
- `LedgerService::post(string $description, array $lines, ?string $sourceDomain,
  ?string $sourceEventId)` is the **only** write path. `source_domain` +
  `source_event_id` tie every entry to the domain event that caused it, so any entry
  can be traced to its intent.
- Driver seam: `LedgerDriverInterface` (`post`, `balance`, `trialBalance`,
  `accountHistory`). `EloquentDriver` (MySQL, today) and `TigerBeetleDriver` (existing;
  selected by `LEDGER_DRIVER`, `config/ledger.php`) implement it. TigerBeetle is the
  target for high-volume double-entry; the interface is what makes the swap safe.

### 6.2 Immutability

- `JournalEntry` status enum `EntryStatus`: `draft` → `posted` → `reversed`
  (`isTerminal()` for `posted`/`reversed`). Posted entries are never updated or deleted.
- Corrections are **reversals**: `LedgerService::reverse()` posts a mirror entry (debits
  ↔ credits) and links the original via `reversed_by`. The audit trail is the two
  entries, not an edit.
- Event-sourced aggregates are append-only by construction (`aggregate_uuid` +
  `aggregate_version` unique on the event store).

### 6.3 Exact decimal arithmetic

- **Money on every boundary is integer minor units** (`Account\DataObjects\Money(int
  $amount)`) or exact decimal strings (`numeric-string`) with `bcmath` (`bcadd` /
  `bccomp`, scale 4 in `LedgerService`). **Floats are never used for money.**
  The one exception is FX *rates* (`BigDecimal` in the FX registry), which are not money.
- Currency is always carried with the amount (ISO-4217); a bare number is a bug.
  `LedgerService` defaults currency from `config('ledger.default_currency')` (`USD`)
  — multi-currency entries must pass currency per line (roadmap Phase 0 hardens this).
- Rounding: defined per currency at the posting-rule level, applied once, at posting
  time; rounding differences post to a dedicated rounding account, never silently dropped.

### 6.4 Atomicity

- Journal entry + its lines + the aggregate event + the read-model update that must
  not diverge are written in **one `DB::transaction`** (`LedgerService::post()` already
  does this for entry + lines). Provider calls are **never** inside the DB transaction:
  reserve → commit → call provider → record outcome (see §3.1). A provider call inside
  a transaction holds locks across a network call and is forbidden.
- Cross-aggregate consistency is eventual and event-driven (§9); within one aggregate,
  consistency is transactional.

### 6.5 Idempotency

- Every mutating API request accepts an `Idempotency-Key` header; the key is stored
  UNIQUE on the intent row (`PaymentDeposit`, `PaymentWithdrawal`, transfer, top-up).
  A replayed key returns the original result without re-executing — including replays
  that arrive after the first execution completed.
- Idempotency keys are forwarded to providers on every mutating provider call
  (§4 contract rule) and stored on the provider receipt.
- Webhook processing is idempotent on provider event id: the raw payload is stored
  first (`CustodianWebhook`, `WebhookDelivery`), duplicates are acknowledged with 200
  and no-oped.
- Projectors are idempotent: replaying an event produces the same read model.

### 6.6 Concurrency, locking, timeouts

- **Locking:** balance mutations lock the account row (`SELECT … FOR UPDATE` inside the
  transaction) or, for event-sourced aggregates, rely on the aggregate version
  (optimistic concurrency — a version conflict retries the command, it never silently
  overwrites). Two concurrent debits on the same account serialise; neither can overdraw.
- **Reservation before provider call:** available balance is reduced by a hold before
  any external call, so provider latency cannot create a double-spend window.
- **Retries:** only idempotent operations are retried, with bounded attempts and
  back-off (`Custodian\RetryService` pattern). A non-idempotent call is never retried
  blindly.
- **Timeout ≠ failure:** a timed-out provider call sets the intent to `unknown`
  (§3.3). Resolution is by `get*Status()` polling, webhook, or manual review. The
  system must never mark a timed-out transfer `failed` and release the hold — that is
  how money is created or lost. A scheduled sweeper re-queries every `unknown` intent
  older than its provider's SLA.
- **Circuit breaker:** `Custodian\CircuitBreakerService` pattern generalised — a
  provider failing repeatedly is opened, calls fail fast, and health monitoring
  (`BankHealthMonitor`, `CustodianHealthMonitor`) alerts before users notice.

### 6.7 Reconciliation

- Daily (at minimum) reconciliation per provider and per rail
  (`ReconciliationService`, `DailyReconciliationService`,
  `PerformDailyReconciliation` command, `ReconciliationReport`): ledger vs provider
  statement vs bank statement. Mismatches go to `requires-manual-review` and a
  Filament queue — they are never auto-corrected, and never auto-reversed.
- Balance synchronisation (`SynchronizeCustodianBalances`,
  `BalanceSynchronizationService`) is a *check*, not a write: it reports drift, it does
  not overwrite ledger balances.

## 7. Security architecture

### 7.1 Authentication

- Sanctum (API tokens, `auth:sanctum`) and Passport (OAuth2) — existing.
  Fortify + Jetstream for web auth; passkeys supported (`PasskeyController`).
- **MFA is mandatory for:** Filament admin, any money-movement approval, API key
  management, and provider credential rotation. Implemented via Fortify 2FA
  (`TwoFactorAuthController`); step-up auth is required for high-value operations
  (target: confirm with password/TOTP before executing a withdrawal above the
  step-up threshold — Roadmap Phase 1).
- Sessions: Redis-backed, short-lived; refresh/rotation per Sanctum defaults.
  No credential is ever accepted in a query string or logged.

### 7.2 Authorisation — RBAC via `spatie/laravel-permission`

- Roles + permissions are the only authorisation mechanism; no hard-coded user ids or
  `is_admin` checks in domain code. Existing precedent: migration
  `2025_06_29_235152_add_banking_roles_and_permissions.php`.
- Permission naming: `{domain}.{resource}.{action}` — e.g.
  `ledger.entries.view`, `payment.withdrawals.approve`, `compliance.alerts.resolve`,
  `custodian.reconciliation.run`, `admin.providers.rotate-keys`.
- Money-movement approval is **four-eyes** for amounts above a configured threshold:
  the initiator cannot approve their own instruction (target, Phase 1).
- API scopes (`scope:read/write/delete` middleware, already used on account routes)
  are orthogonal to RBAC: a token needs both the scope and the role permission.
- Filament admin resources enforce the same permissions as the API — the admin panel
  is a client of the domain, not a bypass around it.

### 7.3 Rate limiting

- `config/rate_limiting.php` + `api.rate_limit` middleware, per endpoint class:
  auth 3/min (block 300 s), transaction 30/min, query 100/min, admin 200/min
  (current defaults — tune per environment, never disable globally).
- Per-user + per-IP limits; stricter limits on money endpoints than on reads.
- Provider-facing limits are separate: outbound calls are throttled per provider so a
  retry storm cannot get the platform banned by a vendor.

### 7.4 Webhook security

- Signature verification on the **raw body** with `hash_equals()` (constant-time),
  before parsing or any state change — existing in `Custodian\WebhookVerificationService`
  (HMAC-SHA256 Paysera, HMAC-SHA512 Santander), `FinCardWebhookController`, and
  `KycProviderInterface::getWebhookValidator()`. New providers follow the same pattern;
  a provider without a verifiable signature scheme is not onboarded without a
  compensating control (IP allow-list + shared secret + manual review).
- Raw payload stored before processing; replay protection via provider event id;
  `WebhookReplayService` for controlled replays; `PayloadSanitizer` before logging.
- Webhook endpoints are exempt from CSRF but not from rate limiting; they return 200
  only after the payload is durably stored.

### 7.5 Secrets

- All secrets via env / secret manager (`config/*.php` read `env()` only at boot).
  No secret in code, in the repo, in logs, or in exception messages.
- Per-provider credentials, per-environment; rotation is a runbook, not a code change.
- Provider secrets are scoped to the minimum privilege the vendor offers.

### 7.6 Data protection — what is NEVER stored

- **CVV / CVV2, full PAN, PIN, card magnetic-stripe data:** never stored, never logged.
  Only provider-issued tokens (`cardToken`) and last-4 / expiry for display.
- **Private keys:** never stored in the application database. Key material lives in
  the HSM (`KeyManagement\HsmProviderInterface` — AWS KMS / Azure Key Vault / Demo);
  the app holds only key *references*.
- **Raw biometrics:** never stored. Liveness/document evidence is held by the KYC
  provider; the platform stores only the verdict + provider reference.
- **Passwords:** Fortify hashing only; no reversible storage.
- PII is encrypted at rest where required, minimised in logs, and subject to GDPR
  erasure (`Compliance\Services\GdprService`; events `GdprDataExported` /
  `GdprDataDeleted`).

### 7.7 Audit

- Every money movement, approval, permission change, and provider credential rotation
  is an auditable event (domain events + `TenantAccountEvent` / audit projectors).
  Filament exposes the audit trail read-only; audit records are append-only.

## 8. Database design (per domain)

Conventions: `id` bigint PK; `uuid` (`aggregate_uuid`) on aggregates; money as
`decimal(19,4)` on journal lines and `bigint` minor units on account balances
(matching `Money(int)`); `jsonb` for event payloads; `unique` on
`(aggregate_uuid, aggregate_version)`; `unique` on every `idempotency_key`;
`timestamps` + `softDeletes` only where the domain requires it (financial rows are
never soft-deleted — they are reversed).

| Domain | Core tables (existing — 378 migrations total) | Notes / target changes |
|---|---|---|
| Ledger | `ledgers` (event store: `aggregate_uuid`, `aggregate_version`, `event_class`, `event_properties` jsonb, `meta_data` jsonb), `journal_entries` (`entry_number`, `status` enum, `posted_at`, `source_domain`, `source_event_id`, `reversed_by`), `journal_lines` (`account_code`, `debit_amount`/`credit_amount` decimal, `currency`, `narrative`), `ledger_accounts`, `posting_rules`, `reconciliation_reports` | Add: `currency` per line enforced (not defaulted), `idempotency_key` on entries, partial index on `status` |
| Account | `accounts` (`uuid`, `balance` int minor units, `frozen`, `team_id`, `treasury_*`), `account_balances` (`account_id`, `asset_code`, `balance`, `held` — target: add explicit `held_balance`), `transactions`, `transfers`, `turnover`, `blockchain_addresses`, `blockchain_transactions`, `tenant_account_events`, `tenant_account_snapshots` | Add `held_balance` column; balance reads go through projectors, never ad-hoc sums |
| Payment | `payment_deposits`, `payment_withdrawals`, `payment_transactions` (`status`, `reference`, `external_reference`), `payment_intents`, `hyperswitch_deposit_intents` | Add: `idempotency_key` UNIQUE, canonical `status` enum (§3.3), `provider_reference`, `unknown_since_at` |
| Banking | `bank_connections`, `bank_accounts`, `bank_transfers`, `bank_balances`, `bank_statements`, `bank_transactions`, `sepa_mandates`, `user_bank_preferences` | Add: `rail` enum (incl. reserved `satna`/`paya`/`pel`), `idempotency_key` on transfers |
| CardIssuance | `cards`, `card_funding_accounts`, `card_deposit_addresses`, card transactions/authorisations (via events + projections) | No PAN/CVV columns — ever (see §7.6) |
| Compliance | `compliance_alerts`, `compliance_cases`, `compliance_events`, `compliance_snapshots`, KYC tables (`kyc_*` under `Compliance/Kyc`), `agents` compliance fields | Add: `provider` + `provider_reference` on KYC rows (per-provider partitioning, cf. `KycProviderInterface` §7.5) |
| Custodian | `custodian_accounts` (`balance_*`), `custodian_transfers`, `custodian_webhooks` (raw payload) | Webhook table stores raw body; never delete rows |
| Exchange | `exchange_*` tables, `exchange_rates` (`provider` columns), `exchange_events`, `exchange_matching_errors`, `liquidity_pools`, `orders`, `trades` | Rate rows carry `provider`, `fetched_at`, `expires_at`; stale rates are never used for execution |
| Webhook | `webhooks`, `webhook_deliveries` | Delivery rows are the audit trail for outbound webhooks |
| Shared | `stored_events` (spatie), `users`, `roles`/`permissions` (spatie), `teams`/`tenancy` (`stancl/tenancy`) | Multi-tenancy: every money table carries `team_id`/tenant scope; cross-tenant reads are a defect |

Migrations are additive and backward-compatible; a migration that rewrites financial
history is forbidden — corrections go through reversal entries (§6.2).

## 9. Event-driven workflows

- **Event sourcing (spatie):** aggregates → events → projectors (read models) +
  reactors (side effects). Auto-discovery from `app/Domain/*/Projectors`,
  `*/Reactors`, `*/Sagas`, `*/Listeners` (`config/event-sourcing.php`); projector
  queue via `EVENT_PROJECTOR_QUEUE_NAME` (`App\Values\EventQueues`).
- **Workflows (Waterline / `durable-workflow/workflow`):** long-running, multi-step
  processes — deposit/withdrawal pipelines (`Payment\Activities\*`,
  `InitiateBankTransferActivity`, `ValidateWithdrawalActivity`, …), card top-up
  pipeline (`CardIssuance\Providers\Lepo\LepoTopUpPipeline`), KYC workflows
  (`KycSubmissionActivity`, `KycVerificationActivity`). Activities are idempotent and
  resumable; a workflow never re-executes a money step on resume — it re-reads the
  aggregate state.
- **Outbox pattern (target):** domain events that must reach another system (webhook
  delivery, provider notification) are written to an outbox table in the same DB
  transaction as the event, then relayed by a worker. This closes the
  "event stored but notification lost" gap. (Existing `WebhookDelivery` is the
  starting point; generalising it to an outbox is Roadmap Phase 2.)
- **Queues:** Redis queue, Horizon supervisors per queue (payments, webhooks,
  reconciliation, notifications separated — a slow webhook queue must never starve
  payments). Failed jobs go to the failed-jobs table + Horizon; money jobs are never
  auto-discarded — they are retried within their idempotency budget, then escalated
  to manual review.
- **Scheduler:** daily reconciliation, balance sync, custodian health checks,
  `PurgeExpiredDeposits`, rate refresh (`RefreshExchangeRatesJob`), ledger trial-balance
  snapshot. All scheduled jobs are idempotent and safe to re-run.

## 10. Deployment strategy

- **Local/dev:** `docker-compose.yml` — `app` (PHP 8.4, `docker/Dockerfile`), `mysql:8.0`,
  `redis:7-alpine`, `mailpit`. One command (`make`/compose up) brings the stack up with
  seeded demo data; demo adapters active.
- **Production topology (target):** same services, managed equivalents — managed MySQL 8
  (or compatible), managed Redis, app containers behind a load balancer (stateless —
  sessions/cache/queues in Redis), Horizon workers as separate containers/processes,
  scheduler as a single cron container (`php artisan schedule:run` every minute).
  No local disk state; uploads to object storage (`config/filesystems.php`).
- **Config:** 12-factor — everything environment-specific in env, never in code.
  `ops:verify-env` gates production deploys (fails while demo direct funding is on,
  §5). Secrets via the deployment platform's secret manager.
- **Queues:** Horizon (`config/horizon.php`, `laravel/horizon ^5.44`) — supervisors per
  queue with autoscaling by load; `horizon` dashboard behind admin RBAC + MFA.
- **Observability:** Pulse (app metrics), Telescope (local/debug), Prometheus metrics
  endpoint (`/monitoring/metrics`, gated by `MetricsAccessMiddleware`),
  health/readiness (`/monitoring/health`, `/monitoring/ready`), structured logging
  (`config/logging.php`), OpenTelemetry (`open-telemetry/*` packages present).
- **Backups & DR:** `spatie/laravel-backup` (`config/backup.php`); MySQL point-in-time
  recovery; event store is the recovery source — projections can be rebuilt from events,
  so backup priority is (1) event store + journal, (2) read models, (3) everything else.
- **Releases:** migrations run before code cutover; migrations are additive (§8);
  feature flags via Pennant (`laravel/pennant`, `config/pennant.php`) for new rails and
  providers; a provider is enabled by flag + credentials, not by deploy.
- **Environments:** `local` → `testing` (CI) → `demo` → `staging` (production adapters
  in sandbox mode) → `production`. Staging must use sandbox credentials, never
  production ones.

## 11. Open decisions (not decided by this document)

| # | Decision | Owner / blocker |
|---|---|---|
| 1 | Canonical name for each provider seam: rename `IBankConnector` → `BankProviderInterface` etc., or keep existing names with an alias? | Phase 2; must be one name, no duplicates |
| 2 | Ledger driver in production: Eloquent vs TigerBeetle (`LEDGER_DRIVER`) | Phase 0 load test; TigerBeetle needs its own deployment |
| 3 | Iranian rails (SATNA/PAYA/PEL): vendor/bank, docs, sandbox credentials, CBI approval path | External — blocked until docs exist (§4.1) |
| 4 | Card issuer for production (Marqeta vs Rain vs FinCard vs other) | External — commercial + licensing |
| 5 | Custody provider for production (licensed custodian) | External — licensing + contract |
| 6 | KYC/liveness vendor for production (Ondato vs Bridge vs other) | External — contract + DPA |
| 7 | Video-KYC vendor / Jitsi self-host vs managed | External — vendor selection (§4.10) |
| 8 | Four-eyes threshold + step-up auth amount | Product + compliance |

## Appendix — file map (where each part of this architecture lives)

| Concern | Location |
|---|---|
| API orchestration | `routes/api.php`, `routes/api-v2.php`, `app/Infrastructure/Domain/ModuleRouteLoader.php` |
| Domain routes | `app/Domain/{Name}/Routes/api.php` |
| Ledger | `app/Domain/Ledger/` (+ `config/ledger.php`, `config/event-store.php`) |
| Provider configs | `config/payment_rails.php`, `config/exchange.php`, `config/custodian.php`, `config/custodians.php`, `config/cardissuance.php`, `config/kyc.php`, `config/baas.php`, `config/hyperswitch.php`, `config/blockchain.php`, `config/cgo.php` |
| Security | `config/auth.php`, `config/fortify.php`, `config/permission.php`, `config/rate_limiting.php`, `config/sanctum.php`, `config/security.php` |
| Queues / scheduling | `config/queue.php`, `config/horizon.php`, `routes/console.php` |
| Deployment | `docker-compose.yml`, `docker/Dockerfile`, `helm/`, `Makefile`, `Envoy.blade.php` |
| Existing architecture docs | `docs/ARCHITECTURAL_ROADMAP.md`, `docs/VERSION_ROADMAP.md`, `docs/02-ARCHITECTURE/` |
