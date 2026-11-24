# Test Coverage Report — FinTech Hardening, Phases 0–5 (Phase 9 validation)

> **Status:** 2026-11-24, branch `feat/hardening-phase0-9` (HEAD `2d6ca15`).
> **Runner:** `./vendor/bin/pest --configuration=phpunit.ci.xml
> --default-time-limit=300` (sqlite `:memory:`, `CACHE_STORE=array`,
> `QUEUE_CONNECTION=sync`). All numbers below were observed in this
> session — nothing is copied from an earlier report. The full suite
> (1,359 test files) was **not** run; only the hardening suites listed
> in §1.

## 1. Results per suite (observed)

| Suite | Files | Tests | Assertions | Result |
|---|---|---|---|---|
| Ledger | `LedgerHardeningTest`, `LedgerHardeningPhase42Test`, `TransferFeeLedgerTest`, `PostGlEntryListenerTest`, `TigerBeetleDriverTest` | **48 passed** | 157 | 0 failed |
| Compliance / KYC | `KycHardeningPhase41Test`, `KycSandboxGateTest`, `IdentityVerificationSmileIdTest`, `OndatoServiceTest` | **80 passed** | 223 | 0 failed |
| Banking / Rails | `SandboxIranianRailAdapterTest`, `ProviderKernelTest`, `BankRoutingServiceTest`, `BankAccountOpeningReconciliationTest`, `BankIntegrationHardeningTest`, `PaymentIntentServiceTest`, `SandboxPaymentServiceTest`, `RailTransmissionGateTest` | **75 passed** | 263 | 0 failed |
| Exchange | `OtcSettlementHardeningTest`, `TradeSettlementHardeningTest`, `EnhancedExchangeRateServiceTest` | **32 passed** | 143 | 0 failed |
| Wallet / Card | `WalletPhase3HardeningTest`, `SpendLimitExactTest` | **27 passed** | 88 | 0 failed |
| SafeDeal | `SafeDealApiTest`, `SafeDealLifecycleTest`, `SafeDealStateMachineTest` | **19 passed** | 105 | 0 failed |
| International | `InternationalApiTest`, `InternationalAccountTest`, `DisabledRailGatewayTest`, `InternationalTransferServiceTest`, `QuotationServiceTest`, `ReconciliationServiceTest`, `ScreeningServiceTest` | **36 passed** | 194 | 0 failed |
| **Combined, per-group runs** | 33 files | **317 passed** | **1,173** | **0 failed** |
| **Combined, single process (re-run)** | same 33 files | **317 passed** | **1,173** | **0 failed, exit 0** |

Durations (per-group runs): Ledger 15.8 s, KYC 24.1 s, Banking/Rails
22.0 s, Exchange 17.8 s, Wallet/Card 10.8 s, SafeDeal 9.5 s,
International 13.5 s. Combined single-process run: 95.2 s.

### What each suite proves (one line each)

- **Ledger:** balanced posting, idempotency claims + replay semantics,
  outbox + retry, overdraw guard, TigerBeetle fail-closed, GL-listener
  replay, fee-as-separate-entry.
- **Compliance/KYC:** simulated results never approve, fake liveness/face/
  document never pass, demo flags off in production + blocking preflight.
- **Banking/Rails:** disabled Iranian rails cannot initiate, provider
  kernel outcomes, account opening without fabricated credentials,
  payment intents, transmission gate fail-closed.
- **Exchange:** no double-fill/double-settle, dust-truncation refusal,
  precision rejection, interrupted-trade resume, OTC settlement.
- **Wallet/Card:** precision registry, exact decimal math, sandbox-rate
  labelling, custody fail-closed, spend limits.
- **SafeDeal:** full lifecycle to settlement with ledger + audit, dispute
  paths (refund / release / split), idempotent funding, state machine.
  ⚠️ Verified against the working tree **including the sibling Security
  agent's uncommitted Phase-8 hardening** (counterparty verification now
  requires approved platform KYC; see §6).
- **International:** draft → screening → processing → settlement with
  balanced ledger legs, idempotency, disabled-rail failure + refund,
  manual-review screening default, reconciliation.

### Adjacent verification (read-only, sibling domains)

- **Meeting** (`MeetingSchedulingTest`, `MeetingConcurrencyTest`,
  `LocalizationHelperTest`): **21 passed (64 assertions)** — conflict
  prevention including the new `MeetingReservationLock`, fa/en
  localisation (Persian digits, fa validation messages). These files are
  the Meetings/i18n sibling's uncommitted work; verified, not owned.
- **Meeting/i18n state:** `lang/fa/` now holds all 7 files
  (`accounts`, `auth`, `dashboard`, `meeting`, `pagination`, `passwords`,
  `validation`) plus `lang/fa.json`; key counts match `lang/en/` for
  `meeting.php` (51) and `dashboard.php` (27). Default locale is `en`,
  fallback `en` (`config/app.php`).

## 2. Static analysis (observed)

### PHPStan (level 8, `phpstan.neon`)

```bash
XDEBUG_MODE=off TMPDIR=<writable> vendor/bin/phpstan analyse \
  --memory-limit=2G --no-progress \
  app/Domain/SafeDeal app/Domain/International
```

- **49 errors, all in SafeDeal; 0 in International.**
- By file: `SafeDealTradeController` 19 (nullable `$request->user()` —
  `$id`/`hasRole()` on `User|null`), `SafeDealService` 14 (bcmath
  `numeric-string` types, missing iterable value types, nullable
  returns), `SafeDealSettlementService` 6, `SafeDealTradeWorkflow` 2,
  `TradeAmount` 2, `TradeRiskAssessmentService` 2,
  `CounterpartyVerificationService` 2, `SafeDealEvent` 1,
  `SafeDealTradeAggregate` 1.
- These are type-level findings (null-safety, bcmath string types,
  missing generics), not test failures. Not fixed in Phase 9.

### PHPCS (PSR-12, `phpcs.xml`)

```bash
vendor/bin/phpcs --standard=phpcs.xml \
  app/Domain/SafeDeal app/Domain/International \
  tests/Domain/SafeDeal tests/Domain/International
```

- Before: **18 errors, 0 warnings** in 7 files.
- `phpcbf` fixed **11** (multi-line control-structure layout) in 4 files.
- After: **7 errors, 0 warnings**, all in test files — stub classes
  declared inside Pest test files without a namespace
  (`InternationalTransferServiceTest` 3, `ReconciliationServiceTest` 1,
  `SafeDealStateMachineTest` 3). **App code is clean.**

### First PHPStan runs failed on the environment, not the code

`/tmp` is a 512 MB tmpfs and was **100% full** (stale PHPStan caches from
earlier phases: `phpstan-run` 236 MB, `phpstan-intl` 47 MB,
`phpstan-phase2` 46 MB, `phpstan-phase3` 38 MB, …). PHPStan died with
`Could not write file … (unknown cause)` / `errno=28 No space left on
device`. The caches belong to other agents' runs and were not deleted.
Workaround: `TMPDIR` on `/home` (147 GB free), after which PHPStan ran
normally. The full PHPStan output is not committed; the counts above are
from the successful run.

## 3. Migrations on a clean database

- The 4 hardening migrations run cleanly on a fresh sqlite `:memory:`
  schema — proven by every passing suite, which migrates from scratch
  (`LazilyRefreshDatabase`):
  - `2026_11_24_000002_ledger_posting_failures_and_driver_sync.php`
  - `2026_11_24_000005_create_provider_payment_intents_table.php`
  - `2026_11_24_120000_create_international_tables.php`
  - `2026_11_24_120001_create_safedeal_tables.php`
- **MySQL / MariaDB: not verified.** No MySQL server or binaries exist in
  this VM and Docker is not installed, so `FOR UPDATE` row locking (which
  sqlite ignores) and MySQL-specific migration behaviour are untested.

## 4. Environment (observed)

| Component | Observed |
|---|---|
| PHP | 8.4.28 |
| Pest / PHPUnit | via `vendor/bin/pest`, `phpunit.ci.xml` |
| DB | sqlite `:memory:` (`pdo_sqlite` present); MySQL/PostgreSQL servers **not installed** |
| Redis | server reachable (`PING` → `PONG`), but suites use `CACHE_STORE=array` |
| Docker | **not installed** (`docker: command not found`) |
| Node / Vite | not used by these suites (frontend never built — pre-existing) |
| VM restarts | none during this session |

## 5. The combined-run anomaly (found, root-caused, re-run clean)

**First combined single-process run: 11 failed, 306 passed.**
All 11 failures were SafeDeal (`Counterparty … platform KYC is not
approved`), all on the `verifyCounterparty` path. Before that run, a
`reconService()` helper was declared in two test files, which made the
combined run fatal before any test executed; renaming the International
helper to `intlReconService()` fixed the fatal.

**Root cause of the 11 failures:** the sibling Security agent was editing
the SafeDeal test files mid-run (uncommitted Phase-8 hardening). At HEAD
`2d6ca15` the tests carry no KYC-approved fixture; the sibling's edit adds
it (`$this->user->update(['kyc_status' => 'approved', …])` in setUp) at
the same time as hardening `CounterpartyVerificationService` to require
approved platform KYC. Pest loaded the test files while the edit was
incomplete, so the fixtures had no approved KYC and verification failed
closed — exactly as designed. The service behaved correctly; the tree was
mid-edit.

**Evidence it is transient, not a product bug:**

- Probe test inserted immediately before SafeDeal in the full combined
  order showed a clean users table (`[1:not_started, 2:not_started]`,
  count 2, `transLevel=1`) and a working approve→read roundtrip; that run
  passed **318/318 (1,174 assertions)**.
- Every pairwise bisect passed (KYC+SafeDeal 99, Exchange+SafeDeal 51,
  Ledger+SafeDeal 67, Banking+SafeDeal 94, Wallet+SafeDeal 46) and both
  half-bisects passed (first-half+SafeDeal 222, second-half+SafeDeal 78).
- **Confirmation re-run of the exact first-run file list: 317 passed
  (1,173 assertions), exit 0.**

**Lesson recorded:** do not run the combined suite while a sibling is
editing its files; if a combined run fails, re-run after the tree settles
before concluding anything about the code.

## 6. What is NOT covered (do not present as done)

- Full suite (1,359 test files) — not run.
- MySQL / PostgreSQL behaviour, `FOR UPDATE` locking, multi-process
  concurrency races (cache store is `array` in tests).
- Live TigerBeetle cluster (only failure modes tested).
- Live bank / card / KYC / custodian / market-data providers (none exist).
- Live Jitsi / meeting provider end-to-end.
- Frontend build (Vite manifest missing — pre-existing).
- PHPStan/PHPCS on the whole repo (only SafeDeal + International scoped
  here, per the Phase 9 brief).
