# Security Hardening Report — Phase 8

- **Branch:** `feat/hardening-phase0-9` (HEAD `2d6ca15` at review start)
- **Date:** 2026-11-24
- **Scope:** full security review of the FinTech hardening work (Phases 0–5) plus the
  surrounding platform surfaces it touches: authentication, authorization, role
  permissions, API authentication, rate limiting, input validation, SQL injection,
  XSS, CSRF, SSRF, file upload security, webhook authentication, secrets
  management, encryption, key storage, session handling, multi-tenant isolation,
  sensitive-data exposure, audit logging.
- **Method:** code review against the running code (not the docs), reproduction of
  the reported defects, fixes with regression tests, targeted test-suite runs.
  No changes were committed or pushed; all changes are uncommitted in the
  working tree (see "Files changed").
- **Out of scope (not changed):** Ledger / Exchange / Wallet business logic
  (only the security-relevant gates around them), production credentials,
  live-provider contracts.

> **No certification is claimed.** Nothing in this report asserts PCI DSS, SOC 2,
> banking-licence, AML, or any other regulatory compliance or certification.
> FinAegis remains a prototype: live banking, card, KYC, custody, and HSM
> integrations still need external contracts, credentials, and approvals
> (blockers B1–B8 from the audit). The fixes below close specific holes that
> were reachable in the code as shipped; they do not make the platform
> production-ready.

## Summary

| # | Finding | Severity | Status |
|---|---------|----------|--------|
| F-01 | Mock exchange-rate provider (random rates) registered in **all** environments and stored as real rates | High | **Fixed** |
| F-02 | SafeDeal dispute resolution had **no actor check** — any authenticated user could resolve any dispute (incl. money-moving outcomes) | High | **Fixed** |
| F-03 | SafeDeal counterparty "verification" accepted **any self-supplied string** as a KYC reference | High | **Fixed** |
| F-04 | Filament `UserResource` displayed/editable `two_factor_secret` + `two_factor_recovery_codes` | High | **Fixed** |
| F-05 | `key:generate` (and **every** artisan command) crashed with no `APP_KEY` — eager command discovery resolved the key-storage service | Medium | **Fixed** |
| F-06 | International + SafeDeal money endpoints had **no rate limiting** | Medium | **Fixed** |
| F-07 | Sandbox exchange-rate tables were gated by a bare config flag only (`ASSET_SANDBOX_RATES_ENABLED=true` in production would serve invented prices) | Medium | **Fixed** |
| F-08 | `SANDBOX_MODE=true` rebound the **production** payment service to the sandbox implementation (preflight was the only guard) | Medium | **Fixed** |
| F-09 | Webhook replay guard did not cover bare signatures (no `t=` timestamp) | Medium | **Fixed** |
| F-10 | CORS default allowed localhost origins in **every** environment with `supports_credentials: true` | Low | **Fixed** |
| F-11 | Committed test `APP_KEY`s in tracked env files (`.env.ci`, `.env.test`, `.env.testing`, `.env.performance`) | Low | **Fixed at HEAD** (history retains the old keys — see O-07) |
| F-12 | SafeDeal document review / risk assessment callable by any authenticated user | Medium | **Fixed** (with F-02) |
| V-01 | Demo KYC flags default-off in production — **verified** (see § Verification) | — | Verified |
| V-02 | No SQL injection / SSRF / hardcoded production secrets found in the scoped review | — | Verified (no finding) |

## Findings in detail

### F-01 — Mock exchange-rate provider served random rates as real rates (High)

**Evidence.**

- `config/exchange.php`: `default_provider` defaults to `mock` and
  `providers.mock.enabled` defaulted to `true` in **every** environment.
- `app/Providers/ExchangeRateProviderServiceProvider.php::boot()` registered
  `MockExchangeRateProvider` whenever the flag was on — no environment check.
- `app/Domain/Exchange/Providers/MockExchangeRateProvider.php`: `getRate()`
  returns fixed tables plus `rand(-1000, 1000)` jitter and random volume /
  24h change.
- `app/Domain/Exchange/Services/EnhancedExchangeRateService.php` (the
  service actually resolved for `ExchangeRateService::class`) **overrides**
  `fetchAndStoreRate()` to use the provider registry, bypassing the parent
  `ExchangeRateService::sandboxRatesEnabled()` guard entirely, and stores the
  result via `storeQuote()` with provider label `registry` — not labelled
  `sandbox_mock`. Callers: `RefreshExchangeRatesJob` and
  `ExchangeRateProviderController` (rate refresh).
- Reproduced in a test: with `APP_ENV=production`, `fetchAndStoreRate('USD',
  'EUR')` returned a stored (random) rate instead of failing closed.
- Note: wallet conversions were already protected — `ConversionService::
  fetchRate()` refuses mock/sandbox providers unless
  `wallet.conversion.allow_sandbox_provider` is on (production default off,
  env-gated). The hole was the rate-refresh / quote path, not conversions.

**Remediation.**

- `ExchangeRateProviderServiceProvider::boot()`: the mock provider is now
  registered only outside production, whatever the flag says; `setDefault()`
  is now skipped when the named provider is not registered (it throws
  otherwise, which would have crashed production boot once the mock was
  skipped).
- `config/exchange.php`: `mock.enabled` now defaults to on only in
  local/testing/demo (same convention as the other simulation flags).
- `ops:verify-env` now FAILs while `exchange.providers.mock.enabled` is on.
- `.env.production.example` now sets `EXCHANGE_MOCK_ENABLED=false`
  explicitly.

**Verification.** New tests: mock provider is absent from a freshly booted
registry in production and `fetchAndStoreRate()` returns `null`;
preflight FAILs when the flag is on. Existing
`EnhancedExchangeRateServiceTest` still passes (26-test Phase 8 suite +
121-test related batch, all green).

### F-02 — SafeDeal dispute resolution had no actor check (High)

**Evidence.** `SafeDealService::resolveDispute()` performed no party or role
check at all, and `SafeDealTradeController::resolveDispute()` did not consult
`SafeDealTradePolicy::resolveDispute()` (admin-only) either. Any
authenticated user could resolve any trade's dispute, including
`release_to_seller` / `return_to_buyer`, which move money through the
settlement refund. The same gap existed for document review and risk
assessment (F-12): `reviewDocument()` and `assessRisk()` had no actor check
— a stranger could approve/reject documents or trigger a risk assessment
whose high-risk outcome **cancels** the trade.

**Remediation.**

- Controller: `resolveDispute` now aborts 403 unless the caller is an admin
  (policy); `reviewDocument` / `assessRisk` abort 403 unless the caller is
  a counterparty or an admin (policy; new `assessRisk()` policy method).
- Service (defence in depth): `resolveDispute()` throws unless the resolver
  resolves to a known user with the `admin` role; `reviewDocument()` /
  `assessRisk()` throw unless the actor is a party or an admin. Unknown
  actor ids fail closed.
- Tests updated: `SafeDealLifecycleTest` previously passed the placeholder
  string `'admin-1'` as resolver — it now creates a real admin user.

**Verification.** New API tests: party → 403, stranger → 403, admin → 200
(`refunded`); service-level refusal without HTTP. Full SafeDeal suite green.

### F-03 — SafeDeal verification accepted any self-supplied KYC reference (High)

**Evidence.** `CounterpartyVerificationService::verify()` returned
`VERIFIED` for any non-empty `kyc_reference` string. The HTTP endpoint
lets the party themselves post the reference, so any user could post e.g.
`"fake-123"` and pass the funding gate (both parties "verified", enhanced
gate included). The domain docblock says the reference is evidence of an
external KYC check — the code treated the string itself as the check.

**Remediation.** `verify()` now binds the reference to the platform KYC
record: the party's `users.kyc_status` must be `approved` (set by
`KycService` from a real provider verdict or a manual-review decision);
level `enhanced` additionally requires platform `kyc_level` of `enhanced`
or `full`. Otherwise it throws `SafeDealException` and the party stays
`UNVERIFIED`. No live provider is called (none is contracted for this
domain); the reference is still recorded as evidence in trade metadata.
Existing SafeDeal fixtures were updated to carry approved enhanced KYC.

**Verification.** New tests: unapproved user → exception + stays
unverified; approved-basic user requesting `enhanced` → exception;
approved-enhanced user → verified. See also open item O-01.

### F-04 — Filament UserResource exposed 2FA secrets (High)

**Evidence.** `app/Filament/Admin/Resources/UserResource.php::form()` had
editable `Textarea` fields for `two_factor_secret` and
`two_factor_recovery_codes`. Any admin with user-edit access could read (and
overwrite) another user's second factor and bypass it. (`User::$hidden`
already excluded both fields from API serialization — the exposure was the
admin form only. The table never showed them.)

**Remediation.** Both fields removed from the form. `two_factor_confirmed_at`
is now a disabled status-only field with a helper note. The password field
was left as-is (hashed via the `password => hashed` cast; admin password
reset is an existing deliberate capability — see O-02).

**Verification.** New Livewire test: the edit page renders 200 and contains
neither `two_factor_secret` nor `two_factor_recovery_codes`.

### F-05 — `key:generate` boot failure via BlockchainServiceProvider (Medium)

**Evidence / root cause (reproduced).** With no `APP_KEY`,
`php artisan key:generate --show`, `php artisan --version`, and in fact
**every** artisan command failed with `MissingAppKeyException`, traced to
`app/Providers/BlockchainServiceProvider.php:51`
(`$app->make('encrypter')` inside the `SecureKeyStorageService` singleton
closure). Boot alone succeeded; the failure happened during command
handling: Artisan discovers and **instantiates every console command** when
any command runs, and `RotateWalletKeys::__construct(SecureKeyStorageService
$keyStorage)` forced resolution of the key-storage service (→ encrypter →
missing key) before `key:generate` could run — a chicken-and-egg failure
that also blocks fresh installs and any key-rotation recovery.

**Remediation.** `RotateWalletKeys` no longer injects the service in its
constructor; it resolves `SecureKeyStorageService` lazily inside `handle()`
via `app()`. No provider or service behaviour changed.

**Verification.** `APP_KEY= php artisan key:generate --show` now prints a
valid key; `APP_KEY= php artisan --version` works. Both are covered by new
subprocess regression tests.

### F-06 — No rate limiting on International / SafeDeal money endpoints (Medium)

**Evidence.** The `v1/international` and `v1/safedeal` route groups carried
only `auth:sanctum`. The `api` middleware group has no global throttle by
design ("use custom rate limiting"), and unlike transfers/deposits
(`transaction.rate_limit:*`) these new money-moving POST endpoints had no
per-endpoint limit at all: an authenticated account could hammer transfer /
trade / quotation / dispute creation without limit. (Idempotency keys limit
duplicate *effects* for transfers, not request volume.)

**Remediation.** Both groups now run under `api.rate_limit:transaction`
(30/min per user, 60s block after exceeding — the existing transaction
bucket). Rate limiting is skipped in the `testing` environment unless
`rate_limiting.force_in_tests` is set, so existing tests are unaffected.

**Verification.** Existing International + SafeDeal API suites pass
unchanged; the bucket is the same middleware already used by the other
money endpoints.

### F-07 — Sandbox exchange-rate tables gated by a bare flag only (Medium)

**Evidence.** `ExchangeRateService::sandboxRatesEnabled()` returned only
`config('asset.sandbox_rates_enabled')`. The config default is env-gated
(off in production), but an operator setting
`ASSET_SANDBOX_RATES_ENABLED=true` in production would silently serve the
hardcoded invented tables (BTC 42000, USD/EUR 0.85) on money paths — unlike
the KYC gate and the demo-mode guard, there was no runtime environment
refusal.

**Remediation.** `sandboxRatesEnabled()` now also requires a
non-production environment. `ops:verify-env` FAILs while the flag is on.
`.env.production.example` sets it `false` explicitly.

**Verification.** New test: parent service instantiated directly returns
`null` from `fetchAndStoreRate()` in production even with the flag on.

### F-08 — `SANDBOX_MODE` rebound the production payment service (Medium)

**Evidence.** `DemoServiceProvider::register()` bound
`PaymentServiceInterface` to `SandboxPaymentService` whenever
`config('demo.sandbox.enabled')` was true, with no environment check — in
production the only guard was the `ops:verify-env` preflight FAIL
(`demo.sandbox.enabled`).

**Remediation.** The sandbox binding now also requires a non-production
environment; production always falls through to
`ProductionPaymentService`.

**Verification.** New tests: production + `SANDBOX_MODE` on →
`ProductionPaymentService`; non-production + `SANDBOX_MODE` on →
`SandboxPaymentService`.

### F-09 — Webhook replay guard skipped bare signatures (Medium)

**Evidence.** `WebhookVerifier::verify()` applied its `Cache::add()` replay
guard only when the signature carried a `t=` timestamp. A bare signature
(`hash_hmac(body, secret)` with no timestamp) verified correctly could be
replayed indefinitely. (Timestamped verification already used
`hash_equals()`, a 300s tolerance, empty-secret refusal, and one-time
acceptance — all verified.)

**Remediation.** The replay guard now covers both forms (cache key is a
hash of body + signature + timestamp; no secret material is stored).

**Verification.** New test: bare signature valid once, replay rejected;
tampered body and empty secret rejected. Existing `ProviderKernelTest`
webhook test still passes.

### F-10 — CORS localhost origins allowed in every environment (Low)

**Evidence.** `config/cors.php` defaulted `CORS_LOCAL_ORIGINS` to the
localhost list (`:3000`, `:8080`, `:5173`, `:8081`) in **all** environments,
combined with `supports_credentials: true`. A production deploy that forgot
to clear it would allow credentialed cross-origin calls from a local
dev-server origin. Exploitability is low (requires the victim to run a
server on one of those ports), hence Low.

**Remediation.** Localhost defaults now apply only in local/testing/demo;
production/staging default to empty unless an operator opts in explicitly.
`ops:verify-env` FAILs a production deploy that still allows a localhost
origin (WARN outside production).

### F-11 — Committed test APP_KEYs in tracked env files (Low)

**Evidence (hashes only — no secret values are reproduced anywhere in this
report).** `.env.ci`, `.env.test`, `.env.testing`, `.env.performance` each
contained a distinct valid `base64:` `APP_KEY` (`.env.ci` and
`.env.performance` shared one). The local untracked `.env` uses a different
key. No real production secret was found in any tracked env file: the other
flagged values were settlement-account labels, model names, commented-out
placeholders, and `pk_test_xxx`-style placeholders (verified by a strict
entropy/placeholder scan — see § (e) below). `.env.testing` is copied to
`.env` by CI workflows, so the key must stay a valid 32-byte key.

**Remediation.** All four keys were rotated to fresh, distinct, randomly
generated keys at HEAD (no test depends on the old values — verified by the
test runs below). The old keys remain in git history (see O-07).

### F-12 — SafeDeal document review / risk assessment open to strangers (Medium)

Covered with F-02 above (same fix, same verification).

## Verified items from the commission

### (a) UserResource 2FA exposure — fixed (F-04)

### (b) DEMO_SKIP_KYC / DEMO_AUTO_APPROVE — verified, preflight enforced

- Phase 4.1's fix is present and correct: `config/demo.php` defaults
  `skip_kyc` / `auto_approve` to `APP_ENV !== 'production'` when unset.
  `DEMO_SKIP_KYC` is not consumed by any code path; `DEMO_AUTO_APPROVE` is
  consumed only by the demo-domain services, never by the KYC state
  machine; `KycVerificationGate` refuses simulated providers in production
  regardless of the flag.
- New tests prove both halves: evaluating `config/demo.php` with
  `APP_ENV=production` and both flags unset yields `false`/`false`; with
  `DEMO_SKIP_KYC=true` explicitly set, the value is `true` **and**
  `ops:verify-env` in production exits 1 with `demo.features` FAIL.
  The other three demo toggles (`instant_deposits`, `mock_external_apis`,
  `fixed_exchange_rates`) still default to `true` even in production, so a
  production deploy must set all five `false` explicitly — the preflight
  enforces this (existing behaviour, kept).

### (c) key:generate boot error — root-caused and fixed (F-05)

### (d) Simulated-provider inventory — reviewed

| Simulated provider | Production gate | Status after Phase 8 |
|---|---|---|
| Sandbox KYC / liveness / document / identity (`KycVerificationGate`) | Env/mode fail-closed; `simulated_providers_enabled` default env-gated | OK; preflight FAIL added for explicit opt-in |
| Sandbox Iranian KYC (`SandboxIranianKycProvider`) | Format-only checksum; every real check `requires_manual_review`; no provider contracted | OK (no live path exists) |
| Sandbox Iranian rails SATNA/PAYA/PEL/card-to-card (`SandboxIranianRailAdapter`) | `DemoModeGuard` (local/testing/demo + flag only); rails disabled by default | OK |
| Sandbox/demopayment (`SandboxPaymentService`, `DemoPaymentService`) | `DemoServiceProvider` env binding; `SANDBOX_MODE` preflight FAIL | **Fixed** (F-08: runtime guard added) |
| Sandbox bank adapter (`SandboxBankAdapter` via `ProviderRegistry`) | `sandboxAllowed=false` in production; unknown names fail closed | OK; test added |
| Mock custodian (`MockBankConnector`) | `isPermitted()` false outside local/testing/demo | OK |
| Mock/demo exchange rates (`MockExchangeRateProvider`, `ExchangeRateService` sandbox tables) | — | **Fixed** (F-01, F-07) |
| Wallet conversion sandbox rates (`ConversionService`) | Refused unless `wallet.conversion.allow_sandbox_provider` (production default off, env-gated) | OK (pre-existing) |
| Demo Exchange / Lending / Stablecoin / Paymaster / Bundler | Bound only in `demo` env (or sandbox mode) | OK |
| Simulated custody / key management (`KEY_MANAGEMENT_DEMO_MODE`, default true) | Preflight FAIL while on | OK (no change; template sets `false`) |
| RegTech / AI / SOC2 demo modes | Preflight FAIL while on | OK |
| Apple JWS verification bypass | Preflight FAIL while on | OK |
| Bridge webhook dev passthrough (no credentials) | Accepts any well-formed request only in non-production; 401 in production | OK (verified in code) |

No simulated provider can be enabled in production by accident after this
phase **except by an operator explicitly setting its flag and skipping the
preflight** — and the preflight now FAILs for every such flag
(`demo.features`, `demo.sandbox.enabled`, `asset.sandbox_rates_enabled`,
`exchange.providers.mock.enabled`, `kyc.verification.simulated_providers_enabled`,
`payment_rails.simulation.enabled`, `keymanagement`/`regtech`/`ai`/`soc2`
demo modes, Apple JWS bypass). Runtime env-guards backstop the KYC,
payment, banking-registry, exchange-rate, and demo-mode paths.

### (e) Tracked env files — scanned, no production secrets

- Strict scan (secret-named keys, high-entropy values, placeholders/URLs/
  labels excluded; values never printed): **no real production secret** in
  `.env.example`, `.env.production.example`, `.env.zelta.example`,
  `.env.demo`, `.env.dev`, `.env.ci`, `.env.test`, `.env.testing`,
  `.env.performance`.
- Real findings: the four committed test `APP_KEY`s (F-11, rotated at HEAD).
- `.env.production.example` now explicitly sets the simulation opt-outs
  (`SANDBOX_MODE`, `KYC_SIMULATED_PROVIDERS_ENABLED`,
  `KYC_VERIFICATION_MODE=production`, `PAYMENT_SIMULATION_ENABLED`,
  `ASSET_SANDBOX_RATES_ENABLED`, `EXCHANGE_MOCK_ENABLED`,
  `CORS_LOCAL_ORIGINS=` empty) and `SESSION_SECURE_COOKIE=true`.
  It already had `DEMO_SKIP_KYC=false`, `DEMO_AUTO_APPROVE=false`,
  `APP_DEBUG=false`, `SESSION_ENCRYPT=true`, `KEY_MANAGEMENT_DEMO_MODE=false`.

## Other areas reviewed (no finding / verified)

- **Authorization — International:** ownership is enforced everywhere
  checked: transfer `show`/`act`/`history` use `InternationalTransferPolicy`
  (owner only); operator routes require the compliance role; `createTransfer`
  verifies the account / counterparty / beneficiary all belong to the caller
  and that the beneficiary belongs to the counterparty; account / counterparty
  / beneficiary / quotation `show`/`accept` return 404 for other users.
  New cross-user + idempotency-replay tests added.
- **Authentication:** `auth:sanctum` on all International / SafeDeal routes;
  unauthenticated requests return 401 (tested); non-admin `GET /admin`
  redirects to `/dashboard` (tested); admin access returns 200 (tested).
- **Rate limiting:** auth (3/min + 5-min block), transaction (30/min),
  query, webhook buckets exist and are applied on auth / webhook / transfer /
  deposit routes. Gap was only the two new route groups (F-06).
- **Webhooks:** `WebhookVerifier` (HMAC + `hash_equals` + tolerance +
  replay) fixed (F-09); `BridgeWebhookVerifier` (RSA `v0` / HMAC `v1`,
  fail-closed in production) verified; webhook routes carry the
  `api.rate_limit:webhook` bucket; idempotent ingestion via
  `processed_webhook_events`.
- **Input validation:** SafeDeal / International controllers validate via
  `FormRequest` / `$request->validate()` (amounts as integer minor units or
  decimal strings with bcmath; enums via `in:`; lengths capped). No
  user-controlled `orderBy` / raw SQL with interpolation found
  (`DB::raw` uses are static `count(*)` aggregates only). No user-controlled
  outbound URL fetching (SSRF) found in the scoped domains. No hardcoded
  production secrets found (`sk_live_` hits are runtime-generated keys or
  OpenAPI examples).
- **File uploads:** KYC documents require `file|mimes:jpg,jpeg,png,pdf|
  max:10240`, stored on the `private` disk under `kyc/{uuid}` with an audit
  row. Avatars require `image|mimes:jpg,jpeg,png,webp|max:2048` with
  dimension caps. No executable upload path found.
- **Secrets / encryption / key storage:** `User::$hidden` covers `password`,
  `two_factor_secret`, `two_factor_recovery_codes`; `kyc_data` uses the
  `encrypted:array` cast; `SecureKeyStorageService` encrypts seeds with
  AES-256-GCM + PBKDF2 (100k iterations) + audit logging; provider audit
  logging redacts secrets and masks account numbers (existing test).
- **Multi-tenant isolation:** International and SafeDeal queries are
  owner-scoped (`where('user_id', …)` + policy checks); `UsesTenantConnection`
  models were not changed by this phase.
- **Session handling:** `SESSION_ENCRYPT` defaults `false` in config, but
  the production template sets `true`; `http_only` defaults `true`,
  `same_site` defaults `lax`. `SESSION_SECURE_COOKIE` was unset in the
  template — now set `true` (see § (e)).
- **CSRF:** web routes use `VerifyCsrfToken`; API routes are stateless
  Sanctum token auth; MCP subdomain routes intentionally carry no CSRF /
  session (documented in CLAUDE.md).
- **Audit logging:** KYC approve/reject, KYC document upload, provider
  calls, SafeDeal lifecycle events, and International transfers/audit logs
  write audit records (verified by existing tests).

## Tests

### New tests — `tests/Feature/Security/Phase8SecurityHardeningTest.php` (26 tests, 74 assertions)

- SafeDeal authorization: dispute resolution (party 403 / stranger 403 /
  admin 200 + service-level refusal); document review + risk assessment
  (stranger 403 / party 200).
- SafeDeal fake KYC: unapproved user refused + stays unverified;
  approved-basic requesting `enhanced` refused; approved-enhanced accepted.
- International cross-user access (transfer/account/counterparty/quotation)
  + idempotency replay (same payload → same transfer; different payload →
  409 `IDEMPOTENCY_CONFLICT`) + unauthenticated 401s.
- Webhook spoofing/replay: bare signature valid once, replay/tamper/empty
  secret rejected.
- Disabled providers in production: sandbox registry refusal; KYC gate;
  demo-mode guard; sandbox exchange rates (parent + mock-registry);
  payment-service binding both ways.
- Production demo flags: unset defaults `false`; explicit `true` fails the
  preflight; all simulation opt-ins fail the preflight; localhost CORS in
  production fails the preflight.
- `key:generate` with empty `APP_KEY` (subprocess) + `--version` with empty
  `APP_KEY` (subprocess).
- Filament UserResource: edit page contains no 2FA secret fields.
- Admin panel: non-admin redirected, admin admitted.

### Updated existing tests

- `tests/Domain/SafeDeal/SafeDealLifecycleTest.php` — fixtures carry
  approved enhanced KYC; dispute resolver is a real admin user.
- `tests/Domain/SafeDeal/SafeDealApiTest.php` — buyer/seller carry approved
  enhanced KYC for the full-flow test.
- `tests/Feature/Console/OpsVerifyEnvCommandTest.php` — happy-path helper
  sets the new preflight flags `false` and a production-only CORS origin
  list.

### Test results (actual runs, this session)

| Suite | Result |
|---|---|
| `tests/Feature/Security/Phase8SecurityHardeningTest.php` (new) | **26 passed** (74 assertions) |
| SafeDeal + International + `OpsVerifyEnvCommandTest` + `ProviderKernelTest` | **129 passed** (563 assertions) |
| KYC Phase 4.1 + Asset + EnhancedExchangeRateService + UserResource Filament | **121 passed** (365 assertions) |
| GHSA security suites + Bridge webhook verifier | **202 passed, 1 risky** (763 assertions) |
| Banking + Payment + Wallet Phase 3 + ConversionService | **216 passed** (708 assertions) |
| Phase 8 + OpsVerifyEnv + SafeDeal (after php-cs-fixer) | **108 passed** (405 assertions) |

- `php-cs-fixer` run on the changed files (3 files adjusted: import style,
  array alignment, one pre-existing alignment in `UserResource`).
- `phpstan` (level 8) on the changed app files: **no new errors introduced
  by Phase 8**. Pre-existing errors remain on untouched lines of the
  SafeDeal files (`$request->user()` nullability, bcmath `numeric-string`
  types) and in `config/exchange.php` (provider classes referenced in
  config that do not exist) — reported, not fixed here; see O-06.
- Full test suite (1,359 files) was **not** run; the suites above cover the
  touched areas. The working tree also contains other agents' in-flight
  changes (Meeting, International services, docs) that were not touched by
  this phase.

## Remaining open findings

| # | Item | Severity | Next step |
|---|------|----------|-----------|
| O-01 | SafeDeal verification still trusts the platform KYC record + a self-supplied reference string; there is no live lookup of the reference against Ondato/Bridge. By design (no vendor contracted for this domain) — but a forged *platform* KYC approval (e.g. a rogue manual review) would propagate into escrow verification. | Medium | When a real KYC vendor is contracted, resolve the reference against the vendor record instead of `users.kyc_status`; until then keep manual-review approval audited. |
| O-02 | Filament `UserResource` still lets an admin set any user's password via the edit form (hashed on save). Deliberate admin capability, but it is a silent account-takeover primitive with no dedicated audit event. | Low | Route password resets through a dedicated, audited admin action (or require the user's own reset flow). |
| O-03 | `SESSION_SECURE_COOKIE` / `SESSION_ENCRYPT` are env-dependent; a production deploy that copies `.env.example` instead of `.env.production.example` would get insecure defaults. | Low | Template fixed (see § (e)); consider making the config defaults secure when `APP_ENV=production` (as done for the demo flags). |
| O-04 | Blog views render `{!! $post->icon_svg !!}` (raw SVG from the DB). Only admin-authored content today; a compromised author account could store XSS. | Low | Sanitize SVG on save (allow-list) or render via an SVG component. |
| O-05 | Review-account bypass flags (`account_flags`, incl. `RATE_LIMIT` bypass) are a standing privileged mechanism; not re-audited here. | Low | Keep the existing per-flag feature tests in `tests/Feature/AccountProvisioning/Bypasses/`; review the flag table periodically. |
| O-06 | Pre-existing PHPStan level-8 errors on untouched lines of the SafeDeal files and `config/exchange.php` (missing provider classes). | Low | Burn down separately; do not present the tree as phpstan-clean. |
| O-07 | Rotated test `APP_KEY`s remain in git history. Harmless if the keys were only ever used for tests (they were), but history rewrite was out of scope. | Low | If any of the four keys was ever copied outside test/CI use, rotate everywhere it was used and consider history cleanup. |
| O-08 | No live Iranian-rail / KYC / custody / HSM provider exists (blockers B1–B8). All simulation gates hold, but the platform cannot execute a real rail until contracts + credentials exist. | — | Unchanged from the audit; not a code fix. |
| O-09 | `ops:verify-env` is a deploy-time gate. An operator who deploys without running it (or without `--strict` in staging) bypasses every preflight FAIL in this report. | Medium | Wire the gate into CI as a blocking step for production deploys (deploy.yml already runs it in CI — verify it stays blocking). |

## Files changed (uncommitted, not pushed)

- `app/Console/Commands/OpsVerifyEnvCommand.php` — new preflight FAILs
  (asset sandbox rates, mock exchange provider, KYC simulated providers,
  payment simulation, localhost CORS in production).
- `app/Console/Commands/RotateWalletKeys.php` — lazy key-storage resolution
  (F-05).
- `app/Domain/Asset/Services/ExchangeRateService.php` — sandbox rates
  env-gated (F-07).
- `app/Domain/Banking/Providers/Support/WebhookVerifier.php` — replay guard
  for bare signatures (F-09).
- `app/Domain/International/Routes/api.php` — `transaction` rate-limit
  bucket (F-06).
- `app/Domain/SafeDeal/Http/Controllers/SafeDealTradeController.php` —
  policy 403s for dispute resolution / document review / risk assessment
  (F-02, F-12).
- `app/Domain/SafeDeal/Policies/SafeDealTradePolicy.php` — new `assessRisk()`
  (F-12).
- `app/Domain/SafeDeal/Services/CounterpartyVerificationService.php` —
  verification bound to approved platform KYC (F-03).
- `app/Domain/SafeDeal/Services/SafeDealService.php` — service-level
  admin/party guards (F-02, F-12).
- `app/Domain/SafeDeal/Routes/api.php` — `transaction` rate-limit bucket
  (F-06).
- `app/Filament/Admin/Resources/UserResource.php` — 2FA secrets removed
  from the form (F-04).
- `app/Providers/DemoServiceProvider.php` — sandbox payment binding
  production-guarded (F-08).
- `app/Providers/ExchangeRateProviderServiceProvider.php` — mock provider
  not registered in production; `setDefault` guarded (F-01).
- `config/cors.php` — localhost origins default only outside production
  (F-10).
- `config/exchange.php` — mock provider default env-gated (F-01).
- `.env.ci`, `.env.test`, `.env.testing`, `.env.performance` — test
  `APP_KEY`s rotated (F-11).
- `.env.production.example` — explicit simulation opt-outs +
  `SESSION_SECURE_COOKIE=true`.
- `tests/Domain/SafeDeal/SafeDealApiTest.php`,
  `tests/Domain/SafeDeal/SafeDealLifecycleTest.php`,
  `tests/Feature/Console/OpsVerifyEnvCommandTest.php` — fixture/helper
  updates for the fixes above.
- `tests/Feature/Security/Phase8SecurityHardeningTest.php` — **new** (26 tests).
- `docs/SECURITY_HARDENING_REPORT.md` — **new** (this file).
