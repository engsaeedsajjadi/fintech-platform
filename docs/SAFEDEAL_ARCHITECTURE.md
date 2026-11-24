# SafeDeal — Simulated Escrow Trade Lifecycle

> **Simulation only.** SafeDeal models a protected trade (escrow-style)
> lifecycle with **virtual ledger accounts inside the platform ledger**.
> It does **not** move real customer funds, hold custody, or advertise a
> regulated payment/escrow service. Real funding, custody, or card/bank
> settlement needs an authorized provider contract plus legal approval
> (blockers B1–B8 in the FinTech hardening programme). Business logic is
> deliberately independent of any real custody/funding rail.

## What it is

A dedicated bounded context for a trade between a **buyer** and a
**seller**: request → counterparty verification → agreement → documents →
risk assessment → funding authorization → payment → milestones → delivery
→ dual approval → settlement eligibility → settlement, with dispute,
cancellation, refund/reversal, and a final audit record throughout.

It is **not** the generic `AgentProtocol` escrow reused as-is. See
"Relationship to AgentProtocol" below.

## Lifecycle (state machine)

`SafeDealStateMachine` is a pure, explicit transition table
(`app/Domain/SafeDeal/StateMachines/SafeDealStateMachine.php`). Any
action not listed for the current state throws
`InvalidTradeTransitionException` — there is no silent fallback and no
state jumping (e.g. `draft → settled` is impossible).

```
draft ──request_verification──▶ pending_verification ──mark_verified──▶ verified
verified ──submit_agreement──▶ agreement_pending ──accept_agreement──▶ agreed
agreed ──submit_documents──▶ documents_pending ──review_documents──▶ documents_reviewed
documents_reviewed ──assess_risk──▶ risk_assessed ──authorize_funding──▶ funding_pending
funding_pending ──confirm_payment──▶ funded ──start_milestones──▶ in_progress
in_progress ──all_milestones_done──▶ delivery_pending ──confirm_delivery──▶ delivered
delivered ──record_approval──▶ approvals_pending ──all_approved──▶ settlement_eligible
settlement_eligible ──settle──▶ settled (terminal)

Dispute (from funded … settlement_eligible):
  ──open_dispute──▶ disputed ──resolve_release──▶ settlement_eligible
                             ──resolve_return──▶ refunded (terminal)
                             ──resolve_split──▶ settlement_eligible (split stored)

Cancel: pre-funding states ──cancel──▶ cancelled (terminal)
        funded states      ──cancel──▶ refunded (terminal, funding reversed)
Risk reject: risk_assessed ──reject_risk──▶ cancelled
Terms change (agreed only): ──update_terms──▶ agreement_pending (version+1)
```

## Components

| Piece | Location | Notes |
|---|---|---|
| Aggregate | `Aggregates/SafeDealTradeAggregate.php` | Loads trade via repository, applies state-machine transitions, records events atomically, writes the final audit record on terminal states. |
| State machine | `StateMachines/SafeDealStateMachine.php` | Pure transition table + `can()` / `allowedActions()`. |
| Models | `Models/SafeDeal{Trade,Milestone,Document,Dispute,EventRecord,AuditRecord}.php` | Plain Eloquent (no tenant connection, like Ledger models). Money is `decimal(20,4)` strings, never floats. |
| Events | `Events/*.php` (18) | Plain value objects extending `SafeDealEvent` (name + payload + timestamp). Dispatched via Laravel's dispatcher **and** recorded durably. |
| Services | `Services/SafeDealService.php` | One method per lifecycle step; enforces who may act; money moves only via `SafeDealSettlementService`. |
| | `Services/CounterpartyVerificationService.php` | Fail-closed verification gate; ENHANCED required for both parties at/above the threshold (default 10,000). Verification is recorded from an explicit KYC reference — no live provider call, nothing invented. |
| | `Services/TradeRiskAssessmentService.php` | Deterministic rule-based scoring (amount, verification level, document completeness, deadline, milestones, prior disputes). ≥ 70 rejects, 40–69 flags manual review. |
| | `Services/SafeDealSettlementService.php` | The only place SafeDeal touches money (see below). |
| Repositories | `Contracts/SafeDealTradeRepositoryInterface.php`, `Repositories/SafeDealTradeRepository.php` | Eloquent-backed; bound in the provider. |
| Workflow | `Workflows/SafeDealTradeWorkflow.php` | Ordered step-runner for the happy path with compensation: any failure after funding cancels the trade and reverses the funding entry. |
| Policy | `Policies/SafeDealTradePolicy.php` | Parties may view/act; dispute resolution is admin-only. |
| HTTP | `Http/Controllers/SafeDealTradeController.php`, `Http/Requests/*.php`, `Routes/api.php` | Sanctum-protected `POST/GET /api/v1/safedeal/trades/...` (auto-loaded by `ModuleRouteLoader`). |
| Value objects | `ValueObjects/TradeAmount.php`, `TradeTerms.php` | Decimal-string money (bcmath, 4-decimal fail-closed precision); immutable terms with versioning. |
| Enums | `Enums/{TradeStatus,RiskLevel,DisputeResolution,DocumentStatus,MilestoneStatus,VerificationStatus}.php` | |
| Migrations | `database/migrations/2026_11_24_120001_create_safedeal_tables.php` | `safe_deal_trades`, `safe_deal_milestones`, `safe_deal_documents`, `safe_deal_disputes`, `safe_deal_events` (append-only), `safe_deal_audit_records`. |
| Provider | `app/Providers/SafeDealServiceProvider.php` (registered in `bootstrap/providers.php`) | Binds repository + services + workflow; registers the policy. |
| Module manifest | `app/Domain/SafeDeal/module.json` | `finaegis/safedeal` v0.1.0, requires `finaegis/ledger`. |

## Money — approved ledger path only

`SafeDealSettlementService` posts **only** through
`LedgerService::postIdempotent` / `LedgerService::reverse`. No new ledger
mechanism exists. Accounts are per-trade / per-party virtual accounts,
all `LIABILITY` (credit-normal):

- Funding: debit `SAFEDEAL:BUYER:{buyer}:{ccy}`, credit
  `SAFEDEAL:ESCROW:{trade}:{ccy}` — key `safedeal-fund:{trade}`.
- Settlement: debit escrow, credit `SAFEDEAL:SELLER:{seller}:{ccy}` —
  key `safedeal-settle:{trade}`. A SPLIT resolution posts two legs
  (`safedeal-settle-seller:{trade}`, `safedeal-settle-buyer:{trade}`).
- Refund/reversal: `LedgerService::reverse()` on the funding entry — a
  mirror entry; the original is marked `REVERSED`, never edited.
- Funding/settlement guards run **before** any posting, so a rejected
  transition can never leave an entry behind. Funding is idempotent on
  retry (same key → same entry).

## Audit

- Every state change appends a row to `safe_deal_events` (never updated
  or deleted).
- On reaching a terminal state the aggregate writes exactly one
  `safe_deal_audit_records` row: final status, ordered event history,
  SHA-256 hash of that history, ledger entry ids, and a snapshot of the
  trade. The `trade.refund_issued` event is recorded before the terminal
  transition so the audit includes it.

## Relationship to AgentProtocol escrow

Inspected: `EscrowAggregate`, `EscrowService`, `EscrowWorkflow`,
`EscrowRequest`/`EscrowResult`, `Escrow`/`EscrowDispute` models and
escrow events.

**Reused (conceptually, not by code import):**
- The escrow shape itself: funded hold → conditional release → dispute
  with release/return/split resolutions, and cancellation refunding the
  funded amount. SafeDeal mirrors these semantics with its own
  implementation because the parties, money types, and lifecycle differ.
- The dispute resolution vocabulary (`release_to_receiver` /
  `return_to_sender` / `split`), renamed to SafeDeal's
  `release_to_seller` / `return_to_buyer` / `split`.

**Deliberately NOT reused:**
- `EscrowAggregate` (Spatie event sourcing, own stored-events/snapshot
  tables, `float` amounts, agent DIDs as parties). SafeDeal uses its own
  aggregate + explicit state machine + append-only event table with
  decimal-string amounts and human counterparties.
- `EscrowService` (agent wallets, `ComplianceService` transaction checks,
  float limits). SafeDeal has its own verification gate and deterministic
  risk rules.
- `EscrowWorkflow` (Laravel Workflow package, durable workflow tables,
  agent payment requests). SafeDeal's workflow is a plain step-runner
  with compensation, no workflow infrastructure.
- `Escrow`/`EscrowDispute` models (tenant-connection models bound to
  agent identities). SafeDeal models are plain Eloquent with their own
  tables.

## API

All routes under `auth:sanctum`, prefix `/api/v1/safedeal/trades`:

| Method | Path | Step |
|---|---|---|
| POST | `/trades` | Create (buyer = caller) |
| GET | `/trades/{id}` | Show (party or admin) |
| POST | `/trades/{id}/verify` | Verify caller (`kyc_reference`, `level`) |
| POST | `/trades/{id}/agreement/submit`, `/agreement/accept` | Agreement |
| POST | `/trades/{id}/documents`, `/documents/{doc}/review` | Documents |
| POST | `/trades/{id}/risk-assessment` | Risk assessment |
| POST | `/trades/{id}/funding/authorize`, `/funding/confirm` | Funding |
| POST | `/trades/{id}/milestones/start`, `/milestones/{m}/complete` | Milestones |
| POST | `/trades/{id}/delivery/confirm` | Delivery |
| POST | `/trades/{id}/approve` | Buyer/seller approval |
| POST | `/trades/{id}/settle` | Settlement |
| POST | `/trades/{id}/disputes`, `/disputes/{d}/resolve` | Disputes |
| POST | `/trades/{id}/cancel` | Cancel / refund |

## Tests

`tests/Domain/SafeDeal/` — 19 tests, 105 assertions:

- `SafeDealLifecycleTest` (13): full lifecycle to settlement with ledger
  + audit assertions, workflow happy path, dispute return/release/split,
  cancel pre/post funding, invalid transitions, settle-twice, delivery
  guard, funding idempotency, milestone-sum validation, risk scoring.
- `SafeDealStateMachineTest` (4): full happy-path transition chain,
  invalid-transition matrix, dispute/cancel edges, deterministic risk
  scoring (0 / 100, reject rule).
- `SafeDealApiTest` (2): API end-to-end to FUNDED, invalid API
  transition, stranger 403, create validation 422.

Run: `./vendor/bin/pest tests/Domain/SafeDeal/`

## Known limits / open items

- Verification is simulated from a supplied KYC reference; wiring a real
  KYC provider (Ondato/TrustCert/Bridge) is a separate, credentialed step.
- No real custody/funding rail: funding posts only to virtual ledger
  accounts; connecting a bank/card/crypto rail needs provider + legal
  approval.
- Dispute resolution is an operator/admin act via API; no arbitrator
  assignment or voting flow yet.
- No GraphQL schema, no Filament admin resource, no notifications yet.
- `config('safedeal.enhanced_verification_threshold')` has no
  `config/safedeal.php` file yet — the default (`10000.0000`) is read
  with a fallback; add the file if the threshold needs env control.
