# Critical Fixes Report — FinTech Hardening, Phases 0–5

> **Status:** 2026-11-24, branch `feat/hardening-phase0-9` (HEAD `2d6ca15`).
> **How to read this:** every item below names the code that implements the
> fix and the test that proves it, with the numbers observed in the Phase 9
> validation run. Items marked *(working tree, uncommitted sibling work)*
> were not in HEAD `2d6ca15` — they were in the tree when validated and are
> flagged as such. Nothing here claims production readiness, PCI DSS,
> ISO 27001, SOC 2, or any regulatory compliance.

## 1. Simulated KYC can never approve a user

**Was:** demo/sandbox verification paths could produce approval-shaped
results, and `DEMO_SKIP_KYC` / `DEMO_AUTO_APPROVE` defaulted on.

**Now:**
- `KycStatusMachine::approve()` requires a real basis —
  `provider_verdict` or `manual_review` — and throws otherwise:
  *"Simulated results can never approve a user."*
- `SandboxIranianKycProvider` always returns `verified: false`,
  `status: requires_manual_review`; it approves nothing.
- `EnhancedKycService::completeVerification()` throws `LogicException`
  for sandbox / manual-review verifications.
- `KycVerificationGate` blocks simulated providers in production;
  biometric similarity / face-match / document-authenticity never pass in
  production; no face-template id is issued in production.
- `config/demo.php`: `skip_kyc` / `auto_approve` default to **false** under
  `APP_ENV=production`; `ops:verify-env` reports `demo.features` **FAIL**
  (deploy-blocking) when any demo toggle is on in production.

**Proof:** `KycHardeningPhase41Test` + `KycSandboxGateTest`
("a simulated provider result never passes, even in the sandbox", "fake
face match never passes, even in the sandbox", "demo skip_kyc and
auto_approve default to off under APP_ENV=production", "the deploy
preflight FAILs (blocking) when demo flags are on") — in the Compliance/KYC
group, **80 passed (223 assertions)**.

**Remaining:** `DEMO_SKIP_KYC` / `DEMO_AUTO_APPROVE` still default **on**
in non-production environments by design; no real liveness/document vendor
implements `verifyLiveness()` / `verifyDocument()`; no Iranian KYC provider
exists (blocker B3).

## 2. Ledger reversal no longer double-counts

**Was:** reversing a posted entry could restore balances twice.

**Now:** `LedgerService::reverse()` creates a new balanced entry with
debits/credits swapped and marks the original `REVERSED`; balances are
restored exactly once.

**Proof:** `LedgerHardeningTest` ("restores balances exactly once when a
posted entry is reversed") — Ledger group **48 passed (157 assertions)**.

## 3. An unavailable ledger is never mistaken for a zero balance

**Was:** the TigerBeetle driver returned `0.0000` / empty history when it
could not be reached — indistinguishable from a real zero.

**Now:** `TigerBeetleDriver` throws `LedgerUnavailableException` on
connect failure, on balance/history reads, and when per-transfer sync
results report a failure. Callers must treat this as "unknown".

**Proof:** `LedgerHardeningPhase42Test` ("throws instead of returning a
fabricated zero balance when TigerBeetle is unreachable", "throws instead
of returning an empty history when TigerBeetle is unreachable").

## 4. Failed external ledger sync is recoverable (outbox)

**Was:** a driver sync failure after the journal commit lost the sync with
no durable record.

**Now:** the failure is recorded durably (`ledger_driver_syncs`, status
pending) while the posting succeeds; `ReconciliationService::
retryPendingDriverSyncs()` re-sends with the same deterministic transfer
ids (TigerBeetle deduplicates instead of double-posting); rows are marked
synced only after the driver confirms.
`php artisan ledger:retry-driver-sync` exits 0 when every retried row
synced, 1 otherwise, and never posts an entry or changes a balance.

**Proof:** `LedgerHardeningPhase42Test` ("records a failed driver sync in
the outbox instead of failing the posting", "retries pending syncs and
marks them synced only after the driver confirms", "retries pending
driver syncs through the artisan command").

**Known gap:** the retry command resolves the driver from
`ledger.driver`, while `AppServiceProvider` hardcodes the Eloquent driver
and ignores that config key.

## 5. Replays cannot duplicate journals

**Was:** a replayed event or a retried post could create a second journal
entry.

**Now:** `LedgerService::postIdempotent()` claims the idempotency key in
the database (TTL 120 s, `ledger.idempotency_claim_ttl_seconds`); a
same-payload replay returns the original entry; a different payload under
the same key throws; a stale claim left by a crash is reclaimable; a
failed posting releases the claim. The GL listener uses
`gl:{event}:{eventId}` as its key, so a replayed event maps to the same
key. Failed auto-postings are recorded in `ledger_posting_failures` and
rethrown, never swallowed.

**Proof:** `LedgerHardeningPhase42Test` ("returns the original entry for a
same-payload replay", "refuses a replay with a different payload under the
same key", "reclaims a stale claim left by a crash instead of blocking the
key forever") and `PostGlEntryListenerTest`.

## 6. Disabled Iranian rails cannot initiate (dual gate)

**Was:** no explicit gate; a rail could be addressed without proving it
was enabled or that simulation was allowed.

**Now:** `SandboxIranianRailAdapter` enforces two gates — the rail must be
enabled (`payment_rails.<rail>.enabled`) **and** simulation must be
allowed (`DemoModeGuard`: flag on **and** env local/testing/demo). Every
operation starts with `assertRailEnabled()`; a disabled rail throws a
`RuntimeException` naming the rail and the config key. SATNA / PAYA / PEL /
card-to-card are all `enabled=false` by default, and no live endpoint,
credential, or message format exists anywhere in the repo — none is
invented. Unknown transfers stay unknown: no settle, fail, re-mark,
cancel, or auto-recovery.

**Proof:** `SandboxIranianRailAdapterTest` ("refuses to initiate a transfer
on a disabled rail even when simulation is allowed", "fails closed in
production", "keeps an unknown transfer unknown…") — Banking/Rails group
**75 passed (263 assertions)**. Full design: `docs/IRANIAN_PAYMENT_RAILS.md`.

## 7. Trading settlement cannot double-fill or double-settle

**Was:** concurrent settlement could over-fill orders and post legs twice.

**Now:** `TradeSettlementService` settles in durable phases — (A) lock both
order rows in deterministic order and clamp to the genuine remainder
(never double-fill); (B) post ledger legs outside any open transaction via
idempotent posting (a replay replays the posted leg); (C) finalise in one
transaction (consume holds exactly once, flip `settlement_status` to
`settled`). A per-trade cache lock serialises concurrent `settle()` calls;
`settle()` refuses to run inside an open business transaction. A trade
interrupted between claim and finalise resumes without double-posting.

**Proof:** `TradeSettlementHardeningTest` ("clamps an oversized settlement
to the remaining amounts and never double-fills", "resumes a trade
interrupted between claim and finalise without double-posting", "settles a
workflow match under the match trade id, and a replayed matchOrder is a
no-op") — Exchange group **32 passed (143 assertions)**.

## 8. Precision: refused, never silently truncated

**Was:** amounts could be truncated or guessed into the wrong precision.

**Now:** the ledger posts at scale 4 (`decimal(20,4)`); crypto amounts
needing more decimals are refused (`LedgerPrecisionException`); unknown
asset codes are refused fail-closed by `CurrencyPrecisionRegistry` instead
of getting a guessed precision; trade amounts/prices beyond the asset
precision are rejected before posting; a dust trade that truncates to zero
is refused. One pinned exception: sub-`0.0001` fiat dust truncates (does
not fail) — deliberate and test-pinned.

**Proof:** `LedgerHardeningPhase42Test`, `WalletPhase3HardeningTest`
("refuses an unknown currency instead of guessing its precision",
"settles a crypto conversion at the asset native precision"),
`TradeSettlementHardeningTest` — Wallet/Card group **27 passed
(88 assertions)**.

## 9. Provider outcomes are explicit: SUCCESS / FAILED / UNKNOWN

**Was:** provider results were booleans or exceptions, so a timeout looked
like a rejection.

**Now:** the provider kernel (`Banking/Providers/Support/` — registry,
circuit breaker, idempotency store, audit logger, rate limiter, request
signer, webhook verifier) returns `ProviderResult` with
`ProviderOutcome::SUCCESS | FAILED | UNKNOWN`. UNKNOWN (timeout, ambiguous
response, lost webhook) is never auto-failed and never retried as a new
operation.

**Proof:** `ProviderKernelTest`, `BankIntegrationHardeningTest` —
Banking/Rails group **75 passed (263 assertions)**. Design:
`docs/BANKING_INTEGRATION_DESIGN.md`.

## 10. Account opening never fabricates credentials

**Was:** (risk) generated account numbers / IBANs could be mistaken for
real ones.

**Now:** `BankAccountOpeningService` copies the account number and IBAN
**only** from the provider response into encrypted rows; the sandbox
adapter's deterministic `SBX…` numbers and structurally-valid DE test
IBANs carry `metadata.sandbox=true`, and its constructor throws in
production. A provider rejection → `rejected` + exception; an
unclassifiable outcome fails closed. KYC eligibility is checked via
`KycService::isKycApproved()` (no duplicated KYC logic).

**Proof:** `BankAccountOpeningReconciliationTest`,
`BankIntegrationHardeningTest` — Banking/Rails group **75 passed**.

## 11. Wallet precision and custody gates

**Was:** sandbox rates and mock custodians could be mistaken for live ones.

**Now:** sandbox rates are labelled `sandbox_mock`, never as a live API
rate, and are served only when sandbox rates are allowed; quotes priced
from the mock provider are labelled `sandbox` with the serving provider
recorded. Custody fails closed when no custodian is configured, and fails
closed for a mock custodian in production; the default model is
non-custodial. Conversions settle at the asset native precision with exact
decimal math (no float).

**Proof:** `WalletPhase3HardeningTest` — Wallet/Card group **27 passed**.

## 12. SafeDeal counterparty verification requires approved platform KYC *(working tree, uncommitted sibling work)*

**Was (HEAD `2d6ca15`):** any authenticated party could self-assert
VERIFIED by posting an arbitrary string (e.g. `"fake-123"`) as
`kyc_reference`.

**Now (working tree only — Security sibling, uncommitted):**
`CounterpartyVerificationService::verify()` requires the party's platform
KYC to be approved (`users.kyc_status = 'approved'`, set by `KycService`
from a real provider verdict or a manual-review decision); level ENHANCED
additionally requires platform KYC level `enhanced`/`full`. The reference
is recorded as evidence only. Dispute resolution is admin-only and the
resolver id must belong to a real admin user. The SafeDeal tests were
updated in the same change (KYC-approved fixtures, real admin fixture).

**Proof:** SafeDeal group **19 passed (105 assertions)** in the working
tree. **At HEAD alone this fix does not exist** — do not present the
hardened behaviour as committed until the sibling's change lands.

## 13. Payment intents separate production from sandbox

**Now:** `PaymentIntentService` (idempotent intents with direction/kind),
`ProductionPaymentService`, and `SandboxPaymentService` are separate;
`ops:verify-env` fails the deploy when `SANDBOX_MODE=true` routes payments
through the sandbox service in production. Payment rails are gated by
`RailTransmissionGate` (fails closed when no rail is enabled).

**Proof:** `PaymentIntentServiceTest`, `SandboxPaymentServiceTest`,
`RailTransmissionGateTest` — Banking/Rails group **75 passed**.

## 14. Transfer fees are a separate ledger entry

**Now:** the fee is computed with exact decimal arithmetic and posted as
its own ledger entry, not folded into the principal entry (international
transfers included).

**Proof:** `TransferFeeLedgerTest`; `InternationalTransferServiceTest`
("posts the fee as a separate ledger entry") — International group
**36 passed (194 assertions)**.

## 15. Test-harness fix made in Phase 9 (this session)

- **Combined-run fatal:** `reconService()` was declared in two test files
  (`BankAccountOpeningReconciliationTest.php:26` and
  `International/ReconciliationServiceTest.php:71`), so the combined suite
  could not run in one process (`Cannot redeclare function`). Fixed by
  renaming the International helper to `intlReconService()` (2 lines,
  uncommitted). Combined suite then runs: **317 passed (1,173 assertions),
  exit 0**.
- **PHPCS:** 11 auto-fixable PSR-12 violations fixed with `phpcbf` in
  `InternationalReconciliationService`, `InternationalTransferService`,
  `QuotationService`, `TradeRiskAssessmentService` (uncommitted).

## 16. What was found but NOT fixed (debt)

- **PHPStan level 8, SafeDeal + International: 49 errors, all in SafeDeal,
  0 in International.** Largest groups: `SafeDealTradeController` (19 —
  `$request->user()` nullable, `$id`/`hasRole()` on `User|null`),
  `SafeDealService` (14 — bcmath `numeric-string` types, missing iterable
  value types, nullable returns), `SafeDealSettlementService` (6). These
  include the sibling's uncommitted Phase-8 code. Not fixed in Phase 9:
  validation only.
- **PHPCS: 7 errors left, all in test files** — stub classes declared
  inside Pest test files without a namespace
  (`InternationalTransferServiceTest` 3, `ReconciliationServiceTest` 1,
  `SafeDealStateMachineTest` 3). App code is clean after §15.
- **Combined-run flake (transient):** the first combined run failed 11
  SafeDeal tests (`Counterparty … platform KYC is not approved`) because
  the sibling Security agent was editing the SafeDeal test files mid-run
  (the KYC-approval fixture lines landed after Pest had loaded the files).
  Re-runs after the edit completed pass: combined **317 passed**,
  probe run **318 passed**. See `docs/TEST_COVERAGE_REPORT.md` §5.
- **Known gap:** `AppServiceProvider` hardcodes the Eloquent driver and
  ignores `ledger.driver` (see §4).
- **`/tmp` full:** the VM's 512 MB tmpfs was 100% full during this session
  (stale PHPStan caches from earlier phases), which broke the first PHPStan
  runs; worked around with `TMPDIR` on `/home` (147 GB free). The stale
  caches belong to other agents' runs and were not deleted.

## 17. Unresolved production dependencies (B1–B8, standing)

No live provider is contracted. Everything below still needs external
contracts, credentials, and approvals before any production use:

- **B1** live bank integration (account opening / statements / transfers)
- **B2** live card issuer (Marqeta / FinCard / Rain programme onboarding)
- **B3** live KYC vendor (liveness/document) and an Iranian KYC provider
- **B4** custody (custodian contract; mock custodian is refused in prod)
- **B5** HSM / key management for production signing
- **B6** live meeting provider (Jitsi deployment / credentials)
- **B7** Iranian rails: CBI / partner-bank API documentation, licensed
  bank/PSP partnership + sandbox credentials, compliance/sanctions review
- **B8** live market-data / exchange-rate provider (all current rates are
  sandbox-labelled or fixed demo values)

(Detail: `docs/IMPLEMENTATION_STATUS.md` §9; `docs/ENGINEERING_BASELINE.md`.)
