# International Banking & P2P — Design

> **Status:** implemented 2026-11-24, branch `feat/hardening-phase0-9`, uncommitted.
> **Scope:** provider-independent architecture for international financial
> operations. No SWIFT / SEPA / Fedwire / ACH / RTP / FedNow connectivity
> exists in this codebase, and none is invented here. Every external rail
> is explicitly disabled (fail-closed), and this document states exactly
> what unblocks one.

## 0. Placement decision

Inspected before building: `Banking` (SEPA/SWIFT message services and
connectors, Iranian sandbox rails), `Payment` (domestic transfer service,
fees), `PaymentRails` (US rails + a router that already "routes" SEPA/SWIFT
by name), `ISO20022` (message construction/validation only), `Treasury`
(cash/risk), `Compliance` (KYC/AML screening services).

None of them owns the international P2P workflow, and none may be
extended to pretend a rail exists:

- `Banking`'s SEPA/SWIFT services build messages; they are not execution
  providers and this domain does not call them.
- `PaymentRails`' router selects rails by country/currency; it has no
  international settlement, screening, or multi-currency account concept.
- `ISO20022` validates messages; it holds no money state.
- `Treasury` / `Compliance` are supporting contexts, reused through
  interfaces only (ledger, sanctions-screening interface).

So the work lives in a small new bounded context,
`app/Domain/International/`, following the repo's DDD conventions
(`Enums/`, `Models/`, `Services/`, `Contracts/`, `Http/Controllers/`,
`Routes/api.php`, `module.json`). It depends on `Ledger` (settlement),
`Compliance` (screening interface) and `Payment` (fee arithmetic only).
Routes are auto-loaded by `ModuleRouteLoader`; no bootstrap change was
needed. One file outside the domain was added because the task requires
migrations and the repo has no domain-migration loading:
`database/migrations/2026_11_24_120000_create_international_tables.php`
(new file; no existing file was modified).

## 1. What is built

| Capability | Where |
|---|---|
| Multi-currency accounts (one row per user+currency; balance derived from the ledger, never stored) | `Models/InternationalAccount.php`, `Services/InternationalAccountService.php` |
| Counterparty + beneficiary management (last-4 hints only — no full account numbers / IBANs) | `Models/Counterparty.php`, `Models/Beneficiary.php`, `Http/Controllers/CounterpartyController.php` |
| Transfer purpose (+ mandatory detail for `other`) | `Enums/TransferPurpose.php` |
| Reference numbers (`INTL-…`, internal end-to-end ref, operator-supplied external ref) | `ValueObjects/InternationalReference.php` |
| Exchange quotations (operator-supplied rate, TTL, exact bcmath) | `Models/ExchangeQuotation.php`, `Services/QuotationService.php`, `ValueObjects/ExchangeRate.php` |
| Transfer fees (bps + fixed, separate ledger entry) | `Services/TransferFeeService.php` (reuses `Payment`'s `TransferFeeCalculator`) |
| Status tracking + transaction history | `Models/InternationalTransferStatusHistory.php`, `GET …/transfers/{id}/history` |
| Settlement instructions | `Models/SettlementInstruction.php` |
| Reconciliation (clearing + statement matching) | `Services/InternationalReconciliationService.php` |
| Compliance screening integration points | `Contracts/InternationalScreeningInterface.php`, `Services/ManualReviewScreeningAdapter.php`, `Services/AmlScreeningAdapter.php` |
| Audit trail (append-only) | `Models/InternationalAuditLog.php`, `Services/InternationalAuditService.php` |
| API | `Routes/api.php` → `/api/v1/international/*` |
| State machine | `Enums/InternationalTransferStatus.php`, `Services/InternationalTransferService.php` |

## 2. Rails: everything is disabled, on purpose

`Enums/InternationalRail.php` names SWIFT, SEPA, SEPA Instant, Fedwire,
ACH, RTP, FedNow. Each rail's `isEnabled()` reads
`config('international.<rail>.enabled')` and defaults to **false** — no
config file is shipped, so every rail is disabled until an operator
enables it AND a provider is registered.

The only gateway implementation is `DisabledInternationalRailGateway`:
`dispatch()`, `status()` and `cancel()` all throw
`InternationalRailNotAvailableException` naming the rail and stating
that no provider, endpoint or credential exists. There is no retryable
transient here — the message says so.

`InternationalTransferService::attemptExternalDispatch()` is the proof
point: on the (always thrown) refusal it reverses the posted ledger
legs, marks the transfer `failed`, blocks the settlement instruction,
and rethrows — so a disabled route provably cannot execute and no money
is stranded. Covered by
`tests/Domain/International/Services/DisabledRailGatewayTest.php` (every
rail × every method) and by the service + API tests.

**What unblocks a real rail** (all three, in order): (1) a licensed
provider/partner contract with official API documentation, (2) sandbox
credentials, (3) a compliance/sanctions review. Then: implement
`InternationalRailGatewayInterface` against the real provider, bind it
(`Providers/InternationalServiceProvider.php` is the one place — register
it with one line in `bootstrap/providers.php`), and enable the rail's
config flag. Enabling the flag alone never executes anything.

## 3. State machine

```
draft → submitted → screening → approved → processing → settled
                               ↘ rejected
approved → reversed          processing → failed | reversed
```

`settled`, `rejected`, `failed`, `reversed` are terminal. Transitions
are enforced by `InternationalTransferStatus::canTransitionTo()` under a
row lock (`lockForUpdate`) in `InternationalTransferService::transition()`;
every transition writes a status-history row and an audit row. A settled
transfer is never edited — a correction is a new transfer (or an
operator reversal entry).

| Transition | Actor | Money effect |
|---|---|---|
| `draft → submitted` | owner | none |
| `submitted → screening` | operator | screening check runs |
| `screening → approved` | screening CLEAR or officer decision | none |
| `screening → rejected` | screening HIT or officer decision | none |
| `approved → processing` | owner | legs posted (see §4) |
| `processing → settled` | operator + external reference | clearing → settled leg |
| `processing → failed` | dispatch refusal | legs reversed, funds returned |
| `approved/processing → reversed` | owner/operator + reason | legs reversed (if any) |

## 4. Money flow — ledger only, never float

Customer funds are a platform **liability** (credit-normal):
`intl:account:{uuid}`. Funding credits it; a transfer debits it.
`LedgerService::postGuarded()` guards the sender account, so a transfer
that would overdraw it is refused and the transfer stays `approved`
with no legs posted.

`process()` (approved → processing), in one DB transaction:

1. **Fee entry** (only when `fee_minor > 0`) — a SEPARATE balanced
   entry: debit sender / credit `intl:fees:{CCY}` (revenue). This
   closes the audit finding that the transfer fee was not posted as a
   separate ledger entry.
2. **Principal entry**: debit sender / credit `intl:clearing:{CCY}`
   (liability). The funds sit in a named clearing account, linked to
   the transfer by `journal_entry_id` — that is a balanced ledger
   position, not a float: every unit is accounted for on both sides.

`settle()` (processing → settled, operator only, external reference
required): pre-checks the clearing credit balance covers the principal,
then posts via `postIdempotent("{key}:settle")`: debit clearing /
credit `intl:settled:{CCY}`. This records a settlement that happened
**outside** this system (the operator supplies the provider reference
proving the funds were remitted); it does not execute one. The
settlement instruction moves to `executed` with that reference.

Reversal uses `LedgerService::reverse()` (mirror entries). Original
entries are never edited. `failTransfer()` reverses principal + fee.

All amounts are integer minor units in the domain and decimal strings
(scale 4) in ledger lines; arithmetic is bcmath only, never floats.
Same-currency transfers need no quotation; cross-currency transfers
require an accepted, unexpired quotation matching the exact amount and
pair (see §5).

## 5. Exchange quotations

There is **no live FX feed**. `QuotationService::createQuotation()`
takes a rate explicitly supplied by an operator / treasury desk
(`source = 'manual'`, recorded on the row). `ExchangeRate` validates a
positive decimal rate and converts minor units with one bcmath chain,
**truncated** — a quote never promises more destination currency than
the rate supports. Quotes expire after their TTL (default 15 min);
`acceptQuotation()` refuses expired quotes, and
`usableQuotationFor()` enforces ownership + accepted + unexpired +
exact currency/amount match. A future licensed feed implements
`ExchangeRateProviderInterface`; the interface is deliberately unbound
in the provider (resolving it throws a clear error).

## 6. Fees

`TransferFeeService` wraps the domestic `TransferFeeCalculator`:
`fee = amount × bps / 10_000 + fixed`, integer minor units, truncated.
Defaults are **zero** (`international.fee_basis_points`,
`international.fee_fixed_minor`) — no fee is invented; an operator sets
them explicitly. The fee is always a separate ledger entry (§4).

## 7. Compliance screening integration points

`InternationalScreeningInterface::screen(Counterparty, Beneficiary)` →
`ScreeningResult{outcome, provider, matches, detail}` with outcomes
`clear | hit | manual_review_required | error`.

- **Default: `ManualReviewScreeningAdapter`.** Every check answers
  `manual_review_required`; the transfer stays in `screening` until a
  compliance officer records a decision (`recordScreeningDecision`,
  reason required to reject). Nothing is ever auto-cleared by the
  absence of a provider.
- **`AmlScreeningAdapter`** wraps the existing
  `SanctionsScreeningInterface` (hit → reject, clean → clear, provider
  error → `error`, fail-closed). Bind it ONLY with a real sanctions
  provider behind that interface: the local simulation behind it is not
  a sanctions clearance and must never auto-approve a transfer.
- `beginScreening()` maps HIT → `rejected`, CLEAR → `approved`,
  manual/error → stays in `screening`. The outcome, provider and match
  count are stored on the counterparty and the transfer, and audited.

## 8. Settlement instructions

Created at `process()`: rail, charge bearer (`our/sha/ben`, default
`sha`), value date, remittance information. Statuses: `pending →
executed | cancelled | blocked`. `executed` means "an operator recorded
an external settlement confirmation", never "we sent it". `blocked` is
set when dispatch is refused. Instructions hold no endpoint, credential
or provider session.

## 9. Reconciliation

`InternationalReconciliationService`, exact bcmath (scale 4):

1. **Clearing**: `intl:clearing:{CCY}` ledger balance (credit − debit)
   must equal the sum of principals of transfers in `processing`.
   Any variance → `discrepancy` report for manual review.
2. **Statement matching**: operator-supplied external statement rows
   (`reference`, `amount_minor`, `currency`) are matched against
   settled transfers by reference + amount. Unmatched rows and
   unmatched settled transfers are listed in the report details; nothing
   is auto-corrected.

Reports are stored in `international_reconciliation_reports` and the
run is audited.

## 10. Idempotency

- Transfer creation requires the `Idempotency-Key` **header** (422
  `MISSING_IDEMPOTENCY_KEY` without it). The key is unique; a replay
  with the same key + same payload hash returns the original transfer;
  a replay with a different payload is a 409 `IDEMPOTENCY_CONFLICT`.
- Settlement posts via `LedgerService::postIdempotent("{key}:settle")`.
- `process()` reuses stored entry ids on retry instead of posting again.

## 11. API surface (`/api/v1/international`, `auth:sanctum`)

Owner: `GET/POST /accounts`, `GET /accounts/{id}` (ledger-derived
balance), `GET/POST /counterparties`, `GET/POST
/counterparties/{id}/beneficiaries`, `POST /quotations`,
`POST /quotations/{id}/accept`, `GET/POST /transfers`,
`GET /transfers/{id}`, `GET /transfers/{id}/history`,
`POST /transfers/{id}/submit|process|reverse`.

Operator (compliance role via route middleware): `POST
/transfers/{id}/screening`, `POST /transfers/{id}/screening-decision`,
`POST /transfers/{id}/dispatch`, `POST /transfers/{id}/settle`,
`POST /reconciliation/clearing|statement`, `GET /reconciliation/{id}`.

Ownership is enforced by `Policies/InternationalTransferPolicy.php`
(invoked directly by the controllers — the model lives in a domain
namespace, so Laravel's convention-based discovery does not apply).
Error codes: `MISSING_IDEMPOTENCY_KEY`, `IDEMPOTENCY_CONFLICT`,
`INVALID_TRANSITION`, `RAIL_NOT_AVAILABLE`, `QUOTATION_REQUIRED`,
`VALIDATION_ERROR`, `FORBIDDEN`, `NOT_FOUND`.

## 12. Data stored — and deliberately not stored

Stored: references, minor-unit amounts, currencies, purposes, statuses,
masked beneficiary hints (`iban_last4`, `account_last4`), bank name,
BIC (public routing data), screening outcomes, ledger entry links,
audit/history rows.

**Not stored:** full account numbers, full IBANs, card numbers, provider
endpoints, credentials, or secrets — following the
`IranianRailTransfer` precedent. Full destination account details live
with the (not yet connected) provider.

## 13. Configuration

No config file is shipped; all switches default fail-closed:

| Key | Default | Meaning |
|---|---|---|
| `international.<rail>.enabled` | `false` | per-rail enable (needs a provider too) |
| `international.fee_basis_points` | `0` | fee rate |
| `international.fee_fixed_minor` | `0` | fixed fee (minor units) |

Provider wiring (future): register
`App\Domain\International\Providers\InternationalServiceProvider`
with one line in `bootstrap/providers.php`; it binds the gateway to
the disabled implementation and screening to manual review. Replacing
either binding is the single integration point for a real provider.

## 14. Tests

`tests/Domain/International/` — **36 tests, 194 assertions, all
passing** (`./vendor/bin/pest tests/Domain/International
--configuration=phpunit.ci.xml`):

- `Services/InternationalTransferServiceTest.php` — creation,
  idempotency replay/conflict, validation, happy path with balanced
  legs, overdraft refusal, separate fee entry, dispatch refusal +
  funds returned, reversal, invalid transitions, manual screening,
  screening hit, missing reason/reference.
- `Services/DisabledRailGatewayTest.php` — every rail disabled by
  default; dispatch/status/cancel refused on every rail.
- `Services/QuotationServiceTest.php` — exact truncated conversion,
  rate validation, accept/expiry, mismatch refusal.
- `Services/ScreeningServiceTest.php` — manual default, AML adapter
  hit/clear, provider error → `error`.
- `Services/ReconciliationServiceTest.php` — clearing matched,
  discrepancy on an unrelated posting, statement matching.
- `Models/InternationalAccountTest.php` — one account per currency,
  ledger-derived balance.
- `Http/InternationalApiTest.php` — account + transfer creation,
  idempotency-key requirement, cross-user 403, manual screening flow
  via API, dispatch 422 + balance restored.

Note: the default `phpunit.xml` (10 s per-test limit) cannot run the
full migration suite in this environment — the first test in each
process times out while migrating (pre-existing: the same failure hits
`tests/Domain/Ledger/Services/LedgerHardeningTest.php` on the clean
tree). The CI config (`phpunit.ci.xml`, 120 s limit) is the working
way to run these tests.

## 15. Blocked items / future work

1. **Live rails (B-blocker):** SWIFT/SEPA/Fedwire/ACH/RTP/FedNow
   execution needs a licensed provider contract, sandbox credentials
   and a compliance review. Until then the gateway stays disabled by
   design — this is not a code gap to "fix" by simulation.
2. **Real sanctions provider:** bind `AmlScreeningAdapter` only with a
   real provider behind `SanctionsScreeningInterface`.
3. **Live FX feed:** bind `ExchangeRateProviderInterface` only with a
   licensed feed; until then rates are operator-supplied.
4. **Provider registration:** one line in `bootstrap/providers.php`
   for `InternationalServiceProvider` (left unregistered deliberately —
   another agent owns that file in this session; the domain works
   without it via internal defaults).
5. **Concurrency:** `process()` serialises on the transfer row lock;
   a production deployment should add a per-transfer distributed lock
   for concurrent process calls (same residual note as the ledger's
   claim TTL).
6. **Full-suite run:** the repo-wide suite was not run (see §14 note
   about the default phpunit time limit); run the CI config in CI.
