# Iranian Payment Rails — Implemented & Verified

> **Status:** 2026-11-24, hardening session Phases 0–5, branch
> `feat/hardening-phase0-9` (HEAD `2d6ca15`).
> **Scope:** this document describes only what exists in the code and is
> covered by the passing tests. It invents no endpoint, credential, or
> message format: **there is no live Iranian rail in this codebase**.

## 1. The honest position

SATNA / PAYA / PEL (پل) / card-to-card do **not** exist as live rails here.
There is no CBI or bank API documentation, no endpoint, no credential, and
no message format anywhere in the repository, and none is invented. The
rails exist as **reserved, disabled configuration** plus one sandbox adapter
that simulates the local state machine and nothing else.

Production execution stays disabled until **all three** exist:

1. official CBI / partner-bank API documentation,
2. a licensed Iranian bank / PSP partnership and sandbox credentials,
3. a compliance / sanctions review for the intended users.

Until then, enabling any of these rails — or running the sandbox adapter
outside `local` / `testing` / `demo` — fails closed.

## 2. Configuration

`config/payment_rails.php` — every rail defaults to disabled:

| Rail | Env flag | Default |
|---|---|---|
| `satna` | `SATNA_ENABLED` | `false` |
| `paya` | `PAYA_ENABLED` | `false` |
| `pel` | `PEL_ENABLED` | `false` |
| `card_to_card` | `CARD_TO_CARD_ENABLED` | `false` |
| simulation gate | `PAYMENT_SIMULATION_ENABLED` | `false` |

The same file also keeps the US rails disabled by default (`ach`, `fedwire`,
`rtp`, `fednow` — all `enabled => env(…, false)`), and the internal-transfer
settings used by `PaymentTransferService` (integer minor units,
`fee_basis_points` / `fee_fixed_minor`, both 0 by default, and
`TRANSFER_HOLD_ACCOUNT_CODE` defaulting to `HOLD` — see §5).

## 3. The dual gate

`App\Domain\Banking\Connectors\SandboxIranianRailAdapter`
(`IranianRailAdapterInterface`).

A transfer may run only when **both** hold:

1. **Rail gate** — `payment_rails.<rail>.enabled` is true. This is about
   *which* rail may run. A rail configured as disabled can never initiate —
   or settle — a transfer.
2. **Simulation gate** — `DemoModeGuard::isSimulationAllowed()`: simulation
   is allowed only when the simulation flag is on **and** the app
   environment is `local`, `testing`, or `demo`. There is no flag that
   re-enables simulation in production.

`isAvailable()` returns true only when both gates hold; every public
operation starts with `assertRailEnabled()`, which throws a
`RuntimeException` naming the rail and the config key, e.g.:

> `Iranian rail 'satna' is disabled (payment_rails.satna.enabled=false);
> transfers are not allowed while the rail is disabled. Enable the rail
> explicitly to use the sandbox adapter.`

## 4. Sandbox state machine (local only)

The adapter simulates **only the local state machine** for a transfer:

- `initiateTransfer` — validates amount (positive integer in minor units),
  idempotency key (non-empty, ≤ 255 chars), and rail; a duplicate key
  returns the original transfer; a key that belongs to another rail is
  refused.
- Transitions: `fail`, `unknown`, `cancel`, `settle`, status checks. All of
  them are blocked while the rail is disabled, and a terminal transfer
  cannot be settled again.
- **Unknown stays unknown**: an `unknown` transfer cannot be settled,
  failed, re-marked, or cancelled, and there is no auto-recovery. The money
  state is explicitly not-known, and the code never guesses it back to
  success or failure.
- Every transition is persisted (unique-violation-safe) so a crash cannot
  lose the transfer state.

## 5. Internal transfers and the hold account

`PaymentTransferService` — internal transfers between ledger accounts:

- Amounts are integer minor units of the transfer currency; fees are
  computed with exact decimal arithmetic (`TransferFeeCalculator`) and
  posted as a **separate ledger entry** (see
  `docs/FINANCIAL_LEDGER_DESIGN.md` §8).
- Reserved funds sit in the hold account (`TRANSFER_HOLD_ACCOUNT_CODE`,
  default `HOLD`). Operators must create this account in the chart of
  accounts as an ASSET account; **the service fails closed when it is
  missing** instead of inventing one.

## 6. Payment transmission gate (US rails)

`App\Domain\PaymentRails\Services\RailTransmissionGate` — the transmission
gate for ACH / Fedwire / RTP / FedNow refuses to dispatch when no rail is
enabled, instead of silently dropping the payment
(`RailTransmissionGateTest`: "router fails closed when no rail is enabled
instead of dispatching or silently succeeding"). The router, `FedNowService`,
`FedwireService`, and `RtpService` are all gated the same way; no live
transmission endpoint is configured.

## 7. What is NOT built (do not present as done)

- No SATNA / PAYA / PEL / card-to-card API client, message format, or
  credentials — and no invented stand-in for any of them.
- No Shaparak / CBI integration, no IBAN validation against a live
  registry, no Iranian KYC provider (see `docs/CRITICAL_FIXES_REPORT.md`,
  blocker B3).
- No live end-to-end test against any Iranian bank or PSP sandbox — none
  exists to test against.

## 8. Tests (observed 2026-11-24, this session)

The rail tests run inside the Banking/Rails group (`phpunit.ci.xml`,
`--default-time-limit=300`): **75 passed, 0 failed (263 assertions)** for
the whole group, including `SandboxIranianRailAdapterTest`
("refuses to initiate a transfer on a disabled rail even when simulation
is allowed", "fails closed in production", "keeps an unknown transfer
unknown: no settle, fail, re-mark or cancel, no auto-recovery") and
`RailTransmissionGateTest`. See `docs/TEST_COVERAGE_REPORT.md`.
