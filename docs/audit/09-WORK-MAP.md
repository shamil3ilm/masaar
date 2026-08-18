# Work Map — Where to Start

**Prepared:** 2026-08-18 · **Verified against:** branch `chore/security-remediation-and-cleanup` @ `0da1b93`
**Related:** [08-CLEANUP-PLAN](08-CLEANUP-PLAN.md) · [02-SECURITY-AUDIT](02-SECURITY-AUDIT.md) · [06-PORTFOLIO-AUDIT-ERP](06-PORTFOLIO-AUDIT-ERP.md)

> **Read this before [08-CLEANUP-PLAN](08-CLEANUP-PLAN.md).** That plan was written against the 2026-08-03 snapshot. **22 commits have landed since**, and most of its Track B is already done. This document supersedes its sequencing.

---

## 1. What changed since the audit

I re-verified every finding against the current working tree rather than trusting the earlier snapshot. The remediation work is substantial and real.

### Closed ✅

| ID | Finding | Evidence in tree |
|---|---|---|
| **C-1** | `/admin/*` unauthenticated | `routes/web.php:39` → `->middleware(['auth', 'platform.admin'])` |
| **C-2** | `/portal/*` unauthenticated + `?org_id=` | `routes/web.php:69` → `->middleware(['auth', 'portal.tenant'])`; `PortalTenant` middleware added |
| **C-3** | Credentials in query parameters | `query('api_key')` / `query('api_secret')` removed from `ValidateLicense` |
| **C-4** | Dead `TenantIsolationGuard`, no structural isolation | Guard **deleted**; `Organization/Concerns/BelongsToTenant.php` + `TenantScope.php` added |
| **H-2** | SSRF via OCSP/CRL | commit `3363240` |
| **H-3** | CRL revocation silently non-functional | commit `2bc4d9a` |
| **H-5** | OpenSSL config injection | commit `3363240` |
| **H-6** | Open `/metrics` | `routes/api.php:58` → `->middleware(['metrics', 'throttle:60,1'])` + `config/metrics.php` |
| **M-1** | XML parsing unhardened | `app/Support/Xml.php` — `LIBXML_NONET`, DOCTYPE rejection, documented rationale |
| **M-3** | Per-user rate limiting | commit `8054ba2` — per-tenant limits |
| **M-5** | API key hashing | commit `8054ba2` |
| **M-6** | Insecure `.env.example` defaults | `APP_ENV=production`, `APP_DEBUG=false` |
| **M-8** | No security audit events | commit `8054ba2` — audit trail |
| **L-1/L-2** | Guard internal defects | Moot — guard deleted |
| **L-4** | Log sanitisation unwired | commit `47ba178` |

Also landed: **8,228 lines of unreferenced code deleted** (commits `ff375c9`, `463f2d5`), domain restructuring, naming conventions enforced in CI, five silently-broken scheduled jobs repaired, offline-queue fallback restored, pipeline routed through the tracked submission path. Test files grew 29 → **49 (+69%)**.

**This is genuinely good work.** All four Criticals and four of six Highs are closed.

### Still open ⏳

| ID | Finding | Current state |
|---|---|---|
| **H-1** | Signing keys under `APP_KEY` on local disk | `BranchService.php:152` still `Storage::disk('local')->put($path, encrypt(...))`. No KMS, no rotation command. **Now the top security item.** |
| **H-4** | Shell-outs to `openssl` / `java` | 20 calls across 5 files — but see §3 for the corrected risk distribution |
| **M-2** | Two auth stacks, two API surfaces | `routes/api.php:76` (`jwt.auth`) and `:207` (`license` + `/v1`) both live |
| **M-4** | Usage write on every request | Unchanged |
| **L-3** → 🟠 **H-7** | No dependency scanning | **Upgraded from Low to High on measurement.** `composer audit --locked` reports **40 advisories across 13 packages — 10 of them HIGH, all in production dependencies** (`guzzlehttp/guzzle`, `laravel/framework`, `symfony/http-kernel`, `symfony/mime`, `league/commonmark`, **`phpseclib/phpseclib`**). The phpseclib entry matters most: it performs the X.509/ASN.1 work on the signing path. See §2. |
| — | **Three licensing systems** | `Domains/Licensing/` + `Services/Licensing/` + `LicenseRegistration*` all still present |
| — | **OpenAPI spec drift** | Still **16 paths** against ~119 route registrations; still documents the deprecated prefix |
| — | **SDK stubs** | 11 of 12 directories hold ≤4 files; `javascript/` holds 1 |
| — | **erp-backend EB-H-1, EB-H-2, Rule 2** | Untouched |
| — | **erp-frontend FE-1** | Undecided |

---

## 2. W-0 — Restore the ability to verify · **EXECUTED 2026-08-18**

### The original diagnosis, and how it turned out

The blocker was **only** the PHP version. Running the suite on PHP 8.4.12:

```
Tests:    358 passed (638 assertions)
Duration: 28.62s
```

**The 22 commits are green.** The remediation is not merely plausible — it passes a substantial suite. That converts the earlier caveat ("the code reads as correct; nobody can demonstrate it *is* correct") into evidence.

### Corrections to this map

Three items I specified were **already implemented**, and better than I specified them:

| Item | Status | Found |
|---|---|---|
| **W-0.4** route-posture test | ✅ **Already existed** | `tests/Feature/Security/RouteAuthPostureTest.php` — sweeps the *router itself* for any `web` route lacking `auth`, with a documented `PUBLIC_ROUTES` allowlist requiring a stated reason. Also asserts `platform.admin` on `/admin/*` and `portal.tenant` on `/portal/*`. |
| **W-0.5** tenant isolation test | ✅ **Already existed** | `tests/Feature/Security/TenantIsolationTest.php` — 7 tests covering scoping, cross-tenant fetch-by-id, counts, credentials/webhooks, inheritance on create, and deliberate suspension. |
| **W-1.2** fail-closed scope check | ✅ **Already covered** | `TenantIsolationTest::missing_tenant_context_yields_no_rows` — Masaar's `TenantScope` does **not** carry erp-backend's [EB-H-1](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests) defect. |

**W-1.3** (security regression tests) is also largely done: `AdminApiAccessTest`, `AdminConsoleAccessTest`, `CustomerPortalAccessTest`, `LicenseCredentialSourceTest`, `MetricsAccessTest`, `MiddlewareAliasTest`, `SecurityAuditTest`, `SessionAuthTest`. Plus architecture tests (`ClassReferenceTest`, `NamingConventionTest`, `ScheduledCommandTest`).

*My audit counted 49 test files without reading what they covered. That was the error — file counts are not coverage.*

### What was actually done

| | Change | Result |
|---|---|---|
| **W-0.1** ✅ | `composer.json` `"php": "^8.2"` → `"^8.4"`; `composer.lock` hash refreshed | `composer validate --strict` passes. **No `config.platform` pin added** — the constraint is real, so pinning would only mask a genuine mismatch. |
| **W-0.2** ✅ | Ran the suite on PHP 8.4.12 | **358 passed / 638 assertions** — recorded as the baseline |
| **W-0.3** ✅ | Added `.github/workflows/ci.yml` — 3 jobs: **Tests**, **Formatting (report-only)**, **Dependency audit** | See notes below |
| **W-0.4/0.5** ✅ | Already present | — |

**Two deliberate deviations from the plan, both to avoid shipping a red build:**

- **Pint is report-only** (`continue-on-error: true`). `pint --test` flags **~170 pre-existing files** — the codebase has never been formatted. Making it blocking would fail every build from day one; fixing it here would bury a CI addition under a 170-file reformat. The reformat belongs in its own reviewable commit, after which the flag is deleted.
- **PHP 8.4 is not set as Laragon's global default.** That is a shared install — `erp-backend` and every other project on this machine use it. Changing it unilaterally would be a cross-project change ([debugging rules: pre-change breakage check](../../CLAUDE.md)). Developers switch Laragon's PHP per project, or invoke `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64\php.exe` directly. **erp-backend should be moved to 8.4 deliberately, as its own task.**

### 🟠 What CI found on its first run — H-7

Adding `composer audit` immediately surfaced a finding that my audit had rated **Low**:

| Scope | Package | Version | Advisories |
|---|---|---|---|
| PROD | `guzzlehttp/guzzle` | 7.10.0 | 9 *(high, medium)* |
| PROD | `league/commonmark` | 2.8.0 | 8 *(high, medium)* |
| PROD | `guzzlehttp/psr7` | 2.8.0 | 4 *(medium)* |
| PROD | **`phpseclib/phpseclib`** | 3.0.49 | 4 *(high, medium, low)* |
| PROD | `laravel/framework` | v12.49.0 | 3 *(high, medium)* |
| PROD | `symfony/mime` | v7.4.5 | 2 *(high, medium)* |
| PROD | `symfony/routing` | v7.4.4 | 2 *(medium)* |
| PROD | `symfony/http-kernel` | v7.4.5 | 1 *(**high**)* |
| PROD | `symfony/http-foundation`, `symfony/mailer`, `symfony/polyfill-intl-idn`, `psy/psysh` | — | 4 *(medium, low)* |
| dev | `symfony/yaml` | v7.4.1 | 3 *(low)* |

**Totals: 40 advisories · 13 packages · 10 HIGH, all in production dependencies.**

`phpseclib` is the one to note specifically: it performs the X.509 and ASN.1 work on the certificate and signing path, so a vulnerability there lands closer to the product's core guarantee than the others.

**This is the clearest possible justification for W-0.3.** The advisories were not new — nothing was looking. L-3 is reclassified **H-7**.

### ✅ H-7 fixed the same day

With a green 358-test baseline available to verify against, `composer update` was run within the existing constraints (no constraint was loosened):

| | Before | After |
|---|---|---|
| Advisories | **40 across 13 packages, 10 HIGH** | **0** |
| Test suite | 358 passed / 638 assertions | **358 passed / 638 assertions** |

Notable version moves: `laravel/framework` v12.49.0 → v12.66.0 · `guzzlehttp/guzzle` 7.10.0 → 7.15.3 · `phpseclib/phpseclib` 3.0.49 → **3.0.56** · `league/commonmark` 2.8.0 → 2.10.0 · `symfony/*` 7.4.4 → 7.4.16.

⚠️ **One change needs verification beyond the unit suite:** `lcobucci/jwt` **4.3.0 → 5.6.0 — a major version bump**, in the library underlying `tymon/jwt-auth`. The JWT tests pass (`JwtAuthenticatesUsersTest`, `MiddlewareAliasTest::jwt_sets_tenant_context`, all of `SessionAuthTest`), but a major bump in the token library warrants an **explicit manual check of issue/refresh/expiry against a real client** before this reaches production. Do not treat green units as sufficient here.

---

## 3. The work map

Complexity: **S** ≤1 week · **M** 1–4 weeks · **L** 1–3 months.
Each item lists what to change, how to know it is done, and what it depends on.

### Phase 1 — Lock in what was just fixed *(after W-0; ~2 weeks)*

| ID | Work | Files | Done when | Cplx | Deps |
|---|---|---|---|:---:|---|
| **W-1.1** | Verify `BelongsToTenant` is actually applied to every tenant-scoped model — the trait exists, but adoption was not confirmed | `app/Domains/*/Models/*` | Every model with `organization_id` uses the trait or carries an explicit `// PLATFORM-LEVEL:` annotation; an architectural test enforces it | **S** | W-0 |
| **W-1.2** | Confirm `TenantScope` fails **closed** when no tenant context exists (the defect found in erp-backend's equivalent — [EB-H-1](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests)) | `Organization/Concerns/TenantScope.php` | A query with no tenant context throws rather than returning all rows; test proves it | **S** | W-0 |
| **W-1.3** | Add a security-regression test per closed Critical/High | `tests/Feature/Security/` | One test per C-1…C-4, H-2, H-3, H-5, H-6 | **S** | W-0 |
| **W-1.4** | Dependency scanning: `composer audit` in CI + Renovate/Dependabot | `.github/` | CI fails on a known-vulnerable package | **S** | W-0.3 |

**Why first:** cheapest possible insurance on work already paid for.

---

### Phase 2 — Close the remaining security gap *(~3 weeks)*

| ID | Work | Files | Done when | Cplx | Deps |
|---|---|---|---|:---:|---|
| **W-2.1** | 🟠 **H-1 — signing keys off local disk.** Envelope-encrypt with KMS (or Vault Transit); store ciphertext in the DB, not `storage/app`. Write `masaar:rotate-credential-encryption` **before** it is needed. | `Organization/Services/BranchService.php`, `Fatoora/Services/FatooraSubmissionService.php` | Keys resolve from KMS; app scales to >1 replica; rotation command tested | **M** | W-0 |
| **W-2.2** | 🟠 **H-4 — runtime shell-outs.** See the risk split below; fix the 4 runtime calls, defer the 16 in console commands. | `Fatoora/Services/CertificateService.php` (3), `XadesSigner.php` (1) | No `shell_exec` in any request or queue path; `phpseclib` used instead | **M** | W-0 |
| **W-2.3** | Boot-time assertion: refuse to start when `APP_ENV=production && APP_DEBUG=true` | `Providers/AppServiceProvider.php` | App fails fast on the misconfiguration | **S** | — |

**Corrected risk distribution for H-4.** My audit reported 20 shell-outs without distinguishing where they run. Re-checked:

| Location | Calls | Risk |
|---|:---:|---|
| `Console/Commands/FatooraGenerateCsr.php` | 9 | 🔵 Low — developer tooling, not request-path |
| `Console/Commands/FatooraSandboxTest.php` | 5 | 🔵 Low — same |
| `Console/Commands/FatooraOnboarding.php` | 2 | 🔵 Low — same |
| **`Fatoora/Services/CertificateService.php`** | **3** | 🟠 **Runtime** — OCSP/CRL on the submission path |
| **`Fatoora/Services/XadesSigner.php`** | **1** | 🟠 **Runtime** — TSA timestamp-token parsing |

**Only 4 of 20 sit in a runtime path.** Fix those; the 16 console-command calls are acceptable for now and should be a documented follow-up, not a blocker. *(Note: an earlier count of mine also matched `curl_exec` — that is the cURL library, not a shell-out, and is benign.)*

**W-2.1 is the highest-value item on this map** — it is simultaneously the top open security finding *and* the blocker on horizontal scaling ([05 §1](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#1-non-functional-requirements)). One piece of work, two outcomes.

---

### Phase 3 — Make the product honest *(~4 weeks)*

The largest remaining gap is no longer security. It is that **the documentation still describes a product that does not exist**, which is the first thing every prospective customer encounters.

| ID | Work | Done when | Cplx | Deps |
|---|---|---|:---:|---|
| **W-3.1** | **Generate `docs/openapi.yaml` from routes + FormRequests.** Add a CI test that fails when the committed spec drifts from the generated one. | Spec covers every route; CI catches drift; deprecated prefixes gone | **M** | W-0.3 |
| **W-3.2** | **Delete 10 empty SDK directories.** Keep TypeScript and PHP as the Tier-1 targets. Correct the README. | `sdks/` contains only what exists; README claims match disk | **S** | — |
| **W-3.3** | Correct remaining README/doc overclaims — "production ready", `docs/PRODUCTION-READINESS.md` | Every claim is verifiable | **S** | — |
| **W-3.4** | Generate the TypeScript SDK from the spec | One real, tested SDK published | **M** | W-3.1 |

**W-3.2 costs an hour and removes a standing credibility risk with the primary persona** ([04 §5.2](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#52-personas)). Do it the same day you read this.

---

### Phase 4 — Reduce accidental complexity *(~4 weeks)*

| ID | Work | Done when | Cplx | Deps |
|---|---|---|:---:|---|
| **W-4.1** | Collapse three licensing systems → `Subscription` + `DeploymentLicence` | One concept per purpose; `Services/Licensing` merged or deleted | **M** | W-0, W-1 |
| **W-4.2** | Introduce one `AuthContext`; unify the JWT and licence stacks | Nothing reads `$request->attributes` or a resolver singleton directly | **M** | W-4.1 |
| **W-4.3** | Collapse `/api/*` and `/api/v1/*` into one `/v1` surface, one error envelope, one scope vocabulary | One documented surface; deprecation notice issued for the other | **M** | W-4.2, W-3.1 |

**W-4.2 is the critical-path item** — it unblocks W-4.3 and everything in [05 §7](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#7-roadmap) Phase 2.

---

### Phase 5 — Prove the compliance claim *(~4 weeks, can run parallel to Phase 3–4)*

| ID | Work | Done when | Cplx |
|---|---|---|:---:|
| **W-5.1** | **ZATCA conformance suite** — validate generated XML against the official UBL 2.1 XSD and ZATCA schematron, using ZATCA's published sample invoices as golden fixtures. Assert hash, signature and QR byte-for-byte. | Suite green against official fixtures | **M** |
| **W-5.2** | Certificate fixtures: valid / expired / **revoked** / wrong-curve / malformed | Each fixture asserted; the revoked one would have caught H-3 | **S** |
| **W-5.3** | Raise coverage on auth, tenancy, licensing, signing to ≥80% | Coverage gate in CI | **M** |

**W-5.1 is the work that determines whether the product actually works.** It is the largest open risk to the compliance claim ([R-5](05-TARGET-ARCHITECTURE-AND-ROADMAP.md#6-risk-register)) and nothing in the last 22 commits addressed it.

---

### Phase 6 — erp-backend *(parallel track, different repo)*

Independent of Masaar; can run concurrently with any phase above if you have a second pair of hands.

| ID | Work | Finding | Cplx |
|---|---|---|:---:|
| **W-6.1** | Make the tenant scope fail closed; add explicit `TenantContext` for jobs | [EB-H-1](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-1--the-tenant-global-scope-silently-disengages-outside-http-requests) | **M** |
| **W-6.2** | Audit 501 models lacking the tenancy trait; add the enforcing architectural test | [EB-H-2](06-PORTFOLIO-AUDIT-ERP.md#-eb-h-2--501-models-carry-organization_id-without-the-tenancy-trait) | **M** |
| **W-6.3** | Delete `ZatcaClientV1`; Masaar owns all ZATCA knowledge | [C-3 in cleanup plan](08-CLEANUP-PLAN.md#4-track-c--delete-dont-refactor) | **S** |
| **W-6.4** | Convert the top 8 cross-module flows to orchestrators | [06 §3.2](06-PORTFOLIO-AUDIT-ERP.md#32-the-central-architectural-weakness--rule-2-is-125th-adopted) | **L** |
| **W-6.5** | Restrict `forceState()` / `withoutTenantCheck()` by architectural test | EB-M-1 | **S** |

**Note:** Masaar now has its own `BelongsToTenant`, so the [Track A](08-CLEANUP-PLAN.md#2-track-a--propagate-erp-backends-standards-to-masaar) "transplant the trait" recommendation is **superseded** — it was implemented independently. The remaining transplant opportunities are `VersionedRuleResolver` (Masaar's `rule_version` column is still unused), `HasStateMachine`, and the architecture-rules document format.

---

## 4. This week — ✅ W-0 complete, remainder below

W-0 finished in one session rather than four days, because W-0.4 and W-0.5 already existed. What was planned for days 3–4 became the day-1 dependency fix instead.

**Delivered:** PHP 8.4 constraint · green 358-test baseline · CI with three jobs · 40 advisories → 0.

### Remaining for this week

| Task | Why now |
|---|---|
| **Open the PR and confirm CI is green on the runner** | Everything above was verified locally on Windows. The workflow itself has never executed. |
| **Manually verify JWT issue / refresh / expiry** | `lcobucci/jwt` major bump — units are not sufficient (§2) |
| **W-3.2 + W-3.3** — delete the 10 empty SDK directories, correct the README overclaims | ~1 hour; removes a standing credibility risk |
| **Move `erp-backend` to PHP 8.4** | Keeps the portfolio on one runtime before the two drift |

Then **W-2.1 (KMS)** as the next substantial item — still the top open security finding and the horizontal-scaling blocker.

### Follow-ups this session created

| Item | Detail |
|---|---|
| **Format the codebase with Pint** | ~170 files. Its own isolated commit; then delete `continue-on-error` from the `formatting` job to make it blocking. |
| **Renovate or Dependabot** | `composer audit` catches advisories but does not raise the PR. Automating updates is what stops this recurring. |
| **Decide the Laragon PHP default** | Left untouched deliberately — it is a shared install (§2). Needs a per-project or global decision. |

---

## 5. Decisions — RESOLVED 2026-08-18

### ✅ D-1 — Target PHP **8.4**

Not a preference; the dependency tree already requires it. There is **no `config.platform` pin** in `composer.json`, so the constraint is genuine:

| Package | Requires |
|---|---|
| `symfony/clock`, `css-selector`, `event-dispatcher`, `string`, `translation` | `>=8.4` |
| `pestphp/pest` + 5 plugins, `brianium/paratest`, `lcobucci/clock` | `~8.3–8.5` |

Staying on 8.2 would mean **downgrading Symfony and Pest** — real work, in the wrong direction. 8.2 also reaches end of security support in **December 2026**; 8.4 runs to **December 2028**.

**PHP 8.4.12 is already installed** at `C:\laragon\bin\php\php-8.4.12-nts-Win32-vs17-x64`. W-0.1 is a Laragon version switch plus a one-line `composer.json` edit — not an install.

**Actions:** set `"php": "^8.4"` in `composer.json`; switch Laragon to 8.4.12; re-run `composer install`; **align `erp-backend` to 8.4 as well** so the portfolio runs one runtime (it declares `^8.2` today and will drift otherwise).

### ✅ D-2 — Build the UI, but **generate** it

UI is required. An ERP without screens is unsellable to most ERP buyers, so "headless forever" was never the recommendation — the real question was *when* and *how*.

**How is the part that decides whether it is affordable.** 25+ modules × ~6 screens ≈ 150+ screens. Hand-built at the current rate (38 files in the monorepo to date) this does not converge. Generated from an OpenAPI spec — which the backend can emit from its 387 controllers, FormRequests and API Resources — the marginal cost per module collapses to reviewing and styling generated CRUD.

**This makes the OpenAPI work (W-3.1 / W-6) the gate on the frontend**, and reorders the map accordingly: spec first, then generated client, then screens. Hand-building screens before the spec exists is the expensive path.

### ✅ D-3 — All three together

Noted, and planned for in §6. One caution, stated once: shipping three products in parallel is how the current state arose — a compliance platform that had four unauthenticated endpoints, an ERP applying its own best pattern in 1 of 25 places, and a frontend with 38 files. Capacity, not capability, was the binding constraint.

§6 therefore sequences the work so the three tracks **share one foundation** rather than competing for the same weeks.

---

## 6. Shipping all three together — the shared-keystone plan

Running three tracks in parallel only works if they are not three separate efforts. They are not — **two pieces of work serve all three products.** Build those first, then the tracks run concurrently without competing.

### The two shared foundations

| Foundation | Serves Masaar | Serves erp-backend | Serves erp-frontend |
|---|---|---|---|
| **F-1 — CI that runs tests** (W-0) | Verifies the 22 security commits | Verifies EB-H-1/H-2 fixes | Typecheck + lint gate |
| **F-2 — Generated OpenAPI specs** (both backends) | SDKs, docs, contract tests, sandbox | Contract tests, API docs | **Generates the client and CRUD screens** |

**F-2 is the keystone.** The same artefact that fixes Masaar's biggest DX gap ([04 §1.1](04-PRODUCT-DX-AND-GAP-ANALYSIS.md#11-documentation-that-is-wrong)) is what makes 150 ERP screens affordable (D-2) and what gives erp-backend contract tests. One piece of work, three products. Building it once, early, is what converts "all three together" from over-extension into a plan.

### Sequence

```
WEEKS 1–2   F-1  CI + tests green (both backends)
            └─ W-0.1…W-0.5 · quick wins W-3.2, W-3.3

WEEKS 3–6   F-2  Generate OpenAPI from routes/FormRequests (both backends)
            └─ W-3.1 (Masaar) + W-6 D-6 (erp-backend), with drift tests

            ══ tracks fork here ══════════════════════════════════

MASAAR              ERP-BACKEND              ERP-FRONTEND
W-1 lock in fixes   W-6.1 tenant scope       generate api-client from F-2
W-2.1 KMS  ◀top     W-6.2 501 models         generate CRUD scaffolding
W-2.2 shell-outs    W-6.3 del ZatcaClientV1  screen-by-screen review
W-5.1 conformance   W-6.5 arch tests         ── highest-volume track ──
W-3.4 TS SDK        W-6.4 orchestrators (L)
W-4 consolidation
```

**Critical path:** F-1 → F-2 → frontend generation. The frontend is the longest track, so **the earlier F-2 lands, the earlier it can start** — which is the strongest argument for not deferring the spec work.

### What this costs

| | Weeks 1–6 | Then |
|---|---|---|
| **Shared** | 1 engineer on F-1, then F-2 across both backends | — |
| **Masaar** | quick wins only | 1 engineer: KMS → conformance → SDK → consolidation |
| **erp-backend** | included in F-2 | 1 engineer: tenancy → arch tests → orchestrators |
| **erp-frontend** | blocked on F-2 *(use the time for D-2 screen inventory + design review)* | 1–2 engineers: generate → review → style |

**Below roughly three engineers, "all together" becomes round-robin context-switching rather than parallelism.** If the team is smaller than that, the same sequence still holds — F-1, then F-2, then tracks — but serially, and the honest expectation is Masaar first because it is closest to revenue and already has every Critical closed.

### Where to be careful

- **Do not start frontend screens before F-2.** Hand-built screens become the thing you must throw away when generation lands.
- **Do not defer W-2.1 (KMS).** It is the top open security item *and* the horizontal-scaling blocker — it gates production for Masaar regardless of the other tracks.
- **Do not let W-6.4 (8 orchestrators, complexity L) run early.** It is the largest erp-backend item and the least urgent; it should trail W-6.1/W-6.2.
- **Re-verify erp-backend before starting W-6.** Its findings are from 2026-08-18 discovery and assume no commits have landed there since — Masaar taught us that assumption expires quickly.

---

## 7. What I have not verified

Stated plainly so this map is not read as more certain than it is.

- **The test suite has never been executed** in either audit pass — blocked by the PHP mismatch. Every claim about the remediation's *correctness* rests on reading the code, not running it. W-0.2 is what converts that from assumption to evidence.
- **`BelongsToTenant` adoption breadth was not measured.** The trait exists; how many models use it is unknown. That is W-1.1, and it is deliberately the first item after W-0.
- **The 22 commits were reviewed by their diffs and current-state greps**, not line by line. A full review of that branch before merge would be prudent and is not something this map substitutes for.
- **erp-backend and erp-frontend were not re-verified** in this pass — their findings are as of 2026-08-18 discovery and assume no commits landed there since.
