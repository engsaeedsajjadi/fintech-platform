# AGENTS.md — SafeDeal domain

Simulated escrow trade lifecycle. Virtual ledger accounts only — no real
funds, no custody, no regulated service.

## Rules

- Every state change goes through `SafeDealStateMachine` via
  `SafeDealTradeAggregate::transition()`. Never set `status` directly.
- Money moves ONLY in `SafeDealSettlementService`, ONLY through
  `LedgerService::postIdempotent` / `reverse`. No new ledger path.
- Amounts are decimal strings (bcmath, scale 4). Never `(float)` money.
- Guards that can reject a step must run BEFORE any ledger posting.
- Events are plain value objects (`Events/SafeDealEvent`); they are
  recorded in the append-only `safe_deal_events` table by the aggregate.
- The final audit record is written once, by the aggregate, on terminal
  states. Do not write audit rows from services.
- Verification is simulated from a supplied KYC reference. Never invent
  a verification and never call a live KYC provider from this domain.

## Testing

`./vendor/bin/pest tests/Domain/SafeDeal/` — lifecycle, state machine,
risk rules, API. Drive trades through `SafeDealService`, not the models.
