# Financial Ledger Design — Implemented & Verified

> **Status:** 2026-11-24, hardening session Phases 0–5, branch
> `feat/hardening-phase0-9` (HEAD `2d6ca15`).
> **Scope:** this document describes only code that exists and is covered by
> the passing test suites listed in `docs/TEST_COVERAGE_REPORT.md`. It is not
> a production-readiness claim: no live bank, card, KYC-vendor, custodian, or
> market-data integration exists, and the ledger has never run against a
> production database (see §9).

## 1. Principles

1. **The DB journal is authoritative.** `JournalEntry` + `JournalLine` rows in
   the application database are the ledger. The external driver
   (TigerBeetle) is a *sync target*, never the source of truth.
2. **Every posting is balanced or it does not happen.** Debits must equal
   credits exactly, at the posting scale, before anything is persisted.
3. **Money is decimal strings, never float.** All arithmetic is bcmath at a
   fixed scale (`LedgerService`, `DecimalMath`, `LedgerPrecision`).
4. **Fail closed, never fabricate.** An unavailable ledger throws
   `LedgerUnavailableException`; it never answers `0.0000`. A refused
   amount throws; it is never truncated into a different amount (except the
   one pinned fiat-dust behaviour in §4).
5. **Idempotency is durable, not in-memory.** Claims live in the database
   with a TTL, so a crash cannot lose them and a replay cannot double-post.

## 2. Posting path

`App\Domain\Ledger\Services\LedgerService`

| Method | Behaviour |
|---|---|
| `postIdempotent($lines, $idempotencyKey, …)` | Claims the key, posts once, returns the original entry on a same-payload replay. A different payload under the same key throws. An empty key throws. |
| `post($lines, …)` | Validates and persists a balanced entry + lines inside one DB transaction. |
| `postGuarded($lines, …)` | As `post`, plus an overdraw guard: a debit-normal account may not go negative. The guard reads the **DB journal**, so it still enforces when the external driver is down. A guarded account code must exist in the chart of accounts. |
| `reverse($entryId, …)` | Creates a new balanced entry with debits/credits swapped and marks the original `REVERSED`. Balances are restored exactly once — the earlier double-count on reversal is fixed and covered by test. |

### 2.1 Validation (all enforced before persistence)

- At least two lines; every line needs an `account_code`.
- Amounts must be finite decimal numbers, never negative.
- `Σ debit == Σ credit` at the posting scale, else
  `RuntimeException: "Journal entry is unbalanced: debits=…,
  credits=…"`. A zero-total entry is rejected.
- No mixed currencies in one entry. A line's explicit currency must match
  its account's currency; when no currency is given it is inherited from the
  account (never a global default that could mislabel EUR as USD).
- Posting to an inactive account is rejected. When
  `ledger.require_registered_accounts` is on, unregistered account codes are
  rejected (default off: trading virtual accounts such as
  `trading:account:{id}:{ccy}` are not chart rows).

### 2.2 Idempotency claims

- A claim with no journal entry attached is treated as stale after
  `ledger.idempotency_claim_ttl_seconds` (default **120 s**) and may be
  reclaimed by the next caller instead of blocking the key forever.
- If posting fails, the claim is released so the key can be retried.
- While a claim is being processed, a duplicate call throws
  `Duplicate idempotency key … is still being processed` (with the previous
  exception attached).

## 3. Precision model

`config/ledger.php` (`posting_scale`, default **4**), `LedgerPrecision`,
`CurrencyPrecisionRegistry` (`app/Domain/Asset/ValueObjects/`).

- Journal lines are `decimal(20,4)`; the ledger posts at scale 4.
- Native scales: BTC 8, ETH 8, SOL 9, USDT/USDC/DAI 6, GCU 4; fiat uses the
  ISO-4217 minor-unit exponent (≤ 3).
- An amount that needs more decimals than the posting scale is **refused,
  never truncated** (e.g. a crypto amount the 4-decimal ledger cannot
  represent throws `LedgerPrecisionException`). Unknown asset codes are
  refused fail-closed instead of getting a guessed precision.
- **Pinned exception:** sub-`0.0001` fiat dust truncates (it does not fail).
  This is deliberate, tested, and pinned — do not "fix" it without changing
  the test that pins it (`LedgerHardeningPhase42Test`).
- Trade amounts/prices beyond the asset precision are rejected by the
  settlement path before posting (`TradeSettlementHardeningTest`: "rejects
  order amounts and prices beyond the asset precision"; "refuses a dust
  trade that truncates to zero in the ledger").

## 4. Drivers and the unavailable-ledger rule

`App\Domain\Ledger\Services\Drivers\{EloquentDriver,TigerBeetleDriver}`

- **EloquentDriver** reads balances from the DB journal. An account code
  with no chart row still reports the sum of its journal lines (the DB read
  succeeded, so an empty sum is a real zero, not a fabricated one). This is
  what `FiatWalletService` and `ConversionService` rely on: they read a
  wallet balance before its account row is created.
- **TigerBeetleDriver throws `LedgerUnavailableException` instead of
  answering `0.0000`** — on connect failure, on balance/history reads, and
  when per-transfer sync results report a failure. Callers must treat this
  as "unknown", never as "zero balance". (The previous version returned
  zero/empty on failure; that behaviour is removed and covered by test.)
- A multi-leg entry is decomposed into amount-paired transfers with
  deterministic transfer ids, so TigerBeetle deduplicates a retried sync
  instead of double-posting.

## 5. Outbox and recovery of failed external sync

`LedgerDriverSync`, `LedgerPostingFailure`, `ReconciliationService`,
`ledger:retry-driver-sync`
(`App\Domain\Ledger\Console\Commands\RetryDriverSyncCommand`).

- Posting and driver sync are separate steps. If the driver sync fails
  **after** the journal commit, the posting still succeeds and the failure
  is recorded durably in the outbox (`ledger_driver_syncs`, status pending)
  — the entry is never lost and never double-posted.
- Retry re-sends the entry with the same deterministic transfer ids; rows
  are marked synced only after the driver confirms. Exit code 0 when every
  retried row synced, 1 when any row is still pending/failed. The command
  never posts a journal entry and never changes a balance.
- Known gap: the command resolves the driver from `ledger.driver`, while
  `AppServiceProvider` hardcodes the Eloquent driver and ignores that config
  key — see `docs/CRITICAL_FIXES_REPORT.md`.

## 6. Event-sourced auto-posting

`PostGlEntryListener` (Spatie Reactor) + `PostingRuleEngine`.

- Every stored event is offered to the posting rules; a matching rule posts
  a journal entry, a non-matching event is ignored.
- The idempotency key is `gl:{event}:{eventId}`, so **a replayed event maps
  to the same key and returns the original entry instead of duplicating the
  journal**.
- Minor-unit `Money` objects are converted to major units before posting
  (cents are never mis-posted as whole units).
- A failed auto-posting is recorded durably in
  `ledger_posting_failures` and rethrown — never swallowed.
- Migration: `database/migrations/2026_11_24_000002_ledger_posting_failures_and_driver_sync.php`.

## 7. Trading settlement (ledger side)

`TradeSettlementService` — durable phases, never one transaction around an
external sync:

- **A. Claim + record** — one transaction: lock both order rows in a
  deterministic order (deadlock-safe), clamp to what is genuinely left on
  each order (never double-fill), persist the match.
- **B. Ledger legs** — outside any open transaction, via idempotent posting;
  a replay replays the posted leg instead of double-posting it.
- **C. Finalise** — one transaction: re-check the `Trade` row, consume the
  holds exactly once, flip `metadata.settlement_status` to `settled`. A
  trade is only *returned* as settled once the entries are durable.
- A per-trade cache lock serialises concurrent `settle()` calls; a second
  caller gets `Trade … is already being settled by another process`.
  `settle()` refuses to run inside an open business DB transaction (the
  test-suite transaction is exempt) so the driver sync lands after a real
  commit.
- A trade interrupted between claim and finalise resumes without
  double-posting; a trade that settles while the driver sync fails still
  settles, with the failed sync recorded in the outbox (§5).

## 8. Fees

- Transfer fees are posted as a **separate ledger entry**, not folded into
  the principal entry (`TransferFeeLedgerTest`;
  `InternationalTransferServiceTest`: "posts the fee as a separate ledger
  entry").
- Internal transfers use integer minor units; the fee is computed with exact
  decimal arithmetic (`TransferFeeCalculator`, `config/payment_rails.php`
  `transfers.fee_basis_points` / `fee_fixed_minor`, both 0 by default).

## 9. What is NOT verified (honest limits)

- **No MySQL/PostgreSQL run.** All tests run on sqlite `:memory:` (see
  `phpunit.ci.xml`). The migrations run cleanly on a fresh sqlite schema
  (proven by every passing suite, which migrates from scratch), but MySQL /
  MariaDB behaviour — including `FOR UPDATE` row locking, which sqlite
  ignores — is **not** verified. No MySQL server or binaries exist in this
  VM; Docker is not installed.
- **No live TigerBeetle run.** The driver is tested against failure modes
  (unreachable → throws), not against a real TigerBeetle cluster.
- **No concurrent-process test.** Balance invariants under concurrency are
  verified through the serialising locks and the guarded-posting tests, not
  through a multi-process race (the test cache store is `array`; Redis is
  reachable in this VM but the suites do not use it).
- **No production database, no production run.** Nothing in this document
  claims production readiness, PCI DSS, ISO 27001, SOC 2, or any regulatory
  compliance.

## 10. Tests (observed 2026-11-24, this session)

Ledger group, `phpunit.ci.xml`, `--default-time-limit=300`:
**48 passed, 0 failed (157 assertions)** — `LedgerHardeningTest`,
`LedgerHardeningPhase42Test`, `TransferFeeLedgerTest`,
`PostGlEntryListenerTest`, `TigerBeetleDriverTest`. See
`docs/TEST_COVERAGE_REPORT.md` for the per-suite table and the one
combined-run anomaly (test-order pollution affecting SafeDeal only, fixed
in part; full detail there).
