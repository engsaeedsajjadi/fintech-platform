# International — provider-independent international banking & P2P

New bounded context (Phase Five). Full design: `docs/INTERNATIONAL_P2P_DESIGN.md`.

## What it is

- Multi-currency accounts (`InternationalAccount`, one row per user+currency;
  balance is **derived from the ledger**, never stored). The ledger
  account is a liability (customer funds), so funding credits it.
- Counterparties + beneficiaries (only last-4 account hints stored — no full
- Counterparties + beneficiaries (only last-4 account hints stored — no full
  account numbers / IBANs, following the `IranianRailTransfer` precedent).
- Exchange quotations with operator-supplied rates and a TTL (no live FX feed).
- International P2P transfers as a controlled state machine:
  `draft → submitted → screening → approved → processing → settled`,
  with `rejected` / `failed` / `reversed` terminals.
- Settlement **only** through `LedgerService` (`postGuarded` /
  `postIdempotent` / `reverse`): sender → `intl:clearing:{CCY}` on process,
  fee as a **separate** entry to `intl:fees:{CCY}`, clearing →
  `intl:settled:{CCY}` on operator-recorded settlement.
- Compliance screening integration points (`InternationalScreeningInterface`;
  default adapter is manual review — nothing auto-clears).
- Reconciliation: clearing-account vs open transfers, plus external
  statement matching. Append-only status history + audit log.

## What it is NOT

- No SWIFT / SEPA / Fedwire / ACH / RTP / FedNow connectivity exists.
  Every rail is disabled by default and `DisabledInternationalRailGateway`
  refuses every dispatch. `attemptExternalDispatch()` provably fails the
  transfer and returns the funds. See the design doc §2 for what unblocks
  a real rail (provider contract, sandbox credentials, compliance review).

## Key files

- `Enums/` — status machine, rails, purposes, screening outcomes
- `Models/` — accounts, counterparties, beneficiaries, transfers,
  quotations, settlement instructions, history, audit, reconciliation reports
- `Services/InternationalTransferService.php` — the workflow + ledger legs
- `Services/DisabledInternationalRailGateway.php` — the fail-closed gateway
- `Providers/InternationalServiceProvider.php` — bindings for the three
  provider seams (NOT registered in `bootstrap/providers.php` yet; the
  domain defaults internally, registration is the future wiring point)
- `Routes/api.php` — `/api/v1/international/*` (auto-loaded)
- Tests: `tests/Domain/International/`

## Run the tests

```bash
./vendor/bin/pest tests/Domain/International --configuration=phpunit.ci.xml
```

(The default `phpunit.xml` 10 s per-test limit cannot run the full
migration suite in this environment; the CI config is the working way.)
